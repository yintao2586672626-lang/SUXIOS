(() => {
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const bodyKey = 'CtripOrderAnalysisPanelBody';
    const scriptSrc = 'components/online-data/ctrip-order-analysis-panel.js?v=20260813-order-analysis-h7ec5d31239';
    let loadPromise = null;

    const loadBody = () => {
        if (components[bodyKey]) return Promise.resolve(components[bodyKey]);
        if (loadPromise) return loadPromise;
        loadPromise = new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-suxi-ctrip-order-analysis="${scriptSrc}"]`);
            const script = existing || document.createElement('script');
            script.src = scriptSrc;
            script.async = true;
            script.dataset.suxiCtripOrderAnalysis = scriptSrc;
            script.addEventListener('load', () => {
                const body = components[bodyKey];
                if (!body) {
                    reject(new Error('订单分析组件未完成注册'));
                    return;
                }
                resolve(body);
            }, { once: true });
            script.addEventListener('error', () => {
                loadPromise = null;
                if (!existing) script.remove();
                reject(new Error('订单分析组件加载失败'));
            }, { once: true });
            if (!existing) document.head.appendChild(script);
        });
        return loadPromise;
    };

    components.CtripOrderAnalysisPanel = Vue.defineAsyncComponent({
        loader: loadBody,
        delay: 0,
        timeout: 15000,
        loadingComponent: {
            inheritAttrs: false,
            render() {
                return Vue.h('section', {
                    'data-testid': 'ctrip-order-analysis-loading',
                    role: 'status',
                    'aria-live': 'polite',
                    class: 'rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500',
                }, '正在加载双平台订单快析…');
            },
            template: '<section data-testid="ctrip-order-analysis-loading" role="status" aria-live="polite" class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500">正在加载双平台订单快析…</section>',
        },
        errorComponent: {
            inheritAttrs: false,
            render() {
                return Vue.h('section', {
                    'data-testid': 'ctrip-order-analysis-load-error',
                    role: 'alert',
                    class: 'rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700',
                }, '订单分析组件加载失败，请刷新页面重试。');
            },
            template: '<section data-testid="ctrip-order-analysis-load-error" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">订单分析组件加载失败，请刷新页面重试。</section>',
        },
    });
})();
