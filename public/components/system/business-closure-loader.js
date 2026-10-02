(() => {
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const bodyScript = 'business-closure-views.js?v=20260803-business-closure-template-split-v1-h81815879c7';
    const aiDailyDeliveryScript = 'ai-daily-report-delivery.js?v=20260824-ai-daily-report-delivery-v1-hedbefa7c92';
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

    let paybackPromise = null;
    registry.InvestmentPaybackView = Vue.defineAsyncComponent({
        loader: () => {
            if (!paybackPromise) {
                paybackPromise = loadScript('investment-scenario.min.js?v=investment-scenario-hbd86fc0366').then(() => {
                    if (!registry.InvestmentScenarioWorkbench) throw new Error('投资经营测算组件未注册');
                    return loadScript('investment-payback.min.js?v=investment-payback-h08207c0f84');
                }).then(() => {
                    if (!registry.InvestmentPaybackBody) throw new Error('投资回本组件未注册');
                    return registry.InvestmentPaybackBody;
                }).catch(error => { paybackPromise = null; throw error; });
            }
            return paybackPromise;
        },
        errorComponent: { render() { return Vue.h('p', { role: 'alert', class: 'text-red-700 p-4' }, '投资回本模块加载失败，请刷新重试。'); } },
        timeout: 15000,
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
        ['KnowledgeXlsxImportDialogView', 'KnowledgeXlsxImportDialogBody'],
        ['MeituanReviewOrderEvidenceView', 'MeituanReviewOrderEvidenceBody'],
        ['AiDailyTrustedBroadcastView', 'AiDailyTrustedBroadcastBody'],
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
