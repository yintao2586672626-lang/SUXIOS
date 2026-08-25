(() => {
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const bodyScript = 'business-closure-views.js?v=20260803-business-closure-template-split-v1-hbc6902eef8';
    const aiDailyDeliveryScript = 'ai-daily-report-delivery.js?v=20260824-ai-daily-report-delivery-v1-hc7c72f2962';
    let loadPromise = null;

    const loadScript = (source) => new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `components/system/${source}`;
        script.dataset.suxiBusinessClosureAsset = source;
        script.onload = resolve;
        script.onerror = () => {
            script.remove();
            reject(new Error(`经营闭环资源加载失败：${source.split('?')[0]}`));
        };
        document.head.appendChild(script);
    });

    const loadBodies = () => {
        if (loadPromise) return loadPromise;
        loadPromise = Promise.all([
            loadScript(aiDailyDeliveryScript),
            loadScript(bodyScript),
        ]);
        loadPromise.catch(() => {
            loadPromise = null;
        });
        return loadPromise;
    };
    registry.loadAiDailyReportDelivery = () => loadBodies().then(() => {
        const delivery = window.SUXI_AI_DAILY_REPORT_DELIVERY;
        if (!delivery || typeof delivery.downloadCompetitionReport !== 'function') {
            throw new Error('AI日报交付资源加载完成但未注册');
        }
        return delivery;
    });

    const loadingComponent = {
        inheritAttrs: false,
        render() {
            return Vue.h('div', {
                class: 'border p-4 text-sm text-slate-500',
                'data-testid': 'business-closure-view-loading',
            }, '经营闭环模块加载中…');
        },
    };
    const errorComponent = {
        inheritAttrs: false,
        render() {
            return Vue.h('div', {
                class: 'border border-red-200 bg-red-50 p-4 text-sm text-red-700',
                'data-testid': 'business-closure-view-load-error',
            }, '模块加载失败，请刷新重试。');
        },
    };
    const definitions = [
        ['KnowledgeFeatureFinderView', 'KnowledgeFeatureFinderBody'],
        ['KnowledgePromotionWorkbenchView', 'KnowledgePromotionWorkbenchBody'],
        ['OperatingGoalInterventionView', 'OperatingGoalInterventionBody'],
        ['HomeTemporalTrialView', 'HomeTemporalTrialBody'],
        ['KnowledgeXlsxImportDialogView', 'KnowledgeXlsxImportDialogBody'],
        ['MeituanReviewOrderEvidenceView', 'MeituanReviewOrderEvidenceBody'],
        ['AiDailyPresentationDeliveryView', 'AiDailyPresentationDeliveryBody'],
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
