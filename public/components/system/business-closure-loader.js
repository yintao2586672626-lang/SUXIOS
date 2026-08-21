(() => {
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const bodyScript = 'business-closure-views.js?v=20260803-business-closure-template-split-v1-h16c73bcb0e';
    let loadPromise = null;

    const loadBodies = () => {
        if (loadPromise) return loadPromise;
        loadPromise = new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-suxi-business-closure-views="${bodyScript}"]`);
            if (existing?.dataset?.loaded === '1') {
                resolve();
                return;
            }
            const script = existing || document.createElement('script');
            script.src = `components/system/${bodyScript}`;
            script.async = true;
            script.dataset.suxiBusinessClosureViews = bodyScript;
            script.onload = () => {
                script.dataset.loaded = '1';
                resolve();
            };
            script.onerror = () => {
                loadPromise = null;
                script.remove();
                reject(new Error('经营闭环组件加载失败'));
            };
            if (!existing) document.head.appendChild(script);
        });
        return loadPromise;
    };

    const loadingComponent = {
        inheritAttrs: false,
        render() {
            return Vue.h('div', {
                class: 'rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500',
                'data-testid': 'business-closure-view-loading',
            }, '经营闭环模块加载中…');
        },
    };
    const errorComponent = {
        inheritAttrs: false,
        render() {
            return Vue.h('div', {
                class: 'rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700',
                'data-testid': 'business-closure-view-load-error',
            }, '经营闭环模块加载失败，请刷新页面后重试。');
        },
    };
    const definitions = [
        ['KnowledgeFeatureFinderView', 'KnowledgeFeatureFinderBody'],
        ['KnowledgePromotionWorkbenchView', 'KnowledgePromotionWorkbenchBody'],
        ['OperatingGoalInterventionView', 'OperatingGoalInterventionBody'],
        ['HomeTemporalTrialView', 'HomeTemporalTrialBody'],
        ['KnowledgeXlsxImportDialogView', 'KnowledgeXlsxImportDialogBody'],
    ];
    for (const [viewKey, bodyKey] of definitions) {
        registry[viewKey] = Vue.defineAsyncComponent({
            loader: () => loadBodies().then(() => {
                const body = registry[bodyKey];
                if (!body) throw new Error(`经营闭环组件未注册：${bodyKey}`);
                return body;
            }),
            loadingComponent,
            errorComponent,
            delay: 120,
            timeout: 15000,
        });
    }
})();
