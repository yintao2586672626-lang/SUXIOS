(() => {
    'use strict';

    const fullScript = 'components/system/operating-intelligence-components.js?v=20260830-review-fixes-he57e00d407';
    const analystScript = 'components/system/hotel-data-analyst-components.js?v=20260830-h7350d7b13d';
    const fullStyle = 'style.min.css';
    let fullScriptPromise = null;
    let analystScriptPromise = null;

    const loadAnalystScript = async () => {
        if (window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS?.create) {
            return window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS;
        }
        if (analystScriptPromise) return analystScriptPromise;
        analystScriptPromise = new Promise((resolve, reject) => {
            const resolvedSrc = new URL(analystScript, document.baseURI).href;
            const existing = [...document.scripts].find(script => script.src === resolvedSrc);
            const script = existing || document.createElement('script');
            const finish = () => {
                const factory = window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS;
                if (factory?.create) {
                    script.dataset.suxiAssetLoaded = '1';
                    resolve(factory);
                    return;
                }
                analystScriptPromise = null;
                script.remove();
                reject(new Error('酒店数据分析师组件未完成注册'));
            };
            if (existing?.dataset?.suxiAssetLoaded === '1') {
                finish();
                return;
            }
            script.src = analystScript;
            script.async = true;
            script.dataset.suxiHotelDataAnalyst = analystScript;
            script.addEventListener('load', finish, { once: true });
            script.addEventListener('error', () => {
                analystScriptPromise = null;
                script.remove();
                reject(new Error('酒店数据分析师组件加载失败'));
            }, { once: true });
            if (!existing) document.head.appendChild(script);
        });
        analystScriptPromise.catch(() => {
            analystScriptPromise = null;
        });
        return analystScriptPromise;
    };

    const loadFullScript = async () => {
        if (window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL?.create) {
            return window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL;
        }
        if (fullScriptPromise) return fullScriptPromise;
        const startScriptLoad = () => new Promise((resolve, reject) => {
            const resolvedSrc = new URL(fullScript, document.baseURI).href;
            const existing = [...document.scripts].find(script => script.src === resolvedSrc);
            const script = existing || document.createElement('script');
            const finish = () => {
                const factory = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL;
                if (factory?.create) {
                    script.dataset.suxiAssetLoaded = '1';
                    resolve(factory);
                    return;
                }
                fullScriptPromise = null;
                script.remove();
                reject(new Error('经营问答完整组件未完成注册'));
            };
            if (existing?.dataset?.suxiAssetLoaded === '1') {
                finish();
                return;
            }
            script.src = fullScript;
            script.async = true;
            script.dataset.suxiOperatingIntelligence = fullScript;
            script.addEventListener('load', finish, { once: true });
            script.addEventListener('error', () => {
                fullScriptPromise = null;
                script.remove();
                reject(new Error('经营问答完整组件加载失败'));
            }, { once: true });
            if (!existing) document.head.appendChild(script);
        });
        const styleLoader = window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET;
        const styleReady = typeof styleLoader === 'function'
            ? Promise.resolve(styleLoader(fullStyle))
            : Promise.resolve();
        fullScriptPromise = styleReady.then(loadAnalystScript).then(startScriptLoad);
        fullScriptPromise.catch(() => {
            fullScriptPromise = null;
        });
        return fullScriptPromise;
    };
    const requestFullComponents = () => {
        void loadFullScript().catch(() => {});
    };
    const councilTerminalStatuses = new Set([
        'completed', 'partial', 'failed', 'blocked_by_missing_facts', 'blocked_not_configured',
    ]);
    const councilReadbackIntegrityMatches = (exact) => {
        const status = String(exact?.status || '');
        const synthesis = exact?.synthesis || {};
        const members = Array.isArray(exact?.members) ? exact.members : [];
        const evidenceRefs = Array.isArray(exact?.evidence_refs) ? exact.evidence_refs : [];
        const modelMeta = Array.isArray(exact?.model_meta) ? exact.model_meta : [];
        const quarantine = synthesis.quarantine || null;
        if (quarantine) return quarantine.content_retained === false
            && members.length === 0 && evidenceRefs.length === 0 && modelMeta.length === 0;
        if (!['completed', 'partial'].includes(status)) return true;
        const integrity = synthesis.artifact_integrity || {};
        const panelDigest = String(synthesis.advisory_panel_contract_digest || '');
        const lenses = Array.isArray(synthesis.selected_lenses) ? synthesis.selected_lenses : [];
        const lensDigests = new Map(lenses.map(lens => [String(lens?.key || ''), String(lens?.contract_digest || '')]));
        return integrity.status === 'verified'
            && /^[a-f0-9]{64}$/.test(panelDigest)
            && String(integrity.panel_contract_digest || '') === panelDigest
            && Number(integrity.member_count || 0) === members.length
            && Number(integrity.evidence_ref_count || 0) === evidenceRefs.length
            && Number(integrity.model_meta_count || 0) === modelMeta.length
            && /^[a-f0-9]{64}$/.test(String(integrity.members_digest || ''))
            && /^[a-f0-9]{64}$/.test(String(integrity.evidence_refs_digest || ''))
            && members.every(member => String(member?.panel_contract_digest || '') === panelDigest
                && String(member?.lens_contract_digest || '') === lensDigests.get(String(member?.key || '')));
    };
    const assertWorkerReceipt = (saved) => {
        const receipt = saved?.worker_receipt || {};
        const parentDigest = String(receipt.parent_digest || '');
        const generation = Number(receipt.lease_generation || 0);
        const dispatchAttemptId = String(receipt.dispatch_attempt_id || '');
        const exitCode = receipt.exit_code == null ? null : Number(receipt.exit_code);
        const cleanExit = exitCode == null || exitCode === 0;
        const identityMatches = /^[a-f0-9]{64}$/.test(parentDigest)
            && parentDigest === String(saved?.dispatch_parent_digest || '')
            && generation > 0
            && generation === Number(saved?.expected_lease_generation || 0)
            && /^[a-f0-9]{32}$/.test(dispatchAttemptId)
            && dispatchAttemptId === String(saved?.dispatch_attempt_id || '')
            && receipt.persisted === true;
        const acknowledged = receipt.status === 'acknowledged'
            && receipt.acknowledged === true
            && saved?.worker_dispatched === true
            && identityMatches
            && cleanExit;
        const alreadyRunning = receipt.status === 'already_running'
            && receipt.acknowledged === false
            && receipt.existing_active_worker === true
            && saved?.worker_dispatched === false
            && identityMatches
            && cleanExit;
        const terminalObserved = receipt.status === 'terminal_observed'
            && receipt.acknowledged === false
            && saved?.worker_dispatched === false
            && receipt.persisted === true
            && councilTerminalStatuses.has(String(saved?.status || ''));
        if (saved?.accepted !== true
            || saved?.persistence_status !== 'readback_verified'
            || (!acknowledged && !alreadyRunning && !terminalObserved)
        ) throw new Error(saved?.synthesis?.summary || '经营顾问会诊 worker 未返回匹配本次派发的数据库启动回执');
        return saved;
    };
    const submitCouncilRun = async ({ questionId, clientRunKey, state, request, isCurrent }) => {
        const currentStatus = String(state?.council_run?.status || '');
        const currentQuestionMatches = Number(state?.council_run?.question_id || 0) === Number(questionId || 0);
        if (currentQuestionMatches && ['pending', 'running'].includes(currentStatus)) {
            return {
                saved: state.council_run,
                resumed: false,
                reusedActive: true,
                pollOnly: true,
            };
        }
        if (currentQuestionMatches
            && String(state?.council_run?.synthesis?.error_code || '') === 'council_terminal_fact_drift'
        ) {
            throw new Error('严格事实已变化，请重新生成上游事实或经营问题后再发起会诊。');
        }
        const resumable = [
            'partial', 'failed', 'blocked_by_missing_facts', 'blocked_not_configured',
        ].includes(String(state?.council_run?.status || ''))
            && Number(state?.council_run?.question_id || 0) === Number(questionId || 0);
        const url = resumable
            ? `/agent/operating-questions/${questionId}/council-runs/${Number(state.council_run.id || 0)}/resume`
            : `/agent/operating-questions/${questionId}/council-runs`;
        const options = resumable
            ? { method: 'POST' }
            : { method: 'POST', body: JSON.stringify({ client_run_key: clientRunKey }) };
        const response = await request(url, options);
        if (!isCurrent()) return null;
        if (response.code !== 200 || !response.data) throw new Error(response.message || '经营顾问会诊提交失败');
        const saved = assertWorkerReceipt(response.data);
        return {
            saved,
            resumed: resumable,
            reusedActive: saved?.reused_active === true,
            pollOnly: false,
        };
    };
    const pollCouncilRun = async ({ exact, questionId, runId, requestKey, state, request, matches, isCurrent }) => {
        if (typeof request !== 'function' || typeof matches !== 'function' || typeof isCurrent !== 'function') {
            throw new Error('经营顾问会诊轮询依赖未就绪');
        }
        const deadline = Date.now() + (10 * 60 * 1000);
        let attempt = 0;
        let current = exact;
        let lastDigest = String(current?.content_digest || '');
        let unchangedSince = Date.now();
        while (Date.now() < deadline) {
            if (!isCurrent()) return null;
            if (councilTerminalStatuses.has(String(current?.status || ''))) return isCurrent() ? current : null;
            if (String(current?.synthesis?.worker?.status || '') === 'dispatch_failed') {
                throw new Error('经营顾问会诊已保留，但本机后台 worker 未启动，请稍后重试。');
            }
            const delay = attempt < 5 ? 1000 : (attempt < 20 ? 2000 : 5000);
            await new Promise(resolve => window.setTimeout(resolve, delay));
            if (!isCurrent()) return null;
            const response = await request(`/agent/operating-questions/${questionId}/council-runs/${runId}`);
            if (!isCurrent()) return null;
            if (response.code !== 200 || !response.data) {
                throw new Error(response.message || '经营顾问会诊轮询回读失败');
            }
            current = response.data;
            if (!matches(current, questionId, Number(state?.result?.hotel_id || 0))
                || Number(current.id || 0) !== Number(runId || 0)
                || String(current.request_key || '') !== String(requestKey || '')
            ) throw new Error('经营顾问会诊 checkpoint 身份或范围回读不一致');
            if (!isCurrent()) return null;
            state.council_run = current;
            const digest = String(current.content_digest || '');
            if (digest !== lastDigest) {
                lastDigest = digest;
                unchangedSince = Date.now();
            } else if (Date.now() - unchangedSince >= 150000) {
                const resume = await request(`/agent/operating-questions/${questionId}/council-runs`, {
                    method: 'POST',
                    body: JSON.stringify({ client_run_key: String(requestKey || '').replace(/^council:/, '') }),
                });
                if (!isCurrent()) return null;
                if (resume.code !== 200
                    || Number(resume.data?.id || 0) !== Number(runId || 0)
                    || String(resume.data?.request_key || '') !== String(requestKey || '')
                ) throw new Error('经营顾问会诊停滞恢复派发失败');
                const resumed = assertWorkerReceipt(resume.data);
                if (String(resumed.content_digest || '') !== digest) unchangedSince = Date.now();
            }
            attempt += 1;
        }
        if (!isCurrent()) return null;
        state.council_error = '经营顾问会诊仍在后台运行；已停止前端轮询，稍后可刷新回读。';
        return isCurrent() ? state.council_run : null;
    };

    const create = (createOptions) => {
        const { h, Vue } = createOptions;
        if (!Vue?.defineAsyncComponent) throw new Error('经营问答启动桥缺少 Vue 运行时');
        let fullComponents = null;
        const loadComponent = key => loadFullScript().then(factory => {
            fullComponents ||= factory.create(createOptions);
            const component = fullComponents[key];
            if (!component) throw new Error(`经营问答完整组件缺少：${key}`);
            return component;
        });
        const buildLazyComponent = (key, loadingComponent) => Vue.defineAsyncComponent({
            loader: () => loadComponent(key),
            delay: 0,
            loadingComponent,
            errorComponent: {
                inheritAttrs: false,
                render: () => h('div', {
                    class: 'rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700',
                    'data-testid': 'operating-intelligence-load-error',
                }, '经营助手加载失败，请刷新页面重试。'),
            },
        });
        const panelLoading = {
            inheritAttrs: false,
            render: () => h('button', {
                type: 'button',
                class: 'w-full rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-700',
                'data-testid': 'operating-question-panel-load',
                onClick: requestFullComponents,
            }, '加载经营问答'),
        };
        const consultantGate = {
            name: 'OperatingQuestionConsultantGate',
            props: {
                ctx: { type: Object, required: true },
            },
            setup(props) {
                const fullComponent = Vue.shallowRef(null);
                const loading = Vue.ref(false);
                const loadError = Vue.ref('');
                const openConsultant = async () => {
                    if (loading.value || fullComponent.value) return;
                    loading.value = true;
                    loadError.value = '';
                    try {
                        fullComponent.value = await loadComponent('operatingQuestionConsultant');
                    } catch (error) {
                        loadError.value = '经营助手加载失败，点击重试';
                    } finally {
                        loading.value = false;
                    }
                };
                return () => fullComponent.value
                    ? h(fullComponent.value, { ctx: props.ctx, openOnMount: true })
                    : h('button', {
                        type: 'button',
                        class: 'sx-ai-consultant sx-ai-consultant-launcher fixed bottom-5 right-5',
                        style: 'z-index:75',
                        'data-testid': 'operating-question-consultant-load',
                        disabled: loading.value,
                        'aria-busy': loading.value ? 'true' : 'false',
                        onClick: openConsultant,
                    }, loading.value ? '正在加载经营助手...' : (loadError.value || '经营助手'));
            },
        };
        return Object.freeze({
            hotelDataAnalystProfile: buildLazyComponent('hotelDataAnalystProfile', panelLoading),
            operatingQuestionPanel: buildLazyComponent('operatingQuestionPanel', panelLoading),
            operatingQuestionConsultant: consultantGate,
        });
    };

    window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS = Object.freeze({
        create, submitCouncilRun, pollCouncilRun, councilReadbackIntegrityMatches,
    });
})();
