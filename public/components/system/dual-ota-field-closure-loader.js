(() => {
    'use strict';

    const Vue = window.Vue;
    if (!Vue?.defineAsyncComponent) return;

    const systemComponents = window.SUXI_SYSTEM_COMPONENTS
        || (window.SUXI_SYSTEM_COMPONENTS = {});
    const panelScript = 'components/system/dual-ota-field-closure-panel.js?v=20260826-trusted-ota-fact-h57e7704cdade';
    let panelPromise = null;

    const loadPanel = () => {
        if (window.SUXI_DUAL_OTA_FIELD_CLOSURE?.createPanel) {
            return Promise.resolve(
                window.SUXI_DUAL_OTA_FIELD_CLOSURE.createPanel({ h: Vue.h }),
            );
        }
        if (panelPromise) return panelPromise;
        panelPromise = new Promise((resolve, reject) => {
            const resolvedSrc = new URL(panelScript, document.baseURI).href;
            const existing = [...document.scripts].find(script => script.src === resolvedSrc);
            const script = existing || document.createElement('script');
            const finish = () => {
                const factory = window.SUXI_DUAL_OTA_FIELD_CLOSURE;
                if (factory?.createPanel) {
                    script.dataset.suxiAssetLoaded = '1';
                    resolve(factory.createPanel({ h: Vue.h }));
                    return;
                }
                panelPromise = null;
                script.remove();
                reject(new Error('双 OTA 字段闭环组件未完成注册'));
            };
            if (existing?.dataset?.suxiAssetLoaded === '1') {
                finish();
                return;
            }
            script.src = panelScript;
            script.async = true;
            script.dataset.suxiDualOtaFieldClosure = panelScript;
            script.addEventListener('load', finish, { once: true });
            script.addEventListener('error', () => {
                panelPromise = null;
                script.remove();
                reject(new Error('双 OTA 字段闭环组件加载失败'));
            }, { once: true });
            if (!existing) document.head.appendChild(script);
        });
        return panelPromise;
    };

    systemComponents.DualOtaFieldClosurePanel = Vue.defineAsyncComponent({
        loader: loadPanel,
        delay: 0,
        loadingComponent: {
            render: () => Vue.h('div', {
                class: 'rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500',
                'data-testid': 'dual-ota-field-closure-loading',
            }, '正在读取双平台字段闭环…'),
        },
        errorComponent: {
            render: () => Vue.h('div', {
                class: 'rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700',
                'data-testid': 'dual-ota-field-closure-load-error',
            }, '双平台字段闭环组件加载失败，请刷新后重试。'),
        },
    });
})();
