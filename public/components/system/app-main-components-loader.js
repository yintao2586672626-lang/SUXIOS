(() => {
    'use strict';

    const fullScript = 'components/system/app-main-components.js?v=20260822-manager-capability-he5d5f0c8d0';
    let fullScriptPromise = null;

    const loadFullScript = () => {
        if (window.SUXI_APP_MAIN_COMPONENTS_FULL?.create) {
            return Promise.resolve(window.SUXI_APP_MAIN_COMPONENTS_FULL);
        }
        if (fullScriptPromise) return fullScriptPromise;
        fullScriptPromise = new Promise((resolve, reject) => {
            const resolvedSrc = new URL(fullScript, document.baseURI).href;
            const existing = [...document.scripts].find(script => script.src === resolvedSrc);
            const script = existing || document.createElement('script');
            const finish = () => {
                const factory = window.SUXI_APP_MAIN_COMPONENTS_FULL;
                if (factory?.create) {
                    script.dataset.suxiAssetLoaded = '1';
                    resolve(factory);
                    return;
                }
                fullScriptPromise = null;
                script.remove();
                reject(new Error('主应用完整领域组件未完成注册'));
            };
            if (existing?.dataset?.suxiAssetLoaded === '1') {
                finish();
                return;
            }
            script.src = fullScript;
            script.async = true;
            script.dataset.suxiAppMainComponents = fullScript;
            script.addEventListener('load', finish, { once: true });
            script.addEventListener('error', () => {
                fullScriptPromise = null;
                script.remove();
                reject(new Error('主应用完整领域组件加载失败'));
            }, { once: true });
            if (!existing) document.head.appendChild(script);
        });
        return fullScriptPromise;
    };

    const create = ({ Vue, h }) => {
        let fullComponents = null;
        const readFullComponents = () => {
            if (fullComponents) return fullComponents;
            const factory = window.SUXI_APP_MAIN_COMPONENTS_FULL;
            if (!factory?.create) throw new Error('主应用完整领域组件尚未加载');
            fullComponents = factory.create({ Vue, h });
            return fullComponents;
        };
        const loadFullComponents = () => loadFullScript().then(readFullComponents);
        const lazyFullComponent = (key) => Vue.defineAsyncComponent(() => (
            loadFullComponents().then(components => {
                const component = components[key];
                if (!component) throw new Error(`主应用完整领域组件缺少：${key}`);
                return component;
            })
        ));
        const delegateFullHelper = key => (...args) => {
            const helper = readFullComponents()[key];
            if (typeof helper !== 'function') throw new Error(`主应用完整领域组件缺少工具：${key}`);
            return helper(...args);
        };

        const onlineDataComponents = window.SUXI_ONLINE_DATA_COMPONENTS
            || (window.SUXI_ONLINE_DATA_COMPONENTS = {});
        const onlineDataComponentScriptPromises = new Map();
        const loadOnlineDataComponentScript = (src) => {
            if (!src) return Promise.reject(new Error('缺少线上数据组件脚本路径'));
            if (onlineDataComponentScriptPromises.has(src)) {
                return onlineDataComponentScriptPromises.get(src);
            }
            const promise = new Promise((resolve, reject) => {
                const existing = document.querySelector(`script[data-suxi-online-data-component="${src}"]`);
                if (existing?.dataset?.loaded === '1') {
                    resolve();
                    return;
                }
                const script = existing || document.createElement('script');
                script.src = src;
                script.async = true;
                script.dataset.suxiOnlineDataComponent = src;
                script.onload = () => {
                    script.dataset.loaded = '1';
                    resolve();
                };
                script.onerror = () => {
                    onlineDataComponentScriptPromises.delete(src);
                    script.remove();
                    reject(new Error(`线上数据组件加载失败：${src}`));
                };
                if (!existing) document.head.appendChild(script);
            });
            onlineDataComponentScriptPromises.set(src, promise);
            return promise;
        };
        const readOnlineDataComponent = key => (
            Object.prototype.hasOwnProperty.call(onlineDataComponents, key)
                ? onlineDataComponents[key]
                : null
        );
        const requireOnlineDataComponent = (key) => {
            const component = readOnlineDataComponent(key);
            if (!component) throw new Error(`缺少线上数据本地组件：${key}`);
            return component;
        };

        const systemComponents = window.SUXI_SYSTEM_COMPONENTS
            || (window.SUXI_SYSTEM_COMPONENTS = {});
        const requireSystemComponent = (key) => {
            const component = systemComponents[key];
            if (!component) throw new Error(`缺少系统管理本地组件：${key}`);
            return component;
        };

        const OnlineTruthSummary = {
            name: 'OnlineTruthSummary',
            props: {
                truth: { type: Object, default: () => ({}) },
                testid: { type: String, default: '' },
            },
            render() {
                const helpers = window.SUXI_DATA_HEALTH_STATIC || {};
                const summary = typeof helpers.onlineTruthSummaryText === 'function'
                    ? helpers.onlineTruthSummaryText(this.truth)
                    : '未验证：缺少可信凭证';
                const nextAction = typeof helpers.onlineTruthNextActionText === 'function'
                    ? helpers.onlineTruthNextActionText(this.truth)
                    : '';
                const rows = typeof helpers.onlineTruthMetaRows === 'function'
                    ? helpers.onlineTruthMetaRows(this.truth)
                    : [];
                const testid = String(this.testid || '').trim();
                return h('div', {
                    class: 'mt-2 text-[11px] leading-5 text-slate-500',
                    'data-testid': testid || undefined,
                }, [
                    h('div', { class: 'font-medium text-slate-600' }, summary),
                    nextAction ? h('div', { class: 'mt-0.5 text-amber-700' }, `下一步：${nextAction}`) : null,
                    h('details', {
                        class: 'mt-1.5',
                        'data-testid': testid ? `${testid}-details` : undefined,
                    }, [
                        h('summary', {
                            class: 'cursor-pointer select-none text-slate-400 hover:text-slate-600',
                        }, '查看详情'),
                        h('dl', { class: 'mt-1.5 space-y-1 border-t border-slate-100 pt-1.5' }, rows.map(row => h('div', {
                            key: row.key,
                            class: 'grid grid-cols-[52px_minmax(0,1fr)] gap-1',
                        }, [
                            h('dt', { class: 'text-slate-400' }, row.label),
                            h('dd', { class: 'break-words text-slate-600' }, row.value),
                        ]))),
                    ]),
                ]);
            },
        };

        const OperatingLoopAuthority = {
            name: 'OperatingLoopAuthority',
            render() {
                const ctx = this.$root || {};
                const loop = ctx.operatingLoop && typeof ctx.operatingLoop === 'object' ? ctx.operatingLoop : {};
                const scope = loop.scope && typeof loop.scope === 'object' ? loop.scope : {};
                const issue = loop.priority_issue && typeof loop.priority_issue === 'object' ? loop.priority_issue : {};
                const nextAction = loop.next_action && typeof loop.next_action === 'object' ? loop.next_action : {};
                const owner = nextAction.owner && typeof nextAction.owner === 'object' ? nextAction.owner : {};
                const result = loop.yesterday_result && typeof loop.yesterday_result === 'object' ? loop.yesterday_result : {};
                const actors = loop.actors && typeof loop.actors === 'object' ? loop.actors : {};
                const experience = loop.experience && typeof loop.experience === 'object' ? loop.experience : {};
                const stages = Array.isArray(loop.stages) ? loop.stages : [];
                const stateClass = String(ctx.operatingLoopStateClass || 'border-slate-200 bg-slate-50 text-slate-600');
                const answer = (label, primary, secondary = '') => h('article', {
                    class: 'rounded-xl border border-slate-200 bg-slate-50 p-3',
                }, [
                    h('div', { class: 'text-xs font-semibold text-slate-500' }, label),
                    h('p', { class: 'mt-2 text-sm font-semibold leading-6 text-slate-900' }, primary),
                    secondary ? h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, secondary) : null,
                ]);
                const actor = (label, value) => h('span', `${label}：${Number(value) > 0 ? value : '未记录'}`);

                return h('section', {
                    class: 'mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm',
                    'data-testid': 'operating-loop-authority',
                }, [
                    h('div', { class: 'border-b border-slate-100 bg-slate-50/80 px-4 py-4 sm:px-5' }, [
                        h('div', { class: 'flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between' }, [
                            h('div', [
                                h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                    h('h2', { class: 'text-lg font-bold text-slate-950' }, '宿析经营闭环内核'),
                                    h('span', { class: ['rounded-full border px-2.5 py-1 text-xs font-semibold', stateClass] }, String(ctx.operatingLoopStateLabel || '未核验')),
                                    h('span', {
                                        class: ['rounded-full border px-2.5 py-1 text-xs', loop.readback_verified
                                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                            : 'border-amber-200 bg-amber-50 text-amber-700'],
                                    }, loop.readback_verified ? '精确回读通过' : '未通过精确回读'),
                                ]),
                                h('p', { class: 'mt-1 text-sm leading-6 text-slate-600' }, '这里只显示唯一权威状态；线上数据、Revenue AI、运营优化、目标、执行和知识均为专业下钻，不能自行宣布成功。'),
                            ]),
                            ctx.canReconcileOperatingLoop ? h('button', {
                                type: 'button',
                                class: 'inline-flex items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60',
                                disabled: Boolean(ctx.operatingLoopSyncing) || !ctx.filterReportHotel,
                                onClick: () => ctx.reconcileOperatingLoop?.(),
                            }, [
                                h('i', { class: ctx.operatingLoopSyncing ? 'fas fa-spinner fa-spin' : 'fas fa-rotate' }),
                                ctx.operatingLoopSyncing ? '正在按正式记录同步' : '同步权威状态',
                            ]) : null,
                        ]),
                        h('div', { class: 'mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500' }, [
                            h('span', `酒店：${scope.hotel_name || `ID ${scope.system_hotel_id || ctx.filterReportHotel || '未确认'}`}`),
                            h('span', `业务日：${scope.business_date || ctx.operationYesterday || '未确认'}`),
                            h('span', `指标版本：${scope.metric_version || '未冻结'}`),
                            h('span', `Kernel：${loop.kernel_id || '未建立'} · revision ${Number(loop.revision || 0)}`),
                        ]),
                        ctx.operatingLoopError ? h('div', { class: 'mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700' }, ctx.operatingLoopError) : null,
                    ]),
                    h('div', { class: 'grid grid-cols-1 gap-3 p-4 sm:px-5 lg:grid-cols-2 xl:grid-cols-4' }, [
                        answer('什么是真的', loop.what_is_true || '尚无通过权威证据链成立的经营事实。'),
                        answer('最重要的问题', issue.title || '等待权威事实形成后判断。', issue.detail || ''),
                        answer('下一步谁做什么', nextAction.action || '先确认酒店、来源门店、业务日和指标版本。', `负责人：${owner.role || (owner.user_id ? `用户 ${owner.user_id}` : '待明确')}`),
                        answer('昨天动作有没有结果', result.status || 'pending', result.result_summary || '尚无同酒店、同平台、同指标口径的结果回读。'),
                    ]),
                    h('div', { class: 'border-t border-slate-100 px-4 py-4 sm:px-5' }, [
                        h('div', { class: 'mb-2 flex flex-wrap items-center justify-between gap-2' }, [
                            h('div', { class: 'text-sm font-semibold text-slate-900' }, '唯一八阶段状态链'),
                            h('div', { class: 'text-xs text-slate-500' }, `证据引用 ${Number(loop.evidence_ref_count || 0)} 条`),
                        ]),
                        h('div', { class: 'grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4' }, stages.map((stage, index) => h('div', {
                            key: stage.key,
                            class: ['rounded-xl border px-3 py-2', ctx.operatingLoopStageClass?.(stage.status)],
                        }, [
                            h('div', { class: 'flex items-center gap-2' }, [
                                h('span', { class: 'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-current text-[10px] font-bold' }, String(index + 1)),
                                h('span', { class: 'text-xs font-semibold' }, stage.label || stage.key),
                            ]),
                            h('div', { class: 'mt-1 pl-7 text-[11px] opacity-80' }, stage.status === 'complete' ? '证据成立' : (stage.status === 'missing' ? '当前阻断' : '尚未证明')),
                        ]))),
                        h('div', { class: 'mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500' }, [
                            actor('判断人', actors.judged_by), actor('批准人', actors.approved_by),
                            actor('执行人', actors.executed_by), actor('复盘人', actors.reviewed_by),
                            h('span', `经验：${experience.status || 'not_reviewed'}`),
                        ]),
                    ]),
                ]);
            },
        };

        const lazyKeys = [
            'AiDecisionQualityDetails', 'DualOtaAcceptanceReceipt', 'DualOtaPageVerificationPanel',
            'PlatformAutoSettingsPanels', 'PlatformAutoSecondaryPanels', 'CtripProfileFieldConfigPanel',
            'CompetitorDeviceManagement', 'DataConfigDialogs', 'SessionProofNotice',
            'LocalCollectorLoginHandoff', 'PmsRealtimeSyncResult', 'HotelThreeSourceOnboardingPanel',
            'ManagerCapabilityPanel', 'OperatingOpportunityLab',
            'RevenueCockpitOpportunityDetails', 'RevenueCockpitSnapshotStatus',
            'RevenueCockpitActionRestoreStatus',
        ];
        const lazyComponents = Object.fromEntries(lazyKeys.map(key => [key, lazyFullComponent(key)]));
        const helperKeys = [
            'aiDailyReportTaskPositiveInteger', 'aiDailyReportModelIsLimited',
            'normalizeAiDailyReportGenerationTask', 'formatAiDailyReportGenerationStage',
            'resolveAiDailyReportGenerationOutcome', 'pollAiDailyReportGenerationTask',
            'resolveRevenueCockpitIntentLifecycle',
        ];
        const delegatedHelpers = Object.fromEntries(helperKeys.map(key => [key, delegateFullHelper(key)]));

        return Object.freeze({
            ...lazyComponents,
            ...delegatedHelpers,
            OnlineTruthSummary,
            OperatingLoopAuthority,
            onlineDataComponents,
            loadOnlineDataComponentScript,
            readOnlineDataComponent,
            requireOnlineDataComponent,
            systemComponents,
            CtripOrderAnalysisPanel: systemComponents.CtripOrderAnalysisPanel || lazyFullComponent('CtripOrderAnalysisPanel'),
            requireSystemComponent,
            platformAutoPanelsScript: 'components/online-data/platform-auto-settings-panels.js?v=20260811-windows-scheduler-h80-v3',
            ctripProfileFieldConfigPanelScript: 'components/online-data/ctrip-profile-field-config-panel.js?v=20260613-profile-template-split',
            competitorDeviceManagementScript: 'components/admin/competitor-device-management.js?v=20260719-device-lifecycle-v3',
            dataConfigDialogsScript: 'components/system/data-config-dialogs.js?v=20260720-data-config-template-split-v1',
            automationCollectionContractScript: 'components/operations/automation-collection-contract.js?v=20260811-h80-binding-onboarding-v1',
        });
    };

    window.SUXI_APP_MAIN_COMPONENTS = Object.freeze({ create });
})();
