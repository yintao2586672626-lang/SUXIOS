(() => {
    const AUTH_ASSET_MANIFEST_ID = 'suxi-authenticated-assets';
    const AUTH_TOKEN_KEY = 'token';
    const AUTH_USER_CACHE_KEY = 'suxios_auth_user_cache_v1';
    const REMEMBERED_USERNAME_KEY = 'remembered_username';
    const PASSWORD_SAVE_PREFERENCE_KEY = 'suxios_browser_password_save_v1';
    const LEGACY_PASSWORD_KEY = 'remembered_password';
    const PUBLIC_LOCALE_KEY = 'suxios_locale';
    const PUBLIC_LOCALES = Object.freeze(['zh-CN', 'en-US']);
    const LOGIN_AUTOFILL_SYNC_DELAYS = Object.freeze([0, 100, 300, 800, 1600, 3000, 5000, 8000, 12000]);
    const LOGIN_CONNECTION_WARMUP_INTERVAL_MS = 30000;
    const LOGIN_CONNECTION_WARMUP_TIMEOUT_MS = 12000;
    const LOGIN_CONNECTION_WARMUP_MIN_GAP_MS = 15000;
    const LOGIN_PASSWORD_SAVE_TIMEOUT_MS = 1500;
    const LOGIN_HANDOFF_EVENT = 'suxi:login-handoff-metric';
    const ASSET_PHASE_STARTUP = 'startup';
    const ASSET_PHASE_AFTER_FIRST_PAINT = 'after-first-paint';
    let authenticatedAppPromise = null;
    let deferredAuthenticatedAssetsPromise = null;
    let loginHandoffStartedAt = null;
    let loginHandoffMetrics = null;

    const appRoot = () => document.getElementById('app');

    const normalizePublicLocale = (value) => (PUBLIC_LOCALES.includes(String(value || '').trim()) ? String(value).trim() : 'zh-CN');
    const getInitialPublicLocale = () => {
        try {
            const params = new URLSearchParams(window.location.search);
            return normalizePublicLocale(
                params.get('lang')
                || params.get('locale')
                || params.get('think_lang')
                || localStorage.getItem(PUBLIC_LOCALE_KEY)
                || document.documentElement.lang,
            );
        } catch (error) {
            return normalizePublicLocale(document.documentElement.lang);
        }
    };
    const applyPublicLocale = (value) => {
        const normalized = normalizePublicLocale(value);
        document.documentElement.lang = normalized;
        try {
            localStorage.setItem(PUBLIC_LOCALE_KEY, normalized);
        } catch (error) {
            // The selector remains usable for this page when browser storage is unavailable.
        }
        return normalized;
    };
    const syncPublicLocaleUrl = (value) => {
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', normalizePublicLocale(value));
            url.searchParams.delete('locale');
            url.searchParams.delete('think_lang');
            window.history.replaceState(window.history.state, '', url);
        } catch (error) {
            // Locale persistence remains authoritative when URL rewriting is unavailable.
        }
    };
    const initialPublicLocale = applyPublicLocale(getInitialPublicLocale());

    const readAuthToken = () => {
        try {
            const sessionToken = sessionStorage.getItem(AUTH_TOKEN_KEY) || '';
            if (sessionToken) return sessionToken;
            const legacyToken = localStorage.getItem(AUTH_TOKEN_KEY) || '';
            if (legacyToken) {
                sessionStorage.setItem(AUTH_TOKEN_KEY, legacyToken);
                localStorage.removeItem(AUTH_TOKEN_KEY);
                return legacyToken;
            }
        } catch (error) {
            return '';
        }
        return '';
    };

    const authenticatedAssets = () => {
        const manifest = document.getElementById(AUTH_ASSET_MANIFEST_ID);
        if (!manifest) throw new Error('缺少完整应用资源清单');
        let assets;
        try {
            assets = JSON.parse(manifest.textContent || '[]');
        } catch (error) {
            throw new Error(`完整应用资源清单无法解析：${error.message}`);
        }
        if (!Array.isArray(assets) || !assets.length) {
            throw new Error('完整应用资源清单为空');
        }
        return assets.map((item) => ({
            src: String(typeof item === 'string' ? item : item?.src || '').trim(),
            phase: String(typeof item === 'string' ? ASSET_PHASE_STARTUP : item?.phase || ASSET_PHASE_STARTUP).trim(),
        })).map((item) => {
            if (!item.src) throw new Error('完整应用资源清单包含空资源地址');
            if (![ASSET_PHASE_STARTUP, ASSET_PHASE_AFTER_FIRST_PAINT].includes(item.phase)) {
                throw new Error(`完整应用资源清单包含未知加载阶段：${item.phase}`);
            }
            return item;
        });
    };

    const assetBaseName = (src = '') => String(src || '').split(/[?#]/, 1)[0];
    const loadScript = (src) => new Promise((resolve, reject) => {
        const assetName = assetBaseName(src);
        const existing = [...document.scripts].find((script) => assetBaseName(script.getAttribute('src')) === assetName);
        if (existing) {
            if (existing.dataset.suxiAssetLoaded === '1') {
                resolve();
                return;
            }
            existing.addEventListener('load', resolve, { once: true });
            existing.addEventListener('error', () => reject(new Error(`${assetName} 加载失败`)), { once: true });
            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = false;
        script.dataset.suxiAuthenticatedAsset = assetName;
        script.addEventListener('load', () => {
            script.dataset.suxiAssetLoaded = '1';
            resolve();
        }, { once: true });
        script.addEventListener('error', () => reject(new Error(`${assetName} 加载失败`)), { once: true });
        document.body.appendChild(script);
    });

    const waitForFirstAuthenticatedPaint = () => new Promise((resolve) => {
        if (typeof window.requestAnimationFrame !== 'function') {
            window.setTimeout(resolve, 0);
            return;
        }
        window.requestAnimationFrame(() => window.requestAnimationFrame(resolve));
    });

    const browserPerformance = typeof performance !== 'undefined' ? performance : null;
    const monotonicNow = () => (
        typeof browserPerformance?.now === 'function' ? browserPerformance.now() : Date.now()
    );

    const publishLoginHandoffMetrics = () => {
        if (!loginHandoffMetrics) return null;
        const snapshot = { ...loginHandoffMetrics };
        window.SUXI_LOGIN_HANDOFF_METRICS = snapshot;
        try {
            window.dispatchEvent(new CustomEvent(LOGIN_HANDOFF_EVENT, { detail: snapshot }));
        } catch (error) {
            // Metrics remain readable from the global snapshot when events are unavailable.
        }
        return snapshot;
    };

    const markLoginAuthSuccess = ({ source = 'public-login' } = {}) => {
        loginHandoffStartedAt = monotonicNow();
        loginHandoffMetrics = {
            source,
            status: 'loading',
            auth_success_epoch_ms: Date.now(),
            interactive_epoch_ms: null,
            auth_to_interactive_ms: null,
        };
        try {
            browserPerformance?.mark?.('suxi-login-auth-success');
        } catch (error) {
            // Performance marks are optional observability only.
        }
        return publishLoginHandoffMetrics();
    };

    const markLoginInteractive = ({ source = '' } = {}) => {
        if (!loginHandoffMetrics || loginHandoffMetrics.status !== 'loading' || loginHandoffStartedAt === null) {
            return null;
        }
        const durationMs = Math.max(0, monotonicNow() - loginHandoffStartedAt);
        loginHandoffMetrics = {
            ...loginHandoffMetrics,
            source: source || loginHandoffMetrics.source,
            status: 'interactive',
            interactive_epoch_ms: Date.now(),
            auth_to_interactive_ms: Math.round(durationMs * 10) / 10,
        };
        try {
            browserPerformance?.mark?.('suxi-login-interactive');
            browserPerformance?.measure?.(
                'suxi-login-auth-to-interactive',
                'suxi-login-auth-success',
                'suxi-login-interactive',
            );
        } catch (error) {
            // Performance marks are optional observability only.
        }
        return publishLoginHandoffMetrics();
    };

    const markLoginHandoffFailed = (error) => {
        if (!loginHandoffMetrics || loginHandoffMetrics.status !== 'loading') return null;
        loginHandoffMetrics = {
            ...loginHandoffMetrics,
            status: 'failed',
            failure: String(error?.message || error || 'authenticated app load failed'),
        };
        return publishLoginHandoffMetrics();
    };

    const markLoginInteractiveAfterPaint = async (metadata = {}) => {
        await waitForFirstAuthenticatedPaint();
        return markLoginInteractive(metadata);
    };

    const loadDeferredAuthenticatedAssets = (assets = []) => {
        if (deferredAuthenticatedAssetsPromise) return deferredAuthenticatedAssetsPromise;
        deferredAuthenticatedAssetsPromise = (async () => {
            if (!assets.length) return [];
            await waitForFirstAuthenticatedPaint();
            try {
                await Promise.all(assets.map((asset) => loadScript(asset.src)));
                window.dispatchEvent(new CustomEvent('suxi:full-render-ready', {
                    detail: { assets: assets.map((asset) => assetBaseName(asset.src)) },
                }));
                return assets;
            } catch (error) {
                const failedAsset = String(error?.message || '').split(' ')[0] || 'deferred authenticated asset';
                window.dispatchEvent(new CustomEvent('suxi:full-render-error', {
                    detail: { asset: failedAsset, message: error?.message || String(error) },
                }));
                throw error;
            }
        })();
        deferredAuthenticatedAssetsPromise.catch(() => {});
        return deferredAuthenticatedAssetsPromise;
    };

    const loadAuthenticatedApp = () => {
        if (authenticatedAppPromise) return authenticatedAppPromise;
        authenticatedAppPromise = (async () => {
            const assets = authenticatedAssets();
            const startupAssets = assets.filter((asset) => asset.phase === ASSET_PHASE_STARTUP);
            const deferredAssets = assets.filter((asset) => asset.phase === ASSET_PHASE_AFTER_FIRST_PAINT);
            const runtimeIndex = startupAssets.findIndex((asset) => assetBaseName(asset.src) === 'vue.runtime.global.prod.js');
            const entryIndex = startupAssets.findIndex((asset) => assetBaseName(asset.src) === 'app-main.min.js');
            if (runtimeIndex < 0 || entryIndex < 0) {
                throw new Error('完整应用资源清单缺少 Vue 运行时或应用入口');
            }

            const runtime = startupAssets[runtimeIndex].src;
            const entry = startupAssets[entryIndex].src;
            const prerequisites = startupAssets
                .filter((_, index) => index !== runtimeIndex && index !== entryIndex)
                .map((asset) => asset.src);
            try {
                // Vue must exist before the precompiled render executes. The
                // independent helpers can download together; app-main remains
                // the final barrier and therefore never observes half-loaded
                // globals. Dynamic scripts keep manifest execution order while
                // their network fetches run in parallel.
                await loadScript(runtime);
                await Promise.all(prerequisites.map((src) => loadScript(src)));
                await loadScript(entry);
                void loadDeferredAuthenticatedAssets(deferredAssets);
            } catch (error) {
                const failedAsset = String(error?.message || '').split(' ')[0] || 'authenticated asset';
                window.SUXI_RENDER_ASSET_LOAD_ERROR?.(failedAsset);
                throw error;
            }
        })();
        return authenticatedAppPromise;
    };

    const loginMarkup = () => `
        <div class="login-bg min-h-screen flex items-center justify-center p-4 relative" data-testid="public-login-shell">
            <div class="login-glow-bottom"></div>
            <div class="login-glow-purple"></div>
            <div class="login-stage relative z-10">
                <section class="login-context-panel" aria-label="宿析OS登录主视觉">
                    <div class="login-context-topline"><span class="login-context-kicker">酒店全周期经营决策系统</span></div>
                    <p class="login-brand-mark">宿析OS</p>
                    <h2 class="login-hero-lines"><span>看见数据</span><span>看懂经营</span><span>评估未来情景</span></h2>
                    <p class="login-hero-lead">让酒店每个关键决策，都有数据依据</p>
                    <p class="login-hero-subcopy">以数据采集、经营分析、策略推演、结果追踪为核心，帮助酒店从经验判断走向数据决策。</p>
                </section>
                <div class="login-card rounded-2xl p-10 w-full max-w-md relative">
                    <div class="flex justify-end mb-3">
                        <div data-locale-switch="" data-testid="public-login-locale-switch" class="locale-switch login-locale-switch" role="group" aria-label="Language">
                            <label for="public-login-locale-select">Language</label>
                            <select id="public-login-locale-select" data-testid="public-login-locale-select" aria-label="Language">
                                <option value="zh-CN"${initialPublicLocale === 'zh-CN' ? ' selected' : ''}>简体中文</option>
                                <option value="en-US"${initialPublicLocale === 'en-US' ? ' selected' : ''}>English</option>
                            </select>
                        </div>
                    </div>
                    <div class="login-card-head text-center mb-8">
                        <div class="login-logo login-logo-brand inline-flex items-center justify-center w-20 h-20 rounded-xl mb-5 shadow-lg">
                            <img src="images/logo.svg" alt="宿析OS" class="login-brand-logo-img">
                        </div>
                        <p class="login-card-kicker">SUXIOS</p>
                        <h1 class="login-title text-3xl font-bold mb-2">宿析OS</h1>
                        <p class="login-slogan text-base font-medium tracking-wide">进入宿析OS经营系统</p>
                        <p class="login-copy">让酒店经营从经验判断走向数据决策</p>
                    </div>
                    <form id="public-login-form" class="space-y-5" novalidate>
                        <div class="input-group">
                            <label for="login-username" class="input-label block text-sm font-medium mb-2"><i class="fas fa-user mr-2"></i>用户名</label>
                            <div class="relative"><i class="fas fa-at input-icon-prefix"></i><input id="login-username" type="text" name="username" autocomplete="username" data-testid="login-username" class="input-field w-full" placeholder="请输入用户名" aria-describedby="public-login-error" required></div>
                        </div>
                        <div class="input-group">
                            <label for="login-password" class="input-label block text-sm font-medium mb-2"><i class="fas fa-key mr-2"></i>密码</label>
                            <div class="relative"><i class="fas fa-lock input-icon-prefix"></i><input id="login-password" type="password" name="password" autocomplete="current-password" data-testid="login-password" class="input-field w-full" placeholder="请输入密码" aria-describedby="public-login-error public-login-caps-lock" required><button id="public-login-toggle-password" type="button" data-testid="login-toggle-password" class="password-toggle absolute" aria-label="显示密码" aria-pressed="false" aria-controls="login-password"><i class="fas fa-eye" aria-hidden="true"></i></button></div>
                            <p id="public-login-caps-lock" class="login-caps-lock" role="status" aria-live="polite" hidden><i class="fas fa-arrow-up" aria-hidden="true"></i><span>大写锁定已开启，请注意密码大小写</span></p>
                        </div>
                        <div id="public-login-error" class="login-error flex items-center gap-3" role="alert" aria-live="assertive" aria-atomic="true" hidden><i class="fas fa-exclamation-circle" aria-hidden="true"></i><span></span></div>
                        <div class="flex items-center text-sm"><label class="flex items-center text-slate-400 cursor-pointer hover:text-slate-200 transition" title="由浏览器密码管理器保存，宿析OS不存储明文密码"><input id="public-login-remember" type="checkbox" class="remember-checkbox mr-3"><span>记住密码</span></label></div>
                        <button id="public-login-submit" type="submit" data-testid="login-submit" class="btn-login w-full py-3.5 text-base flex items-center justify-center gap-2" disabled><i class="fas fa-sign-in-alt"></i><span>进入决策中心</span></button>
                    </form>
                    <div class="login-card-foot"><button id="public-login-support-open" type="button" data-testid="login-support-open" class="director-entry-link" aria-haspopup="dialog" aria-expanded="false">账号登录遇到问题？联系管理员</button></div>
                </div>
            </div>
            <footer class="login-public-footer" aria-label="登录页使用说明"><span>宿析OS · 酒店经营决策系统</span><span aria-hidden="true">·</span><span>仅限授权用户使用</span><span aria-hidden="true">·</span><span>请勿共享密码、验证码或 Cookie</span></footer>
            <div id="public-login-support-backdrop" class="login-support-backdrop" hidden>
                <section data-testid="login-support-dialog" class="login-support-dialog" role="dialog" aria-modal="true" aria-labelledby="public-login-support-title" aria-describedby="public-login-support-description" tabindex="-1">
                    <div class="login-support-head"><div><p class="login-support-kicker">账号协助</p><h2 id="public-login-support-title">联系管理员</h2></div><button id="public-login-support-close" type="button" class="login-support-close" aria-label="关闭联系管理员弹窗"><i class="fas fa-times" aria-hidden="true"></i></button></div>
                    <p id="public-login-support-description" class="login-support-description">开通账号或处理登录问题，请通过以下方式联系管理员。</p>
                    <div class="login-support-contact" aria-live="polite" aria-atomic="true"><p id="public-login-support-value" class="login-support-value">正在获取联系方式...</p></div>
                    <p class="login-support-safety"><i class="fas fa-shield-alt" aria-hidden="true"></i> 请勿发送密码、验证码或浏览器 Cookie。</p>
                    <div class="login-support-actions"><button id="public-login-support-dismiss" type="button" class="login-support-secondary">关闭</button><button id="public-login-support-copy" type="button" data-testid="login-support-copy" class="login-support-primary" disabled><i class="fas fa-copy" aria-hidden="true"></i><span>复制联系方式</span></button></div>
                </section>
            </div>
        </div>`;

    const loadingMarkup = () => `
        <div class="login-bg min-h-screen flex items-center justify-center p-4 relative" data-testid="authenticated-app-loading">
            <div class="login-card rounded-2xl p-10 w-full max-w-md relative text-center">
                <div class="login-logo login-logo-brand inline-flex items-center justify-center w-20 h-20 rounded-xl mb-5 shadow-lg"><img src="images/logo.svg" alt="宿析OS" class="login-brand-logo-img"></div>
                <h1 class="login-title text-2xl font-bold mb-2">正在进入宿析OS</h1>
                <p class="login-copy">正在恢复登录状态并加载经营数据工作台...</p>
            </div>
        </div>`;

    const renderLoadingShell = () => {
        const root = appRoot();
        if (!root) return;
        root.removeAttribute('v-cloak');
        root.innerHTML = loadingMarkup();
    };

    const setCachedAuthUser = (user) => {
        try {
            localStorage.setItem(AUTH_USER_CACHE_KEY, JSON.stringify({ saved_at: Date.now(), user }));
        } catch (error) {
            // 缓存只用于改善首屏，失败不阻断登录。
        }
    };

    const persistLoginSuccess = ({ token, user }) => {
        const normalizedToken = String(token || '').trim();
        if (!normalizedToken) throw new Error('登录成功但未返回有效会话，请重新登录');
        try {
            sessionStorage.setItem(AUTH_TOKEN_KEY, normalizedToken);
        } catch (error) {
            throw new Error('浏览器无法保存登录状态，请检查隐私设置后重试');
        }
        try {
            localStorage.removeItem(AUTH_TOKEN_KEY);
            localStorage.removeItem(LEGACY_PASSWORD_KEY);
        } catch (error) {
            // 旧密码清理失败不阻断当前会话。
        }
        setCachedAuthUser(user);
    };

    const applyPasswordSavePreference = ({ username = '', remember = false } = {}) => {
        try {
            localStorage.removeItem(LEGACY_PASSWORD_KEY);
            if (remember) {
                localStorage.setItem(REMEMBERED_USERNAME_KEY, String(username || ''));
                localStorage.setItem(PASSWORD_SAVE_PREFERENCE_KEY, '1');
                return;
            }
            localStorage.removeItem(REMEMBERED_USERNAME_KEY);
            localStorage.removeItem(PASSWORD_SAVE_PREFERENCE_KEY);
        } catch (error) {
            // 非敏感偏好保存失败不阻断当前会话。
        }
    };

    const saveLoginPasswordWithBrowser = async ({
        username = '',
        password = '',
        remember = false,
        credentialStore = typeof navigator !== 'undefined' ? navigator.credentials : null,
        PasswordCredentialCtor = typeof PasswordCredential !== 'undefined' ? PasswordCredential : null,
        timeoutMs = LOGIN_PASSWORD_SAVE_TIMEOUT_MS,
    } = {}) => {
        if (!remember) return { status: 'not_requested', message: '', level: 'info' };
        const normalizedUsername = String(username || '').trim();
        const normalizedPassword = String(password || '');
        if (!normalizedUsername || !normalizedPassword) {
            return { status: 'invalid', message: '密码未保存：用户名或密码为空', level: 'warning' };
        }
        if (typeof PasswordCredentialCtor !== 'function' || typeof credentialStore?.store !== 'function') {
            return {
                status: 'unsupported',
                message: '当前浏览器不支持自动保存密码，请在浏览器密码管理器中手动保存',
                level: 'warning',
            };
        }
        let credential;
        try {
            credential = new PasswordCredentialCtor({
                id: normalizedUsername,
                name: normalizedUsername,
                password: normalizedPassword,
            });
        } catch (error) {
            return {
                status: 'failed',
                message: '密码未保存，请在浏览器密码管理器中重试',
                level: 'warning',
            };
        }
        const normalizedTimeoutMs = Number.isFinite(Number(timeoutMs))
            ? Math.max(1, Number(timeoutMs))
            : LOGIN_PASSWORD_SAVE_TIMEOUT_MS;
        return new Promise((resolve) => {
            let settled = false;
            const finish = (result) => {
                if (settled) return;
                settled = true;
                window.clearTimeout(timeoutId);
                resolve(result);
            };
            const timeoutId = window.setTimeout(() => finish({
                status: 'timeout',
                message: '浏览器密码管理器未及时确认，登录不受影响',
                level: 'warning',
            }), normalizedTimeoutMs);

            Promise.resolve()
                .then(() => credentialStore.store(credential))
                .then(
                    () => finish({ status: 'saved', message: '密码已由浏览器密码管理器保存', level: 'success' }),
                    (error) => finish(error?.name === 'NotAllowedError'
                        ? {
                            status: 'declined',
                            message: '浏览器未保存密码；可在密码管理器中手动保存',
                            level: 'warning',
                        }
                        : {
                            status: 'failed',
                            message: '密码未保存，请在浏览器密码管理器中重试',
                            level: 'warning',
                        }),
                );
        });
    };

    const fetchJson = async (url, options = {}, timeoutMs = 15000) => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), timeoutMs);
        try {
            const response = await fetch(url, { ...options, signal: controller.signal });
            const payload = await response.json().catch(() => ({}));
            return { response, payload };
        } finally {
            window.clearTimeout(timer);
        }
    };

    const createLoginConnectionWarmup = ({
        fetchImpl = window.fetch.bind(window),
        nowImpl = Date.now,
        setTimeoutImpl = window.setTimeout.bind(window),
        clearTimeoutImpl = window.clearTimeout.bind(window),
        setIntervalImpl = window.setInterval.bind(window),
        clearIntervalImpl = window.clearInterval.bind(window),
        isVisible = () => document.visibilityState !== 'hidden',
    } = {}) => {
        let stopped = false;
        let intervalId = null;
        let timeoutId = null;
        let controller = null;
        let inFlight = null;
        let lastStartedAt = 0;

        const warm = ({ force = false } = {}) => {
            const now = nowImpl();
            if (stopped || !isVisible()) return Promise.resolve(false);
            if (inFlight) return inFlight;
            if (!force && lastStartedAt > 0 && now - lastStartedAt < LOGIN_CONNECTION_WARMUP_MIN_GAP_MS) {
                return Promise.resolve(false);
            }

            lastStartedAt = now;
            controller = new AbortController();
            timeoutId = setTimeoutImpl(() => controller?.abort(), LOGIN_CONNECTION_WARMUP_TIMEOUT_MS);
            inFlight = Promise.resolve(fetchImpl('/api/health', {
                method: 'GET',
                credentials: 'omit',
                cache: 'no-store',
                priority: 'low',
                signal: controller.signal,
            }))
                .then((response) => Boolean(response?.ok))
                .catch(() => false)
                .finally(() => {
                    if (timeoutId !== null) clearTimeoutImpl(timeoutId);
                    timeoutId = null;
                    controller = null;
                    inFlight = null;
                });
            return inFlight;
        };

        const start = () => {
            if (stopped || intervalId !== null) return;
            void warm({ force: true });
            intervalId = setIntervalImpl(() => {
                void warm({ force: true });
            }, LOGIN_CONNECTION_WARMUP_INTERVAL_MS);
        };

        const stop = () => {
            if (stopped) return;
            stopped = true;
            if (intervalId !== null) clearIntervalImpl(intervalId);
            intervalId = null;
            if (timeoutId !== null) clearTimeoutImpl(timeoutId);
            timeoutId = null;
            controller?.abort();
            controller = null;
        };

        return { start, stop, warm };
    };

    const renderLoginShell = () => {
        const root = appRoot();
        if (!root) return;
        root.removeAttribute('v-cloak');
        root.innerHTML = loginMarkup();

        const form = document.getElementById('public-login-form');
        const username = document.getElementById('login-username');
        const password = document.getElementById('login-password');
        const remember = document.getElementById('public-login-remember');
        const localeSelect = document.getElementById('public-login-locale-select');
        const submit = document.getElementById('public-login-submit');
        const errorBox = document.getElementById('public-login-error');
        const errorText = errorBox.querySelector('span');
        const togglePassword = document.getElementById('public-login-toggle-password');
        const capsLock = document.getElementById('public-login-caps-lock');
        const supportOpen = document.getElementById('public-login-support-open');
        const supportBackdrop = document.getElementById('public-login-support-backdrop');
        const supportDialog = supportBackdrop.querySelector('[role="dialog"]');
        const supportClose = document.getElementById('public-login-support-close');
        const supportDismiss = document.getElementById('public-login-support-dismiss');
        const supportValue = document.getElementById('public-login-support-value');
        const supportCopy = document.getElementById('public-login-support-copy');
        let loading = false;
        let supportContact = '';
        const loginConnectionWarmup = createLoginConnectionWarmup();
        const warmLoginConnection = () => {
            void loginConnectionWarmup.warm();
        };

        try {
            const remembered = localStorage.getItem(REMEMBERED_USERNAME_KEY) || '';
            const passwordSavePreferred = localStorage.getItem(PASSWORD_SAVE_PREFERENCE_KEY) === '1';
            localStorage.removeItem(LEGACY_PASSWORD_KEY);
            username.value = remembered;
            remember.checked = Boolean(remembered) && passwordSavePreferred;
            if (!remembered && passwordSavePreferred) localStorage.removeItem(PASSWORD_SAVE_PREFERENCE_KEY);
        } catch (error) {
            // 浏览器存储不可用时仍允许登录。
        }

        const setError = (message = '') => {
            const text = String(message || '').trim();
            errorText.textContent = text;
            errorBox.hidden = !text;
            username.setAttribute('aria-invalid', text ? 'true' : 'false');
            password.setAttribute('aria-invalid', text ? 'true' : 'false');
        };
        const hasBrowserAutofill = (input) => {
            try {
                return Boolean(input?.matches?.(':-webkit-autofill'));
            } catch (error) {
                return false;
            }
        };
        const updateSubmit = () => {
            const loadingState = loading ? '1' : '0';
            submit.disabled = loading || !username.value.trim() || (!password.value && !hasBrowserAutofill(password));
            submit.classList.toggle('is-loading', loading);
            submit.setAttribute('aria-busy', loading ? 'true' : 'false');
            if (submit.dataset.suxiLoading !== loadingState) {
                submit.dataset.suxiLoading = loadingState;
                submit.innerHTML = loading
                    ? '<i class="fas fa-spinner fa-spin"></i><span>登录中...</span>'
                    : '<i class="fas fa-sign-in-alt"></i><span>进入决策中心</span>';
            }
        };
        const handleInput = () => {
            setError('');
            updateSubmit();
        };
        let autofillSyncTimers = [];
        const scheduleLoginAutofillSync = () => {
            autofillSyncTimers.forEach((timer) => window.clearTimeout(timer));
            autofillSyncTimers = LOGIN_AUTOFILL_SYNC_DELAYS.map((delay) => window.setTimeout(updateSubmit, delay));
        };
        const handleLoginVisibilityChange = () => {
            if (document.visibilityState !== 'visible') return;
            scheduleLoginAutofillSync();
            warmLoginConnection();
        };
        username.addEventListener('input', handleInput);
        username.addEventListener('change', handleInput);
        password.addEventListener('input', handleInput);
        password.addEventListener('change', handleInput);
        localeSelect.addEventListener('change', (event) => {
            const normalized = applyPublicLocale(event.target.value);
            localeSelect.value = normalized;
            syncPublicLocaleUrl(normalized);
        });
        form.addEventListener('focusin', scheduleLoginAutofillSync);
        form.addEventListener('focusin', warmLoginConnection);
        window.addEventListener('pageshow', scheduleLoginAutofillSync);
        window.addEventListener('pageshow', warmLoginConnection);
        window.addEventListener('focus', scheduleLoginAutofillSync);
        window.addEventListener('focus', warmLoginConnection);
        document.addEventListener('visibilitychange', handleLoginVisibilityChange);
        window.addEventListener('pagehide', loginConnectionWarmup.stop, { once: true });
        password.addEventListener('keydown', (event) => { capsLock.hidden = !event.getModifierState?.('CapsLock'); });
        password.addEventListener('keyup', (event) => { capsLock.hidden = !event.getModifierState?.('CapsLock'); });
        password.addEventListener('blur', () => { capsLock.hidden = true; });
        togglePassword.addEventListener('click', () => {
            const visible = password.type === 'password';
            password.type = visible ? 'text' : 'password';
            togglePassword.setAttribute('aria-label', visible ? '隐藏密码' : '显示密码');
            togglePassword.setAttribute('aria-pressed', visible ? 'true' : 'false');
            togglePassword.querySelector('i').className = visible ? 'fas fa-eye-slash' : 'fas fa-eye';
        });

        const closeSupport = () => {
            supportBackdrop.hidden = true;
            supportBackdrop.style.display = 'none';
            supportOpen.setAttribute('aria-expanded', 'false');
            supportOpen.focus();
        };
        const loadSupport = async () => {
            supportValue.textContent = '正在获取联系方式...';
            supportCopy.disabled = true;
            try {
                const { payload } = await fetchJson('/api/auth/login-support', {}, 8000);
                supportContact = String(payload?.data?.contact || '').trim();
                if (payload?.code !== 200 || !supportContact) throw new Error(payload?.message || payload?.msg || '暂时无法获取管理员联系方式');
                supportValue.textContent = supportContact;
                supportCopy.disabled = false;
            } catch (error) {
                supportContact = '';
                supportValue.textContent = error?.name === 'AbortError' ? '获取联系方式超时，请稍后重试' : (error?.message || '暂时无法获取管理员联系方式');
            }
        };
        supportOpen.addEventListener('click', () => {
            supportBackdrop.hidden = false;
            supportBackdrop.style.display = '';
            supportOpen.setAttribute('aria-expanded', 'true');
            supportDialog.focus();
            loadSupport();
        });
        supportClose.addEventListener('click', closeSupport);
        supportDismiss.addEventListener('click', closeSupport);
        supportBackdrop.addEventListener('click', (event) => { if (event.target === supportBackdrop) closeSupport(); });
        supportDialog.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeSupport(); });
        supportCopy.addEventListener('click', async () => {
            if (!supportContact) return;
            try {
                await navigator.clipboard.writeText(supportContact);
                supportCopy.querySelector('span').textContent = '已复制';
            } catch (error) {
                supportCopy.querySelector('span').textContent = '复制失败';
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (loading) return;
            const payload = { username: username.value, password: password.value };
            if (!payload.username || (!payload.password && !hasBrowserAutofill(password))) {
                setError('请输入用户名和密码');
                return;
            }
            if (!payload.password) {
                setError('请先点击密码框确认浏览器保存的密码，再登录');
                password.focus();
                return;
            }
            loading = true;
            updateSubmit();
            setError('');
            try {
                const { payload: responsePayload } = await fetchJson('/api/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (responsePayload?.code !== 200 || !responsePayload?.data?.token || !responsePayload?.data?.user) {
                    throw new Error(responsePayload?.message || responsePayload?.msg || '登录失败，请检查用户名和密码');
                }
                markLoginAuthSuccess({ source: 'public-login' });
                persistLoginSuccess({
                    token: responsePayload.data.token,
                    user: responsePayload.data.user,
                });
                loginConnectionWarmup.stop();
                const passwordSavePromise = saveLoginPasswordWithBrowser({
                    username: payload.username,
                    password: payload.password,
                    remember: remember.checked,
                });
                password.value = '';
                void passwordSavePromise.then((passwordSaveResult) => {
                    const passwordSaved = passwordSaveResult.status === 'saved';
                    remember.checked = passwordSaved;
                    applyPasswordSavePreference({
                        username: payload.username,
                        remember: passwordSaved,
                    });
                    const result = {
                        status: passwordSaveResult.status,
                        message: passwordSaveResult.message,
                        level: passwordSaveResult.level,
                    };
                    window.SUXI_LOGIN_PASSWORD_SAVE_RESULT = result;
                    try {
                        window.dispatchEvent(new CustomEvent('suxi:login-password-save-result', { detail: result }));
                    } catch (error) {
                        // app-main will consume the global result after mounting.
                    }
                }).catch(() => {});
                submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>正在加载经营系统...</span>';
                await loadAuthenticatedApp();
                await markLoginInteractiveAfterPaint({ source: 'public-login' });
            } catch (error) {
                markLoginHandoffFailed(error);
                loading = false;
                const message = error?.name === 'AbortError'
                    ? '登录请求超时，请检查网络后重试'
                    : (error?.message || '网络连接失败，请检查网络后重试');
                setError(message);
                updateSubmit();
            }
        });
        updateSubmit();
        form.dataset.suxiLoginReady = '1';
        scheduleLoginAutofillSync();
        loginConnectionWarmup.start();
    };

    const start = async () => {
        if (readAuthToken()) {
            renderLoadingShell();
            try {
                await loadAuthenticatedApp();
            } catch (error) {
                // 具体失败资源已由统一入口错误视图展示。
            }
            return;
        }
        renderLoginShell();
    };

    window.SUXI_LOAD_AUTHENTICATED_APP = loadAuthenticatedApp;
    window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS = () => deferredAuthenticatedAssetsPromise;
    window.SUXI_MARK_LOGIN_AUTH_SUCCESS = markLoginAuthSuccess;
    window.SUXI_MARK_LOGIN_INTERACTIVE = markLoginInteractive;
    window.SUXI_MARK_LOGIN_INTERACTIVE_AFTER_PAINT = markLoginInteractiveAfterPaint;
    start();
})();
