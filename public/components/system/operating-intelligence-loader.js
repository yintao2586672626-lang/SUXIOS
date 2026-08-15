(() => {
    'use strict';

    const fullScript = 'components/system/operating-intelligence-components.js?v=20260816-runtime-closure-h33d4563d1b';
    const requestEvent = 'suxi:operating-intelligence-requested';
    let fullScriptPromise = null;

    const requestFullComponents = () => {
        window.dispatchEvent(new CustomEvent(requestEvent));
    };
    const waitForActivation = () => {
        if (window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL?.create
            || document.documentElement.dataset.suxiFullRenderReady === '1') {
            return Promise.resolve();
        }
        return new Promise(resolve => {
            const finish = () => {
                window.removeEventListener(requestEvent, finish);
                window.removeEventListener('suxi:full-render-ready', finish);
                resolve();
            };
            window.addEventListener(requestEvent, finish, { once: true });
            window.addEventListener('suxi:full-render-ready', finish, { once: true });
        });
    };
    const loadFullScript = async () => {
        await waitForActivation();
        if (window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL?.create) {
            return window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL;
        }
        if (fullScriptPromise) return fullScriptPromise;
        fullScriptPromise = new Promise((resolve, reject) => {
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
        return fullScriptPromise;
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
        const consultantLoading = {
            inheritAttrs: false,
            render: () => h('button', {
                type: 'button',
                class: 'fixed bottom-5 right-5 z-40 rounded-full bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-lg',
                'data-testid': 'operating-question-consultant-load',
                onClick: requestFullComponents,
            }, '经营助手'),
        };
        return Object.freeze({
            operatingQuestionPanel: buildLazyComponent('operatingQuestionPanel', panelLoading),
            operatingQuestionConsultant: buildLazyComponent('operatingQuestionConsultant', consultantLoading),
        });
    };

    window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS = Object.freeze({ create });
})();
