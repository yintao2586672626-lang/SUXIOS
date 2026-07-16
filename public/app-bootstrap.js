(() => {
    const AUTH_ASSET_MANIFEST_ID = 'suxi-authenticated-assets';
    const AUTH_TOKEN_KEY = 'token';
    const AUTH_USER_CACHE_KEY = 'suxios_auth_user_cache_v1';
    const REMEMBERED_USERNAME_KEY = 'remembered_username';
    const LEGACY_PASSWORD_KEY = 'remembered_password';
    const LOGIN_AUTOFILL_SYNC_DELAYS = Object.freeze([0, 100, 300, 800, 1600, 3000, 5000, 8000, 12000]);
    let authenticatedAppPromise = null;

    const appRoot = () => document.getElementById('app');

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
        return assets.map((item) => String(typeof item === 'string' ? item : item?.src || '').trim()).filter(Boolean);
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

    const loadAuthenticatedApp = () => {
        if (authenticatedAppPromise) return authenticatedAppPromise;
        authenticatedAppPromise = (async () => {
            const assets = authenticatedAssets();
            const runtimeIndex = assets.findIndex((src) => assetBaseName(src) === 'vue.runtime.global.prod.js');
            const entryIndex = assets.findIndex((src) => assetBaseName(src) === 'app-main.min.js');
            if (runtimeIndex < 0 || entryIndex < 0) {
                throw new Error('完整应用资源清单缺少 Vue 运行时或应用入口');
            }

            const runtime = assets[runtimeIndex];
            const entry = assets[entryIndex];
            const prerequisites = assets.filter((_, index) => index !== runtimeIndex && index !== entryIndex);
            try {
                // Vue must exist before the precompiled render executes. The
                // independent helpers can download together; app-main remains
                // the final barrier and therefore never observes half-loaded
                // globals. Dynamic scripts keep manifest execution order while
                // their network fetches run in parallel.
                await loadScript(runtime);
                await Promise.all(prerequisites.map((src) => loadScript(src)));
                await loadScript(entry);
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
                            <div class="relative"><i class="fas fa-lock input-icon-prefix"></i><input id="login-password" type="password" name="password" autocomplete="current-password" data-testid="login-password" class="input-field w-full" style="padding-right:3.5rem" placeholder="请输入密码" aria-describedby="public-login-error public-login-caps-lock" required><button id="public-login-toggle-password" type="button" data-testid="login-toggle-password" class="password-toggle absolute" style="right:.75rem;top:50%;transform:translateY(-50%)" aria-label="显示密码" aria-pressed="false" aria-controls="login-password"><i class="fas fa-eye" aria-hidden="true"></i></button></div>
                            <p id="public-login-caps-lock" class="login-caps-lock" role="status" aria-live="polite" hidden><i class="fas fa-arrow-up" aria-hidden="true"></i><span>大写锁定已开启，请注意密码大小写</span></p>
                        </div>
                        <div id="public-login-error" class="login-error flex items-center gap-3" role="alert" aria-live="assertive" aria-atomic="true" hidden><i class="fas fa-exclamation-circle" aria-hidden="true"></i><span></span></div>
                        <div class="flex items-center text-sm"><label class="flex items-center text-slate-400 cursor-pointer hover:text-slate-200 transition"><input id="public-login-remember" type="checkbox" class="remember-checkbox mr-3"><span>记住账号（不保存密码）</span></label></div>
                        <button id="public-login-submit" type="submit" data-testid="login-submit" class="btn-login w-full py-3.5 text-base flex items-center justify-center gap-2" disabled><i class="fas fa-sign-in-alt"></i><span>进入决策中心</span></button>
                    </form>
                    <div class="login-card-foot"><button id="public-login-support-open" type="button" data-testid="login-support-open" class="director-entry-link" aria-haspopup="dialog" aria-expanded="false">账号登录遇到问题？联系管理员</button></div>
                </div>
            </div>
            <footer class="login-public-footer" aria-label="登录页使用说明"><span>宿析OS · 酒店经营决策系统</span><span aria-hidden="true">·</span><span>仅限授权用户使用</span><span aria-hidden="true">·</span><span>请勿共享密码、验证码或 Cookie</span></footer>
            <div id="public-login-support-backdrop" class="login-support-backdrop" style="display:none" hidden>
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

    const persistLoginSuccess = ({ token, user, username, remember }) => {
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
            if (remember) localStorage.setItem(REMEMBERED_USERNAME_KEY, username);
            else localStorage.removeItem(REMEMBERED_USERNAME_KEY);
        } catch (error) {
            // 记住账号失败不阻断当前会话。
        }
        setCachedAuthUser(user);
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

    const renderLoginShell = () => {
        const root = appRoot();
        if (!root) return;
        root.removeAttribute('v-cloak');
        root.innerHTML = loginMarkup();

        const form = document.getElementById('public-login-form');
        const username = document.getElementById('login-username');
        const password = document.getElementById('login-password');
        const remember = document.getElementById('public-login-remember');
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

        try {
            const remembered = localStorage.getItem(REMEMBERED_USERNAME_KEY) || '';
            localStorage.removeItem(LEGACY_PASSWORD_KEY);
            username.value = remembered;
            remember.checked = Boolean(remembered);
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
            submit.disabled = loading || !username.value.trim() || (!password.value && !hasBrowserAutofill(password));
            submit.classList.toggle('is-loading', loading);
            submit.setAttribute('aria-busy', loading ? 'true' : 'false');
            submit.innerHTML = loading
                ? '<i class="fas fa-spinner fa-spin"></i><span>登录中...</span>'
                : '<i class="fas fa-sign-in-alt"></i><span>进入决策中心</span>';
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
            if (document.visibilityState === 'visible') scheduleLoginAutofillSync();
        };
        username.addEventListener('input', handleInput);
        username.addEventListener('change', handleInput);
        password.addEventListener('input', handleInput);
        password.addEventListener('change', handleInput);
        form.addEventListener('focusin', scheduleLoginAutofillSync);
        window.addEventListener('pageshow', scheduleLoginAutofillSync);
        window.addEventListener('focus', scheduleLoginAutofillSync);
        document.addEventListener('visibilitychange', handleLoginVisibilityChange);
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
                persistLoginSuccess({
                    token: responsePayload.data.token,
                    user: responsePayload.data.user,
                    username: payload.username,
                    remember: remember.checked,
                });
                submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>正在加载经营系统...</span>';
                await loadAuthenticatedApp();
            } catch (error) {
                loading = false;
                const message = error?.name === 'AbortError'
                    ? '登录请求超时，请检查网络后重试'
                    : (error?.message || '网络连接失败，请检查网络后重试');
                setError(message);
                updateSubmit();
            }
        });
        updateSubmit();
        scheduleLoginAutofillSync();
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
    start();
})();
