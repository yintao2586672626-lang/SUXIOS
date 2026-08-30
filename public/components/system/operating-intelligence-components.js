(() => {
    'use strict';

    const create = ({ ref, computed, inject, h, nextTick, onMounted, onUnmounted }) => {
    const decisionObjectOptions = [
        { value: '', label: '自动识别（可不选）' },
        { value: 'demand', label: '需求' },
        { value: 'segment', label: '客群' },
        { value: 'product', label: '产品' },
        { value: 'price', label: '价格' },
        { value: 'channel', label: '渠道' },
        { value: 'inventory_progress', label: '库存与进度' },
        { value: 'competition', label: '竞争' },
        { value: 'organization_review', label: '组织复盘' },
    ];
    const analystFactory = window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS;
    if (!analystFactory?.create) {
        throw new Error('缺少酒店数据分析师组件：hotel-data-analyst-components.js 未加载');
    }
    const analystComponents = analystFactory.create({ inject, h, nextTick });
    const HOTEL_DATA_ANALYST_SUGGESTIONS = analystComponents.suggestions;
    const createHotelDataAnalystFeedbackUi = analystComponents.createFeedbackUi;
    const renderHotelDataAnalystQualityReceipt = analystComponents.renderQualityReceipt;
    const renderPreciseMetricEvidence = analystComponents.renderPreciseMetricEvidence;
    const hotelDataAnalystProfile = analystComponents.hotelDataAnalystProfile;
    const renderRevenueDecisionFrame = (frame, testId = '') => {
        if (!frame || typeof frame !== 'object') return null;
        const candidates = Array.isArray(frame.candidate_objects) ? frame.candidate_objects : [];
        const keyInputs = Array.isArray(frame.key_inputs) ? frame.key_inputs : [];
        const primaryMethods = Array.isArray(frame.method_refs?.primary) ? frame.method_refs.primary : [];
        const supportingMethods = Array.isArray(frame.method_refs?.supporting) ? frame.method_refs.supporting : [];
        const status = String(frame.classification_status || 'unclassified');
        const label = String(frame.primary_label || '')
            || (candidates.length ? `待锁定：${candidates.map((item) => String(item?.label || '')).filter(Boolean).join(' / ')}` : '尚未锁定');
        const statusText = ({
            selected: '人工选择', inferred: '问题识别', ambiguous: '跨维歧义', unclassified: '未识别',
        })[status] || '待核对';
        const sourceFingerprint = String(frame.source?.fingerprint || '');
        return h('section', {
            class: 'mt-3 rounded-xl border border-amber-200 bg-amber-50/70 p-3',
            'data-testid': testId || undefined,
        }, [
            h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                h('div', [
                    h('div', { class: 'text-[11px] font-semibold uppercase tracking-wide text-amber-700' }, '八维决策框架'),
                    h('strong', { class: 'mt-1 block text-sm text-slate-900' }, label),
                ]),
                h('span', { class: 'rounded-full bg-white px-2 py-1 text-[11px] font-medium text-amber-800' }, statusText),
            ]),
            keyInputs.length
                ? h('div', { class: 'mt-2' }, [
                    h('div', { class: 'text-[11px] text-slate-500' }, '关键输入（需逐项核对）'),
                    h('div', { class: 'mt-1 flex flex-wrap gap-1.5' }, keyInputs.map((item) => h('span', {
                        key: String(item), class: 'rounded-md border border-amber-200 bg-white px-2 py-1 text-[11px] text-slate-700',
                    }, String(item)))),
                ])
                : h('p', { class: 'mt-2 text-xs text-amber-800' }, '请先选择一个主决策对象；系统不会把跨维问题强行归到单一对象。'),
            h('p', { class: 'mt-2 text-xs leading-5 text-slate-700' }, `边界：${String(frame.core_boundary || frame.framework_boundary || '待核对')}`),
            h('p', { class: 'mt-1 text-[11px] leading-5 text-slate-500' }, String(frame.evidence_gate?.message || '框架不替代经营事实。')),
            h('details', { class: 'mt-2 text-[11px] text-slate-500' }, [
                h('summary', { class: 'cursor-pointer font-medium text-slate-600' }, '查看方法索引与来源边界'),
                h('p', { class: 'mt-1 leading-5' }, `主方法：${primaryMethods.join('、') || '未锁定'}；支撑方法：${supportingMethods.join('、') || '未锁定'}。RM代码仅保留来源索引，定义未提供。`),
                sourceFingerprint ? h('p', { class: 'mt-1 break-all leading-5' }, `来源指纹：${sourceFingerprint}`) : null,
            ].filter(Boolean)),
        ]);
    };
    const operatingQuestionPanel = {
        setup() {
            const ui = inject('operatingQuestionUi');
            const valueOf = (value, fallback = {}) => (
                value && typeof value === 'object' && 'value' in value
                    ? (value.value ?? fallback)
                    : (value ?? fallback)
            );
            const currentState = () => valueOf(ui?.state);
            const currentHotelId = () => Number(ui?.ensureScope?.() || 0);
            const request = (...args) => {
                if (typeof ui?.request !== 'function') {
                    return Promise.reject(new Error('经营问答请求能力未就绪'));
                }
                return ui.request(...args);
            };
            const qualityFeedbackUi = createHotelDataAnalystFeedbackUi({
                getState: currentState,
                request,
            });
            const loadLocalAiCapabilities = async () => {
                const state = currentState();
                if (state.local_ai_loading) return state.local_ai_capabilities;
                state.local_ai_loading = true;
                state.local_ai_error = '';
                try {
                    const response = await request('/agent/local-ai/capabilities');
                    if (response.code !== 200 || !response.data) {
                        throw new Error(response.message || '本地第二大脑状态读取失败');
                    }
                    const capability = response.data;
                    if (capability?.boundaries?.local_only !== true
                        || capability?.boundaries?.external_message !== false
                        || capability?.boundaries?.automatic_execution !== false
                        || capability?.boundaries?.ota_write !== false
                    ) throw new Error('本地第二大脑能力边界回读不一致');
                    state.local_ai_capabilities = capability;
                    return capability;
                } catch (error) {
                    state.local_ai_capabilities = null;
                    state.local_ai_error = error?.message || '本地第二大脑状态读取失败';
                    return null;
                } finally {
                    state.local_ai_loading = false;
                }
            };
            const setMediaFile = (file) => {
                const state = currentState();
                state.media_file = file instanceof File ? file : null;
                state.media_error = '';
                state.media_result = null;
            };
            const loadMediaHistory = async () => {
                const state = currentState();
                const hotelId = currentHotelId();
                if (!hotelId) {
                    state.media_history = [];
                    return [];
                }
                try {
                    const response = await request(`/agent/local-media-extractions?hotel_id=${hotelId}&limit=10`);
                    if (response.code !== 200) throw new Error(response.message || '本地媒体记录读取失败');
                    const list = Array.isArray(response.data?.list) ? response.data.list : [];
                    if (list.some((item) => Number(item?.hotel_id || 0) !== hotelId)) {
                        throw new Error('本地媒体记录返回了其他门店数据');
                    }
                    state.media_history = list;
                    return list;
                } catch (error) {
                    state.media_history = [];
                    state.media_error = error?.message || '本地媒体记录读取失败';
                    return [];
                }
            };
            const extractLocalMedia = async () => {
                const state = currentState();
                const hotelId = currentHotelId();
                const file = state.media_file;
                if (state.media_loading) return null;
                if (!hotelId || !(file instanceof File)) {
                    state.media_error = '请先选择酒店和图片、音频或视频文件。';
                    return null;
                }
                state.media_loading = true;
                state.media_error = '';
                state.media_result = null;
                try {
                    const body = new FormData();
                    body.append('hotel_id', String(hotelId));
                    body.append('file', file, file.name);
                    const saved = await request('/agent/local-media-extractions', { method: 'POST', body });
                    if (saved.code !== 200 || !saved.data) throw new Error(saved.message || '本地媒体提取失败');
                    const savedResult = saved.data;
                    const resultId = Number(savedResult.id || 0);
                    if (!resultId || savedResult.persistence_status !== 'readback_verified') {
                        throw new Error('本地媒体提取没有返回保存回读凭证');
                    }
                    const readback = await request(`/agent/local-media-extractions/${resultId}`);
                    if (readback.code !== 200 || !readback.data) throw new Error(readback.message || '本地媒体提取回读失败');
                    const exact = readback.data;
                    if (Number(exact.id || 0) !== resultId
                        || Number(exact.hotel_id || 0) !== hotelId
                        || String(exact.source_sha256 || '') !== String(savedResult.source_sha256 || '')
                        || String(exact.content_digest || '') !== String(savedResult.content_digest || '')
                        || String(exact.extraction_status || '') !== String(savedResult.extraction_status || '')
                        || String(exact.source_retention || '') !== 'discarded_after_extraction'
                        || exact?.boundaries?.source_file_retained !== false
                        || exact?.boundaries?.hotel_fact_created !== false
                    ) throw new Error('本地媒体提取保存与精确回读不一致');
                    if (currentHotelId() !== hotelId) {
                        throw new Error('酒店范围已变化，本次媒体提取结果不再展示。');
                    }
                    state.media_result = exact;
                    await loadMediaHistory();
                    return exact;
                } catch (error) {
                    state.media_error = error?.message || '本地媒体提取失败';
                    return null;
                } finally {
                    state.media_loading = false;
                }
            };
            const loadWecom = async () => {
                const state = currentState();
                const hotelId = currentHotelId();
                if (state.wecom_loading) return null;
                state.wecom_loading = true;
                state.wecom_error = '';
                try {
                    const eventUrl = hotelId > 0
                        ? `/agent/wecom-inbound/events?hotel_id=${hotelId}&limit=10`
                        : '/agent/wecom-inbound/events?limit=10';
                    const [capability, bindings, events] = await Promise.all([
                        request('/agent/wecom-inbound/capabilities'),
                        request('/agent/wecom-inbound/bindings'),
                        request(eventUrl),
                    ]);
                    for (const [label, response] of [['能力', capability], ['绑定', bindings], ['事件', events]]) {
                        if (response.code !== 200) throw new Error(response.message || `企业微信${label}读取失败`);
                    }
                    state.wecom_capabilities = capability.data || null;
                    state.wecom_bindings = (Array.isArray(bindings.data?.list) ? bindings.data.list : [])
                        .filter((item) => !hotelId || Number(item?.hotel_id || 0) === hotelId);
                    state.wecom_events = (Array.isArray(events.data?.list) ? events.data.list : [])
                        .filter((item) => !hotelId || Number(item?.hotel_id || 0) === hotelId);
                    return state.wecom_capabilities;
                } catch (error) {
                    state.wecom_error = error?.message || '企业微信智能机器人工作台读取失败';
                    return null;
                } finally {
                    state.wecom_loading = false;
                }
            };
            const createWecomBindingCode = async () => {
                const state = currentState();
                const hotelId = currentHotelId();
                if (!hotelId || state.wecom_loading) return null;
                if (String(state.wecom_capabilities?.aibot_websocket?.status || '') !== 'ready') {
                    state.wecom_error = `企业微信 WebSocket 尚未就绪：${String(state.wecom_capabilities?.aibot_websocket?.error_code || '请先完成 Bot ID、Secret、中继令牌与 Worker 认证')}`;
                    return null;
                }
                state.wecom_loading = true;
                state.wecom_error = '';
                state.wecom_binding_code = null;
                try {
                    const response = await request('/agent/wecom-inbound/aibot-binding-codes', {
                        method: 'POST',
                        body: JSON.stringify({ hotel_id: hotelId, label: '宿析经营追问' }),
                    });
                    if (response.code !== 200 || !response.data) throw new Error(response.message || '企微绑定码创建失败');
                    const code = response.data;
                    if (Number(code.hotel_id || 0) !== hotelId
                        || code.persistence_status !== 'readback_verified'
                        || code.single_use !== true
                        || !/^[A-Z0-9]{8}$/.test(String(code.binding_code || ''))
                    ) throw new Error('企微绑定码保存回读凭证不一致');
                    state.wecom_binding_code = code;
                    return code;
                } catch (error) {
                    state.wecom_error = error?.message || '企微绑定码创建失败';
                    return null;
                } finally {
                    state.wecom_loading = false;
                }
            };
            const setWecomReply = async (binding, enabled) => {
                const state = currentState();
                const bindingId = Number(binding?.id || 0);
                if (!bindingId || state.wecom_reply_loading_id) return null;
                if (enabled && !window.confirm('确认允许该企微会话接收宿析回答？这只发送只读 OTA 经营问答，不会审批或执行经营动作。')) return null;
                state.wecom_reply_loading_id = bindingId;
                state.wecom_error = '';
                try {
                    const response = await request(`/agent/wecom-inbound/bindings/${bindingId}/reply-setting`, {
                        method: 'POST',
                        body: JSON.stringify({ enabled: Boolean(enabled) }),
                    });
                    if (response.code !== 200 || !response.data) throw new Error(response.message || '企微回复开关保存失败');
                    const exact = response.data;
                    if (Number(exact.id || 0) !== bindingId
                        || exact.reply_enabled !== Boolean(enabled)
                        || exact.persistence_status !== 'readback_verified'
                        || exact.automatic_execution !== false
                        || exact.ota_write !== false
                    ) throw new Error('企微回复开关保存与回读不一致');
                    await loadWecom();
                    return exact;
                } catch (error) {
                    state.wecom_error = error?.message || '企微回复开关保存失败';
                    return null;
                } finally {
                    state.wecom_reply_loading_id = 0;
                }
            };
            const disableWecomBinding = async (binding) => {
                const state = currentState();
                const bindingId = Number(binding?.id || 0);
                if (!bindingId || state.wecom_reply_loading_id) return null;
                if (!window.confirm('确认停用并解绑该企微会话？历史事件会保留，但该会话将不能继续收发宿析回答。')) return null;
                state.wecom_reply_loading_id = bindingId;
                state.wecom_error = '';
                try {
                    const response = await request(`/agent/wecom-inbound/bindings/${bindingId}/disable`, {
                        method: 'POST',
                        body: JSON.stringify({}),
                    });
                    if (response.code !== 200 || !response.data) throw new Error(response.message || '企微会话解绑失败');
                    const exact = response.data;
                    if (Number(exact.id || 0) !== bindingId
                        || String(exact.status || '') !== 'disabled'
                        || exact.reply_enabled !== false
                        || exact.conversation_reference_released !== true
                        || exact.historical_events_retained !== true
                        || exact.persistence_status !== 'readback_verified'
                    ) throw new Error('企微会话解绑与回读不一致');
                    await loadWecom();
                    return exact;
                } catch (error) {
                    state.wecom_error = error?.message || '企微会话解绑失败';
                    return null;
                } finally {
                    state.wecom_reply_loading_id = 0;
                }
            };
            onMounted(() => {
                ui?.ensureScope?.();
                void ui?.loadScopeOptions?.({ applyRecommendation: true });
                void ui?.loadHistory?.();
                void loadLocalAiCapabilities();
                void loadMediaHistory();
                void loadWecom();
            });
            return () => {
                const state = valueOf(ui?.state);
                const form = valueOf(ui?.form);
                const selectedHotel = valueOf(ui?.selectedHotel, null);
                const hotels = valueOf(ui?.hotels, []);
                const result = state.result || null;
                const evidence = result?.answer?.evidence_counts || {};
                const localAi = state.local_ai_capabilities || null;
                const wecom = state.wecom_capabilities || null;
                const wecomRuntimeReady = String(wecom?.aibot_websocket?.status || '') === 'ready';
                const updateScope = (field, value) => {
                    const changed = ui?.updateScope?.(field, value);
                    if (changed && field === 'hotel_id') {
                        void loadMediaHistory();
                        void loadWecom();
                    }
                    return changed;
                };
                const children = [
                    h('div', { class: 'flex flex-col gap-3 lg:flex-row lg:items-end' }, [
                        h('div', { class: 'min-w-0 flex-1' }, [
                            h('div', { class: 'text-sm font-semibold text-indigo-900' }, '经营问答 · 统一 Agent 入口'),
                            h('div', { class: 'mt-1 text-xs text-indigo-700' }, `当前范围：${selectedHotel?.name || `酒店 #${form.hotel_id || '未选择'}`} · ${form.platform || '未选择平台'} · ${form.date_start || '未选择日期'} 至 ${form.date_end || '未选择日期'}。只读已保存证据，不写 OTA、不外发。`),
                            h('div', {
                                class: 'mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-5',
                                'data-testid': 'operating-question-scope-controls',
                            }, [
                                h('label', { class: 'text-xs font-medium text-indigo-800 sm:col-span-2' }, [
                                    h('span', { class: 'mb-1 block' }, '酒店'),
                                    h('select', {
                                        value: String(form.hotel_id || ''),
                                        disabled: Boolean(state.loading || state.scope_loading),
                                        class: 'w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm disabled:opacity-60',
                                        'data-testid': 'operating-question-hotel',
                                        onChange: (event) => updateScope('hotel_id', event?.target?.value),
                                    }, [
                                        h('option', { value: '' }, '请选择酒店'),
                                        ...(Array.isArray(hotels) ? hotels : []).map((hotel) => h('option', {
                                            key: String(hotel?.value || hotel?.id || ''),
                                            value: String(hotel?.value || hotel?.id || ''),
                                        }, String(hotel?.name || `酒店 #${hotel?.value || hotel?.id || ''}`))),
                                    ]),
                                ]),
                                h('label', { class: 'text-xs font-medium text-indigo-800' }, [
                                    h('span', { class: 'mb-1 block' }, 'OTA 平台'),
                                    h('select', {
                                        value: String(form.platform || ''),
                                        disabled: Boolean(state.loading || state.scope_loading),
                                        class: 'w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm disabled:opacity-60',
                                        'data-testid': 'operating-question-platform',
                                        onChange: (event) => updateScope('platform', event?.target?.value),
                                    }, [
                                        h('option', { value: 'ctrip' }, '携程'),
                                        h('option', { value: 'meituan' }, '美团'),
                                        h('option', { value: 'all_ota' }, '携程+美团 OTA'),
                                    ]),
                                ]),
                                h('label', { class: 'text-xs font-medium text-indigo-800' }, [
                                    h('span', { class: 'mb-1 block' }, '开始日期'),
                                    h('input', {
                                        type: 'date',
                                        value: String(form.date_start || ''),
                                        disabled: Boolean(state.loading || state.scope_loading),
                                        class: 'w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm disabled:opacity-60',
                                        'data-testid': 'operating-question-date-start',
                                        onInput: (event) => updateScope('date_start', event?.target?.value),
                                    }),
                                ]),
                                h('label', { class: 'text-xs font-medium text-indigo-800' }, [
                                    h('span', { class: 'mb-1 block' }, '结束日期'),
                                    h('input', {
                                        type: 'date',
                                        value: String(form.date_end || ''),
                                        disabled: Boolean(state.loading || state.scope_loading),
                                        class: 'w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm disabled:opacity-60',
                                        'data-testid': 'operating-question-date-end',
                                        onInput: (event) => updateScope('date_end', event?.target?.value),
                                    }),
                                ]),
                            ]),
                            h('label', { class: 'mt-3 block max-w-xs' }, [
                                h('span', { class: 'mb-1 block text-xs font-medium text-indigo-800' }, '本次决策对象'),
                                h('select', {
                                    value: String(form.decision_object || ''),
                                    disabled: Boolean(state.loading),
                                    class: 'w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm disabled:opacity-60',
                                    'data-testid': 'operating-question-decision-object',
                                    onChange: (event) => {
                                        form.decision_object = String(event?.target?.value || '');
                                        state.error = '';
                                        state.result = null;
                                    },
                                }, decisionObjectOptions.map((option) => h('option', {
                                    key: option.value || 'auto', value: option.value,
                                }, option.label))),
                            ]),
                            h('input', {
                                value: String(state.question || ''),
                                disabled: Boolean(state.loading),
                                class: 'mt-3 w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm disabled:opacity-60',
                                placeholder: '例如：这家店今天最需要复核什么？',
                                'data-testid': 'hotel-data-analyst-question-input',
                                onInput: (event) => { state.question = String(event?.target?.value || ''); },
                                onKeydown: (event) => {
                                    if (event?.key !== 'Enter' || event.isComposing || state.loading) return;
                                    event.preventDefault();
                                    ui?.ask?.();
                                },
                            }),
                        ]),
                        h('button', {
                            type: 'button',
                            disabled: Boolean(state.loading),
                            class: 'rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50',
                            'data-testid': 'hotel-data-analyst-submit',
                            onClick: () => ui?.ask?.(),
                        }, state.loading ? '回读中…' : '提交并回读'),
                    ]),
                ];
                if (state.scope_notice || state.scope_loading || state.scope_error) {
                    children.push(h('div', {
                        class: ['mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2 text-xs', state.scope_error ? 'bg-red-50 text-red-700' : 'bg-white text-indigo-700'],
                        'data-testid': 'operating-question-scope-status',
                    }, [
                        h('span', state.scope_error || (state.scope_loading ? '正在查找该酒店最近可用的严格回读事实…' : state.scope_notice)),
                        state.scope_recommended
                            ? h('button', {
                                type: 'button',
                                disabled: Boolean(state.loading || state.scope_loading),
                                class: 'rounded border border-indigo-200 bg-indigo-50 px-2 py-1 font-medium text-indigo-700 disabled:opacity-50',
                                onClick: () => ui?.applyRecommendedScope?.(),
                            }, '使用最近可用范围')
                            : null,
                    ].filter(Boolean)));
                }
                children.push(h('div', { class: 'mt-3 grid gap-3 xl:grid-cols-2' }, [
                    h('section', {
                        class: 'rounded-xl border border-emerald-100 bg-white p-3',
                        'data-testid': 'local-ai-capability-status',
                    }, [
                        h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                            h('div', [
                                h('div', { class: 'text-xs font-semibold text-emerald-800' }, '本机第二大脑'),
                                h('p', { class: 'mt-1 text-[11px] text-slate-500' }, 'Ollama 文本、视觉、向量和本机语音；只作建议与提取，不自动写 OTA。'),
                            ]),
                            h('button', {
                                type: 'button',
                                disabled: Boolean(state.local_ai_loading),
                                class: 'rounded border border-emerald-200 px-2 py-1 text-xs text-emerald-700 disabled:opacity-50',
                                onClick: () => loadLocalAiCapabilities(),
                            }, state.local_ai_loading ? '探测中…' : '刷新能力'),
                        ]),
                        state.local_ai_error
                            ? h('p', { class: 'mt-2 text-xs text-red-700' }, String(state.local_ai_error))
                            : h('div', { class: 'mt-2 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4' }, [
                                ['文本', localAi?.text],
                                ['视觉', localAi?.vision],
                                ['向量', localAi?.embedding],
                                ['语音', localAi?.audio],
                            ].map(([label, capability]) => h('div', {
                                key: label,
                                class: ['rounded-lg border px-2 py-2', capability?.ready === true ? 'border-emerald-100 bg-emerald-50 text-emerald-800' : 'border-amber-100 bg-amber-50 text-amber-800'],
                            }, [
                                h('strong', label),
                                h('div', { class: 'mt-1 break-words text-[11px]' }, capability?.ready === true ? 'ready' : String(capability?.error_code || capability?.status || '未探测')),
                                capability?.model ? h('div', { class: 'mt-0.5 break-all text-[10px] opacity-75' }, String(capability.model)) : null,
                            ].filter(Boolean)))),
                        h('div', { class: 'mt-3 border-t border-emerald-100 pt-3' }, [
                            h('div', { class: 'text-xs font-semibold text-slate-700' }, '本机图片 / 音视频理解'),
                            h('div', { class: 'mt-2 flex flex-col gap-2 sm:flex-row sm:items-center' }, [
                                h('input', {
                                    type: 'file',
                                    accept: 'image/*,audio/*,video/*',
                                    disabled: Boolean(state.media_loading),
                                    class: 'min-w-0 flex-1 text-xs',
                                    'data-testid': 'local-media-file',
                                    onChange: (event) => setMediaFile(event?.target?.files?.[0] || null),
                                }),
                                h('button', {
                                    type: 'button',
                                    disabled: Boolean(state.media_loading || !state.media_file || !form.hotel_id),
                                    class: 'rounded-lg bg-emerald-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50',
                                    'data-testid': 'local-media-extract',
                                    onClick: () => extractLocalMedia(),
                                }, state.media_loading ? '本机提取中…' : '提取并回读'),
                            ]),
                            state.media_error ? h('p', { class: 'mt-2 text-xs text-red-700' }, String(state.media_error)) : null,
                            state.media_result ? h('div', {
                                class: 'mt-2 rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700',
                                'data-testid': 'local-media-readback',
                            }, [
                                h('div', { class: 'font-medium' }, `#${Number(state.media_result.id || 0)} · ${String(state.media_result.media_kind || '')} · ${String(state.media_result.extraction_status || '')}`),
                                h('p', { class: 'mt-1 whitespace-pre-wrap break-words leading-5' }, String(state.media_result.extracted_text || state.media_result.error_code || '未提取到文本')),
                                h('p', { class: 'mt-1 text-[10px] text-slate-500' }, `来源文件：${String(state.media_result.source_retention || '未说明')} · 摘要 ${String(state.media_result.content_digest || '').slice(0, 12)}…`),
                            ]) : null,
                            Array.isArray(state.media_history) && state.media_history.length
                                ? h('p', { class: 'mt-2 text-[11px] text-slate-500' }, `该门店已保存并校验 ${state.media_history.length} 条本机提取记录。`)
                                : null,
                        ].filter(Boolean)),
                    ]),
                    h('section', {
                        class: 'rounded-xl border border-sky-100 bg-white p-3',
                        'data-testid': 'wecom-aibot-workbench',
                    }, [
                        h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                            h('div', [
                                h('div', { class: 'text-xs font-semibold text-sky-800' }, '企业微信 Agent 接入'),
                                h('p', { class: 'mt-1 text-[11px] text-slate-500' }, '优先使用官方 AI Bot WebSocket；回复默认关闭，不展示或保存凭证。'),
                            ]),
                            h('button', {
                                type: 'button',
                                disabled: Boolean(state.wecom_loading),
                                class: 'rounded border border-sky-200 px-2 py-1 text-xs text-sky-700 disabled:opacity-50',
                                onClick: () => loadWecom(),
                            }, state.wecom_loading ? '读取中…' : '刷新'),
                        ]),
                        h('div', { class: 'mt-2 rounded-lg bg-sky-50 px-2 py-2 text-xs text-sky-800' }, [
                            h('strong', String(wecom?.aibot_websocket?.status || '未读取')),
                            h('span', { class: 'ml-2 break-all text-[11px]' }, String(wecom?.aibot_websocket
                                ? (wecom.aibot_websocket.error_code || '官方 WebSocket 状态正常')
                                : '尚未读取运行状态')),
                        ]),
                        h('button', {
                            type: 'button',
                            disabled: Boolean(state.wecom_loading || !form.hotel_id || !wecomRuntimeReady),
                            class: 'mt-2 rounded-lg bg-sky-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50',
                            'data-testid': 'wecom-aibot-binding-code',
                            onClick: () => createWecomBindingCode(),
                        }, wecomRuntimeReady ? '生成一次性门店绑定码' : 'WebSocket 就绪后可生成绑定码'),
                        state.wecom_binding_code ? h('div', {
                            class: 'mt-2 rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900',
                            'data-testid': 'wecom-aibot-binding-code-readback',
                        }, [
                            h('strong', String(state.wecom_binding_code.instruction || '')),
                            h('p', { class: 'mt-1 text-[11px]' }, `有效至 ${String(state.wecom_binding_code.expires_at || '')}；一次性显示，刷新后不保留。`),
                        ]) : null,
                        state.wecom_error ? h('p', { class: 'mt-2 text-xs text-red-700' }, String(state.wecom_error)) : null,
                        Array.isArray(state.wecom_bindings) && state.wecom_bindings.length
                            ? h('div', { class: 'mt-3 grid gap-2' }, state.wecom_bindings.map((binding) => h('div', {
                                key: Number(binding?.id || 0),
                                class: 'flex items-center justify-between gap-2 rounded-lg border border-slate-200 p-2 text-xs',
                            }, [
                                h('div', [
                                    h('strong', String(binding?.label || `绑定 #${Number(binding?.id || 0)}`)),
                                    h('p', { class: 'mt-1 text-[11px] text-slate-500' }, `${String(binding?.transport || '')} · ${String(binding?.status || '')}`),
                                ]),
                                binding?.transport === 'wecom_aibot_websocket' && binding?.status === 'verified'
                                    ? h('div', { class: 'flex shrink-0 items-center gap-1' }, [
                                        h('button', {
                                            type: 'button',
                                            disabled: Number(state.wecom_reply_loading_id || 0) > 0,
                                            class: ['rounded px-2 py-1 text-[11px] font-medium', binding?.reply_enabled ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'],
                                            onClick: () => setWecomReply(binding, !binding.reply_enabled),
                                        }, binding?.reply_enabled ? '关闭回复' : '主动开启回复'),
                                        h('button', {
                                            type: 'button',
                                            disabled: Number(state.wecom_reply_loading_id || 0) > 0,
                                            class: 'rounded bg-red-50 px-2 py-1 text-[11px] font-medium text-red-700 disabled:opacity-50',
                                            onClick: () => disableWecomBinding(binding),
                                        }, '停用解绑'),
                                    ])
                                    : null,
                            ].filter(Boolean))))
                            : h('p', { class: 'mt-3 text-xs text-slate-500' }, '当前门店尚无已验证企微会话绑定。'),
                        Array.isArray(state.wecom_events) && state.wecom_events.length
                            ? h('details', { class: 'mt-3 text-xs text-slate-600' }, [
                                h('summary', { class: 'cursor-pointer font-medium' }, `最近事件（${state.wecom_events.length}）`),
                                h('ul', { class: 'mt-2 space-y-1' }, state.wecom_events.slice(0, 5).map((event) => h('li', { key: Number(event?.id || 0) }, `#${Number(event?.id || 0)} · ${String(event?.processing_status || '')} · ${String(event?.delivery_status || 'not_sent')}`))),
                            ])
                            : null,
                    ].filter(Boolean)),
                ]));
                const history = Array.isArray(state.history) ? state.history.slice(0, 5) : [];
                children.push(h('details', {
                    class: 'mt-3 rounded-lg border border-indigo-100 bg-white px-3 py-2',
                    'data-testid': 'operating-question-history',
                    onToggle: (event) => {
                        if (event?.currentTarget?.open
                            && (state.history_error || !state.history_loaded_hotel_id)
                        ) {
                            void ui?.loadHistory?.({ force: true });
                        }
                    },
                }, [
                    h('summary', { class: 'cursor-pointer text-xs font-semibold text-indigo-800' }, `最近保存问答${state.history_loading ? '（读取中…）' : `（${history.length}）`}`),
                    state.history_error
                        ? h('p', { class: 'mt-2 text-xs text-red-700' }, String(state.history_error))
                        : (history.length
                            ? h('div', { class: 'mt-2 grid gap-2' }, history.map((item) => h('button', {
                                key: Number(item?.id || 0),
                                type: 'button',
                                disabled: Boolean(state.history_opening_id),
                                class: ['rounded-lg border px-3 py-2 text-left text-xs transition hover:border-indigo-300 hover:bg-indigo-50 disabled:opacity-50', Number(result?.id || 0) === Number(item?.id || 0) ? 'border-indigo-300 bg-indigo-50' : 'border-slate-200 bg-white'],
                                'data-testid': `operating-question-history-${Number(item?.id || 0)}`,
                                onClick: () => ui?.openHistory?.(item),
                            }, [
                                h('span', { class: 'block truncate font-medium text-slate-800' }, String(item?.question_text || '未命名经营问题')),
                                h('span', { class: 'mt-1 block text-[11px] text-slate-500' }, `#${Number(item?.id || 0)} · ${String(item?.platform || '')} · ${String(item?.date_start || '')}${item?.date_end !== item?.date_start ? ` 至 ${String(item?.date_end || '')}` : ''} · ${String(item?.answer_status || '已保存')}`),
                            ])))
                            : h('p', { class: 'mt-2 text-xs text-slate-500' }, state.history_loading ? '正在读取…' : '该酒店还没有已保存问答。')),
                ]));
                if (state.error) {
                    children.push(h('div', {
                        class: 'mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700',
                        'data-testid': 'operating-question-error',
                    }, String(state.error)));
                }
                if (result) {
                    const answerChildren = [
                        h('div', { class: 'flex flex-wrap items-center gap-2 text-xs' }, [
                            h('span', { class: 'font-semibold text-indigo-800' }, String(result.answer_status || '已回读')),
                            h('span', { class: 'text-gray-500' }, `事实 ${Number(evidence.facts || 0)} · 知识 ${Number(evidence.knowledge_chunks || 0)} · 记忆 ${Number(evidence.operating_memories || 0)} · 复盘 ${Number(evidence.execution_reviews || 0)}`),
                        ]),
                        h('p', { class: 'mt-2 text-sm leading-6 text-gray-700' }, String(result.answer_summary || '')),
                    ];
                    answerChildren.push(renderHotelDataAnalystQualityReceipt(
                        result.analysis_quality_receipt,
                        'operating-question-quality-receipt',
                        {
                            question: result,
                            feedbackUi: qualityFeedbackUi,
                            interactive: true,
                            feedbackTestId: 'operating-question-quality-feedback',
                        }
                    ));
                    const preciseEvidence = renderPreciseMetricEvidence(result.answer, {
                        dataGaps: result.data_gaps,
                        testId: 'operating-question-precise-results',
                        metricSetTestId: 'operating-question-precise-metric-set',
                        itemTestIdPrefix: 'operating-question-precise-item',
                    });
                    if (preciseEvidence) answerChildren.push(preciseEvidence);
                    const decisionFrame = renderRevenueDecisionFrame(result.answer?.decision_frame, 'operating-question-decision-frame');
                    if (decisionFrame) answerChildren.push(decisionFrame);
                    if (Array.isArray(result.data_gaps) && result.data_gaps.length) {
                        answerChildren.push(h('ul', { class: 'mt-2 list-disc pl-5 text-xs text-amber-700' }, result.data_gaps.map((gap, index) => (
                            h('li', { key: String(gap?.code || index) }, String(gap?.message || gap?.code || '未说明的数据缺口'))
                        ))));
                    }
                    const council = state.council_run || null;
                    const synthesis = council?.synthesis && typeof council.synthesis === 'object' ? council.synthesis : {};
                    const integrity = synthesis.artifact_integrity && typeof synthesis.artifact_integrity === 'object'
                        ? synthesis.artifact_integrity
                        : {};
                    const rawMembers = Array.isArray(council?.members) ? council.members : [];
                    const rawEvidenceRefs = Array.isArray(council?.evidence_refs) ? council.evidence_refs : [];
                    const councilRenderable = ['completed', 'partial'].includes(String(council?.status || ''))
                        && integrity.status === 'verified'
                        && Number(integrity.member_count || 0) === rawMembers.length
                        && Number(integrity.evidence_ref_count || 0) === rawEvidenceRefs.length;
                    const councilMembers = councilRenderable ? rawMembers : [];
                    const councilEvidenceRefs = councilRenderable ? rawEvidenceRefs : [];
                    const selectedLenses = councilRenderable && Array.isArray(synthesis.selected_lenses)
                        ? synthesis.selected_lenses
                        : [];
                    const terminalFactDrift = String(synthesis.error_code || '') === 'council_terminal_fact_drift';
                    const advisorySource = synthesis.advisory_source && typeof synthesis.advisory_source === 'object'
                        ? synthesis.advisory_source
                        : {};
                    const executionHandoff = synthesis.execution_handoff && typeof synthesis.execution_handoff === 'object'
                        ? synthesis.execution_handoff
                        : {};
                    answerChildren.push(h('section', {
                        class: 'mt-3 rounded-xl border p-3',
                        style: { borderColor: 'rgba(185,150,91,.36)', background: '#fbf8f0' },
                        'data-testid': 'operating-question-council-readback',
                    }, [
                        h('div', { class: 'flex flex-wrap items-start justify-between gap-2' }, [
                            h('div', [
                                h('div', { class: 'text-[11px] font-semibold uppercase tracking-wide', style: { color: '#7b6034' } }, '165视角经营顾问团'),
                                h('p', { class: 'mt-1 text-xs text-slate-600' }, '用户提供的来源包共165个条目；经静态审查后只吸纳七域方法框架，并按问题选2–5个领域视角。由同一本机模型分别审视，不等于165位真人在线或独立专家共识。'),
                            ]),
                            h('button', {
                                type: 'button',
                                disabled: Boolean(state.council_loading) || terminalFactDrift,
                                class: 'rounded-lg px-3 py-2 text-xs font-medium text-white disabled:opacity-50',
                                style: { background: '#173f34' },
                                'data-testid': 'operating-question-council-run',
                                onClick: () => ui?.runCouncil?.(),
                            }, state.council_loading
                                ? '本机会诊中…'
                                : (terminalFactDrift
                                    ? '请重新生成事实/问题'
                                    : (['pending', 'running'].includes(String(council?.status || ''))
                                        ? '继续查看进度'
                                        : (['partial', 'failed', 'blocked_by_missing_facts', 'blocked_not_configured'].includes(String(council?.status || '')) ? '继续未完成会诊' : (council ? '重新发起顾问会诊' : '发起顾问会诊'))))),
                        ]),
                        state.council_error ? h('p', { class: 'mt-2 text-xs text-red-700' }, String(state.council_error)) : null,
                        terminalFactDrift ? h('p', { class: 'mt-2 text-xs text-amber-700' }, '严格事实已发生变化；旧会诊内容已隔离，请重新生成上游事实或经营问题后再发起。') : null,
                        council ? h('div', { class: 'mt-3' }, [
                            h('div', { class: 'flex flex-wrap items-center gap-2 text-xs' }, [
                                h('strong', { style: { color: '#173f34' } }, String(council.status || '未知状态')),
                                h('span', { class: 'text-slate-500' }, `记录 #${Number(council.id || 0)} · 摘要 ${String(council.content_digest || '').slice(0, 12)}…`),
                            ]),
                            Number(advisorySource.source_entry_count || 0) > 0 ? h('p', {
                                class: 'mt-1 text-[11px] text-slate-500',
                                'data-testid': 'operating-question-council-source',
                            }, `来源包 ${Number(advisorySource.source_entry_count)} 个条目 · 指纹 ${String(advisorySource.outer_zip_sha256 || '').slice(0, 12)}… · 人物Skill未安装、未执行`) : null,
                            selectedLenses.length ? h('div', { class: 'mt-2 flex flex-wrap gap-1.5' }, selectedLenses.map((lens) => h('span', {
                                key: String(lens?.key || lens?.label || ''),
                                class: 'rounded-full border bg-white px-2 py-1 text-[11px]',
                                style: { borderColor: 'rgba(185,150,91,.4)', color: '#6f572f' },
                            }, String(lens?.label || lens?.key || '视角')))) : null,
                            councilMembers.length ? h('div', { class: 'mt-2 grid gap-2 md:grid-cols-2 xl:grid-cols-3' }, councilMembers.map((member) => h('div', {
                                key: String(member?.key || member?.label || ''),
                                class: 'rounded-lg border bg-white p-2 text-xs',
                                style: { borderColor: 'rgba(185,150,91,.28)' },
                            }, [
                                h('strong', { class: 'text-slate-800' }, `${String(member?.label || member?.key || '角色')} · ${String(member?.status || '')}`),
                                Array.isArray(member?.source_lenses) && member.source_lenses.length
                                    ? h('p', { class: 'mt-1 text-[11px] text-slate-500' }, `框架来源：${member.source_lenses.map((source) => String(source?.name || '')).filter(Boolean).join('、')}`)
                                    : null,
                                member?.business_question
                                    ? h('p', { class: 'mt-1 text-[11px] leading-5 text-slate-500' }, String(member.business_question))
                                    : null,
                                h('p', { class: 'mt-1 leading-5 text-slate-600' }, String(member?.assessment || member?.error_code || '未形成评估')),
                                Array.isArray(member?.supported_points) && member.supported_points.length
                                    ? h('p', { class: 'mt-1 text-[11px] leading-5 text-emerald-700' }, `支持观点：${member.supported_points.slice(0, 3).join('；')}`)
                                    : null,
                                Array.isArray(member?.supporting_evidence_refs) && member.supporting_evidence_refs.length
                                    ? h('p', { class: 'mt-1 break-all text-[11px] leading-5 text-emerald-700' }, `支持证据引用：${member.supporting_evidence_refs.join('、')}`)
                                    : null,
                                Array.isArray(member?.conflicting_points) && member.conflicting_points.length
                                    ? h('p', { class: 'mt-1 text-[11px] leading-5 text-amber-700' }, `冲突观点：${member.conflicting_points.slice(0, 3).join('；')}`)
                                    : null,
                                Array.isArray(member?.conflicting_evidence_refs) && member.conflicting_evidence_refs.length
                                    ? h('p', { class: 'mt-1 break-all text-[11px] leading-5 text-amber-700' }, `冲突证据引用：${member.conflicting_evidence_refs.join('、')}`)
                                    : null,
                                Array.isArray(member?.risks) && member.risks.length
                                    ? h('p', { class: 'mt-1 text-[11px] text-amber-700' }, `风险：${member.risks.slice(0, 3).join('；')}`)
                                    : null,
                                member?.falsification_check
                                    ? h('p', { class: 'mt-1 text-[11px] leading-5', style: { color: '#1f5b63' } }, `可证伪检查：${String(member.falsification_check)}`)
                                    : null,
                            ].filter(Boolean)))) : null,
                            h('div', { class: 'mt-2 rounded-lg border bg-white p-2 text-xs text-slate-700', style: { borderColor: 'rgba(185,150,91,.28)' } }, [
                                h('strong', '会商汇总'),
                                h('p', { class: 'mt-1 leading-5' }, String(synthesis.summary || synthesis.error_code || '未形成汇总')),
                                councilEvidenceRefs.length
                                    ? h('p', { class: 'mt-1 break-all text-[11px] leading-5 text-slate-500', 'data-testid': 'operating-question-council-evidence-refs' }, `本次证据引用：${councilEvidenceRefs.join('、')}`)
                                    : null,
                                councilRenderable && Array.isArray(synthesis.agreements) && synthesis.agreements.length
                                    ? h('p', { class: 'mt-1 leading-5' }, `一致点：${synthesis.agreements.join('；')}`)
                                    : null,
                                councilRenderable && Array.isArray(synthesis.conflicts) && synthesis.conflicts.length
                                    ? h('p', { class: 'mt-1 leading-5 text-amber-700' }, `冲突点：${synthesis.conflicts.join('；')}`)
                                    : null,
                                councilRenderable && Array.isArray(synthesis.missing_information) && synthesis.missing_information.length
                                    ? h('p', { class: 'mt-1 leading-5 text-amber-700' }, `缺口：${synthesis.missing_information.join('；')}`)
                                    : null,
                                councilRenderable && Array.isArray(synthesis.falsification_checks) && synthesis.falsification_checks.length
                                    ? h('p', { class: 'mt-1 leading-5', style: { color: '#1f5b63' } }, `可证伪：${synthesis.falsification_checks.join('；')}`)
                                    : null,
                                councilRenderable && synthesis.recommended_next_step
                                    ? h('p', { class: 'mt-1 font-medium leading-5', style: { color: '#173f34' } }, `建议下一步：${String(synthesis.recommended_next_step)}`)
                                    : null,
                                executionHandoff.message
                                    ? h('p', { class: 'mt-2 rounded-md bg-slate-50 px-2 py-1.5 text-[11px] leading-5 text-slate-600', 'data-testid': 'operating-question-council-execution-handoff' }, `执行衔接：${String(executionHandoff.message)}`)
                                    : null,
                            ].filter(Boolean)),
                        ].filter(Boolean)) : h('p', { class: 'mt-2 text-xs text-slate-500' }, '尚未运行；只有你主动点击后才调用本机模型并保存回读，不会自动创建或执行经营动作。'),
                    ].filter(Boolean)));
                    const actionDrafts = Array.isArray(result.answer?.action_drafts)
                        ? result.answer.action_drafts.slice(0, 1)
                        : [];
                    actionDrafts.forEach((action, actionIndex) => {
                        const actionKey = `${Number(result.id || 0)}:${actionIndex}`;
                        const intent = state.action_intents?.[actionKey] || null;
                        const ready = ui?.isActionReady?.(result, action, form) === true;
                        const steps = Array.isArray(action?.execution_steps) ? action.execution_steps : [];
                        const controls = Array.isArray(action?.risk?.controls) ? action.risk.controls : [];
                        const stops = Array.isArray(action?.stop_conditions) ? action.stop_conditions : [];
                        const evidenceRefs = Array.isArray(action?.evidence_refs) ? action.evidence_refs : [];
                        const actionChildren = [
                            h('div', { class: 'flex flex-wrap items-start justify-between gap-2' }, [
                                h('div', [
                                    h('div', { class: 'text-xs font-semibold uppercase tracking-wide text-[#8c6a2d]' }, 'AI 行动草案 · 独立评审'),
                                    h('h4', { class: 'mt-1 text-sm font-semibold text-slate-900' }, String(action?.title || '运营复核草案')),
                                ]),
                                h('span', {
                                    class: ['rounded-full px-2 py-1 text-[11px] font-semibold', ready ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'],
                                }, ready ? '证据门已通过' : '需补齐后提交'),
                            ]),
                            h('p', { class: 'mt-2 text-sm leading-6 text-slate-700' }, String(action?.action || '')),
                            h('div', { class: 'mt-2 flex flex-wrap gap-1.5 text-[11px] text-slate-600' }, [
                                h('span', { class: 'rounded bg-slate-100 px-2 py-1' }, `范围锁定：${result.platform} · ${result.date_start}${result.date_end !== result.date_start ? ` 至 ${result.date_end}` : ''}`),
                                h('span', { class: 'rounded bg-slate-100 px-2 py-1' }, `复核指标：${String(action?.expected_metric || '待补齐')}`),
                                h('span', { class: 'rounded bg-slate-100 px-2 py-1' }, `风险：${String(action?.risk?.level || action?.risk_level || '未评估')}`),
                            ]),
                        ];
                        if (steps.length) {
                            actionChildren.push(h('ol', { class: 'mt-3 list-decimal space-y-1 pl-5 text-xs leading-5 text-slate-700' }, steps.map((step, index) => (
                                h('li', { key: `step-${index}-${String(step)}` }, String(step))
                            ))));
                        }
                        actionChildren.push(h('div', { class: 'mt-3 grid gap-2 text-xs text-slate-600 md:grid-cols-2' }, [
                            h('div', { class: 'rounded-lg border border-slate-200 bg-white p-2.5' }, [
                                h('strong', { class: 'text-slate-800' }, '效果复核'),
                                h('p', { class: 'mt-1 leading-5' }, String(action?.expected_effect?.summary || '仅作为复核目标，不承诺经营提升。')),
                                h('p', { class: 'mt-1 leading-5' }, String(action?.review_window || '按同口径前后窗口复核')),
                            ]),
                            h('div', { class: 'rounded-lg border border-slate-200 bg-white p-2.5' }, [
                                h('strong', { class: 'text-slate-800' }, '风险与停止条件'),
                                h('p', { class: 'mt-1 leading-5' }, String(action?.risk?.summary || '执行前仍需按风险控制与停止条件核对。')),
                                controls.length || stops.length
                                    ? h('ul', { class: 'mt-1 list-disc pl-4 leading-5' }, [...controls, ...stops.map(item => `停止：${item}`)].slice(0, 6).map((item, index) => (
                                        h('li', { key: `guard-${index}-${String(item)}` }, String(item))
                                    )))
                                    : null,
                            ]),
                        ]));
                        if (!ready) {
                            actionChildren.push(h('p', {
                                class: 'mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800',
                                'data-testid': 'operating-question-action-blocked',
                            }, String(action?.blocked_reason || '行动草案缺少完整证据、步骤或停止条件，暂不能提交。')));
                        } else {
                            actionChildren.push(h('div', { class: 'mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-3' }, [
                                h('div', { class: 'text-[11px] leading-5 text-slate-500' }, [
                                    h('span', `已绑定 ${evidenceRefs.length} 条严格证据引用。`),
                                    h('br'),
                                    h('span', '提交后由独立 AI 重新核验事实；通过后只创建本地人工执行任务，不采集或写 OTA。'),
                                ]),
                                h('button', {
                                    type: 'button',
                                    disabled: Boolean(state.action_loading),
                                    class: 'rounded-lg bg-[#a88a52] px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-[#8f733f] disabled:opacity-50',
                                    'data-testid': intent ? 'operating-question-action-open' : 'operating-question-action-submit',
                                    onClick: () => intent
                                        ? ui?.openActionIntent?.(intent)
                                        : ui?.createActionIntent?.(action, actionIndex),
                                }, state.action_loading === actionKey
                                    ? '独立评审并回读中…'
                                    : (intent ? `查看${String(intent.status || '待评审')}任务 #${Number(intent.id || 0)}` : '提交独立评审')),
                            ]));
                        }
                        answerChildren.push(h('section', {
                            class: 'mt-3 rounded-xl border border-[#d8c7a5] bg-[#fbf8f1] p-3',
                            'data-testid': 'operating-question-action-card',
                        }, actionChildren));
                    });
                    if (state.action_error) {
                        answerChildren.push(h('div', {
                            class: 'mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700',
                            'data-testid': 'operating-question-action-error',
                        }, String(state.action_error)));
                    }
                    children.push(h('div', {
                        class: 'mt-3 rounded-lg border border-indigo-100 bg-white p-3',
                        'data-testid': 'operating-question-readback',
                    }, answerChildren));
                }
                return h('section', {
                    class: 'mb-4 rounded-xl border border-indigo-100 bg-indigo-50/60 p-4',
                    'data-testid': 'operating-question-entry',
                    'data-role-key': 'hotel_data_analyst',
                    'data-contract-version': 'hotel_data_analyst.v1',
                }, children);
            };
        },
    };
    // The evidence consultant remains available in the dedicated Agent surface.
    // The global floating entry is intentionally reassigned below to a system-use
    // guide so navigation help and operating conclusions no longer share one UI.
    const operatingEvidenceConsultant = {
        name: 'OperatingQuestionConsultant',
        props: {
            ctx: { type: Object, required: true },
        },
        setup(props) {
            const icon = (name) => h('i', { class: `fas ${name}`, 'aria-hidden': 'true' });
            const textList = (value) => Array.isArray(value) ? value : [];
            const updateScope = (field, value) => {
                const ctx = props.ctx;
                if (!ctx?.operatingQuestionForm) return;
                if (ctx.operatingQuestionState?.loading) return;
                ctx.operatingQuestionForm[field] = String(value || '');
                if (ctx.operatingQuestionState) {
                    ctx.operatingQuestionState.error = '';
                    ctx.operatingQuestionState.result = null;
                }
            };
            const applyQuestion = (question) => {
                props.ctx?.applyOperatingQuestionSuggestion?.(question);
            };
            const ask = () => props.ctx?.askOperatingQuestion?.();
            const selectField = ({ label, field, options, testId, wide = false }) => h('label', {
                class: ['sx-ai-consultant-field', wide ? 'sx-ai-consultant-field-wide' : ''],
            }, [
                h('span', label),
                h('select', {
                    value: String(props.ctx?.operatingQuestionForm?.[field] || ''),
                    disabled: Boolean(props.ctx?.operatingQuestionState?.loading),
                    'data-testid': testId,
                    onChange: (event) => updateScope(field, event?.target?.value),
                }, options.map((option) => h('option', {
                    key: String(option.value),
                    value: String(option.value),
                }, String(option.label)))),
            ]);
            const dateField = (label, field, testId) => h('label', {
                class: 'sx-ai-consultant-field',
            }, [
                h('span', label),
                h('input', {
                    type: 'date',
                    value: String(props.ctx?.operatingQuestionForm?.[field] || ''),
                    disabled: Boolean(props.ctx?.operatingQuestionState?.loading),
                    'data-testid': testId,
                    onInput: (event) => updateScope(field, event?.target?.value),
                }),
            ]);
            const runRecoveryAction = async (action, recoveryPlan, result) => {
                const ctx = props.ctx || {};
                const state = ctx.operatingQuestionState || {};
                const form = ctx.operatingQuestionForm || {};
                if (state.loading) return false;
                const scope = recoveryPlan?.scope && typeof recoveryPlan.scope === 'object'
                    ? recoveryPlan.scope
                    : {};
                const key = String(action?.key || '').trim();
                const hotelId = Number(scope.hotel_id || 0);
                const scopePlatform = String(scope.platform || '').trim();
                const dateStart = String(scope.date_start || '').trim();
                const dateEnd = String(scope.date_end || '').trim();
                const scopeMatches = hotelId > 0
                    && Number(result?.hotel_id || 0) === hotelId
                    && String(result?.platform || '') === scopePlatform
                    && String(result?.date_start || '') === dateStart
                    && String(result?.date_end || '') === dateEnd
                    && Number(form.hotel_id || 0) === hotelId
                    && String(form.platform || '') === scopePlatform
                    && String(form.date_start || '') === dateStart
                    && String(form.date_end || '') === dateEnd;
                const fail = (message) => {
                    state.error = message;
                    return false;
                };
                if (!scopeMatches || recoveryPlan?.status !== 'waiting_for_verified_fact') {
                    return fail('问答范围已变化，不能使用旧范围的补全动作；请按当前范围重新提问。');
                }
                if (key === 'recheck') {
                    await ctx.askOperatingQuestion?.();
                    return true;
                }
                if (action?.read_only !== true || !['open_data_health', 'open_platform_collection_status'].includes(key)) {
                    return fail('该证据补全动作不在只读白名单内，已停止。');
                }
                const targetPlatform = String(action?.platform || '').trim();
                const businessDate = String(action?.date || '').trim();
                const exactDate = (value) => {
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
                    const parsed = new Date(`${value}T00:00:00Z`);
                    return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
                };
                const businessDateInScope = exactDate(dateStart)
                    && exactDate(dateEnd)
                    && dateStart <= dateEnd
                    && exactDate(businessDate)
                    && businessDate >= dateStart
                    && businessDate <= dateEnd;
                const missingItems = textList(recoveryPlan?.missing_items);
                const isMissingItem = missingItems.some((item) => (
                    String(item?.platform || '') === targetPlatform
                    && String(item?.date || '') === businessDate
                ));
                const platformAllowed = scopePlatform === 'all_ota'
                    ? ['ctrip', 'meituan'].includes(targetPlatform)
                    : targetPlatform === scopePlatform;
                const allowedTargets = key === 'open_data_health'
                    ? { page: 'online-data', tab: 'data-health' }
                    : (targetPlatform === 'ctrip'
                        ? { page: 'ctrip-ebooking', tab: 'data-health' }
                        : { page: 'meituan-ebooking', tab: 'meituan-ranking' });
                if (!platformAllowed
                    || !businessDateInScope
                    || !isMissingItem
                    || String(action?.target_page || '') !== allowedTargets.page
                    || String(action?.target_tab || '') !== allowedTargets.tab
                ) {
                    return fail('证据补全动作与已保存的酒店、平台或日期不一致，已停止跳转。');
                }
                try {
                    return await ctx.openRevenueAiGap?.({
                        target_page: allowedTargets.page,
                        target_tab: allowedTargets.tab,
                        target_platform: targetPlatform,
                        hotel_id: hotelId,
                        business_date: businessDate,
                    });
                } catch (error) {
                    return fail(error?.message || '打开证据补全入口失败');
                }
            };

            return () => {
                const ctx = props.ctx || {};
                const form = ctx.operatingQuestionForm || {};
                const state = ctx.operatingQuestionState || {};
                const result = state.result || null;
                const answer = result?.answer || {};
                const evidence = answer.evidence_counts || {};
                const hotelOptions = [
                    { value: '', label: '请选择单个酒店' },
                    ...textList(ctx.otaDiagnosisHotelOptions).map((hotel) => ({
                        value: hotel?.value || '',
                        label: hotel?.name || `酒店 ${hotel?.value || ''}`,
                    })),
                ];
                const modelOptions = [
                    { value: '', label: 'DeepSeek V4 默认' },
                    ...textList(ctx.availableAiModelOptions).filter((model) => {
                        const value = String(model?.value || '').toLowerCase();
                        const label = String(model?.label || '').toLowerCase();
                        return value.includes('deepseek') || value.includes('local_second_brain') || label.includes('ollama');
                    }).map((model) => ({
                        value: model?.value || '',
                        label: model?.label || model?.value || '模型',
                    })),
                ];
                const conversation = [];

                if (!result && !state.loading && !state.error) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-welcome' }, [
                        h('div', { class: 'sx-ai-consultant-message-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                        h('div', [
                            h('strong', '你好，我是宿析智能咨询。'),
                            h('p', '我用严格回读的经营事实回答，并从你的知识库检索相关SOP作解释；知识不会替代缺失事实。'),
                        ]),
                    ]));
                }
                if (state.loading) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-loading', role: 'status' }, [
                        icon('fa-spinner fa-spin'),
                        h('span', '正在读取证据并生成回答…'),
                    ]));
                }
                if (state.error) {
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-error',
                        role: 'alert',
                        'data-testid': 'operating-question-floating-error',
                    }, [icon('fa-exclamation-circle'), h('span', String(state.error))]));
                }
                if (result) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-user-message' }, [
                        h('p', String(result.question_text || '')),
                    ]));
                    const answerChildren = [
                        h('div', { class: 'sx-ai-consultant-answer-meta' }, [
                            h('span', {
                                class: ['sx-ai-consultant-status', result.answer_status === 'blocked_by_missing_facts' ? 'is-blocked' : ''],
                            }, String(ctx.operatingQuestionAnswerStatusText?.(result.answer_status) || '已严格回读')),
                            answer.confidence
                                ? h('span', String(ctx.operatingQuestionConfidenceText?.(answer.confidence) || ''))
                                : null,
                        ].filter(Boolean)),
                        h('p', { class: 'sx-ai-consultant-answer-summary' }, String(result.answer_summary || '')),
                    ];
                    const keyPoints = textList(answer.key_points);
                    if (keyPoints.length) {
                        answerChildren.push(h('ul', { class: 'sx-ai-consultant-key-points' }, keyPoints.map((point) => (
                            h('li', { key: String(point) }, String(point))
                        ))));
                    }
                    const gaps = textList(result.data_gaps);
                    const missingInformation = textList(answer.missing_information);
                    if (gaps.length || missingInformation.length) {
                        answerChildren.push(h('div', { class: 'sx-ai-consultant-gaps' }, [
                            h('strong', '仍缺少'),
                            h('ul', [
                                ...gaps.map((gap, index) => h('li', {
                                    key: `gap-${String(gap?.code || index)}`,
                                }, String(gap?.message || gap?.code || '未说明的数据缺口'))),
                                ...missingInformation.map((item, index) => h('li', {
                                    key: `missing-${index}-${String(item)}`,
                                }, String(item))),
                            ]),
                        ]));
                    }
                    const recoveryPlan = answer.recovery_plan && typeof answer.recovery_plan === 'object'
                        ? answer.recovery_plan
                        : null;
                    if (recoveryPlan?.status === 'waiting_for_verified_fact') {
                        const missingItems = textList(recoveryPlan.missing_items);
                        const recoveryActions = textList(recoveryPlan.actions);
                        answerChildren.push(h('section', {
                            class: 'sx-ai-consultant-recovery',
                            'data-testid': 'operating-question-recovery-card',
                        }, [
                            h('div', { class: 'sx-ai-consultant-recovery-title' }, [
                                icon('fa-shield-alt'),
                                h('strong', '证据补全卡'),
                            ]),
                            h('p', `${ctx.operatingQuestionSelectedHotel?.name || `酒店 #${Number(result.hotel_id || 0)}`} · ${ctx.operatingQuestionPlatformText?.(result.platform) || result.platform} · ${result.date_start}${result.date_end !== result.date_start ? ` 至 ${result.date_end}` : ''}`),
                            missingItems.length
                                ? h('ul', missingItems.map((item) => h('li', {
                                    key: `${String(item?.platform || '')}-${String(item?.date || '')}`,
                                }, `缺少 ${ctx.operatingQuestionPlatformText?.(item?.platform) || item?.platform} · ${String(item?.date || '')} · 严格保存回读事实`)))
                                : null,
                            h('div', { class: 'sx-ai-consultant-recovery-actions' }, recoveryActions.map((action) => h('button', {
                                key: `${String(action?.key || '')}-${String(action?.platform || 'scope')}`,
                                type: 'button',
                                disabled: Boolean(state.loading),
                                'data-testid': `operating-question-recovery-${String(action?.key || 'unknown')}-${String(action?.platform || 'scope')}`,
                                onClick: () => runRecoveryAction(action, recoveryPlan, result),
                            }, [
                                icon(action?.key === 'recheck' ? 'fa-sync-alt' : 'fa-arrow-right'),
                                h('span', String(action?.label || '查看补全入口')),
                            ]))),
                            h('small', '只读导航与重新核验，不会自动采集、调用模型补事实或写入 OTA。'),
                        ].filter(Boolean)));
                    }
                    answerChildren.push(h('div', { class: 'sx-ai-consultant-evidence' }, [
                        h('span', String(ctx.operatingQuestionAiRuntimeText?.(result) || '严格证据回读模式')),
                        h('span', `事实 ${Number(evidence.facts || 0)} · 知识 ${Number(evidence.knowledge_chunks || 0)} · 记忆 ${Number(evidence.operating_memories || 0)} · 复盘 ${Number(evidence.execution_reviews || 0)}`),
                        h('span', `已保存并严格回读 #${Number(result.id || 0)}`),
                    ]));
                    const knowledgeResources = textList(answer.knowledge_resources);
                    if (knowledgeResources.length) {
                        answerChildren.push(h('details', { class: 'sx-ai-consultant-evidence-refs' }, [
                            h('summary', `查看知识库参考（${knowledgeResources.length}）`),
                            h('ul', knowledgeResources.map((resource, index) => h('li', {
                                key: String(resource?.ref || index),
                            }, `${String(resource?.name || resource?.ref || '知识片段')} · ${String(resource?.usage_policy || 'reference_only')} · ${String(resource?.excerpt || '')}`))),
                        ]));
                    }
                    const evidenceRefs = Array.from(new Set([
                        ...textList(answer.used_evidence_refs),
                        ...textList(result.fact_refs),
                        ...textList(result.memory_refs),
                        ...textList(result.knowledge_refs),
                        ...textList(result.execution_refs),
                        ...textList(answer.diagnosis_refs),
                    ])).slice(0, 20);
                    if (evidenceRefs.length) {
                        answerChildren.push(h('details', {
                            class: 'sx-ai-consultant-evidence-refs',
                            'data-testid': 'operating-question-floating-evidence',
                        }, [
                            h('summary', `查看使用证据（${evidenceRefs.length}）`),
                            h('ul', evidenceRefs.map((ref) => h('li', { key: ref }, String(ref)))),
                        ]));
                    }
                    conversation.push(h('article', {
                        class: 'sx-ai-consultant-answer',
                        'data-testid': 'operating-question-floating-readback',
                    }, answerChildren));

                    const followUps = textList(answer.follow_up_questions);
                    if (followUps.length) {
                        conversation.push(h('div', { class: 'sx-ai-consultant-follow-ups' }, [
                            h('span', '继续追问'),
                            ...followUps.map((question) => h('button', {
                                key: String(question),
                                type: 'button',
                                onClick: () => applyQuestion(question),
                            }, String(question))),
                        ]));
                    }
                }

                const scopeFields = [
                    selectField({
                        label: '酒店',
                        field: 'hotel_id',
                        options: hotelOptions,
                        testId: 'operating-question-hotel',
                        wide: true,
                    }),
                    selectField({
                        label: '平台',
                        field: 'platform',
                        options: [
                            { value: 'ctrip', label: '携程' },
                            { value: 'meituan', label: '美团' },
                            { value: 'all_ota', label: '携程+美团 OTA' },
                        ],
                        testId: 'operating-question-platform',
                    }),
                    selectField({
                        label: '模型',
                        field: 'model_key',
                        options: modelOptions,
                        testId: 'operating-question-model',
                    }),
                    dateField('开始日期', 'date_start', 'operating-question-date-start'),
                    dateField('结束日期', 'date_end', 'operating-question-date-end'),
                ];
                const scopeDetails = h('details', {
                    class: 'sx-ai-consultant-scope',
                    open: !form.hotel_id,
                }, [
                    h('summary', [
                        h('span', [icon('fa-hotel'), ' 问答范围']),
                        h('span', { class: 'sx-ai-consultant-scope-current' }, `${ctx.operatingQuestionSelectedHotel?.name || '请选择酒店'} · ${ctx.operatingQuestionPlatformText?.(form.platform) || '未选择平台'}`),
                    ]),
                    h('div', { class: 'sx-ai-consultant-scope-grid' }, scopeFields),
                    form.platform === 'all_ota'
                        ? h('p', { class: 'sx-ai-consultant-boundary' }, '只合并同酒店、同日期已严格回读的携程与美团 OTA 事实，不包含 PMS，也不代表全酒店经营。')
                        : null,
                ].filter(Boolean));
                const suggestions = h('div', {
                    class: 'sx-ai-consultant-suggestions',
                    'aria-label': '常用问题',
                }, HOTEL_DATA_ANALYST_SUGGESTIONS.map((suggestion) => h('button', {
                    key: String(suggestion),
                    type: 'button',
                    onClick: () => applyQuestion(suggestion),
                }, String(suggestion))));
                const composer = h('form', {
                    class: 'sx-ai-consultant-composer',
                    onSubmit: (event) => {
                        event?.preventDefault?.();
                        ask();
                    },
                }, [
                    h('textarea', {
                        value: String(state.question || ''),
                        maxlength: 1000,
                        rows: 2,
                        placeholder: '输入经营问题，Enter 发送，Shift+Enter 换行',
                        'data-testid': 'operating-question-floating-input',
                        onInput: (event) => { state.question = String(event?.target?.value || ''); },
                        onKeydown: (event) => {
                            if (event?.key !== 'Enter' || event.shiftKey || event.isComposing) return;
                            event.preventDefault();
                            ask();
                        },
                    }),
                    h('button', {
                        type: 'submit',
                        disabled: Boolean(state.loading) || !String(state.question || '').trim(),
                        'data-testid': 'operating-question-floating-submit',
                        'aria-label': '发送问题',
                    }, [icon(state.loading ? 'fa-spinner fa-spin' : 'fa-paper-plane')]),
                    h('p', '只读问答 · 不改价、不改库存、不自动执行'),
                ]);

                return h('details', {
                    class: 'sx-ai-consultant',
                    'data-testid': 'operating-question-floating-entry',
                }, [
                    h('summary', {
                        class: 'sx-ai-consultant-launcher',
                        'data-testid': 'operating-question-floating-launcher',
                        'aria-label': '打开或关闭智能咨询',
                        onClick: () => ctx.ensureOperatingQuestionScope?.(),
                    }, [
                        h('span', { class: 'sx-ai-consultant-avatar', 'aria-hidden': 'true' }, [
                            icon('fa-concierge-bell'),
                            h('span', { class: 'sx-ai-consultant-online-dot' }),
                        ]),
                        h('span', { class: 'sx-ai-consultant-launcher-label' }, '智能咨询'),
                        h('span', { class: 'sx-ai-consultant-close', 'aria-hidden': 'true' }, [icon('fa-times')]),
                    ]),
                    h('section', {
                        class: 'sx-ai-consultant-panel',
                        role: 'dialog',
                        'aria-label': '宿析OS智能咨询',
                        'data-testid': 'operating-question-floating-panel',
                    }, [
                        h('header', { class: 'sx-ai-consultant-header' }, [
                            h('div', { class: 'sx-ai-consultant-header-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                            h('div', [
                                h('div', { class: 'sx-ai-consultant-title' }, '宿析智能咨询'),
                                h('p', '基于当前酒店的已保存事实与知识库回答'),
                            ]),
                        ]),
                        h('div', { class: 'sx-ai-consultant-body' }, [
                            scopeDetails,
                            suggestions,
                            h('div', {
                                class: 'sx-ai-consultant-conversation',
                                'aria-live': 'polite',
                                'aria-atomic': 'false',
                            }, conversation),
                        ]),
                        composer,
                    ]),
                ]);
            };
        },
    };
    // SYSTEM_USAGE_GUIDE_HELPERS_START
    const SYSTEM_USAGE_GUIDE_TOPICS = [
        {
            key: 'daily-workbench',
            title: '从今日经营工作台开始',
            category: '经营总览',
            example: '我是第一次使用，今天应该先做什么？',
            keywords: ['今天先做什么', '今日工作', '经营看板', '工作台', '今日经营', '待办', '从哪里开始', '第一次使用'],
            context_pages: ['compass'],
            target_page: 'compass',
            action_key: 'page',
            action_label: '打开今日经营工作台',
            summary: '先查看当前酒店今天最需要关注的事实状态、阻塞项和下一步入口。',
            steps: ['确认当前酒店和业务日期。', '查看事实状态与优先阻塞项。', '从对应卡片进入数据、收益或运营页面。'],
            boundary: '工作台是总览入口，卡片存在不代表对应数据或任务已经完成。',
        },
        {
            key: 'data-health',
            title: '检查数据为什么不能用',
            category: '数据与采集',
            example: '数据为什么不可用？',
            keywords: ['数据不可用', '数据是否可用', '数据缺失', '缺数', '数据健康', '采集失败', '未验证', 'partial', 'cookie', '登录过期', '携程数据', '美团数据'],
            context_pages: ['online-data', 'ctrip-ebooking', 'meituan-ebooking', 'compass'],
            target_page: 'online-data',
            action_key: 'data-health',
            action_label: '打开数据健康',
            summary: '先核对酒店、平台、业务日期、来源和质量状态，再决定数据能否进入报告或经营分析。',
            steps: [
                '进入“OTA数据与采集 → 数据健康”。',
                '确认酒店、平台和业务日期与当前任务一致。',
                '查看缺失原因、采集状态以及保存回读结果。',
            ],
            boundary: '数据行存在不等于事实可用；系统不会用历史值、其他酒店或默认值补齐。',
        },
        {
            key: 'auto-collect',
            title: '配置 OTA 数据采集',
            category: '数据与采集',
            example: '怎么配置 OTA 自动采集？',
            keywords: ['自动采集', '采集设置', '携程绑定', '美团绑定', '账号绑定', '平台账号', 'cookie', '定时采集', '采集计划'],
            context_pages: ['online-data', 'ctrip-ebooking', 'meituan-ebooking'],
            target_page: 'online-data',
            action_key: 'auto-collect',
            action_label: '打开自动采集设置',
            summary: '在自动采集设置中完成酒店、平台账号、采集方式和计划配置，然后核对真实运行回执。',
            steps: [
                '选择要采集的系统酒店和 OTA 平台。',
                '完成平台门店身份与授权方式绑定。',
                '运行一次并检查保存数量、失败阶段和精确回读。',
            ],
            boundary: '计划已启用不代表采集成功；登录、验证码和平台授权仍必须在原会话完成。',
        },
        {
            key: 'ctrip-data',
            title: '查看携程经营数据',
            category: 'OTA 渠道经营',
            example: '携程的排名、流量和订单在哪里看？',
            keywords: ['携程', '携程数据', '携程排名', '携程流量', '携程订单', '携程点评', '生意通', 'ebooking'],
            context_pages: ['ctrip-ebooking', 'online-data', 'compass'],
            target_page: 'ctrip-ebooking',
            action_key: 'page',
            action_label: '打开携程数据',
            summary: '在携程数据页查看排名、流量、订单、点评和对应日期的渠道经营状态。',
            steps: ['选择系统酒店和目标业务日期。', '进入需要的排名、流量、订单或点评视图。', '核对来源状态、采集时间和保存回读结果。'],
            boundary: '携程数据只代表携程渠道，不能直接当作全酒店营收或全部客源。',
        },
        {
            key: 'meituan-data',
            title: '查看美团经营数据',
            category: 'OTA 渠道经营',
            example: '美团的排名、流量和订单在哪里看？',
            keywords: ['美团', '美团数据', '美团排名', '美团流量', '美团订单', '美团推广', '酒店管家'],
            context_pages: ['meituan-ebooking', 'online-data', 'compass'],
            target_page: 'meituan-ebooking',
            action_key: 'page',
            action_label: '打开美团数据',
            summary: '在美团数据页查看排名、流量、订单、推广和对应日期的渠道经营状态。',
            steps: ['选择系统酒店和目标业务日期。', '进入需要的排名、流量、订单或推广视图。', '核对来源状态、采集时间和保存回读结果。'],
            boundary: '美团数据只代表美团渠道，缺失字段不能用携程、PMS 或历史值补齐。',
        },
        {
            key: 'pms-data',
            title: '查看 PMS 全酒店经营数据',
            category: '经营数据',
            example: '在哪里看 PMS 营收和间夜？',
            keywords: ['pms', '订单来了', '全酒店', '营收', '房费', '间夜', '入住率', '经营数据'],
            context_pages: ['pms-operating-data', 'compass'],
            target_page: 'pms-operating-data',
            action_key: 'page',
            action_label: '打开 PMS 经营数据',
            summary: 'PMS 页面承载全酒店住宿经营事实，可查看房费、间夜、入住率和对应业务日期。',
            steps: [
                '选择系统酒店和要核对的业务日期。',
                '查看 PMS 来源、采集时间和事实状态。',
                '再进入收益或运营页面使用已验证结果。',
            ],
            boundary: 'PMS 是全酒店口径，不能与携程、美团单渠道指标直接混加。',
        },
        {
            key: 'revenue-report',
            title: '查看报告和经营结论',
            category: '收益分析',
            example: '在哪里看报告和经营结论？',
            keywords: ['报告', '结论', '收益', '诊断', '分析', '预测', '调价', 'adr', 'revpar', '经营问题'],
            context_pages: ['compass', 'revenue-ai', 'revenue-research-center', 'agent-center'],
            target_page: 'revenue-research-center',
            action_key: 'page',
            action_label: '打开收益诊断',
            summary: '报告和经营结论由收益诊断页面承载；系统助手只负责带路，并说明生成结论前缺什么证据。',
            steps: [
                '先确认酒店、平台、业务日期和数据口径。',
                '打开收益诊断，查看事实、异常信号和证据缺口。',
                '对建议进行人工判断后，再进入任务执行。',
            ],
            boundary: '报告结论必须引用已验证事实；建议不等于已执行，也不能证明原因或 ROI。',
        },
        {
            key: 'operation-optimizer',
            title: '把诊断建议转成运营方案',
            category: '运营优化',
            example: '诊断完成后，怎么形成可执行的运营方案？',
            keywords: ['运营优化', '优化方案', '怎么改善', '方案比较', '经营策略', '落地方案', '运营优化台'],
            context_pages: ['operation-optimizer', 'revenue-research-center', 'ops-track', 'compass'],
            target_page: 'operation-optimizer',
            action_key: 'page',
            action_label: '打开运营优化台',
            summary: '基于已保存的事实和诊断，比较可执行方案、负责人、风险和观察窗口。',
            steps: ['确认当前诊断引用的是同范围可信事实。', '比较候选方案、风险、负责人和观察窗口。', '人工确认后再转为运营任务。'],
            boundary: '方案是待判断材料，不会自动改价、改库存、创建任务或证明收益。',
        },
        {
            key: 'operations',
            title: '安排任务并查看执行复盘',
            category: '运营管理',
            example: '怎么给员工安排任务并复盘？',
            keywords: ['员工', '安排任务', '分配任务', '运营任务', '执行', '回执', '复盘', '负责人', '截止时间'],
            context_pages: ['ops-track', 'operating-targets', 'compass'],
            target_page: 'ops-track',
            action_key: 'page',
            action_label: '打开任务执行与复盘',
            summary: '在运营任务页面指定负责人、截止时间和复盘时间，并用真实执行回执判断结果。',
            steps: [
                '从已保存的诊断或人工判断创建任务。',
                '指定负责人、截止时间和复盘时间。',
                '记录真实执行回执，并按同口径结果完成复盘。',
            ],
            boundary: '创建建议不代表任务已执行；没有真实回执和观察窗口时不能宣称有效。',
        },
        {
            key: 'automation-monitor',
            title: '检查自动化为什么没有运行',
            category: '运行监控',
            example: '自动任务没运行去哪里看？',
            keywords: ['自动化', '定时任务', '没有运行', '运行失败', '监控', '调度', '计划状态', '任务日志'],
            context_pages: ['automation-monitor', 'online-data'],
            target_page: 'automation-monitor',
            action_key: 'page',
            action_label: '打开运行监控',
            summary: '运行监控集中展示计划是否启用、最近运行、真实失败阶段和下一步处理入口。',
            steps: [
                '找到对应酒店、平台和任务计划。',
                '区分“已启用”“已运行”和“已成功”。',
                '根据失败阶段处理登录、绑定、采集或保存问题。',
            ],
            boundary: '端口在线、计划启用或历史成功都不能替代本次运行回执。',
        },
        {
            key: 'hotel-settings',
            title: '设置门店、账号和权限',
            category: '系统设置',
            example: '怎么新增门店或设置账号权限？',
            keywords: ['新增门店', '门店设置', '酒店设置', '账号', '用户', '权限', '角色', '授权酒店', '平台门店id'],
            context_pages: ['hotels', 'users', 'system-config'],
            target_page: 'hotels',
            action_key: 'page',
            action_label: '打开门店管理',
            summary: '先建立系统酒店，再为用户分配可查看、可执行的酒店范围；平台门店身份必须单独绑定。',
            steps: [
                '在门店管理中建立或核对系统酒店。',
                '进入账号权限，为用户分配酒店和操作范围。',
                '回到 OTA 设置核对平台门店身份。',
            ],
            boundary: '系统酒店、平台门店和租户身份不能互相猜测或串用。',
        },
        {
            key: 'operating-targets',
            title: '设置经营目标和保底线',
            category: '目标管理',
            example: '怎么给这家酒店设置本月经营目标和保底线？',
            keywords: ['经营目标', '目标', '保底线', '指标版本', '目标值', '每日目标', '负责人目标'],
            context_pages: ['operating-targets', 'compass'],
            target_page: 'operating-targets',
            action_key: 'page',
            action_label: '打开目标与事实',
            summary: '为指定酒店和业务日期设置目标、指标口径、保底线和负责人。',
            steps: ['选择酒店和目标业务日期。', '确认指标定义、目标值与保底线。', '保存后核对目标版本和来源事实。'],
            boundary: '目标是管理合同，不是已经发生的经营事实，也不能改写采集数据。',
        },
        {
            key: 'ai-daily-report',
            title: '生成和查看 AI 经营日报',
            category: '经营报告',
            example: '怎么生成今天的 AI 经营日报？',
            keywords: ['经营日报', 'ai日报', '生成日报', '日报草稿', '日报预览', '日报发送', '可信播报', '可信经营播报', '复制播报稿'],
            context_pages: ['ai-daily-report', 'compass'],
            target_page: 'ai-daily-report',
            action_key: 'page',
            action_label: '打开 AI 经营日报',
            summary: '基于已验证数据生成日报草稿，预览事实、建议和缺口后再决定是否交付。',
            steps: ['选择酒店和报告日期。', '确认数据可用性后生成日报。', '在“经营播报与结果交付”中点击“复制播报稿”，外发仍需人工决定。'],
            boundary: '生成成功不等于内容已确认或已经发送，外部交付必须另有真实回执。',
        },
        {
            key: 'growth-archive',
            title: '查看经营经验和成长档案',
            category: '复盘与知识',
            example: '以前做过哪些动作，结果和经验去哪里看？',
            keywords: ['成长档案', '经营经验', '历史复盘', '成功经验', '失败经验', '里程碑', '经验沉淀'],
            context_pages: ['operating-growth-archive', 'ops-track'],
            target_page: 'operating-growth-archive',
            action_key: 'page',
            action_label: '打开经营成长档案',
            summary: '查看已保存的动作、执行证据、结果复盘、经验层级和适用边界。',
            steps: ['选择酒店和要回顾的时间范围。', '查看动作、执行和结果证据。', '确认经验是否满足复用条件。'],
            boundary: '一次结果或相关变化不能直接升级为可复制经验，跨店使用还需重新验证。',
        },
        {
            key: 'knowledge-search',
            title: '查找制度、经验和操作知识',
            category: '知识与经验',
            example: '制度、SOP 和以前的经营经验在哪里查？',
            keywords: ['知识库', '知识中心', '操作手册', '功能说明', '使用说明', '怎么用系统', '制度', 'sop', '经验', '以前怎么做', '案例'],
            context_pages: ['knowledge-center', 'operating-growth-archive', 'ops-track'],
            target_page: 'knowledge-center',
            action_key: 'knowledge-search',
            action_label: '打开知识与经验',
            summary: '按业务问题查找制度、SOP、历史经验和适用边界，再进入对应功能处理。',
            steps: ['输入业务问题或操作关键词。', '核对知识来源、适用酒店和有效期。', '把知识作为参考并进入真实业务页面执行。'],
            boundary: '知识和历史案例是参考材料，不能替代当前酒店、平台和日期的来源事实。',
        },
        {
            key: 'typeless-dictionary',
            title: '维护 Typeless 总词库',
            category: '个人词库维护',
            example: 'Typeless 总词库怎么更新？',
            keywords: ['typeless', 'typeless词典', 'typeless词库', 'typeless新词', '总词库', '个人词典', '新词导入', '导入csv', '词库更新'],
            context_pages: ['knowledge-center'],
            target_page: 'knowledge-center',
            action_key: 'knowledge-search',
            action_label: '打开词库维护说明',
            summary: '从可追溯词源生成单列、UTF-8 BOM、无表头 CSV，去重验证后再导入 Typeless。',
            steps: ['在知识中心搜索“个人工作语境与宿析OS酒店词汇层”核对词源与版本。', '合并新词并按精确字符串去重，生成单列 UTF-8 BOM 无表头 CSV。', '导入 Typeless 后核对总数、重复项报告和首尾词条。'],
            boundary: '词条只用于识别与检索，属于 reference_only；不得把个人词、资料词或工具名写成酒店经营事实。',
        },
        {
            key: 'team-permissions',
            title: '管理员工账号和角色权限',
            category: '团队管理',
            example: '怎么给新员工开账号并分配酒店权限？',
            keywords: ['员工账号', '新增员工', '用户管理', '角色权限', '账号权限', '分配酒店', '登录账号'],
            context_pages: ['users', 'roles', 'hotels'],
            target_page: 'users',
            action_key: 'page',
            action_label: '打开员工管理',
            summary: '新增或维护员工账号，并按角色分配可见酒店和可操作功能。',
            steps: ['建立或选择员工账号。', '分配角色、酒店范围和功能权限。', '用目标账号核对实际可见入口。'],
            boundary: '页面可见范围必须服从服务端权限，不能通过助手导航绕过授权。',
        },
        {
            key: 'role-permissions',
            title: '配置岗位角色和功能权限',
            category: '团队管理',
            example: '怎么配置岗位角色和菜单权限？',
            keywords: ['角色管理', '岗位权限', '功能权限', '菜单权限', '角色配置', '员工看不到'],
            context_pages: ['roles', 'users', 'hotels'],
            target_page: 'roles',
            action_key: 'page',
            action_label: '打开角色权限',
            summary: '维护岗位角色的功能权限，再把角色分配给对应员工账号。',
            steps: ['选择或建立岗位角色。', '配置该角色允许使用的功能。', '分配给员工并用目标账号核对实际入口。'],
            boundary: '角色配置不能扩大账号所属租户或酒店范围，实际访问仍由服务端鉴权决定。',
        },
        {
            key: 'system-settings',
            title: '调整系统名称、菜单和通知设置',
            category: '系统管理',
            example: '系统名称、菜单或功能开关在哪里设置？',
            keywords: ['系统设置', '系统名称', '菜单配置', '显示设置', '功能开关', '通知设置', 'logo'],
            context_pages: ['system-config'],
            target_page: 'system-config',
            action_key: 'page',
            action_label: '打开系统设置',
            summary: '维护系统基础信息、显示设置、功能开关和通知选项。',
            steps: ['进入对应设置分区。', '修改必要配置并保存。', '刷新后核对实际显示或功能状态。'],
            boundary: '设置页面只对有权限的管理员开放，配置保存不等于外部服务已经可用。',
        },
        {
            key: 'data-settings',
            title: '管理数据配置和采集设备',
            category: '系统管理',
            example: '数据源或采集设备在哪里维护？',
            keywords: ['数据配置', '采集设备', '竞对设备', '数据源配置', '设备管理', '来源配置'],
            context_pages: ['data-config', 'online-data', 'automation-monitor'],
            target_page: 'data-config',
            action_key: 'page',
            action_label: '打开数据配置',
            summary: '维护数据源、平台配置和竞对采集设备的可用状态。',
            steps: ['锁定需要维护的数据源或设备。', '完成配置并保存。', '回到数据健康或运行监控核对真实回执。'],
            boundary: '配置存在不代表采集成功，仍须以来源请求、保存和精确回读为准。',
        },
        {
            key: 'operation-audit',
            title: '查看系统操作记录',
            category: '系统审计',
            example: '怎么查是谁在什么时候做了这次操作？',
            keywords: ['操作日志', '操作记录', '谁操作的', '审计日志', '系统日志', '历史操作'],
            context_pages: ['operation-logs'],
            target_page: 'operation-logs',
            action_key: 'page',
            action_label: '打开操作记录',
            summary: '按账号、时间和操作类型检查系统内的重要操作记录。',
            steps: ['选择时间范围和目标账号。', '定位具体操作与结果状态。', '回到对应业务页面复核当前真实状态。'],
            boundary: '操作记录证明发生过请求或操作，不自动证明外部平台最终成功。',
        },
        {
            key: 'decision-audit',
            title: '复核智能建议和人工确认记录',
            category: '决策审计',
            example: '在哪里复核建议来源、低置信和待确认记录？',
            keywords: ['决策审计', '建议审计', '人工确认', '低置信', '失败记录', '治理状态'],
            context_pages: ['ai-governance', 'agent-center', 'revenue-research-center'],
            target_page: 'ai-governance',
            action_key: 'page',
            action_label: '打开决策审计',
            summary: '查看建议来源、置信状态、人工确认队列和失败或阻断记录。',
            steps: ['选择需要复核的建议或调用记录。', '核对来源、范围、状态和人工确认要求。', '回到业务页面处理缺口或完成确认。'],
            boundary: '审计记录用于追踪和复核，不会替代来源事实或自动批准执行。',
        },
        {
            key: 'ai-capability-settings',
            title: '配置智能能力与调用状态',
            category: '系统管理',
            example: '智能功能不可用时去哪里检查配置？',
            keywords: ['智能能力配置', 'ai配置', '调用失败', '连接测试', '智能功能不可用', '能力开关'],
            context_pages: ['ai-model-config', 'ai-governance'],
            target_page: 'ai-model-config',
            action_key: 'page',
            action_label: '打开智能能力配置',
            summary: '由管理员维护系统智能能力的启用状态、用途和连接测试。',
            steps: ['选择需要维护的智能能力。', '核对用途、启用状态和连接配置。', '运行测试并回到实际功能验证结果。'],
            boundary: '连接测试通过只证明当前配置可调用，不代表具体业务回答或经营结论已经正确。',
        },
        {
            key: 'agent-toolbox',
            title: '使用酒店 AI 工具箱',
            category: 'AI 工具',
            example: 'OTA 诊断、需求预测和价格建议在哪里用？',
            keywords: ['ai工具', '智能工具', 'ota诊断', '需求预测', '价格建议', '智能问答', 'agent'],
            context_pages: ['agent-center', 'revenue-research-center'],
            target_page: 'agent-center',
            action_key: 'page',
            action_label: '打开酒店 AI 工具箱',
            summary: '进入专业 AI 页面使用 OTA 诊断、需求预测、价格建议和保存的经营问答。',
            steps: ['选择要使用的专业工具。', '锁定酒店、平台、日期和证据范围。', '保存并回读结果后再进入人工决策。'],
            boundary: 'AI 输出是辅助材料；未经人工确认不会自动改价、改库存或执行任务。',
        },
        {
            key: 'notifications',
            title: '配置企业微信通知',
            category: '通知与交付',
            example: '怎么配置企业微信推送？',
            keywords: ['企业微信', '微信通知', '推送', '通知', '接收人', '发送报告', '消息'],
            context_pages: ['wechat-notification', 'manual-notifications'],
            target_page: 'wechat-notification',
            action_key: 'page',
            action_label: '打开通知中心',
            summary: '通知中心用于绑定接收方、检查发送条件和查看交付回执；报告先本地生成，再决定是否发送。',
            steps: [
                '选择酒店并核对企业微信接收配置。',
                '先在本地生成和预览要发送的内容。',
                '发送后检查接收方、时间和交付回执。',
            ],
            boundary: '预览成功不等于已发送；没有明确接收方和授权时不会自动外发。',
        },
        {
            key: 'task-navigation',
            title: '查找系统功能入口',
            category: '使用帮助',
            example: '我要完成一个任务，但不知道入口',
            keywords: ['怎么用', '在哪里', '哪个页面', '功能入口', '系统帮助', '使用系统', '不会操作', '任务导航'],
            context_pages: ['knowledge-center'],
            target_page: 'knowledge-center',
            action_key: 'task-navigation',
            action_label: '打开任务导航',
            summary: '任务导航按“想完成什么”查找真实页面、使用场景和前置条件，不要求先知道模块名称。',
            steps: [
                '输入要完成的业务任务或页面关键词。',
                '查看对应使用场景和事实边界。',
                '从唯一主操作进入真实功能页面。',
            ],
            boundary: '任务导航只负责带路，不把页面可打开包装成数据已就绪或业务已完成。',
        },
    ];
    const SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS = Object.freeze({
        'daily-workbench': '已确认当前酒店、业务日期和今天最优先处理的阻塞项。',
        'data-health': '已明确数据停在身份、采集、保存还是精确回读阶段；证据不足时仍显示未确定。',
        'auto-collect': '已核对酒店、平台、账号与计划，并取得一次真实运行或明确失败回执。',
        'ctrip-data': '已确认目标酒店、业务日期、携程来源和需要查看的数据视图。',
        'meituan-data': '已确认目标酒店、业务日期、美团来源和需要查看的数据视图。',
        'pms-data': '已确认目标酒店、业务日期、PMS 来源和可用事实状态。',
        'revenue-report': '报告明确区分已验证事实、证据缺口和人工建议，没有把缺数写成结论。',
        'operation-optimizer': '已形成带来源、负责人、风险和观察窗口的待确认运营方案。',
        operations: '任务已明确负责人、截止时间和复盘口径；未执行时仍保持待执行。',
        'automation-monitor': '已定位本次计划的运行阶段、失败原因和对应恢复入口。',
        'hotel-settings': '系统酒店、平台门店身份和账号可见范围已经逐项核对。',
        'operating-targets': '目标、指标口径、保底线、负责人和版本均已保存并回显。',
        'ai-daily-report': '日报草稿已基于当前可用证据生成并预览；是否外发仍由人工确认。',
        'growth-archive': '已看到动作、执行和结果证据，并明确经验是否具备复用条件。',
        'knowledge-search': '已找到有来源和适用边界的知识，并明确应进入哪个真实业务功能。',
        'typeless-dictionary': '词源、去重结果、CSV格式和导入后总数均已核对；词条仍保持 reference_only。',
        'team-permissions': '目标账号的角色、酒店范围和实际可见入口已经核对。',
        'role-permissions': '岗位角色、功能权限和目标账号的实际入口已经核对。',
        'system-settings': '配置已经保存，并在刷新后的实际页面完成回显核对。',
        'data-settings': '数据源或设备配置已保存，并取得对应健康检查或运行回执。',
        'operation-audit': '已定位具体操作记录，并回到业务页面复核当前结果。',
        'decision-audit': '已核对建议来源、状态、人工确认要求和当前阻断。',
        'ai-capability-settings': '智能能力配置已保存并通过实际功能调用验证。',
        'agent-toolbox': '专业工具已锁定酒店、平台、日期和证据范围，并明确输出边界。',
        notifications: '接收方、内容和发送时间已核对；真实送达仍以发送回执为准。',
        'task-navigation': '已找到与业务目标对应的真实页面和进入前需要满足的条件。',
    });
    const SYSTEM_ASSISTANT_MODE_OPTIONS = Object.freeze([
        { key: 'auto', label: '自动判断', icon: 'fa-wand-magic-sparkles' },
        { key: 'guide', label: '教我使用', icon: 'fa-compass' },
        { key: 'report', label: '数据分析师', icon: 'fa-chart-line' },
        { key: 'action', label: '帮我处理', icon: 'fa-list-check' },
    ]);
    const SYSTEM_ASSISTANT_MODE_LABELS = Object.freeze({
        auto: '自动判断',
        guide: '使用指导',
        report: '证据结论',
        action: '行动草案',
        term: '术语释义',
    });
    const SYSTEM_USAGE_GUIDE_ANCHORS = Object.freeze({
        'daily-workbench': ['[data-testid="page-compass"]'],
        'data-health': ['[data-testid="phase1-employee-closure-summary"]', '[data-testid="online-data-health-panel"]'],
        'auto-collect': ['[data-testid="canonical-daily-operation-status"]', '[data-testid="platform-auto-settings-panels"]'],
        'ctrip-data': ['[data-testid="page-ctrip-ebooking"]'],
        'meituan-data': ['[data-testid="page-meituan-ebooking"]'],
        'pms-data': ['[data-testid="page-pms-operating-data"]'],
        'revenue-report': ['[data-testid="operating-question-entry"]', '[data-testid="page-revenue-research-center"]'],
        'operation-optimizer': ['[data-testid="page-operation-optimizer"]'],
        operations: ['[data-testid="page-ops-track"]'],
        'automation-monitor': ['[data-testid="page-automation-monitor"]'],
        'hotel-settings': ['[data-testid="page-hotels"]'],
        'operating-targets': ['[data-testid="page-operating-targets"]'],
        'ai-daily-report': ['[data-testid="ai-daily-fact-gate"]', '[data-testid="page-ai-daily-report"]'],
        'growth-archive': ['[data-testid="page-operating-growth-archive"]'],
        'knowledge-search': ['[data-testid="page-knowledge-center"]'],
        'typeless-dictionary': ['[data-testid="page-knowledge-center"]'],
        'team-permissions': ['[data-testid="page-users"]'],
        'role-permissions': ['[data-testid="page-roles"]'],
        'system-settings': ['[data-testid="page-system-config"]'],
        'data-settings': ['[data-testid="page-data-config"]'],
        'operation-audit': ['[data-testid="page-operation-logs"]'],
        'decision-audit': ['[data-testid="page-ai-governance"]'],
        'ai-capability-settings': ['[data-testid="page-ai-model-config"]'],
        'agent-toolbox': ['[data-testid="operating-question-entry"]', '[data-testid="page-agent-center"]'],
        notifications: ['[data-testid="page-wechat-notification"]'],
        'task-navigation': ['[data-testid="page-knowledge-center"]'],
    });
    const normalizeSystemUsageGuideText = (value) => String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[\s，。！？、,.!?：:；;（）()【】\[\]《》<>“”"'`]+/g, '');
    const resolveSystemAssistantMode = (query, requestedMode = 'auto', modelMode = '') => {
        const requested = String(requestedMode || 'auto').trim().toLowerCase();
        if (['guide', 'report', 'action'].includes(requested)) return requested;
        const model = String(modelMode || '').trim().toLowerCase();
        if (['guide', 'report', 'action'].includes(model)) return model;
        const normalized = normalizeSystemUsageGuideText(query);
        const actionKeywords = ['帮我处理', '帮我配置', '替我处理', '创建任务', '安排任务', '生成行动草案', '制定行动方案', '落地执行'];
        if (actionKeywords.some((keyword) => normalized.includes(normalizeSystemUsageGuideText(keyword)))) return 'action';
        const reportKeywords = ['给我报告', '看报告', '查看报告', '报告', '给我结论', '经营结论', '结论', '分析一下', '诊断一下', '经营怎么样', '为什么', '有哪些问题', '复核什么', '数据缺口'];
        if (reportKeywords.some((keyword) => normalized.includes(normalizeSystemUsageGuideText(keyword)))) return 'report';
        return 'guide';
    };
    const resolveSystemUsageGuideTopic = (query, currentPage = '') => {
        const originalQuery = String(query || '').trim();
        const normalizedQuery = normalizeSystemUsageGuideText(originalQuery);
        const normalizedPage = String(currentPage || '').trim();
        const fallback = SYSTEM_USAGE_GUIDE_TOPICS.find((topic) => topic.key === 'task-navigation');
        if (!normalizedQuery) {
            return { ...fallback, match_status: 'empty', original_query: originalQuery };
        }
        const topicByKey = (key) => SYSTEM_USAGE_GUIDE_TOPICS.find((topic) => topic.key === key);
        const directTopic = (
            ['没进来', '未进来', '数据缺失', '缺数', '不可用', '采集失败', '登录过期']
                .some((keyword) => normalizedQuery.includes(normalizeSystemUsageGuideText(keyword)))
                ? topicByKey('data-health')
                : (normalizedQuery.includes('携程')
                    ? topicByKey('ctrip-data')
                    : (normalizedQuery.includes('美团')
                        ? topicByKey('meituan-data')
                        : (['pms', '全酒店', '入住率', '间夜', '房费']
                            .some((keyword) => normalizedQuery.includes(normalizeSystemUsageGuideText(keyword)))
                            ? topicByKey('pms-data')
                            : null)))
        );
        if (directTopic) {
            return { ...directTopic, match_status: 'matched', original_query: originalQuery };
        }
        let bestTopic = fallback;
        let bestScore = 0;
        for (const topic of SYSTEM_USAGE_GUIDE_TOPICS) {
            if (topic.key === 'task-navigation') continue;
            const candidates = [topic.title, topic.category, topic.example, ...(topic.keywords || [])]
                .map(normalizeSystemUsageGuideText)
                .filter(Boolean);
            let score = 0;
            for (const candidate of candidates) {
                if (normalizedQuery.includes(candidate)) {
                    score += 6 + Math.min(candidate.length, 8);
                } else if (candidate.includes(normalizedQuery) && normalizedQuery.length >= 2) {
                    score += 3;
                }
            }
            if (score > 0 && (topic.context_pages || []).includes(normalizedPage)) score += 1;
            if (score > bestScore) {
                bestTopic = topic;
                bestScore = score;
            }
        }
        return {
            ...(bestScore > 0 ? bestTopic : fallback),
            match_status: bestScore > 0 ? 'matched' : 'fallback',
            original_query: originalQuery,
        };
    };
    const resolveSystemUsageGuideJourney = (query, primaryTopic) => {
        const normalized = normalizeSystemUsageGuideText(query);
        const keys = [String(primaryTopic?.key || 'task-navigation')];
        const hasAny = (keywords) => keywords.some((keyword) => normalized.includes(normalizeSystemUsageGuideText(keyword)));
        const append = (key) => {
            if (!keys.includes(key) && SYSTEM_USAGE_GUIDE_TOPICS.some((topic) => topic.key === key) && keys.length < 4) {
                keys.push(key);
            }
        };
        if (keys[0] !== 'revenue-report' && hasAny(['分析', '报告', '结论', '方案', '优化', '建议'])) append('revenue-report');
        if (hasAny(['运营方案', '优化方案', '形成方案', '经营方案', '运营优化'])) append('operation-optimizer');
        if (hasAny(['安排任务', '创建任务', '执行任务', '跟进任务', '复盘任务'])) append('operations');
        return keys;
    };
    // SYSTEM_USAGE_GUIDE_HELPERS_END

    const basicSystemUsageAssistant = {
        name: 'SystemUsageAssistant',
        props: {
            ctx: { type: Object, required: true },
        },
        setup(props) {
            const state = ref({
                query: '',
                result: null,
                error: '',
                opening_key: '',
            });
            const icon = (name) => h('i', { class: `fas ${name}`, 'aria-hidden': 'true' });
            const topicByKey = (key) => SYSTEM_USAGE_GUIDE_TOPICS.find((topic) => topic.key === key) || null;
            const visiblePaths = () => {
                const paths = new Set();
                const visit = (items) => {
                    for (const item of Array.isArray(items) ? items : []) {
                        const path = String(item?.path || '').trim();
                        if (path) paths.add(path);
                        visit(item?.children);
                    }
                };
                visit(props.ctx?.visibleMenuItems);
                return paths;
            };
            const canOpenTopic = (topic) => {
                const paths = visiblePaths();
                if (paths.size === 0) return true;
                return paths.has(String(topic?.target_page || ''));
            };
            const currentPageText = () => String(props.ctx?.pageTitle || props.ctx?.currentPage || '当前页面');
            const suggestionTopics = () => {
                const currentPage = String(props.ctx?.currentPage || '');
                const contextKeys = SYSTEM_USAGE_GUIDE_TOPICS
                    .filter((topic) => topic.key !== 'task-navigation' && (topic.context_pages || []).includes(currentPage))
                    .map((topic) => topic.key);
                const preferred = [...contextKeys, 'data-health', 'revenue-report', 'operations', 'task-navigation'];
                return Array.from(new Set(preferred))
                    .map(topicByKey)
                    .filter(Boolean)
                    .slice(0, 4);
            };
            const applySuggestion = (topic) => {
                state.value.query = String(topic?.example || topic?.title || '');
                state.value.error = '';
                state.value.result = null;
            };
            const ask = () => {
                const query = String(state.value.query || '').trim();
                state.value.error = '';
                if (!query) {
                    state.value.result = null;
                    state.value.error = '请说出你想在系统里完成什么，例如“携程数据缺失去哪里处理”。';
                    return false;
                }
                state.value.result = resolveSystemUsageGuideTopic(query, props.ctx?.currentPage);
                return true;
            };
            const openTopic = async (event, topic) => {
                if (!topic || state.value.opening_key) return false;
                if (!canOpenTopic(topic)) {
                    state.value.error = '当前账号没有显示该功能入口，请联系管理员核对角色与酒店权限。';
                    return false;
                }
                state.value.error = '';
                state.value.opening_key = String(topic.key || 'page');
                try {
                    const ctx = props.ctx || {};
                    if (topic.action_key === 'data-health') {
                        ctx.currentPage = 'online-data';
                        ctx.onlineDataTab = 'data-health';
                        await Promise.resolve(ctx.openOnlineDataTab?.('data-health', { force: true }));
                    } else if (topic.action_key === 'auto-collect') {
                        await Promise.resolve(ctx.openOnlinePlatformAutoTab?.({ force: true }));
                    } else {
                        ctx.currentPage = String(topic.target_page || '');
                        if (topic.action_key === 'task-navigation'
                            && ctx.knowledgeCenterFilter
                            && state.value.result?.match_status === 'fallback'
                        ) {
                            ctx.knowledgeCenterFilter.keyword = String(state.value.query || '').trim();
                        }
                    }
                    const panel = event?.currentTarget?.closest?.('details.sx-ai-consultant');
                    if (panel) panel.open = false;
                    return true;
                } catch (error) {
                    state.value.error = error?.message || '打开功能页面失败，请从左侧导航重试。';
                    return false;
                } finally {
                    state.value.opening_key = '';
                }
            };

            return () => {
                const result = state.value.result;
                const conversation = [];
                if (!result && !state.value.error) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-welcome' }, [
                        h('div', { class: 'sx-ai-consultant-message-avatar', 'aria-hidden': 'true' }, [icon('fa-compass')]),
                        h('div', [
                            h('strong', '告诉我你想在系统里完成什么。'),
                            h('p', '我会说明操作步骤并带你进入真实页面；报告和经营结论由对应专业页面生成。'),
                        ]),
                    ]));
                }
                if (state.value.error) {
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-error',
                        role: 'alert',
                        'data-testid': 'system-guide-error',
                    }, [icon('fa-exclamation-circle'), h('span', String(state.value.error))]));
                }
                if (result) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-user-message' }, [
                        h('p', String(result.original_query || state.value.query || '')),
                    ]));
                    const available = canOpenTopic(result);
                    conversation.push(h('article', {
                        class: 'sx-ai-consultant-answer',
                        'data-testid': 'system-guide-result',
                    }, [
                        h('div', { class: 'sx-ai-consultant-answer-meta' }, [
                            h('span', { class: 'sx-ai-consultant-status' }, result.match_status === 'matched' ? '已找到操作路径' : '进入任务导航'),
                            h('span', String(result.category || '使用帮助')),
                        ]),
                        h('p', { class: 'sx-ai-consultant-answer-summary' }, String(result.title || '系统使用指导')),
                        h('p', String(result.summary || '')),
                        h('ol', { class: 'sx-ai-consultant-key-points' }, (result.steps || []).map((step, index) => (
                            h('li', { key: `${result.key}-${index}` }, `${index + 1}. ${String(step)}`)
                        ))),
                        h('div', { class: 'sx-ai-consultant-gaps' }, [
                            h('strong', '使用边界'),
                            h('p', String(result.boundary || '')),
                        ]),
                        h('section', { class: 'sx-ai-consultant-recovery' }, [
                            h('div', { class: 'sx-ai-consultant-recovery-title' }, [
                                icon('fa-location-arrow'),
                                h('strong', available ? '下一步直接进入' : '当前账号暂无入口'),
                            ]),
                            h('div', { class: 'sx-ai-consultant-recovery-actions' }, [
                                h('button', {
                                    type: 'button',
                                    disabled: !available || Boolean(state.value.opening_key),
                                    'data-testid': `system-guide-open-${String(result.key || 'topic')}`,
                                    onClick: (event) => openTopic(event, result),
                                }, [
                                    icon(state.value.opening_key === result.key ? 'fa-spinner fa-spin' : 'fa-arrow-right'),
                                    h('span', available ? String(result.action_label || '打开页面') : '请联系管理员授权'),
                                ]),
                            ]),
                            h('small', '这里只导航和说明，不生成经营结论，也不会写入业务数据。'),
                        ]),
                        h('div', { class: 'sx-ai-consultant-evidence' }, [
                            h('span', `当前页面：${currentPageText()}`),
                            h('span', '系统使用指导 · 非经营报告'),
                        ]),
                    ]));
                }

                const suggestions = h('div', {
                    class: 'sx-ai-consultant-suggestions',
                    'aria-label': '常用系统任务',
                }, suggestionTopics().map((topic) => h('button', {
                    key: topic.key,
                    type: 'button',
                    onClick: () => applySuggestion(topic),
                }, String(topic.example || topic.title))));
                const composer = h('form', {
                    class: 'sx-ai-consultant-composer',
                    onSubmit: (event) => {
                        event?.preventDefault?.();
                        ask();
                    },
                }, [
                    h('textarea', {
                        value: String(state.value.query || ''),
                        maxlength: 500,
                        rows: 2,
                        placeholder: '例如：携程数据缺失要去哪里处理？',
                        'data-testid': 'system-guide-input',
                        onInput: (event) => {
                            state.value.query = String(event?.target?.value || '');
                            state.value.error = '';
                        },
                        onKeydown: (event) => {
                            if (event?.key !== 'Enter' || event.shiftKey || event.isComposing) return;
                            event.preventDefault();
                            ask();
                        },
                    }),
                    h('button', {
                        type: 'submit',
                        disabled: !String(state.value.query || '').trim(),
                        'data-testid': 'system-guide-submit',
                        'aria-label': '查找系统操作路径',
                    }, [icon('fa-arrow-up')]),
                    h('p', '系统导航与操作指导 · 报告结论请进入专业页面'),
                ]);

                return h('details', {
                    class: 'sx-ai-consultant',
                    'data-testid': 'system-guide-floating-entry',
                }, [
                    h('summary', {
                        class: 'sx-ai-consultant-launcher',
                        'data-testid': 'system-guide-floating-launcher',
                        'aria-label': '打开或关闭系统操作助手',
                    }, [
                        h('span', { class: 'sx-ai-consultant-avatar', 'aria-hidden': 'true' }, [icon('fa-compass')]),
                        h('span', { class: 'sx-ai-consultant-launcher-label' }, '系统助手'),
                        h('span', { class: 'sx-ai-consultant-close', 'aria-hidden': 'true' }, [icon('fa-times')]),
                    ]),
                    h('section', {
                        class: 'sx-ai-consultant-panel',
                        role: 'dialog',
                        'aria-label': '宿析OS系统操作助手',
                        'data-testid': 'system-guide-floating-panel',
                    }, [
                        h('header', { class: 'sx-ai-consultant-header' }, [
                            h('div', { class: 'sx-ai-consultant-header-avatar', 'aria-hidden': 'true' }, [icon('fa-compass')]),
                            h('div', [
                                h('div', { class: 'sx-ai-consultant-title' }, '宿析系统助手'),
                                h('p', '告诉你在哪、怎么做、下一步点什么'),
                            ]),
                        ]),
                        h('div', { class: 'sx-ai-consultant-body' }, [
                            h('p', { class: 'sx-ai-consultant-boundary' }, '当前酒店和页面只用于给出更合适的入口，不限制你查找其他系统功能。'),
                            suggestions,
                            h('div', {
                                class: 'sx-ai-consultant-conversation',
                                'aria-live': 'polite',
                                'aria-atomic': 'false',
                            }, conversation),
                        ]),
                        composer,
                    ]),
                ]);
            };
        },
    };

    const operatingQuestionConsultant = {
        name: 'IntelligentSystemUsageAssistant',
        props: {
            ctx: { type: Object, required: true },
            openOnMount: { type: Boolean, default: false },
        },
        setup(props) {
            const state = ref({
                query: '',
                turns: [],
                error: '',
                loading: false,
                opening_key: '',
                active_journey: null,
                selected_mode: 'auto',
                coach: null,
                restoring_precise_query: false,
                learning_context: null,
                learning_loading: false,
                learning_error: '',
                learning_open: false,
                preference_saving_key: '',
                feedback_status: {},
                journey_transition_status: '',
            });
            const journeyStorageVersion = 1;
            const widgetStorageVersion = 1;
            const pendingCoachStorageVersion = 1;
            const preciseQueryStorageVersion = 1;
            const widgetRoot = ref(null);
            const widgetOpen = ref(false);
            const widgetDragging = ref(false);
            const widgetPosition = ref({ right: null, bottom: null });
            const widgetDrag = {
                active: false,
                source: '',
                pointer_id: null,
                start_x: 0,
                start_y: 0,
                start_left: 0,
                start_top: 0,
                width: 0,
                height: 0,
                moved: false,
                capture_target: null,
            };
            let suppressLauncherToggle = false;
            let resizeFrame = 0;
            let preciseRestoreTimer = 0;
            let learningRequestId = 0;
            let coachTarget = null;
            let coachRequestId = 0;
            const icon = (name) => h('i', { class: `fas ${name}`, 'aria-hidden': 'true' });
            const qualityFeedbackUi = createHotelDataAnalystFeedbackUi({
                getState: () => {
                    const current = props.ctx?.operatingQuestionState;
                    return current && typeof current === 'object' && 'value' in current
                        ? current.value
                        : current;
                },
                request: (...args) => {
                    if (typeof props.ctx?.hotelDataAnalystFeedbackRequest !== 'function') {
                        return Promise.reject(new Error('分析反馈请求能力未就绪'));
                    }
                    return props.ctx.hotelDataAnalystFeedbackRequest(...args);
                },
            });
            const topicByKey = (key) => SYSTEM_USAGE_GUIDE_TOPICS.find((topic) => topic.key === key) || null;
            const visiblePaths = () => {
                const paths = new Set();
                const visit = (items) => {
                    for (const item of Array.isArray(items) ? items : []) {
                        const path = String(item?.path || '').trim();
                        if (path) paths.add(path);
                        visit(item?.children);
                    }
                };
                visit(props.ctx?.visibleMenuItems);
                return paths;
            };
            const canOpenTopic = (topic) => {
                const paths = visiblePaths();
                if (paths.size === 0) return true;
                return paths.has(String(topic?.target_page || ''));
            };
            const visibleTopicKeys = () => SYSTEM_USAGE_GUIDE_TOPICS
                .filter((topic) => canOpenTopic(topic))
                .map((topic) => topic.key);
            const currentLearningHotelId = () => Number(
                props.ctx?.operatingQuestionForm?.hotel_id
                || props.ctx?.filterReportHotel
                || latestPreciseOperatingScope?.()?.hotel_id
                || props.ctx?.user?.default_hotel_id
                || props.ctx?.user?.hotel_id
                || 0
            );
            const currentLearningUserId = () => Number(props.ctx?.user?.id || 0);
            const currentLearningTenantId = (hotelId) => {
                const directTenantId = Number(props.ctx?.user?.tenant_id || 0);
                if (directTenantId > 0) return directTenantId;
                const pools = [
                    props.ctx?.otaDiagnosisHotelOptions,
                    props.ctx?.operationHotelOptions,
                    props.ctx?.hotels,
                    props.ctx?.user?.permitted_hotels,
                ];
                for (const pool of pools) {
                    const hotel = (Array.isArray(pool) ? pool : []).find(
                        item => Number(item?.id || item?.hotel_id || 0) === Number(hotelId || 0)
                    );
                    const tenantId = Number(hotel?.tenant_id || 0);
                    if (tenantId > 0) return tenantId;
                }
                return 0;
            };
            const validateLearningContext = (context, targetHotelId) => {
                if (!context || typeof context !== 'object'
                    || String(context.contract_version || '') !== 'system_user_learning_context.v1'
                    || String(context.status || '') !== 'ready'
                ) {
                    throw new Error(String(context?.status || '') === 'migration_required'
                        ? '个人学习数据表未就绪'
                        : '个人学习上下文未取得');
                }
                const scope = context.scope && typeof context.scope === 'object' ? context.scope : {};
                const expectedUserId = currentLearningUserId();
                const expectedTenantId = currentLearningTenantId(targetHotelId);
                if (expectedUserId <= 0
                    || Number(scope.user_id || 0) !== expectedUserId
                    || Number(scope.hotel_id || 0) !== Number(targetHotelId || 0)
                    || Number(scope.tenant_id || 0) <= 0
                    || (expectedTenantId > 0 && Number(scope.tenant_id || 0) !== expectedTenantId)
                ) {
                    throw new Error('个人学习上下文返回的用户、租户或酒店身份不一致');
                }
                return context;
            };
            const journeyStorageKey = () => {
                const userId = Number(props.ctx?.user?.id || 0);
                const hotelId = currentLearningHotelId();
                return `suxios_system_usage_journey_v1:${userId > 0 ? userId : 'session'}:${hotelId > 0 ? hotelId : 'global'}`;
            };
            const widgetStorageKey = () => {
                const userId = Number(props.ctx?.user?.id || 0);
                return `suxios_system_usage_widget_v1:${userId > 0 ? userId : 'session'}`;
            };
            const pendingCoachStorageKey = () => {
                const userId = Number(props.ctx?.user?.id || 0);
                return `suxios_system_usage_pending_coach_v1:${userId > 0 ? userId : 'session'}`;
            };
            const preciseQueryStorageKey = () => {
                const userId = Number(props.ctx?.user?.id || 0);
                return `suxios_precise_query_last_v1:${userId > 0 ? userId : 'session'}`;
            };
            const savePreciseQueryPointer = (readback) => {
                const id = Number(readback?.id || 0);
                const digest = String(readback?.content_digest || '');
                if (!id || !digest) return false;
                try {
                    localStorage.setItem(preciseQueryStorageKey(), JSON.stringify({
                        version: preciseQueryStorageVersion,
                        id,
                        content_digest: digest,
                        saved_at: Date.now(),
                    }));
                    return true;
                } catch (error) {
                    return false;
                }
            };
            const readPreciseQueryPointer = () => {
                try {
                    const raw = JSON.parse(localStorage.getItem(preciseQueryStorageKey()) || 'null');
                    if (!raw
                        || Number(raw.version || 0) !== preciseQueryStorageVersion
                        || Number(raw.id || 0) <= 0
                        || !String(raw.content_digest || '')
                    ) return null;
                    return raw;
                } catch (error) {
                    return null;
                }
            };
            const preciseQueryRequest = (...args) => {
                const handler = props.ctx?.managerCapabilityRequest;
                if (typeof handler !== 'function') throw new Error('宿析精准查数请求通道未就绪');
                return handler(...args);
            };
            const systemLearningRequestId = (prefix = 'learning') => {
                const random = typeof globalThis.crypto?.randomUUID === 'function'
                    ? globalThis.crypto.randomUUID().replaceAll('-', '')
                    : `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
                return `${String(prefix).replace(/[^A-Za-z0-9._:-]/g, '_')}_${random}`.slice(0, 96);
            };
            const systemLearningRequest = async (path, options = {}) => {
                const response = await preciseQueryRequest(
                    `/agent/system-guidance/${String(path || '').replace(/^\/+/, '')}`,
                    options
                );
                if (response.code !== 200 || !response.data || typeof response.data !== 'object') {
                    throw new Error(response.message || '个人经营副驾学习服务没有返回有效结果');
                }
                return response.data;
            };
            const loadSystemLearningContext = (hotelId = 0) => systemLearningRequest(
                `context${Number(hotelId || 0) > 0 ? `?hotel_id=${Number(hotelId)}` : ''}`
            );
            const saveSystemLearningPreference = (payload = {}) => systemLearningRequest('preferences', {
                method: 'POST',
                body: JSON.stringify({
                    ...(payload && typeof payload === 'object' ? payload : {}),
                    idempotency_key: String(payload?.idempotency_key || systemLearningRequestId('preference')),
                }),
            });
            const revokeSystemLearningPreference = (payload = {}) => systemLearningRequest('preferences/revoke', {
                method: 'POST',
                body: JSON.stringify({
                    ...(payload && typeof payload === 'object' ? payload : {}),
                    idempotency_key: String(payload?.idempotency_key || systemLearningRequestId('revoke')),
                }),
            });
            const resetSystemLearningPreferences = (payload = {}) => systemLearningRequest('preferences/reset', {
                method: 'POST',
                body: JSON.stringify({
                    ...(payload && typeof payload === 'object' ? payload : {}),
                    idempotency_key: String(payload?.idempotency_key || systemLearningRequestId('reset')),
                }),
            });
            const saveSystemLearningJourney = (payload = {}) => systemLearningRequest('journey', {
                method: 'POST',
                body: JSON.stringify(payload && typeof payload === 'object' ? payload : {}),
            });
            const archiveSystemLearningJourney = (payload = {}) => systemLearningRequest('journey/archive', {
                method: 'POST',
                body: JSON.stringify(payload && typeof payload === 'object' ? payload : {}),
            });
            const transitionSystemLearningJourney = (payload = {}) => systemLearningRequest('journey/transition', {
                method: 'POST',
                body: JSON.stringify(payload && typeof payload === 'object' ? payload : {}),
            });
            const submitSystemGuidanceFeedbackRequest = (payload = {}) => systemLearningRequest('feedback', {
                method: 'POST',
                body: JSON.stringify({
                    ...(payload && typeof payload === 'object' ? payload : {}),
                    idempotency_key: String(payload?.idempotency_key || systemLearningRequestId('feedback')),
                }),
            });
            const askPreciseQuery = async (payload = {}) => {
                const response = await preciseQueryRequest('/agent/precise-queries', {
                    method: 'POST',
                    body: JSON.stringify(payload && typeof payload === 'object' ? payload : {}),
                });
                if (response.code !== 200 || !response.data || typeof response.data !== 'object') {
                    throw new Error(response.message || '宿析精准查数没有返回有效结果');
                }
                const saved = response.data;
                const id = Number(saved.id || 0);
                if (!id || saved.persistence_status !== 'readback_verified') {
                    throw new Error('宿析精准查数没有返回保存回读编号');
                }
                const exact = await readPreciseQuery(id);
                if (String(exact.content_digest || '') !== String(saved.content_digest || '')
                    || String(exact.question || '') !== String(saved.question || '')
                    || String(exact.route_type || '') !== String(saved.route_type || '')
                ) throw new Error('宿析精准查数保存与按编号回读不一致');
                return exact;
            };
            const readPreciseQuery = async (id) => {
                const questionId = Number(id || 0);
                if (!questionId) throw new Error('宿析精准查数问题编号无效');
                const response = await preciseQueryRequest(`/agent/precise-queries/${questionId}`);
                if (response.code !== 200 || !response.data || typeof response.data !== 'object') {
                    throw new Error(response.message || '宿析精准查数按编号回读失败');
                }
                if (Number(response.data.id || 0) !== questionId
                    || String(response.data.persistence_status || '') !== 'readback_verified'
                ) throw new Error('宿析精准查数编号回读凭证不一致');
                return response.data;
            };
            const savePendingCoach = (topic) => {
                try {
                    sessionStorage.setItem(pendingCoachStorageKey(), JSON.stringify({
                        version: pendingCoachStorageVersion,
                        topic_key: String(topic?.key || ''),
                        target_page: String(topic?.target_page || ''),
                        saved_at: Date.now(),
                    }));
                } catch (error) {
                    // Page navigation still works when temporary coaching continuity is unavailable.
                }
            };
            const clearPendingCoach = () => {
                try {
                    sessionStorage.removeItem(pendingCoachStorageKey());
                } catch (error) {
                    // Nothing else depends on the temporary coaching marker.
                }
            };
            const readPendingCoach = () => {
                try {
                    const raw = JSON.parse(sessionStorage.getItem(pendingCoachStorageKey()) || 'null');
                    if (!raw
                        || Number(raw.version || 0) !== pendingCoachStorageVersion
                        || Date.now() - Number(raw.saved_at || 0) > 60_000) {
                        clearPendingCoach();
                        return null;
                    }
                    return raw;
                } catch (error) {
                    clearPendingCoach();
                    return null;
                }
            };
            const widgetStyle = computed(() => {
                const position = widgetPosition.value || {};
                const style = {};
                if (Number.isFinite(position.right)) style.right = `${Math.round(position.right)}px`;
                if (Number.isFinite(position.bottom)) style.bottom = `${Math.round(position.bottom)}px`;
                return style;
            });
            const saveWidgetState = () => {
                const position = widgetPosition.value || {};
                try {
                    localStorage.setItem(widgetStorageKey(), JSON.stringify({
                        version: widgetStorageVersion,
                        open: Boolean(widgetOpen.value),
                        right: Number.isFinite(position.right) ? Math.round(position.right) : null,
                        bottom: Number.isFinite(position.bottom) ? Math.round(position.bottom) : null,
                    }));
                } catch (error) {
                    // Widget position is optional; the default corner remains usable.
                }
            };
            const readWidgetState = () => {
                try {
                    const raw = JSON.parse(localStorage.getItem(widgetStorageKey()) || 'null');
                    if (!raw || Number(raw.version || 0) !== widgetStorageVersion) return;
                    widgetOpen.value = raw.open === true;
                    const right = typeof raw.right === 'number' && Number.isFinite(raw.right)
                        ? raw.right
                        : null;
                    const bottom = typeof raw.bottom === 'number' && Number.isFinite(raw.bottom)
                        ? raw.bottom
                        : null;
                    if (right !== null && bottom !== null) {
                        widgetPosition.value = { right, bottom };
                    }
                } catch (error) {
                    // Invalid local UI state must not block the assistant entry.
                }
            };
            const applyWidgetPosition = (right, bottom) => {
                const next = {
                    right: Math.max(0, Math.round(Number(right) || 0)),
                    bottom: Math.max(0, Math.round(Number(bottom) || 0)),
                };
                widgetPosition.value = next;
                if (widgetRoot.value) {
                    widgetRoot.value.style.right = `${next.right}px`;
                    widgetRoot.value.style.bottom = `${next.bottom}px`;
                }
            };
            const clampWidgetPosition = (persist = false) => {
                const root = widgetRoot.value;
                if (!root) return;
                const rect = root.getBoundingClientRect();
                const margin = 8;
                const viewportWidth = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
                const viewportHeight = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
                const maxLeft = Math.max(margin, viewportWidth - rect.width - margin);
                const maxTop = Math.max(margin, viewportHeight - rect.height - margin);
                const left = Math.min(Math.max(rect.left, margin), maxLeft);
                const top = Math.min(Math.max(rect.top, margin), maxTop);
                const hasStoredPosition = Number.isFinite(widgetPosition.value?.right)
                    && Number.isFinite(widgetPosition.value?.bottom);
                const needsClamp = Math.abs(left - rect.left) > 0.5 || Math.abs(top - rect.top) > 0.5;
                if (hasStoredPosition || needsClamp) {
                    applyWidgetPosition(
                        viewportWidth - left - rect.width,
                        viewportHeight - top - rect.height,
                    );
                }
                if (persist) saveWidgetState();
            };
            const startWidgetDrag = (event, source) => {
                if (!event || event.isPrimary === false || (Number.isFinite(event.button) && event.button !== 0)) return;
                if (source === 'launcher' && widgetOpen.value) return;
                const root = widgetRoot.value;
                if (!root) return;
                const rect = root.getBoundingClientRect();
                widgetDrag.active = true;
                widgetDrag.source = source;
                widgetDrag.pointer_id = event.pointerId;
                widgetDrag.start_x = Number(event.clientX || 0);
                widgetDrag.start_y = Number(event.clientY || 0);
                widgetDrag.start_left = rect.left;
                widgetDrag.start_top = rect.top;
                widgetDrag.width = rect.width;
                widgetDrag.height = rect.height;
                widgetDrag.moved = false;
                widgetDrag.capture_target = event.currentTarget || null;
                widgetDragging.value = true;
                try {
                    event.currentTarget?.setPointerCapture?.(event.pointerId);
                } catch (error) {
                    // Pointer capture is an enhancement; movement still uses element events.
                }
            };
            const moveWidgetDrag = (event) => {
                if (!widgetDrag.active || event?.pointerId !== widgetDrag.pointer_id) return;
                const deltaX = Number(event.clientX || 0) - widgetDrag.start_x;
                const deltaY = Number(event.clientY || 0) - widgetDrag.start_y;
                if (!widgetDrag.moved && Math.hypot(deltaX, deltaY) < 5) return;
                widgetDrag.moved = true;
                event.preventDefault?.();
                const margin = 8;
                const viewportWidth = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
                const viewportHeight = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
                const maxLeft = Math.max(margin, viewportWidth - widgetDrag.width - margin);
                const maxTop = Math.max(margin, viewportHeight - widgetDrag.height - margin);
                const left = Math.min(Math.max(widgetDrag.start_left + deltaX, margin), maxLeft);
                const top = Math.min(Math.max(widgetDrag.start_top + deltaY, margin), maxTop);
                applyWidgetPosition(
                    viewportWidth - left - widgetDrag.width,
                    viewportHeight - top - widgetDrag.height,
                );
            };
            const endWidgetDrag = (event) => {
                if (!widgetDrag.active || event?.pointerId !== widgetDrag.pointer_id) return;
                const moved = widgetDrag.moved;
                const source = widgetDrag.source;
                try {
                    widgetDrag.capture_target?.releasePointerCapture?.(widgetDrag.pointer_id);
                } catch (error) {
                    // The browser may release capture automatically on pointerup.
                }
                widgetDrag.active = false;
                widgetDrag.pointer_id = null;
                widgetDrag.capture_target = null;
                widgetDragging.value = false;
                if (moved) {
                    suppressLauncherToggle = source === 'launcher';
                    clampWidgetPosition(true);
                    setTimeout(() => {
                        suppressLauncherToggle = false;
                    }, 0);
                }
            };
            const handleLauncherClick = (event) => {
                if (!suppressLauncherToggle) return;
                event?.preventDefault?.();
                event?.stopPropagation?.();
                suppressLauncherToggle = false;
            };
            const handleWidgetToggle = (event) => {
                widgetOpen.value = Boolean(event?.currentTarget?.open);
                nextTick(() => {
                    clampWidgetPosition(false);
                    saveWidgetState();
                });
            };
            const handleWidgetViewportResize = () => {
                if (resizeFrame) window.cancelAnimationFrame(resizeFrame);
                resizeFrame = window.requestAnimationFrame(() => {
                    resizeFrame = 0;
                    clampWidgetPosition(true);
                });
            };
            const journeyStep = (topic, index) => ({
                index: index + 1,
                key: String(topic.key || ''),
                title: String(topic.title || ''),
                category: String(topic.category || ''),
                summary: String(topic.summary || ''),
                success_marker: String(SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS[topic.key] || '已核对目标页面、当前状态和下一步动作。'),
                action: {
                    key: String(topic.key || ''),
                    label: String(topic.action_label || '打开页面'),
                    target_page: String(topic.target_page || ''),
                    action_key: String(topic.action_key || 'page'),
                },
            });
            const normalizeJourney = (rawJourney, primaryTopic = null) => {
                const keys = [];
                const primaryKey = String(primaryTopic?.key || '');
                if (primaryKey) keys.push(primaryKey);
                for (const row of Array.isArray(rawJourney) ? rawJourney.slice(0, 4) : []) {
                    const key = String(typeof row === 'string' ? row : (row?.key || row?.topic_key || '')).trim();
                    const topic = topicByKey(key);
                    if (topic && canOpenTopic(topic) && !keys.includes(key)) keys.push(key);
                }
                return keys.slice(0, 4)
                    .map(topicByKey)
                    .filter(Boolean)
                    .map(journeyStep);
            };
            const saveActiveJourney = (result, _query, activeKey = '') => {
                const journey = normalizeJourney(result?.journey, topicByKey(result?.topic_key));
                if (!journey.length) return false;
                const normalizedActiveKey = journey.some((step) => step.key === activeKey)
                    ? activeKey
                    : journey[0].key;
                const payload = {
                    version: journeyStorageVersion,
                    goal: String(result?.goal || result?.intent_summary || journey[0].title).slice(0, 240),
                    journey,
                    active_key: normalizedActiveKey,
                    saved_at: Date.now(),
                };
                state.value.active_journey = payload;
                try {
                    localStorage.setItem(journeyStorageKey(), JSON.stringify({
                        version: payload.version,
                        goal: payload.goal,
                        journey_keys: payload.journey.map((step) => step.key),
                        active_key: payload.active_key,
                        saved_at: payload.saved_at,
                    }));
                } catch (error) {
                    // Local guidance continuity is optional; navigation remains available.
                }
                void persistActiveJourney(payload);
                return true;
            };
            const readActiveJourney = () => {
                try {
                    const raw = JSON.parse(localStorage.getItem(journeyStorageKey()) || 'null');
                    const savedAt = Number(raw?.saved_at || 0);
                    if (Number(raw?.version || 0) !== journeyStorageVersion
                        || !savedAt
                        || Date.now() - savedAt > 7 * 24 * 60 * 60 * 1000) {
                        localStorage.removeItem(journeyStorageKey());
                        return null;
                    }
                    const journey = normalizeJourney(raw?.journey_keys || [], null);
                    if (!journey.length) {
                        localStorage.removeItem(journeyStorageKey());
                        return null;
                    }
                    return {
                        version: journeyStorageVersion,
                        goal: String(raw?.goal || journey[0].title).slice(0, 240),
                        journey,
                        active_key: journey.some((step) => step.key === raw?.active_key)
                            ? String(raw.active_key)
                            : journey[0].key,
                        saved_at: savedAt,
                    };
                } catch (error) {
                    return null;
                }
            };
            const clearActiveJourney = (archiveServer = false) => {
                state.value.active_journey = null;
                try {
                    localStorage.removeItem(journeyStorageKey());
                } catch (error) {
                    // Nothing else depends on local guidance persistence.
                }
                if (archiveServer) void archiveActiveJourney();
            };
            const learningPreferences = () => (
                Array.isArray(state.value.learning_context?.preferences?.items)
                    ? state.value.learning_context.preferences.items
                    : []
            );
            const consumablePreferences = () => learningPreferences().filter((item) => item?.consumable === true);
            const candidatePreferences = () => learningPreferences().filter((item) => item?.candidate === true);
            const readyCandidatePreferences = () => candidatePreferences()
                .filter((item) => String(item?.learning_status || '') === 'inferred');
            const preferenceScopeText = (item) => String(item?.scope || '') === 'hotel'
                ? '仅当前酒店'
                : (String(item?.scope || '') === 'session' ? '仅当前会话' : '你的全部酒店');
            const preferenceSourceText = (item) => {
                const reason = String(item?.source_context?.reason_code || '');
                if (reason === 'too_long') return '来自重复的“回答太长”反馈';
                if (reason === 'explicit_user_confirmation') return '由你主动确认';
                return item?.source_type === 'behavioral_signal' ? '来自重复使用反馈' : '由你明确设置';
            };
            const preferenceValueText = (item) => {
                const key = String(item?.preference_key || '');
                const value = String(item?.value || '');
                const labels = {
                    'response_detail:concise': '回答保持简洁',
                    'response_detail:detailed': '提供详细步骤',
                    'answer_order:conclusion_first': '先给结论，再给证据',
                    'answer_order:steps_first': '先给操作步骤',
                    'daily_focus:single_priority': '每天只给一件最重要的事',
                    'preferred_platform:ctrip': '默认优先说明携程',
                    'preferred_platform:meituan': '默认优先说明美团',
                    'preferred_platform:all_ota': '默认同时说明双 OTA',
                };
                return labels[`${key}:${value}`] || `${key}：${value}`;
            };
            const applyServerJourney = (context) => {
                const readback = context?.journey;
                const resume = context?.resume_card;
                const raw = readback?.data_status === 'ready' ? readback?.journey : null;
                const card = resume?.data_status === 'ready' && resume?.card?.readback_verified === true
                    ? resume.card
                    : null;
                if (!card) {
                    if (resume?.data_status === 'empty') clearActiveJourney(false);
                    return false;
                }
                const journey = normalizeJourney(card.journey_keys || raw.journey_keys || [], null);
                if (!journey.length) return false;
                const serverSavedAt = Date.parse(String(card.saved_at || raw.created_at || ''));
                const payload = {
                    version: journeyStorageVersion,
                    goal: String(card.goal_summary || raw.goal || journey[0].title).slice(0, 240),
                    journey,
                    active_key: journey.some((step) => step.key === card?.next_step?.topic_key)
                        ? String(card.next_step.topic_key)
                        : (journey.some((step) => step.key === raw.active_key)
                            ? String(raw.active_key)
                            : journey[0].key),
                    current_step_status: String(card?.next_step?.status || raw.current_step_status || 'pending'),
                    blocker_code: String(card?.next_step?.blocker_code || ''),
                    blocker_summary: String(card?.next_step?.blocker_summary || '').slice(0, 500),
                    saved_at: Number.isFinite(serverSavedAt) && serverSavedAt > 0
                        ? serverSavedAt
                        : Date.now(),
                    server_readback_id: Number(card.journey_id || raw.id || 0),
                    content_digest: String(card.content_digest || raw.content_digest || ''),
                    scope_type: String(resume?.scope?.type || (Number(raw.hotel_id || 0) > 0 ? 'hotel' : 'global')),
                    scope_hotel_id: Number(resume?.scope?.hotel_id || raw.hotel_id || 0) || null,
                    server_authoritative: true,
                };
                state.value.active_journey = payload;
                try {
                    localStorage.setItem(journeyStorageKey(), JSON.stringify({
                        version: payload.version,
                        goal: payload.goal,
                        journey_keys: payload.journey.map((step) => step.key),
                        active_key: payload.active_key,
                        saved_at: payload.saved_at,
                    }));
                } catch (error) {
                    // Server readback remains authoritative when local persistence is unavailable.
                }
                return true;
            };
            const loadLearningContext = async () => {
                const requestId = ++learningRequestId;
                const targetHotelId = currentLearningHotelId();
                state.value.learning_loading = true;
                state.value.learning_error = '';
                try {
                    const context = validateLearningContext(
                        await loadSystemLearningContext(targetHotelId),
                        targetHotelId
                    );
                    if (requestId !== learningRequestId || currentLearningHotelId() !== targetHotelId) {
                        return false;
                    }
                    state.value.learning_context = context;
                    applyServerJourney(context);
                    return true;
                } catch (error) {
                    if (requestId === learningRequestId && currentLearningHotelId() === targetHotelId) {
                        state.value.learning_context = null;
                        state.value.learning_open = false;
                        state.value.learning_error = String(error?.message || '个人学习上下文读取失败');
                    }
                    return false;
                } finally {
                    if (requestId === learningRequestId) state.value.learning_loading = false;
                }
            };
            const persistActiveJourney = async (payload) => {
                if (!payload) return false;
                const targetHotelId = currentLearningHotelId();
                try {
                    const currentStep = topicByKey(payload.active_key);
                    const status = guideStepStatus(currentStep);
                    const saved = await saveSystemLearningJourney({
                        hotel_id: targetHotelId,
                        journey: {
                            goal: String(payload.goal || '').slice(0, 240),
                            active_key: String(payload.active_key || ''),
                            journey_keys: (payload.journey || []).map((step) => String(step.key || '')).filter(Boolean),
                            current_step_status: status.key === 'completed'
                                ? 'completed'
                                : (status.key === 'blocked' ? 'blocked' : 'in_progress'),
                            blocker_code: status.key === 'blocked' ? `${String(payload.active_key || 'step')}_blocked` : '',
                            blocker_summary: status.key === 'blocked' ? String(status.detail || '').slice(0, 500) : '',
                        },
                    });
                    const readback = saved?.journey;
                    if (readback?.readback_verified === true && state.value.active_journey === payload) {
                        state.value.active_journey = {
                            ...payload,
                            server_readback_id: Number(readback.id || 0),
                            content_digest: String(readback.content_digest || ''),
                            current_step_status: String(readback.current_step_status || status.key || 'in_progress'),
                            blocker_code: String(readback.blocker_code || ''),
                            blocker_summary: String(readback.blocker_summary || '').slice(0, 500),
                            server_authoritative: true,
                        };
                    }
                    return true;
                } catch (error) {
                    state.value.learning_error = String(error?.message || '任务路线跨会话保存失败');
                    return false;
                }
            };
            const archiveActiveJourney = async () => {
                try {
                    await archiveSystemLearningJourney({ hotel_id: currentLearningHotelId() });
                    await loadLearningContext();
                    return true;
                } catch (error) {
                    state.value.learning_error = String(error?.message || '任务路线归档失败');
                    return false;
                }
            };
            const transitionActiveJourney = async (action) => {
                const active = state.value.active_journey;
                if (!active || state.value.journey_transition_status) return false;
                const targetHotelId = currentLearningHotelId();
                const targetJourneyId = Number(active.server_readback_id || 0);
                const normalizedAction = action === 'complete' ? 'complete' : 'ignore';
                const confirmation = normalizedAction === 'complete'
                    ? '只结束这张续办卡，不代表酒店业务结果已经完成。确认继续吗？'
                    : '不再显示这张续办卡，但会保留历史记录。确认继续吗？';
                if (typeof window.confirm === 'function' && !window.confirm(confirmation)) return false;
                if (!targetJourneyId || !String(active.content_digest || '')) {
                    state.value.learning_error = '续办卡尚未完成服务器精确回读，请刷新后再试';
                    return false;
                }
                state.value.journey_transition_status = normalizedAction;
                state.value.learning_error = '';
                try {
                    const result = await transitionSystemLearningJourney({
                        hotel_id: targetHotelId,
                        journey_id: targetJourneyId,
                        expected_content_digest: String(active.content_digest),
                        action: normalizedAction,
                    });
                    if (result?.status !== 'exact_readback_verified'
                        || result?.journey?.readback_verified !== true
                    ) {
                        throw new Error('续办卡状态没有通过精确回读');
                    }
                    if (currentLearningHotelId() !== targetHotelId
                        || Number(state.value.active_journey?.server_readback_id || 0) !== targetJourneyId
                    ) {
                        await loadLearningContext();
                        return true;
                    }
                    clearActiveJourney(false);
                    await loadLearningContext();
                    return true;
                } catch (error) {
                    state.value.learning_error = String(error?.message || '续办卡状态更新失败');
                    return false;
                } finally {
                    state.value.journey_transition_status = '';
                }
            };
            const resumeActiveJourney = async () => {
                const active = state.value.active_journey;
                const topic = topicByKey(active?.active_key);
                if (!topic) {
                    state.value.learning_error = '当前续办步骤已不在可用功能目录中';
                    return false;
                }
                return openTopic(null, topic, null, { skipJourneySave: true });
            };
            const savePreference = async (key, value, scope = 'global') => {
                if (state.value.preference_saving_key) return false;
                if (consumablePreferences().some((item) => (
                    String(item?.preference_key || '') === String(key)
                    && String(item?.value || '') === String(value)
                    && String(item?.lifecycle_status || 'active') === 'active'
                ))) return true;
                state.value.preference_saving_key = key;
                state.value.learning_error = '';
                try {
                    await saveSystemLearningPreference({
                        scope,
                        context_hotel_id: currentLearningHotelId(),
                        hotel_id: scope === 'hotel' ? currentLearningHotelId() : undefined,
                        preference_key: key,
                        value,
                    });
                    await loadLearningContext();
                    return true;
                } catch (error) {
                    state.value.learning_error = String(error?.message || '偏好保存失败');
                    return false;
                } finally {
                    state.value.preference_saving_key = '';
                }
            };
            const revokePreference = async (item) => {
                if (!item || state.value.preference_saving_key) return false;
                const key = String(item.preference_key || '');
                state.value.preference_saving_key = key;
                try {
                    await revokeSystemLearningPreference({
                        scope: String(item.scope || 'global'),
                        context_hotel_id: currentLearningHotelId(),
                        hotel_id: item.scope === 'hotel' ? Number(item.hotel_id || currentLearningHotelId()) : undefined,
                        preference_key: key,
                    });
                    await loadLearningContext();
                    return true;
                } catch (error) {
                    state.value.learning_error = String(error?.message || '偏好撤销失败');
                    return false;
                } finally {
                    state.value.preference_saving_key = '';
                }
            };
            const resetPreferences = async () => {
                if (state.value.preference_saving_key) return false;
                const targetHotelId = currentLearningHotelId();
                if (typeof window.confirm === 'function'
                    && !window.confirm('确认重置全局和当前酒店的个人偏好吗？历史事件仍会保留。')
                ) return false;
                state.value.preference_saving_key = '*';
                try {
                    await resetSystemLearningPreferences({
                        scope: 'global',
                        context_hotel_id: targetHotelId,
                    });
                    if (targetHotelId > 0) {
                        await resetSystemLearningPreferences({
                            scope: 'hotel',
                            hotel_id: targetHotelId,
                        });
                    }
                    if (currentLearningHotelId() === targetHotelId) {
                        await loadLearningContext();
                    }
                    return true;
                } catch (error) {
                    state.value.learning_error = String(error?.message || '偏好重置失败');
                    return false;
                } finally {
                    state.value.preference_saving_key = '';
                }
            };
            const submitSuggestionFeedback = async (result, feedbackStatus, reasonCode) => {
                const preciseQueryId = Number(result?.precise_query_id || 0);
                if (!preciseQueryId) return false;
                state.value.feedback_status = { ...state.value.feedback_status, [preciseQueryId]: 'saving' };
                try {
                    await submitSystemGuidanceFeedbackRequest({
                        precise_query_id: preciseQueryId,
                        feedback_status: feedbackStatus,
                        reason_code: reasonCode,
                        idempotency_key: `system_guidance_feedback_${preciseQueryId}`,
                    });
                    state.value.feedback_status = { ...state.value.feedback_status, [preciseQueryId]: reasonCode };
                    await loadLearningContext();
                    return true;
                } catch (error) {
                    state.value.feedback_status = { ...state.value.feedback_status, [preciseQueryId]: 'error' };
                    state.value.learning_error = String(error?.message || '建议反馈保存失败');
                    return false;
                }
            };
            const isTopicCurrent = (topic) => {
                const currentPage = String(props.ctx?.currentPage || '');
                const currentTab = String(props.ctx?.onlineDataTab || '');
                if (topic?.action_key === 'data-health') {
                    return currentPage === 'online-data' && currentTab === 'data-health';
                }
                if (topic?.action_key === 'auto-collect') {
                    return currentPage === 'online-data' && currentTab === 'platform-auto';
                }
                return currentPage === String(topic?.target_page || '');
            };
            const guideStepStatus = (topic) => {
                const pending = {
                    key: 'pending', label: '待开始', detail: '还没有进入这一步。', complete: false,
                };
                if (!topic) return pending;
                const current = isTopicCurrent(topic);
                const ctx = props.ctx || {};
                if (topic.key === 'data-health') {
                    const summary = window.SUXI_DATA_HEALTH_STATIC ? ctx.phase1EmployeeClosureSummary : null;
                    if (summary?.status === 'complete') {
                        return {
                            key: 'completed', label: '已核验', complete: true,
                            detail: String(summary.summaryText || '数据健康证据已闭合，可进入经营诊断。'),
                        };
                    }
                    if (summary && typeof summary === 'object') {
                        return {
                            key: 'blocked', label: '有阻塞', complete: false,
                            detail: String(summary.summaryText || summary.topActionText || '数据健康仍有未证明项。'),
                        };
                    }
                    return current
                        ? { key: 'checking', label: '待核验', detail: '数据健康证据尚未加载或接口未返回，不能判定完成。', complete: false }
                        : pending;
                }
                if (topic.key === 'auto-collect') {
                    const runState = ctx.autoFetchRunState || {};
                    const canonical = ctx.autoFetchCanonicalOperationStatus || {};
                    if (runState.active === true) {
                        return { key: 'checking', label: '运行中', detail: '采集任务正在运行，等待保存与精确回读回执。', complete: false };
                    }
                    if (canonical.visible === true && canonical.status === 'verified') {
                        return {
                            key: 'completed', label: '已核验', complete: true,
                            detail: String(canonical.status_text || canonical.scope_text || '已取得当前范围的严格回读回执。'),
                        };
                    }
                    if (canonical.visible === true) {
                        return {
                            key: 'blocked', label: '有阻塞', complete: false,
                            detail: String(canonical.reason || canonical.status_text || '采集回执未通过严格核验。'),
                        };
                    }
                    if (ctx.autoFetchEnabled === true) {
                        return { key: 'in_progress', label: '待回执', detail: '计划已启用，但还没有当前范围的严格运行回执。', complete: false };
                    }
                    return current
                        ? { key: 'in_progress', label: '待配置', detail: '请先完成酒店、平台账号与采集计划配置。', complete: false }
                        : pending;
                }
                if (topic.key === 'revenue-report' || topic.key === 'agent-toolbox') {
                    const questionState = ctx.operatingQuestionState || {};
                    const exact = questionState.result || null;
                    if (questionState.loading === true) {
                        return { key: 'checking', label: '读取中', detail: '正在保存问题并严格回读同范围证据。', complete: false };
                    }
                    if (exact?.answer_status === 'blocked_by_missing_facts') {
                        return { key: 'blocked', label: '缺少事实', detail: String(exact.answer_summary || '缺少同酒店、同平台、同日期的可信事实。'), complete: false };
                    }
                    if (Number(exact?.id || 0) > 0 && String(exact?.content_digest || '')) {
                        return { key: 'completed', label: '已回读', detail: String(exact.answer_summary || '经营问题已保存并完成严格回读。'), complete: true };
                    }
                    return current
                        ? { key: 'in_progress', label: '可提问', detail: '已到达专业问答入口，提交问题后以严格回读结果判断。', complete: false }
                        : pending;
                }
                if (topic.key === 'ai-daily-report') {
                    const report = ctx.aiDailyReport || null;
                    const readiness = ctx.aiDailyReportResultReadiness || {};
                    if (Number(report?.id || report?.report_id || 0) > 0 && readiness?.usable === true) {
                        return {
                            key: 'completed', label: '结果可用', complete: true,
                            detail: String(readiness.status_label || '日报已生成，并有可阅读的来源与测得指标。'),
                        };
                    }
                    if (report && typeof report === 'object') {
                        return {
                            key: 'blocked', label: '结果受限', complete: false,
                            detail: String(readiness?.status_label || '日报已有记录，但来源或测得指标仍不足。'),
                        };
                    }
                    return current
                        ? { key: 'in_progress', label: '待生成', detail: '请先通过事实门，再生成并预览日报。', complete: false }
                        : pending;
                }
                if (topic.key === 'operations') {
                    const drafts = ctx.operatingQuestionState?.result?.answer?.action_drafts;
                    if (Array.isArray(drafts) && drafts.length) {
                        return { key: 'in_progress', label: '待人工确认', detail: '行动草案已生成，但尚未提交、审批或执行。', complete: false };
                    }
                }
                return current
                    ? { key: 'in_progress', label: '进行中', detail: '已到达目标页面；完成操作并核对页面回显后才能继续。', complete: false }
                    : pending;
            };
            const clearTopicCoach = () => {
                coachRequestId += 1;
                if (coachTarget) coachTarget.classList.remove('sx-system-guide-anchor-active');
                coachTarget = null;
                state.value.coach = null;
            };
            const topicAnchorSelectors = (topic) => {
                const configured = SYSTEM_USAGE_GUIDE_ANCHORS[String(topic?.key || '')];
                return Array.isArray(configured) ? configured : [];
            };
            const focusTopicAnchor = async (topic) => {
                if (!topic) return false;
                const requestId = ++coachRequestId;
                if (coachTarget) coachTarget.classList.remove('sx-system-guide-anchor-active');
                coachTarget = null;
                state.value.coach = {
                    topic_key: String(topic.key || ''),
                    target_found: false,
                    checking: true,
                };
                widgetOpen.value = false;
                if (widgetRoot.value) widgetRoot.value.open = false;
                saveWidgetState();
                const delays = [0, 120, 320, 650];
                let target = null;
                for (const delay of delays) {
                    if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
                    if (requestId !== coachRequestId) return false;
                    await nextTick();
                    target = topicAnchorSelectors(topic)
                        .map((selector) => document.querySelector(selector))
                        .find(Boolean) || null;
                    if (target) break;
                }
                if (requestId !== coachRequestId) return false;
                if (!target) {
                    state.value.coach = {
                        topic_key: String(topic.key || ''),
                        target_found: false,
                        checking: false,
                    };
                    setTimeout(clearPendingCoach, 5000);
                    return false;
                }
                coachTarget = target;
                coachTarget.classList.add('sx-system-guide-anchor-active');
                coachTarget.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                state.value.coach = {
                    topic_key: String(topic.key || ''),
                    target_found: true,
                    checking: false,
                };
                setTimeout(clearPendingCoach, 5000);
                return true;
            };
            const resumePendingCoach = async () => {
                const pendingCoach = readPendingCoach();
                const topic = topicByKey(pendingCoach?.topic_key);
                if (!topic) return false;
                for (const delay of [0, 100, 300, 700, 1200]) {
                    if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
                    if (String(topic.target_page || '') === String(props.ctx?.currentPage || '')) {
                        return focusTopicAnchor(topic);
                    }
                }
                return false;
            };
            const currentPageText = () => String(props.ctx?.pageTitle || props.ctx?.currentPage || '当前页面');
            const suggestionItems = () => {
                const currentPage = String(props.ctx?.currentPage || '');
                const contextTopics = SYSTEM_USAGE_GUIDE_TOPICS
                    .filter((topic) => topic.key !== 'task-navigation'
                        && canOpenTopic(topic)
                        && (topic.context_pages || []).includes(currentPage))
                    .slice(0, 2);
                const items = [{
                    key: 'current-page',
                    label: '这个页面怎么用？',
                    query: `我现在在“${currentPageText()}”，这里主要能完成什么，第一步该做什么？`,
                }, {
                    key: 'precise-exposure',
                    label: '查美团曝光',
                    query: 'Hotel 80 8月23日美团曝光多少？',
                }, {
                    key: 'precise-missing',
                    label: '问携程缺失',
                    query: '携程为什么没有曝光转化率？',
                }, {
                    key: 'precise-navigation',
                    label: '找可信播报',
                    query: '可信播报怎么复制？',
                }];
                const activeGoal = String(state.value.active_journey?.goal || '').trim();
                if (activeGoal) {
                    items.unshift({
                        key: 'continue-task',
                        label: '继续当前任务',
                        query: `继续“${activeGoal}”，我现在下一步该做什么？`,
                    });
                }
                const preferredTopics = [
                    ...contextTopics,
                    ...['data-health', 'revenue-report', 'operations', 'task-navigation']
                        .map(topicByKey)
                        .filter((topic) => topic && canOpenTopic(topic)),
                ];
                const rankingItems = Array.isArray(state.value.learning_context?.calibration?.feedback_ranking?.items)
                    ? state.value.learning_context.calibration.feedback_ranking.items
                    : [];
                const rankingAdjustments = new Map();
                const rankingConflicts = new Set();
                for (const item of rankingItems.filter((row) => row?.eligible === true)) {
                    const key = String(item?.topic_key || '');
                    if (!key || rankingConflicts.has(key)) continue;
                    if (rankingAdjustments.has(key)) {
                        rankingAdjustments.delete(key);
                        rankingConflicts.add(key);
                        continue;
                    }
                    rankingAdjustments.set(key, Number(item?.adjustment || 0));
                }
                const contextualTopicKeys = new Set(contextTopics.map((topic) => String(topic?.key || '')));
                const orderedTopics = preferredTopics
                    .map((topic, index) => ({
                        topic,
                        index,
                        base_priority: contextualTopicKeys.has(String(topic?.key || '')) ? 0 : 1,
                    }))
                    .sort((left, right) => (
                        left.base_priority - right.base_priority
                    ) || (
                        (rankingAdjustments.get(String(right.topic?.key || '')) || 0)
                        - (rankingAdjustments.get(String(left.topic?.key || '')) || 0)
                    ) || left.index - right.index)
                    .map((entry) => entry.topic);
                const usedKeys = new Set();
                for (const topic of orderedTopics) {
                    if (!topic || usedKeys.has(topic.key)) continue;
                    usedKeys.add(topic.key);
                    items.push({
                        key: `topic-${topic.key}`,
                        topic_key: String(topic.key || ''),
                        feedback_adjustment: rankingAdjustments.get(String(topic.key || '')) || 0,
                        label: String(topic.title || '查看功能'),
                        query: String(topic.example || topic.title || ''),
                    });
                    if (items.length >= 6) break;
                }
                return items.slice(0, 6);
            };
            const activeJourneyContext = () => {
                const activeJourney = state.value.active_journey;
                const journey = Array.isArray(activeJourney?.journey) ? activeJourney.journey : [];
                if (!journey.length) return null;
                const activeKey = journey.some((step) => step?.key === activeJourney.active_key)
                    ? String(activeJourney.active_key)
                    : String(journey[0]?.key || '');
                const activeTopic = topicByKey(activeKey);
                const stepStatus = activeTopic ? guideStepStatus(activeTopic) : { key: 'pending' };
                return {
                    goal: String(activeJourney.goal || '').slice(0, 240),
                    active_key: activeKey,
                    journey_keys: journey.map((step) => String(step?.key || '')).filter(Boolean).slice(0, 4),
                    current_step_status: String(stepStatus?.key || 'pending'),
                };
            };
            const conversationHistory = () => state.value.turns
                .slice(-4)
                .flatMap((turn) => [
                    { role: 'user', content: String(turn.query || '') },
                    { role: 'assistant', content: String(turn.result?.assistant_message || '') },
                ])
                .filter((message) => message.content);
            const latestPreciseOperatingScope = () => {
                for (let index = state.value.turns.length - 1; index >= 0; index -= 1) {
                    const result = state.value.turns[index]?.result;
                    const scope = result?.precise_query_scope;
                    if (result?.route_type === 'operating_query'
                        && scope && typeof scope === 'object'
                        && Number(scope.hotel_id || 0) > 0
                        && String(scope.business_date || '')
                    ) return scope;
                }
                return null;
            };
            const setAssistantMode = (mode) => {
                const normalized = String(mode || 'auto').trim().toLowerCase();
                state.value.selected_mode = ['auto', 'guide', 'report', 'action'].includes(normalized)
                    ? normalized
                    : 'auto';
                state.value.error = '';
            };
            const operatingPlatformFromQuery = (query) => {
                const normalized = normalizeSystemUsageGuideText(query);
                if (['携程和美团', '携程美团', '全部ota', '全ota', '所有ota'].some((key) => normalized.includes(normalizeSystemUsageGuideText(key)))) {
                    return 'all_ota';
                }
                if (normalized.includes('美团')) return 'meituan';
                if (normalized.includes('携程')) return 'ctrip';
                return '';
            };
            const runOperatingWorkflow = async (guideResult, query) => {
                const assistantMode = String(guideResult?.assistant_mode || 'guide');
                if (!['report', 'action'].includes(assistantMode) || guideResult?.topic_key === 'clarify') {
                    return guideResult;
                }
                const ctx = props.ctx || {};
                const form = ctx.operatingQuestionForm;
                const questionState = ctx.operatingQuestionState;
                if (!form || !questionState || typeof ctx.askOperatingQuestion !== 'function') {
                    return {
                        ...guideResult,
                        operating_result: null,
                        operating_error: '专业经营问答入口尚未加载，请进入酒店 AI 工具箱后重试。',
                    };
                }
                ctx.ensureOperatingQuestionScope?.();
                const inferredPlatform = operatingPlatformFromQuery(query);
                if (inferredPlatform) form.platform = inferredPlatform;
                form.model_key = 'local_second_brain';
                form.decision_object = '';
                questionState.question = query;
                const exact = await ctx.askOperatingQuestion();
                const operatingResult = exact || questionState.result || null;
                return {
                    ...guideResult,
                    operating_result: operatingResult,
                    operating_error: operatingResult ? '' : String(questionState.error || '当前范围没有取得可严格回读的经营回答。'),
                };
            };
            const localFallbackResult = (query, reason = 'client_request_failed', requestedMode = state.value.selected_mode) => {
                const continuing = ['继续', '下一步', '然后呢', '接着', '还要做什么']
                    .some((keyword) => normalizeSystemUsageGuideText(query).includes(normalizeSystemUsageGuideText(keyword)));
                const activeJourney = state.value.active_journey;
                const activeTopic = continuing ? topicByKey(activeJourney?.active_key) : null;
                const topic = activeTopic || resolveSystemUsageGuideTopic(query, props.ctx?.currentPage);
                const journeyKeys = activeTopic && Array.isArray(activeJourney?.journey)
                    ? activeJourney.journey.map((step) => String(step?.key || '')).filter(Boolean)
                    : resolveSystemUsageGuideJourney(query, topic);
                const goal = activeTopic && String(activeJourney?.goal || '').trim()
                    ? String(activeJourney.goal)
                    : (journeyKeys.length > 1 ? query : String(topic.title || ''));
                return {
                    status: 'ready',
                    mode: 'fallback',
                    assistant_mode: resolveSystemAssistantMode(query, requestedMode),
                    assistant_message: activeTopic
                        ? `智能理解暂时不可用，我继续按已保留的任务路线，先带你处理“${String(topic.title || '任务导航')}”。`
                        : `智能理解暂时不可用，我先按“${String(topic.title || '任务导航')}”带你进入最接近的功能。`,
                    intent_summary: String(topic.title || ''),
                    goal,
                    topic_key: String(topic.key || 'task-navigation'),
                    topic: {
                        key: String(topic.key || 'task-navigation'),
                        title: String(topic.title || '查找项目功能入口'),
                        category: String(topic.category || '使用帮助'),
                    },
                    journey: normalizeJourney(journeyKeys, topic),
                    steps: Array.isArray(topic.steps) ? topic.steps.slice(0, 4) : [],
                    clarifying_question: '',
                    follow_up_questions: [],
                    confidence: 'low',
                    boundary: String(topic.boundary || ''),
                    runtime: {
                        status: 'fallback',
                        fallback_used: true,
                        degraded: true,
                        reason,
                    },
                };
            };
            const normalizeResult = (raw, query) => {
                if (!raw || typeof raw !== 'object') {
                    throw new Error('智能引导结果为空');
                }
                const mode = raw.mode === 'intelligent' ? 'intelligent' : 'fallback';
                const assistantMode = resolveSystemAssistantMode(query, state.value.selected_mode, raw.assistant_mode);
                const topicKey = String(raw.topic_key || '').trim();
                if (topicKey === 'clarify') {
                    const question = String(raw.clarifying_question || '').trim();
                    if (!question) throw new Error('智能引导缺少澄清问题');
                    return {
                        ...raw,
                        mode,
                        assistant_mode: assistantMode,
                        goal: String(raw.goal || raw.intent_summary || ''),
                        topic_key: 'clarify',
                        topic: null,
                        journey: [],
                        steps: [],
                        action: null,
                        original_query: query,
                    };
                }
                const topic = topicByKey(topicKey);
                if (!topic) throw new Error('智能引导返回了未知功能');
                const journey = normalizeJourney(raw.journey, topic);
                return {
                    ...raw,
                    mode,
                    assistant_mode: assistantMode,
                    topic_key: topic.key,
                    topic: {
                        key: topic.key,
                        title: topic.title,
                        category: topic.category,
                    },
                    goal: String(raw.goal || raw.intent_summary || topic.title).slice(0, 240),
                    journey,
                    steps: Array.isArray(raw.steps) && raw.steps.length
                        ? raw.steps.slice(0, 4).map((step) => String(step || '')).filter(Boolean)
                        : (topic.steps || []).slice(0, 4),
                    boundary: String(raw.boundary || topic.boundary || ''),
                    original_query: query,
                };
            };
            const normalizePreciseQueryResult = (raw, query) => {
                if (!raw || typeof raw !== 'object'
                    || Number(raw.id || 0) <= 0
                    || String(raw.persistence_status || '') !== 'readback_verified'
                ) throw new Error('宿析精准查数没有返回可回读记录');
                const routeType = String(raw.route_type || '');
                const common = {
                    precise_query_id: Number(raw.id || 0),
                    precise_query_digest: String(raw.content_digest || ''),
                    precise_query_status: String(raw.status || ''),
                    precise_query_scope: raw.parsed_scope && typeof raw.parsed_scope === 'object' ? raw.parsed_scope : {},
                    precise_query_lexicon: raw.lexicon && typeof raw.lexicon === 'object' ? raw.lexicon : {},
                    knowledge_refs: Array.isArray(raw.knowledge_refs) ? raw.knowledge_refs : [],
                    fact_refs: Array.isArray(raw.fact_refs) ? raw.fact_refs : [],
                    persistence_status: 'readback_verified',
                    original_query: query,
                };
                if (routeType === 'system_navigation') {
                    return {
                        ...normalizeResult(raw.answer || {}, query),
                        ...common,
                        route_type: routeType,
                    };
                }
                if (routeType === 'operating_query') {
                    const topic = topicByKey('revenue-report');
                    return {
                        ...common,
                        status: 'ready',
                        mode: 'deterministic',
                        route_type: routeType,
                        assistant_mode: 'report',
                        assistant_message: String(raw.answer_summary || '已按数据库严格回读事实完成确定性查数。'),
                        intent_summary: '经营查数',
                        goal: query,
                        topic_key: topic?.key || 'revenue-report',
                        topic: topic ? { key: topic.key, title: topic.title, category: topic.category } : null,
                        journey: [],
                        steps: [],
                        clarifying_question: '',
                        operating_result: raw.operating_question || null,
                        operating_error: raw.operating_question ? '' : String(raw.answer_summary || '没有取得可回读经营记录。'),
                        boundary: '数值只来自数据库严格回读事实或公开公式的确定性计算；模型不能在自由文本中生成数字。',
                    };
                }
                if (routeType === 'term_definition') {
                    const topic = topicByKey('knowledge-search');
                    return {
                        ...common,
                        status: 'ready',
                        mode: 'deterministic',
                        route_type: routeType,
                        assistant_mode: 'term',
                        assistant_message: String(raw.answer_summary || raw.answer?.definition || ''),
                        intent_summary: '术语查询',
                        goal: query,
                        topic_key: topic?.key || 'knowledge-search',
                        topic: topic ? { key: topic.key, title: topic.title, category: topic.category } : null,
                        journey: [],
                        steps: [],
                        clarifying_question: '',
                        term_result: raw.answer || {},
                        boundary: '术语定义保持 reference_only，不进入酒店经营事实。',
                    };
                }
                if (routeType === 'clarification') {
                    return {
                        ...common,
                        status: 'clarification_required',
                        mode: 'deterministic',
                        route_type: routeType,
                        assistant_mode: 'auto',
                        assistant_message: '为了避免猜错范围，我只确认一个会改变答案的条件。',
                        intent_summary: '需要确认范围',
                        goal: query,
                        topic_key: 'clarify',
                        topic: null,
                        journey: [],
                        steps: [],
                        clarifying_question: String(raw.answer?.clarifying_question || raw.answer_summary || ''),
                        boundary: '酒店、平台或业务日期会改变答案时不自行猜测。',
                    };
                }
                throw new Error('宿析精准查数返回了未知路由');
            };
            const applySuggestion = (item) => {
                state.value.query = String(item?.query || item?.example || item?.title || '');
                state.value.error = '';
            };
            const applyFollowUp = (question) => {
                state.value.query = String(question || '');
                state.value.error = '';
            };
            const ask = async () => {
                if (state.value.loading) return false;
                const query = String(state.value.query || '').trim();
                state.value.error = '';
                if (!query) {
                    state.value.error = '直接说你想完成什么，例如“我刚接手这家店，携程数据没进来，应该先做什么？”';
                    return false;
                }
                state.value.loading = true;
                state.value.query = '';
                const requestedMode = String(state.value.selected_mode || 'auto');
                const conversationScope = latestPreciseOperatingScope();
                const requestPayload = {
                    query,
                    requested_mode: requestedMode,
                    current_page: String(props.ctx?.currentPage || ''),
                    page_title: currentPageText(),
                    current_scope: {
                        hotel_id: Number(
                            conversationScope?.hotel_id
                            ||
                            props.ctx?.operatingQuestionForm?.hotel_id
                            || props.ctx?.filterReportHotel
                            || props.ctx?.user?.hotel_id
                            || 0
                        ),
                        hotel_name: String(conversationScope?.hotel_name || props.ctx?.operatingQuestionSelectedHotel?.name || ''),
                        platform: String(conversationScope?.platform || props.ctx?.operatingQuestionForm?.platform || ''),
                        date_start: String(conversationScope?.business_date || props.ctx?.operatingQuestionForm?.date_start || ''),
                        date_end: String(conversationScope?.business_date || props.ctx?.operatingQuestionForm?.date_end || ''),
                    },
                    visible_topic_keys: visibleTopicKeys(),
                    active_journey: activeJourneyContext(),
                    history: conversationHistory(),
                };
                let result;
                try {
                    if (typeof props.ctx?.managerCapabilityRequest === 'function') {
                        const exact = await askPreciseQuery(requestPayload);
                        result = normalizePreciseQueryResult(exact, query);
                        savePreciseQueryPointer(exact);
                    } else {
                        result = normalizeResult(await props.ctx?.askSystemUsageGuide?.(requestPayload), query);
                        result = await runOperatingWorkflow(result, query);
                    }
                } catch (error) {
                    result = localFallbackResult(query, 'request_failed', requestedMode);
                    result = await runOperatingWorkflow(result, query);
                } finally {
                    state.value.loading = false;
                }
                state.value.turns.push({
                    id: `${Date.now()}-${state.value.turns.length}`,
                    query,
                    result,
                });
                if (result.topic_key !== 'clarify' && result.route_type !== 'term_definition' && result.route_type !== 'operating_query') {
                    saveActiveJourney(result, query, result.topic_key);
                }
                if (state.value.turns.length > 6) {
                    state.value.turns.splice(0, state.value.turns.length - 6);
                }
                return true;
            };
            const restorePreciseQueryReadback = async () => {
                if (state.value.turns.length || state.value.restoring_precise_query) return false;
                const pointer = readPreciseQueryPointer();
                if (!pointer || typeof props.ctx?.managerCapabilityRequest !== 'function') return false;
                state.value.restoring_precise_query = true;
                try {
                    const exact = await readPreciseQuery(pointer.id);
                    if (String(exact?.content_digest || '') !== String(pointer.content_digest || '')) {
                        throw new Error('刷新后的问题摘要与上次保存不一致');
                    }
                    const query = String(exact?.question || '');
                    const result = normalizePreciseQueryResult(exact, query);
                    state.value.turns.push({
                        id: `restored-${Number(exact.id || 0)}`,
                        query,
                        result,
                    });
                    return true;
                } catch (error) {
                    state.value.error = error?.message || '刷新后按编号回读失败';
                    return false;
                } finally {
                    state.value.restoring_precise_query = false;
                }
            };
            const openTopic = async (event, topic, turn, options = {}) => {
                if (!topic || state.value.opening_key) return false;
                if (!canOpenTopic(topic)) {
                    state.value.error = '当前账号没有显示该功能入口，请联系管理员核对角色与酒店权限。';
                    return false;
                }
                state.value.error = '';
                state.value.opening_key = String(topic.key || 'page');
                try {
                    const ctx = props.ctx || {};
                    savePendingCoach(topic);
                    if (topic.action_key === 'data-health') {
                        ctx.currentPage = 'online-data';
                        ctx.onlineDataTab = 'data-health';
                        await Promise.resolve(ctx.openOnlineDataTab?.('data-health', { force: true }));
                    } else if (topic.action_key === 'auto-collect') {
                        await Promise.resolve(ctx.openOnlinePlatformAutoTab?.({ force: true }));
                    } else {
                        ctx.currentPage = String(topic.target_page || '');
                        if (['task-navigation', 'knowledge-search'].includes(topic.action_key) && ctx.knowledgeCenterFilter) {
                            ctx.knowledgeCenterFilter.keyword = String(turn?.query || '').trim();
                        }
                    }
                    if (options?.skipJourneySave !== true) {
                        if (turn?.result?.journey?.length) {
                            saveActiveJourney(turn.result, turn.query, String(topic.key || ''));
                        } else if (state.value.active_journey?.journey?.length) {
                            saveActiveJourney(state.value.active_journey, state.value.active_journey.original_query, String(topic.key || ''));
                        }
                    }
                    await focusTopicAnchor(topic);
                    return true;
                } catch (error) {
                    state.value.error = error?.message || '打开功能页面失败，请从左侧导航重试。';
                    return false;
                } finally {
                    state.value.opening_key = '';
                }
            };
            const runtimeText = (result) => {
                if (Number(result?.precise_query_id || 0) > 0) return '统一路由';
                if (result?.mode === 'deterministic') return '确定性路由';
                if (result?.mode !== 'intelligent') return '基础引导';
                if (result?.status === 'clarification_required') return '需要确认目标';
                return '已理解目标';
            };
            const renderPersonalizationReceipt = (result, isLatest = false) => {
                const personalization = result?.personalization;
                const status = String(personalization?.status || 'not_configured');
                if (!personalization || status === 'not_configured') return null;
                const explanation = personalization.explanation && typeof personalization.explanation === 'object'
                    ? personalization.explanation
                    : {};
                const summary = String(explanation.summary || (
                    status === 'applied'
                        ? '已按你确认的个人偏好调整表达。'
                        : (status === 'overridden_by_current_request'
                            ? '本次明确要求覆盖了历史偏好。'
                            : '已识别保存偏好，但本次没有应用。')
                ));
                const appliedCount = Array.isArray(personalization.applied_preferences)
                    ? personalization.applied_preferences.length
                    : 0;
                return h('details', {
                    class: 'sx-ai-consultant-gaps',
                    'data-testid': isLatest ? 'system-guide-personalization-receipt' : undefined,
                }, [
                    h('summary', [
                        h('strong', '为什么这样回答'),
                        h('span', status === 'applied' ? ` · 使用 ${appliedCount || 1} 条已确认偏好` : ''),
                    ]),
                    h('p', summary),
                    h('small', status === 'applied'
                        ? '只调整表达方式；没有改变酒店事实、权限、审批或外部写入。'
                        : '没有使用历史偏好改变本次结果。'),
                ]);
            };
            const openOperatingWorkspace = async () => {
                const ctx = props.ctx || {};
                ctx.currentPage = 'agent-center';
                await nextTick();
                return focusTopicAnchor(topicByKey('agent-toolbox'));
            };
            const operatingScopeText = (result) => {
                const hotelId = Number(result?.hotel_id || 0);
                const hotel = (Array.isArray(props.ctx?.otaDiagnosisHotelOptions) ? props.ctx.otaDiagnosisHotelOptions : [])
                    .find((item) => Number(item?.value || 0) === hotelId);
                const hotelText = String(hotel?.name || (hotelId > 0 ? `酒店 #${hotelId}` : '未锁定酒店'));
                const platformText = String(props.ctx?.operatingQuestionPlatformText?.(result?.platform) || result?.platform || '未锁定平台');
                const dateStart = String(result?.date_start || '');
                const dateEnd = String(result?.date_end || '');
                const dateText = dateStart ? `${dateStart}${dateEnd && dateEnd !== dateStart ? ` 至 ${dateEnd}` : ''}` : '未锁定日期';
                return `${hotelText} · ${platformText} · ${dateText}`;
            };
            const renderOperatingResult = (guideResult, turn, isLatest = false) => {
                const assistantMode = String(guideResult?.assistant_mode || 'guide');
                if (!['report', 'action'].includes(assistantMode)) return null;
                const exact = guideResult?.operating_result || null;
                if (!exact) {
                    return h('section', {
                        class: 'sx-ai-consultant-operating-result is-blocked',
                        'data-testid': isLatest ? 'system-guide-operating-blocked' : undefined,
                    }, [
                        h('div', { class: 'sx-ai-consultant-operating-result-title' }, [
                            icon('fa-circle-exclamation'),
                            h('strong', assistantMode === 'action' ? '行动草案暂时不能生成' : '当前不能给出可信结论'),
                        ]),
                        h('p', String(guideResult?.operating_error || '当前范围没有取得可严格回读的经营事实。')),
                        h('div', { class: 'sx-ai-consultant-recovery-actions' }, [
                            h('button', {
                                type: 'button',
                                onClick: (event) => openTopic(event, topicByKey('data-health'), turn),
                            }, [icon('fa-shield-alt'), h('span', '去数据健康查阻塞')]),
                            h('button', { type: 'button', onClick: openOperatingWorkspace }, [
                                icon('fa-arrow-right'), h('span', '打开专业问答'),
                            ]),
                        ]),
                    ]);
                }
                const answer = exact.answer && typeof exact.answer === 'object' ? exact.answer : {};
                const keyPoints = Array.isArray(answer.key_points) ? answer.key_points.slice(0, 4).filter(Boolean) : [];
                const gaps = [
                    ...(Array.isArray(exact.data_gaps) ? exact.data_gaps.map((gap) => gap?.message || gap?.code || '').filter(Boolean) : []),
                    ...(Array.isArray(answer.missing_information) ? answer.missing_information.filter(Boolean) : []),
                ].slice(0, 5);
                const evidence = answer.evidence_counts && typeof answer.evidence_counts === 'object'
                    ? answer.evidence_counts
                    : {};
                const blocked = String(exact.answer_status || '').startsWith('blocked');
                const children = [
                    h('div', { class: 'sx-ai-consultant-operating-result-head' }, [
                        h('span', {
                            class: ['sx-ai-consultant-status', blocked ? 'is-blocked' : ''],
                        }, blocked
                            ? '缺少可信事实'
                            : String(props.ctx?.operatingQuestionAnswerStatusText?.(exact.answer_status) || '已严格回读')),
                        h('span', assistantMode === 'action' ? '行动草案模式' : '证据结论模式'),
                    ]),
                    h('p', { class: 'sx-ai-consultant-operating-scope' }, operatingScopeText(exact)),
                    h('p', { class: 'sx-ai-consultant-answer-summary' }, String(exact.answer_summary || '当前严格回读没有返回摘要。')),
                ];
                children.push(renderHotelDataAnalystQualityReceipt(
                    exact.analysis_quality_receipt,
                    isLatest ? 'system-guide-analysis-quality-receipt' : '',
                    {
                        question: exact,
                        feedbackUi: qualityFeedbackUi,
                        interactive: isLatest && widgetOpen.value,
                        feedbackTestId: isLatest ? 'system-guide-analysis-quality-feedback' : '',
                    }
                ));
                const precise = answer.precise_result && typeof answer.precise_result === 'object'
                    ? answer.precise_result
                    : null;
                if (precise) {
                    const valueText = precise.value === null || precise.value === undefined || precise.value === ''
                        ? '--'
                        : `${String(precise.value)}${precise.unit ? ` ${String(precise.unit)}` : ''}`;
                    const fields = [
                        ['酒店', String(precise.hotel?.name || `Hotel ${Number(precise.hotel?.id || 0) || '--'}`)],
                        ['平台', String(precise.platform?.name || precise.platform?.key || '--')],
                        ['业务日期', String(precise.business_date || '--')],
                        ['指标名称', String(precise.metric?.name || precise.metric?.key || '--')],
                        ['数值与单位', valueText],
                        ['来源记录', String(precise.source_record || '--')],
                        ['采集时间', String(precise.collected_at || '未记录，不用回读时间代替')],
                        ['验证状态', String(precise.verification_status || '--')],
                        ['回读状态', String(precise.readback_status || '--')],
                        ['数据范围', String(precise.data_scope || '--')],
                    ];
                    children.push(h('section', {
                        class: 'mt-3 rounded-xl border border-emerald-200 bg-emerald-50/70 p-3',
                        'data-testid': isLatest ? 'precise-query-fact-card' : undefined,
                    }, [
                        h('div', { class: 'mb-2 flex flex-wrap items-center justify-between gap-2' }, [
                            h('strong', { class: 'text-sm text-emerald-950' }, '可核对经营结果'),
                            h('span', { class: 'rounded-full bg-white px-2 py-1 text-[11px] text-emerald-800' }, blocked ? '明确阻塞' : '数据库确定性结果'),
                        ]),
                        h('dl', { class: 'grid grid-cols-1 gap-2 text-xs sm:grid-cols-2' }, fields.map(([label, value], index) => h('div', {
                            key: `precise-field-${index}`,
                            class: ['rounded-lg border border-emerald-100 bg-white px-2.5 py-2', label === '数据范围' ? 'sm:col-span-2' : ''],
                        }, [
                            h('dt', { class: 'text-[11px] text-slate-500' }, label),
                            h('dd', { class: 'mt-0.5 break-words font-medium text-slate-800' }, value),
                        ]))),
                        precise.formula ? h('p', { class: 'mt-2 text-xs leading-5 text-emerald-900' }, `计算：${String(precise.formula)}`) : null,
                        precise.blocked_reason ? h('p', { class: 'mt-2 text-xs leading-5 text-amber-800' }, `阻塞原因：${String(precise.blocked_reason)}`) : null,
                    ].filter(Boolean)));
                }
                const decisionFrame = precise ? null : renderRevenueDecisionFrame(answer.decision_frame, isLatest ? 'system-guide-decision-frame' : '');
                if (decisionFrame) children.push(decisionFrame);
                if (keyPoints.length) {
                    children.push(h('ul', { class: 'sx-ai-consultant-key-points' }, keyPoints.map((point, index) => (
                        h('li', { key: `operating-point-${index}` }, String(point))
                    ))));
                }
                if (gaps.length) {
                    children.push(h('div', { class: 'sx-ai-consultant-gaps' }, [
                        h('strong', '当前阻塞'),
                        h('ul', gaps.map((gap, index) => h('li', { key: `operating-gap-${index}` }, String(gap)))),
                    ]));
                }
                if (assistantMode === 'action') {
                    const action = Array.isArray(answer.action_drafts) ? answer.action_drafts[0] : null;
                    if (action) {
                        const ready = props.ctx?.operatingQuestionActionIsCurrent?.(
                            exact,
                            action,
                            props.ctx?.operatingQuestionForm || {}
                        ) === true;
                        const steps = Array.isArray(action.execution_steps) ? action.execution_steps.slice(0, 4) : [];
                        children.push(h('section', {
                            class: ['sx-ai-consultant-action-draft', ready ? 'is-ready' : 'is-blocked'],
                            'data-testid': isLatest ? 'system-guide-action-draft' : undefined,
                        }, [
                            h('div', { class: 'sx-ai-consultant-operating-result-title' }, [
                                icon(ready ? 'fa-clipboard-check' : 'fa-shield-alt'),
                                h('strong', String(action.title || '待人工确认的行动草案')),
                                h('span', ready ? '可复核' : '需补证据'),
                            ]),
                            h('p', String(action.action || action.blocked_reason || '当前回答没有形成可复核动作。')),
                            steps.length
                                ? h('ol', steps.map((step, index) => h('li', { key: `action-step-${index}` }, String(step))))
                                : null,
                            h('small', '这里只展示草案；不会自动采集、改价、写 OTA、创建任务或发送消息。'),
                        ].filter(Boolean)));
                    } else {
                        children.push(h('div', { class: 'sx-ai-consultant-gaps' }, [
                            h('strong', '没有可执行草案'),
                            h('p', '当前证据只够形成结论或缺口，尚不足以生成行动草案。'),
                        ]));
                    }
                }
                children.push(h('div', { class: 'sx-ai-consultant-evidence' }, [
                    h('span', `事实 ${Number(evidence.facts || 0)} · 知识 ${Number(evidence.knowledge_chunks || 0)} · 记忆 ${Number(evidence.operating_memories || 0)} · 复盘 ${Number(evidence.execution_reviews || 0)}`),
                    h('span', `已保存并严格回读 #${Number(exact.id || 0)}`),
                ].filter(Boolean)));
                children.push(h('div', { class: 'sx-ai-consultant-recovery-actions' }, [
                    blocked
                        ? h('button', {
                            type: 'button',
                            onClick: (event) => openTopic(event, topicByKey('data-health'), turn),
                        }, [icon('fa-shield-alt'), h('span', '补齐可信事实')])
                        : null,
                    h('button', {
                        type: 'button',
                        'data-testid': isLatest ? 'system-guide-open-operating-workspace' : undefined,
                        onClick: openOperatingWorkspace,
                    }, [icon('fa-arrow-right'), h('span', assistantMode === 'action' ? '到专业页面复核草案' : '查看完整证据与引用')]),
                ].filter(Boolean)));
                return h('section', {
                    class: ['sx-ai-consultant-operating-result', blocked ? 'is-blocked' : 'is-ready'],
                    'data-testid': isLatest ? 'system-guide-operating-result' : undefined,
                }, children);
            };
            const renderTermResult = (guideResult, isLatest = false) => {
                if (String(guideResult?.assistant_mode || '') !== 'term') return null;
                const term = guideResult?.term_result && typeof guideResult.term_result === 'object'
                    ? guideResult.term_result
                    : {};
                return h('section', {
                    class: 'sx-ai-consultant-operating-result is-ready',
                    'data-testid': isLatest ? 'precise-query-term-result' : undefined,
                }, [
                    h('div', { class: 'sx-ai-consultant-operating-result-head' }, [
                        h('span', { class: 'sx-ai-consultant-status' }, 'reference_only'),
                        h('span', '不进入经营事实'),
                    ]),
                    h('p', { class: 'sx-ai-consultant-answer-summary' }, String(term.term || '术语释义')),
                    h('p', String(term.definition || guideResult.assistant_message || '当前没有可核对定义。')),
                    term.source ? h('p', { class: 'mt-2 text-xs text-slate-500' }, `来源：${String(term.source)}`) : null,
                    h('div', { class: 'sx-ai-consultant-evidence' }, [
                        h('span', `知识引用 ${Array.isArray(guideResult?.knowledge_refs) ? guideResult.knowledge_refs.length : 0}`),
                        h('span', `已保存并按编号回读 #${Number(guideResult?.precise_query_id || 0)}`),
                    ]),
                ].filter(Boolean));
            };
            const renderJourney = (result, turn = null, persistent = false) => {
                const journey = normalizeJourney(result?.journey, topicByKey(result?.topic_key));
                if (!journey.length) return null;
                const liveSteps = journey.map((step) => ({
                    ...step,
                    status: guideStepStatus(topicByKey(step.key)),
                }));
                const completedCount = liveSteps.filter((step) => step.status.complete).length;
                const firstIncomplete = liveSteps.find((step) => !step.status.complete);
                const activeKey = String(firstIncomplete?.key || result?.active_key || result?.topic_key || journey[journey.length - 1].key);
                const sourceTurn = turn || {
                    query: String(result?.original_query || ''),
                    result: {
                        ...result,
                        journey,
                    },
                };
                return h('section', {
                    class: ['sx-ai-consultant-journey', persistent ? 'is-persistent' : ''],
                    'data-testid': persistent ? 'system-guide-active-journey' : 'system-guide-journey',
                }, [
                    h('div', { class: 'sx-ai-consultant-journey-header' }, [
                        h('div', [
                            h('span', persistent ? '继续上次任务' : '你的任务路线'),
                            h('strong', {
                                'data-testid': persistent ? undefined : 'system-guide-journey-goal',
                            }, String(result?.goal || result?.intent_summary || journey[0].title)),
                            h('small', { class: 'sx-ai-consultant-journey-progress' }, `已核验 ${completedCount} / ${liveSteps.length}；未核验步骤不会被标成完成。`),
                        ]),
                        persistent
                            ? h('span', { class: 'sx-ai-consultant-journey-progress' },
                                String(result?.scope_type || '') === 'global' ? '全部酒店范围' : '当前酒店范围')
                            : null,
                    ]),
                    persistent ? h('div', {
                        class: 'sx-ai-consultant-gaps',
                        'data-testid': 'system-guide-resume-card-actions',
                    }, [
                        result?.blocker_summary
                            ? h('p', [h('b', '当前阻塞：'), String(result.blocker_summary)])
                            : h('p', '从当前未完成步骤继续；仅打开已有功能入口。'),
                        h('div', { class: 'sx-ai-consultant-suggestions' }, [
                            h('button', {
                                type: 'button',
                                disabled: Boolean(state.value.opening_key || state.value.journey_transition_status),
                                'data-testid': 'system-guide-resume-continue',
                                onClick: resumeActiveJourney,
                            }, '继续处理'),
                            h('button', {
                                type: 'button',
                                disabled: Boolean(state.value.journey_transition_status),
                                'data-testid': 'system-guide-resume-complete',
                                onClick: () => transitionActiveJourney('complete'),
                            }, state.value.journey_transition_status === 'complete' ? '保存中…' : '续办卡已完成'),
                            h('button', {
                                type: 'button',
                                disabled: Boolean(state.value.journey_transition_status),
                                'data-testid': 'system-guide-resume-ignore',
                                onClick: () => transitionActiveJourney('ignore'),
                            }, state.value.journey_transition_status === 'ignore' ? '保存中…' : '不再提醒'),
                        ]),
                        h('small', '“续办卡已完成”只结束个人提醒，不代表酒店经营结果、OTA 操作或审批已经完成。'),
                    ]) : null,
                    h('ol', { class: 'sx-ai-consultant-journey-list' }, liveSteps.map((step, index) => {
                        const topic = topicByKey(step.key);
                        const current = isTopicCurrent(topic);
                        const active = step.key === activeKey;
                        const status = step.status;
                        return h('li', {
                            key: `journey-${step.key}`,
                            class: [active ? 'is-active' : '', current ? 'is-current' : '', `is-${status.key}`],
                            'data-testid': `system-guide-journey-step-${step.key}`,
                        }, [
                            h('span', { class: 'sx-ai-consultant-journey-index', 'aria-hidden': 'true' }, String(index + 1)),
                            h('div', { class: 'sx-ai-consultant-journey-content' }, [
                                h('div', { class: 'sx-ai-consultant-journey-title' }, [
                                    h('strong', String(step.title || topic?.title || '系统步骤')),
                                    h('span', { class: `is-${status.key}` }, String(status.label)),
                                ]),
                                h('p', String(step.summary || topic?.summary || '')),
                                h('p', { class: 'sx-ai-consultant-journey-live-status' }, [
                                    h('b', '当前状态：'),
                                    String(status.detail || '尚未核验。'),
                                ]),
                                h('small', [
                                    h('b', '确认标准：'),
                                    String(step.success_marker || SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS[step.key] || ''),
                                ]),
                                h('button', {
                                    type: 'button',
                                    disabled: !canOpenTopic(topic) || Boolean(state.value.opening_key),
                                    'data-testid': `system-guide-journey-open-${step.key}`,
                                    onClick: (event) => openTopic(event, topic, sourceTurn),
                                }, [
                                    icon(state.value.opening_key === step.key
                                        ? 'fa-spinner fa-spin'
                                        : (status.complete ? 'fa-check' : (current ? 'fa-crosshairs' : 'fa-arrow-right'))),
                                    h('span', current
                                        ? '在页面中指给我看'
                                        : (status.complete ? '重新核对' : String(topic?.action_label || '打开页面'))),
                                ]),
                            ]),
                        ]);
                    })),
                    h('p', { class: 'sx-ai-consultant-journey-note' }, '进度来自当前页面的可读状态与严格回读；仅到达页面不会被算作完成。助手不会虚构页面或在这里写入业务数据。'),
                ]);
            };

            onMounted(() => {
                state.value.active_journey = readActiveJourney();
                readWidgetState();
                void loadLearningContext();
                if (props.openOnMount) widgetOpen.value = true;
                preciseRestoreTimer = window.setTimeout(() => {
                    preciseRestoreTimer = 0;
                    if (String(state.value.error || '').includes('Authentication session changed')) {
                        state.value.error = '';
                    }
                    void restorePreciseQueryReadback();
                }, 900);
                window.addEventListener('resize', handleWidgetViewportResize, { passive: true });
                nextTick(() => {
                    clampWidgetPosition(false);
                    resumePendingCoach();
                });
            });
            window.Vue.watch(() => currentLearningHotelId(), (hotelId, previousHotelId) => {
                if (Number(hotelId || 0) === Number(previousHotelId || 0)) return;
                state.value.learning_context = null;
                state.value.learning_error = '';
                state.value.active_journey = readActiveJourney();
                void loadLearningContext();
            });
            onUnmounted(() => {
                window.removeEventListener('resize', handleWidgetViewportResize);
                if (resizeFrame) window.cancelAnimationFrame(resizeFrame);
                if (preciseRestoreTimer) window.clearTimeout(preciseRestoreTimer);
                clearTopicCoach();
            });

            return () => {
                const conversation = [];
                if (state.value.turns.length === 0
                    && !state.value.active_journey
                    && !state.value.error
                    && !state.value.loading) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-welcome' }, [
                        h('div', { class: 'sx-ai-consultant-message-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                        h('div', [
                            h('strong', '直接查数、问缺失原因、找功能或查术语。'),
                            h('p', '例如“Hotel 80 8月23日美团曝光多少”“可信播报怎么复制”。数值只读数据库，不让模型自由生成。'),
                        ]),
                    ]));
                }
                if (state.value.error) {
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-error',
                        role: 'alert',
                        'data-testid': 'system-guide-error',
                    }, [icon('fa-exclamation-circle'), h('span', String(state.value.error))]));
                }
                state.value.turns.forEach((turn, index) => {
                    const result = turn.result || {};
                    const topic = topicByKey(result.topic_key);
                    const available = topic ? canOpenTopic(topic) : false;
                    const isLatest = index === state.value.turns.length - 1;
                    const assistantMode = String(result.assistant_mode || 'guide');
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-user-message',
                        key: `${turn.id}-user`,
                    }, [h('p', String(turn.query || ''))]));
                    const answerChildren = [
                        h('div', { class: 'sx-ai-consultant-answer-meta' }, [
                            h('span', {
                                class: ['sx-ai-consultant-status', result.mode === 'fallback' ? 'is-blocked' : ''],
                            }, runtimeText(result)),
                            h('span', {
                                class: `sx-ai-consultant-mode-badge is-${assistantMode}`,
                                'data-testid': isLatest ? 'system-guide-mode' : undefined,
                            }, String(SYSTEM_ASSISTANT_MODE_LABELS[assistantMode] || '使用指导')),
                        ]),
                        h('p', {
                            class: 'sx-ai-consultant-answer-summary',
                        }, String(result.topic?.title || (result.topic_key === 'clarify' ? '先确认你的目标' : '系统使用引导'))),
                        h('p', String(result.assistant_message || '')),
                    ];
                    const personalizationReceipt = renderPersonalizationReceipt(result, isLatest);
                    if (personalizationReceipt) answerChildren.push(personalizationReceipt);
                    const operatingResult = renderOperatingResult(result, turn, isLatest);
                    if (operatingResult) answerChildren.push(operatingResult);
                    const termResult = renderTermResult(result, isLatest);
                    if (termResult) answerChildren.push(termResult);
                    const journeyCard = ['operating_query', 'term_definition'].includes(String(result.route_type || ''))
                        ? null
                        : renderJourney(result, turn);
                    if (journeyCard) answerChildren.push(journeyCard);
                    if (result.clarifying_question) {
                        answerChildren.push(h('div', {
                            class: 'sx-ai-consultant-gaps',
                            'data-testid': isLatest ? 'system-guide-clarifying-question' : undefined,
                        }, [
                            h('strong', '我需要确认一件事'),
                            h('p', String(result.clarifying_question)),
                        ]));
                    }
                    if (Array.isArray(result.steps) && result.steps.length) {
                        answerChildren.push(h('div', { class: 'sx-ai-consultant-step-guide' }, [
                            h('strong', '当前这一步怎么做'),
                            h('ol', { class: 'sx-ai-consultant-key-points' }, result.steps.map((step, stepIndex) => (
                                h('li', { key: `${turn.id}-step-${stepIndex}` }, `${stepIndex + 1}. ${String(step)}`)
                            ))),
                        ]));
                    }
                    if (result.boundary) {
                        answerChildren.push(h('div', { class: 'sx-ai-consultant-gaps' }, [
                            h('strong', '操作边界'),
                            h('p', String(result.boundary)),
                        ]));
                    }
                    if (topic && !journeyCard && !['operating_query', 'term_definition'].includes(String(result.route_type || ''))) {
                        answerChildren.push(h('section', { class: 'sx-ai-consultant-recovery' }, [
                            h('div', { class: 'sx-ai-consultant-recovery-title' }, [
                                icon('fa-location-arrow'),
                                h('strong', available ? '下一步直接进入' : '当前账号暂无入口'),
                            ]),
                            h('div', { class: 'sx-ai-consultant-recovery-actions' }, [
                                h('button', {
                                    type: 'button',
                                    disabled: !available || Boolean(state.value.opening_key),
                                    'data-testid': isLatest
                                        ? `system-guide-open-${String(topic.key)}`
                                        : `system-guide-open-history-${index}-${String(topic.key)}`,
                                    onClick: (event) => openTopic(event, topic, turn),
                                }, [
                                    icon(state.value.opening_key === topic.key ? 'fa-spinner fa-spin' : 'fa-arrow-right'),
                                    h('span', available ? String(topic.action_label || '打开页面') : '请联系管理员授权'),
                                ]),
                            ]),
                            h('small', '导航目标来自当前账号可用的系统功能；这里只带路，不会写入业务数据。'),
                        ]));
                    }
                    const followUps = Array.isArray(result.follow_up_questions)
                        ? result.follow_up_questions.slice(0, 3).filter(Boolean)
                        : [];
                    if (followUps.length) {
                        answerChildren.push(h('div', { class: 'sx-ai-consultant-follow-ups' }, [
                            h('span', '可以接着问'),
                            ...followUps.map((question) => h('button', {
                                key: `${turn.id}-${String(question)}`,
                                type: 'button',
                                onClick: () => applyFollowUp(question),
                            }, String(question))),
                        ]));
                    }
                    const preciseQueryId = Number(result.precise_query_id || 0);
                    if (isLatest && preciseQueryId > 0) {
                        const savedFeedback = String(state.value.feedback_status?.[preciseQueryId] || '');
                        const feedbackOptions = [
                            ['accepted', 'useful', '有用'],
                            ['rejected', 'wrong_focus', '重点不对'],
                            ['modified', 'too_long', '太长'],
                            ['needs_more_evidence', 'more_evidence', '证据不够'],
                            ['deferred', 'not_now', '暂不需要'],
                        ];
                        answerChildren.push(h('div', {
                            class: 'sx-ai-consultant-gaps',
                            'data-testid': 'system-guide-feedback',
                        }, [
                            h('strong', '这次建议是否有帮助？'),
                            h('div', { class: 'sx-ai-consultant-suggestions' }, feedbackOptions.map(([status, reason, label]) => h('button', {
                                key: reason,
                                type: 'button',
                                disabled: savedFeedback === 'saving'
                                    || Boolean(savedFeedback && savedFeedback !== 'error'),
                                class: savedFeedback === reason ? 'is-active' : '',
                                'data-testid': `system-guide-feedback-${reason}`,
                                onClick: () => submitSuggestionFeedback(result, status, reason),
                            }, savedFeedback === 'saving' ? '保存中…' : label))),
                            savedFeedback && !['saving', 'error'].includes(savedFeedback)
                                ? h('small', '反馈已保存并精确回读；它只用于个人偏好候选和离线质量评测。')
                                : null,
                        ]));
                    }
                    answerChildren.push(h('div', { class: 'sx-ai-consultant-evidence' }, [
                        h('span', `当前页面：${currentPageText()}`),
                        h('span', Number(result.precise_query_id || 0) > 0
                            ? `统一路由已保存并按编号回读 #${Number(result.precise_query_id || 0)}`
                            : (result.mode === 'intelligent'
                                ? (assistantMode === 'guide' ? '已按目标生成系统路径 · 入口权限已核对' : '目标已识别 · 结论使用严格证据回读')
                                : '智能理解暂时不可用 · 已切换基础引导')),
                    ]));
                    conversation.push(h('article', {
                        key: `${turn.id}-answer`,
                        class: 'sx-ai-consultant-answer',
                        'data-testid': isLatest ? 'system-guide-result' : `system-guide-result-history-${index}`,
                    }, answerChildren));
                });
                if (state.value.loading) {
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-loading',
                        role: 'status',
                        'data-testid': 'system-guide-loading',
                    }, [
                        icon('fa-spinner fa-spin'),
                        h('span', '正在理解目标并核对可用入口…'),
                    ]));
                }

                const modeSwitcher = h('div', {
                    class: 'sx-ai-consultant-mode-switcher',
                    role: 'group',
                    'aria-label': '助手工作方式',
                    'data-testid': 'system-guide-mode-switcher',
                }, SYSTEM_ASSISTANT_MODE_OPTIONS.map((option) => h('button', {
                    key: option.key,
                    type: 'button',
                    class: state.value.selected_mode === option.key ? 'is-active' : '',
                    disabled: state.value.loading,
                    'aria-pressed': state.value.selected_mode === option.key ? 'true' : 'false',
                    'data-testid': `system-guide-mode-${option.key}`,
                    'data-role-key': option.key === 'report' ? 'hotel_data_analyst' : undefined,
                    onClick: () => setAssistantMode(option.key),
                }, [icon(option.icon), h('span', option.label)])));
                const suggestions = h('div', {
                    class: 'sx-ai-consultant-suggestions',
                    'aria-label': '常用系统任务',
                }, suggestionItems().map((item) => h('button', {
                    key: item.key,
                    type: 'button',
                    disabled: state.value.loading,
                    'data-topic-key': item.topic_key || undefined,
                    'data-feedback-adjustment': Number(item.feedback_adjustment || 0),
                    title: Number(item.feedback_adjustment || 0) > 0
                        ? '根据当前用户、当前酒店至少 20 次同类反馈，排在同类快捷入口前面。'
                        : undefined,
                    onClick: () => applySuggestion(item),
                }, Number(item.feedback_adjustment || 0) > 0
                    ? `${String(item.label || item.query)} · 更常用`
                    : String(item.label || item.query))));
                const learningContext = state.value.learning_context;
                const calibration = learningContext?.calibration || {};
                const activePreferences = consumablePreferences();
                const candidates = candidatePreferences();
                const readyCandidates = readyCandidatePreferences();
                const feedbackCounts = calibration?.counts || {};
                const feedbackCount = feedbackCounts.feedback_sample_count !== null
                    && feedbackCounts.feedback_sample_count !== undefined
                    && feedbackCounts.feedback_sample_count !== ''
                    && Number.isFinite(Number(feedbackCounts.feedback_sample_count))
                        ? Number(feedbackCounts.feedback_sample_count)
                        : null;
                const feedbackRanking = calibration?.feedback_ranking || {};
                const rankingItems = Array.isArray(feedbackRanking.items) ? feedbackRanking.items : [];
                const rankingSampleCount = rankingItems.reduce(
                    (maximum, item) => Math.max(maximum, Number(item?.sample_count || 0)),
                    0
                );
                const preferenceChoices = [
                    ['response_detail', 'concise', '回答简洁'],
                    ['response_detail', 'detailed', '步骤详细'],
                    ['preferred_platform', 'ctrip', '每日重点优先携程'],
                    ['preferred_platform', 'meituan', '每日重点优先美团'],
                    ['preferred_platform', 'all_ota', '每日重点不偏单平台'],
                ];
                const preferencePanel = h('section', {
                    class: 'sx-ai-consultant-gaps',
                    'data-testid': 'system-guide-learning-memory',
                }, [
                    h('div', { class: 'sx-ai-consultant-answer-meta' }, [
                        h('strong', '学习中心'),
                        h('span', state.value.learning_loading && !learningContext
                            ? '正在读取学习上下文…'
                            : (!learningContext
                                ? (state.value.learning_error ? '学习上下文读取失败' : '学习上下文未取得')
                                : `记忆 ${activePreferences.length} · 候选 ${readyCandidates.length} · 反馈 ${feedbackCount === null ? '未取得' : feedbackCount} · 续办 ${state.value.active_journey ? 1 : 0}`)),
                        h('button', {
                            type: 'button',
                            disabled: state.value.learning_loading || !learningContext,
                            'aria-expanded': state.value.learning_open ? 'true' : 'false',
                            'data-testid': 'system-guide-learning-toggle',
                            onClick: () => { state.value.learning_open = !state.value.learning_open; },
                        }, state.value.learning_open ? '收起' : '查看与修改'),
                    ]),
                    h('small', '系统只从明确确认和结构化反馈中学习；不会改变事实、权限、审批或真实经营动作。'),
                    state.value.learning_open ? h('div', { 'data-testid': 'system-guide-learning-center' }, [
                        h('section', { 'data-testid': 'system-guide-learning-confirmed' }, [
                            h('strong', '已确认记忆'),
                            h('div', {
                                class: 'sx-ai-consultant-suggestions',
                                role: 'group',
                                'aria-label': '已确认的回答与每日重点偏好',
                            }, preferenceChoices.map(([key, value, label]) => {
                                const active = activePreferences.some((item) => (
                                    item.preference_key === key && String(item.value) === value
                                ));
                                return h('button', {
                                    key: `${key}:${value}`,
                                    type: 'button',
                                    disabled: Boolean(state.value.preference_saving_key),
                                    class: active ? 'is-active' : '',
                                    'aria-pressed': active ? 'true' : 'false',
                                    title: active ? `${label}（当前已选择）` : label,
                                    'data-testid': `system-guide-preference-${key}-${value}`,
                                    onClick: () => savePreference(key, value),
                                }, state.value.preference_saving_key === key ? '保存中…' : label);
                            })),
                        activePreferences.length
                            ? h('ul', { class: 'sx-ai-consultant-key-points' }, activePreferences.map((item) => h('li', {
                                key: `${item.preference_key}:${item.id}`,
                            }, [
                                h('span', `${preferenceValueText(item)} · ${preferenceScopeText(item)} · ${preferenceSourceText(item)}`),
                                h('button', {
                                    type: 'button',
                                    disabled: Boolean(state.value.preference_saving_key),
                                    'data-testid': `system-guide-preference-revoke-${String(item.preference_key)}`,
                                    onClick: () => revokePreference(item),
                                }, '撤销'),
                            ])))
                            : h('p', '尚未确认长期偏好；单次点击不会被偷偷当成永久画像。'),
                        ]),
                        h('section', { 'data-testid': 'system-guide-learning-candidates' }, [
                            h('strong', '待确认候选'),
                            candidates.length
                                ? h('ul', { class: 'sx-ai-consultant-key-points' }, candidates.map((item) => {
                                    const count = Number(item?.source_context?.signal_count || 0);
                                    const minimum = Number(learningContext?.learning_policy?.candidate_minimum_repeated_signals || 3);
                                    const ready = String(item?.learning_status || '') === 'inferred';
                                    return h('li', { key: `candidate:${item.preference_key}:${item.id}` }, [
                                        h('span', `${preferenceValueText(item)} · ${ready ? '待你确认，尚未应用' : `观察中 ${count}/${minimum}`}`),
                                        ready ? h('div', { class: 'sx-ai-consultant-suggestions' }, [
                                            h('button', {
                                                type: 'button',
                                                disabled: Boolean(state.value.preference_saving_key),
                                                'data-testid': `system-guide-candidate-confirm-${String(item.preference_key)}`,
                                                onClick: () => savePreference(
                                                    String(item.preference_key || ''),
                                                    String(item.value || ''),
                                                    String(item.scope || 'global')
                                                ),
                                            }, '确认采用'),
                                            h('button', {
                                                type: 'button',
                                                disabled: Boolean(state.value.preference_saving_key),
                                                'data-testid': `system-guide-candidate-dismiss-${String(item.preference_key)}`,
                                                onClick: () => revokePreference(item),
                                            }, '忽略候选'),
                                        ]) : null,
                                    ]);
                                }))
                                : h('p', '暂无待确认候选；重复行为不会自动变成长期偏好。'),
                        ]),
                        h('section', { 'data-testid': 'system-guide-learning-calibration' }, [
                            h('strong', '反馈与调优'),
                            h('p', `反馈 ${feedbackCount} 条 · 有用 ${Number(feedbackCounts.accepted || 0)} · 修改 ${Number(feedbackCounts.modified || 0)} · 重点不对 ${Number(feedbackCounts.rejected || 0)} · 证据不足 ${Number(feedbackCounts.needs_more_evidence || 0)}`),
                            h('small', feedbackRanking.status === 'ready'
                                ? '同类反馈已达到每个入口至少 20 条，只在原有快捷入口之间调整先后。'
                                : `快捷入口排序仍在积累同类样本（最多 ${rankingSampleCount}/${Number(feedbackRanking.minimum_samples_per_topic || 20)}）；当前保持原顺序。`),
                        ]),
                        h('section', { 'data-testid': 'system-guide-learning-journey' }, [
                            h('strong', '任务续办'),
                            state.value.active_journey
                                ? h('div', [
                                    h('p', String(state.value.active_journey.goal || '当前未完成事项')),
                                    h('small', state.value.active_journey.blocker_summary
                                        ? `当前阻塞：${String(state.value.active_journey.blocker_summary)}`
                                        : '可从当前未完成步骤继续。'),
                                    h('div', { class: 'sx-ai-consultant-suggestions' }, [
                                        h('button', {
                                            type: 'button',
                                            disabled: Boolean(state.value.opening_key || state.value.journey_transition_status),
                                            'data-testid': 'system-guide-learning-journey-continue',
                                            onClick: resumeActiveJourney,
                                        }, '继续当前任务'),
                                        h('button', {
                                            type: 'button',
                                            disabled: Boolean(state.value.journey_transition_status),
                                            'data-testid': 'system-guide-learning-journey-ignore',
                                            onClick: () => transitionActiveJourney('ignore'),
                                        }, '不再提醒'),
                                    ]),
                                ])
                                : h('p', '当前没有需要跨会话续办的事项。'),
                        ]),
                        h('section', { 'data-testid': 'system-guide-learning-management' }, [
                            h('strong', '管理'),
                        h('div', { class: 'sx-ai-consultant-suggestions' }, [
                            h('button', {
                                type: 'button',
                                disabled: (!activePreferences.length && !candidates.length)
                                    || Boolean(state.value.preference_saving_key),
                                'data-testid': 'system-guide-preference-reset',
                                onClick: resetPreferences,
                            }, '重置全局和当前酒店偏好'),
                        ]),
                        h('small', '不会保存密码、Cookie、验证码或原始聊天；当前这次明确要求始终覆盖历史偏好。'),
                        ]),
                    ]) : null,
                    state.value.learning_error
                        ? h('p', { class: 'sx-ai-consultant-error', 'data-testid': 'system-guide-learning-error' }, state.value.learning_error)
                        : null,
                ]);
                const composer = h('form', {
                    class: 'sx-ai-consultant-composer',
                    onSubmit: (event) => {
                        event?.preventDefault?.();
                        ask();
                    },
                }, [
                    h('textarea', {
                        value: String(state.value.query || ''),
                        maxlength: 500,
                        rows: 2,
                        disabled: state.value.loading,
                        placeholder: state.value.selected_mode === 'report'
                            ? '例如：给我这家酒店今天携程经营情况的结论和证据缺口'
                            : (state.value.selected_mode === 'action'
                                ? '例如：根据今天的可信事实，帮我生成待人工确认的行动草案'
                                : '例如：Hotel 80 8月23日美团曝光多少？'),
                        'data-testid': 'system-guide-input',
                        onInput: (event) => {
                            state.value.query = String(event?.target?.value || '');
                            state.value.error = '';
                        },
                        onKeydown: (event) => {
                            if (event?.key !== 'Enter' || event.shiftKey || event.isComposing) return;
                            event.preventDefault();
                            ask();
                        },
                    }),
                    h('button', {
                        type: 'submit',
                        disabled: state.value.loading || !String(state.value.query || '').trim(),
                        'data-testid': 'system-guide-submit',
                        'aria-label': '提交宿析精准查数',
                    }, [icon(state.value.loading ? 'fa-spinner fa-spin' : 'fa-arrow-up')]),
                    h('p', state.value.selected_mode === 'guide'
                        ? '页面内教用 + 真实状态核验'
                        : '结论与行动草案基于同酒店、同平台、同日期的严格保存回读事实'),
                ]);

                const coach = state.value.coach;
                const coachTopic = topicByKey(coach?.topic_key);
                const coachStatus = guideStepStatus(coachTopic);
                const coachCard = coach
                    ? h('aside', {
                        class: ['sx-system-guide-coach', coach.target_found ? 'is-found' : 'is-blocked'],
                        role: 'status',
                        'data-testid': 'system-guide-page-coach',
                    }, [
                        h('div', { class: 'sx-system-guide-coach-head' }, [
                            h('span', { class: 'sx-system-guide-coach-icon', 'aria-hidden': 'true' }, [icon(coach.checking ? 'fa-spinner fa-spin' : (coach.target_found ? 'fa-crosshairs' : 'fa-circle-exclamation'))]),
                            h('div', [
                                h('small', coach.target_found ? '我在页面里指给你看' : '页面区域尚未就绪'),
                                h('strong', String(coachTopic?.title || '当前操作区域')),
                            ]),
                            h('button', { type: 'button', 'aria-label': '关闭页面指引', onClick: clearTopicCoach }, [icon('fa-xmark')]),
                        ]),
                        h('p', coach.checking
                            ? '正在等待目标区域加载…'
                            : (coach.target_found
                                ? String(coachStatus.detail || '已高亮当前需要核对的区域。')
                                : '目标区域没有加载出来。请先确认当前账号权限、酒店范围和页面接口状态。')),
                        h('div', { class: 'sx-system-guide-coach-actions' }, [
                            h('span', { class: `is-${coachStatus.key}` }, String(coachStatus.label)),
                            h('button', { type: 'button', onClick: () => focusTopicAnchor(coachTopic) }, [
                                icon('fa-rotate'), h('span', '重新检查'),
                            ]),
                            h('button', { type: 'button', onClick: () => { widgetOpen.value = true; if (widgetRoot.value) widgetRoot.value.open = true; clearTopicCoach(); } }, [
                                icon('fa-comment-dots'), h('span', '打开助手'),
                            ]),
                        ]),
                    ])
                    : null;

                return h('div', { class: 'sx-ai-consultant-shell' }, [h('details', {
                    ref: widgetRoot,
                    class: ['sx-ai-consultant', widgetDragging.value ? 'is-dragging' : ''],
                    open: widgetOpen.value,
                    style: widgetStyle.value,
                    'data-testid': 'system-guide-floating-entry',
                    onToggle: handleWidgetToggle,
                }, [
                    h('summary', {
                        class: 'sx-ai-consultant-launcher',
                        'data-testid': 'system-guide-floating-launcher',
                        'aria-label': widgetOpen.value ? '收起宿析精准查数' : '打开宿析精准查数',
                        title: widgetOpen.value ? '收起为悬浮按钮' : '打开宿析精准查数；按住可移动',
                        onPointerdown: (event) => startWidgetDrag(event, 'launcher'),
                        onPointermove: moveWidgetDrag,
                        onPointerup: endWidgetDrag,
                        onPointercancel: endWidgetDrag,
                        onClick: handleLauncherClick,
                    }, [
                        h('span', { class: 'sx-ai-consultant-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                        h('span', { class: 'sx-ai-consultant-launcher-label' }, '精准查数'),
                        h('span', { class: 'sx-ai-consultant-close' }, [
                            icon('fa-chevron-down'),
                            h('span', '收起'),
                        ]),
                    ]),
                    h('section', {
                        class: 'sx-ai-consultant-panel',
                        role: 'dialog',
                        'aria-label': '宿析精准查数统一入口',
                        'data-testid': 'system-guide-floating-panel',
                    }, [
                        h('header', {
                            class: 'sx-ai-consultant-header',
                            'data-testid': 'system-guide-drag-handle',
                            title: '按住并拖动移动助手',
                            onPointerdown: (event) => startWidgetDrag(event, 'panel'),
                            onPointermove: moveWidgetDrag,
                            onPointerup: endWidgetDrag,
                            onPointercancel: endWidgetDrag,
                        }, [
                            h('div', { class: 'sx-ai-consultant-header-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                            h('div', { class: 'sx-ai-consultant-header-copy' }, [
                                h('div', { class: 'sx-ai-consultant-title' }, '宿析精准查数'),
                                h('p', '查经营事实 · 解释缺失 · 找功能 · 查术语'),
                            ]),
                            h('span', { class: 'sx-ai-consultant-drag-hint', 'aria-hidden': 'true' }, [
                                icon('fa-grip-lines'),
                                h('span', '拖动'),
                            ]),
                        ]),
                        h('div', { class: 'sx-ai-consultant-body' }, [
                            h('div', {
                                class: 'sx-ai-consultant-context',
                                'data-testid': 'system-guide-context',
                            }, [
                                icon('fa-location-dot'),
                                h('span', [
                                    h('small', '当前页面'),
                                    h('strong', currentPageText()),
                                ]),
                                h('em', state.value.active_journey ? '任务路线已保留' : `可引导 ${visibleTopicKeys().length} 项功能`),
                            ]),
                            h('p', { class: 'sx-ai-consultant-boundary' }, '只引导当前账号可用的真实功能，并跨页面保留任务路线。涉及结论、执行或外发时，仍以严格回读和人工确认为准。'),
                            preferencePanel,
                            modeSwitcher,
                            state.value.turns.length === 0 && state.value.active_journey
                                ? renderJourney(state.value.active_journey, null, true)
                                : null,
                            suggestions,
                            h('div', {
                                class: 'sx-ai-consultant-conversation',
                                'aria-live': 'polite',
                                'aria-atomic': 'false',
                            }, conversation),
                        ]),
                        composer,
                    ]),
                ]), coachCard]);
            };
        },
    };

        return Object.freeze({ operatingQuestionPanel, operatingQuestionConsultant, hotelDataAnalystProfile });
    };

    const exportedFactory = Object.freeze({ create });
    window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL = exportedFactory;
    if (!window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS) {
        window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS = exportedFactory;
    }
})();
