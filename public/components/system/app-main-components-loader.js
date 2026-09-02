(() => {
    'use strict';

    const fullScript = 'components/system/app-main-components.js?v=20260830-operating-finance-h47cbd2e799';
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

        const lazyKeys = [
            'AiDecisionQualityDetails', 'DualOtaAcceptanceReceipt', 'DualOtaPageVerificationPanel',
            'PlatformAutoSettingsPanels', 'PlatformAutoSecondaryPanels', 'CtripProfileFieldConfigPanel',
            'CompetitorDeviceManagement', 'DataConfigDialogs', 'SessionProofNotice',
            'LocalCollectorLoginHandoff', 'PmsRealtimeSyncResult', 'HotelThreeSourceOnboardingPanel',
            'OperatingLoopAuthority', 'ManagerCapabilityPanel', 'OperatingOpportunityLab',
            'OperatingFinanceControlCenter', 'OperatingNetworkReplicationList',
            'MeituanSearchKeywordWorkbench', 'SimulationHeroActions',
            'RevenueCockpitOpportunityDetails', 'RevenueCockpitSnapshotStatus',
            'RevenueCockpitActionRestoreStatus',
        ];
        const lazyComponents = Object.fromEntries(lazyKeys.map(key => [key, lazyFullComponent(key)]));
        const helperKeys = [
            'aiDailyReportTaskPositiveInteger', 'aiDailyReportModelIsLimited',
            'normalizeAiDailyReportGenerationTask', 'formatAiDailyReportGenerationStage',
            'resolveAiDailyReportGenerationOutcome', 'pollAiDailyReportGenerationTask',
            'resolveRevenueCockpitIntentLifecycle', 'parseOperationEvidenceNumber',
            'parseOptionalOperationEvidenceNumber', 'operationEvidenceFirstText',
            'operationEvidenceCleanObject', 'operationEvidenceLocalTimestamp',
            'normalizeOperationEvidenceDateTime', 'normalizeOperationReviewStatus',
        ];
        const delegatedHelpers = Object.fromEntries(helperKeys.map(key => [key, delegateFullHelper(key)]));

        return Object.freeze({
            ...lazyComponents,
            ...delegatedHelpers,
            OnlineTruthSummary,
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
