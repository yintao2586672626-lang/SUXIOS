(() => {
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const bodyKey = 'CtripOrderAnalysisPanelBody';
    const scriptSrc = 'components/online-data/ctrip-order-analysis-panel.js?v=20260813-order-analysis-h13b6a5582e';
    let loadPromise = null;

    const loadBody = () => {
        if (components[bodyKey]) return Promise.resolve(components[bodyKey]);
        if (loadPromise) return loadPromise;
        loadPromise = new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-suxi-ctrip-order-analysis="${scriptSrc}"]`);
            const script = existing || document.createElement('script');
            const cleanup = () => {
                script.removeEventListener('load', onLoad);
                script.removeEventListener('error', onError);
            };
            const fail = (message) => {
                cleanup();
                script.remove();
                reject(new Error(message));
            };
            const onLoad = () => {
                const body = components[bodyKey];
                if (!body) {
                    fail('订单分析组件未完成注册');
                    return;
                }
                cleanup();
                resolve(body);
            };
            const onError = () => fail('订单分析组件加载失败');
            script.addEventListener('load', onLoad, { once: true });
            script.addEventListener('error', onError, { once: true });
            if (!existing) {
                script.src = scriptSrc;
                script.async = true;
                script.dataset.suxiCtripOrderAnalysis = scriptSrc;
                document.head.appendChild(script);
            }
        }).catch((error) => {
            loadPromise = null;
            throw error;
        });
        return loadPromise;
    };

    components.CtripOrderAnalysisPanel = Vue.defineAsyncComponent({
        loader: loadBody,
        onError(_error, retry, fail, attempts) {
            if (attempts === 1) retry();
            else fail();
        },
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
