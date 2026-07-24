(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.SuxiFormOperationSupport = api;
    if (root.document && root.localStorage) {
        const start = () => api.init(root);
        if (root.document.readyState === 'loading') {
            root.document.addEventListener('DOMContentLoaded', start, { once: true });
        } else {
            start();
        }
    }
})(typeof window !== 'undefined' ? window : globalThis, function () {
    const DRAFT_PREFIX = 'suxios.form.draft.v1';
    const HISTORY_KEY = 'suxios.form.history.v1';
    const ARCHIVE_KEY = 'suxios.form.archive.v1';
    const MAX_HISTORY = 100;
    const MAX_ARCHIVE = 100;
    const SENSITIVE_FIELD = /password|passwd|pwd|token|cookie|cookies|secret|api[_-]?key|apikey|authorization|auth_data|spidertoken|mtgsig|webhook/i;
    const SKIPPED_TYPES = new Set(['button', 'submit', 'reset', 'hidden', 'file', 'image', 'password']);
    const WRITE_METHODS = new Set(['POST', 'PUT', 'PATCH']);

    function safeJsonParse(raw, fallback) {
        if (!raw) return fallback;
        try {
            const parsed = JSON.parse(raw);
            return parsed ?? fallback;
        } catch (error) {
            return fallback;
        }
    }

    function normalizeMethod(input, options) {
        if (typeof options === 'string') return options.toUpperCase();
        if (options && typeof options.method === 'string') return options.method.toUpperCase();
        if (input && typeof input.method === 'string') return input.method.toUpperCase();
        return 'GET';
    }

    function normalizePath(input) {
        const raw = typeof input === 'string'
            ? input
            : input && typeof input.url === 'string'
                ? input.url
                : '';
        if (!raw) return '';
        try {
            return new URL(raw, 'http://suxios.local').pathname;
        } catch (error) {
            return raw.split('?')[0].split('#')[0];
        }
    }

    function classifyOperation(input, options = {}) {
        const method = normalizeMethod(input, options || {});
        const path = normalizePath(input);
        const lowerPath = path.toLowerCase();
        if (!path || method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return null;
        if (/^\/?api\/(auth|health|operation-logs)(\/|$)/i.test(lowerPath)) return null;
        if (!/^\/?(api|admin|compass)(\/|$)/i.test(lowerPath)) return null;

        if (method === 'DELETE' || /\/(archive|delete|disable|clear|reset)(\/|$|-)/i.test(lowerPath)) {
            return { type: 'archive', method, path };
        }

        if (WRITE_METHODS.has(method)) {
            return { type: 'save', method, path };
        }

        return null;
    }

    function fieldIdentity(field) {
        const attr = (name) => typeof field.getAttribute === 'function' ? field.getAttribute(name) : '';
        return [
            field.name,
            field.id,
            attr('data-testid'),
            attr('aria-label'),
            attr('placeholder'),
            field.type,
        ].filter(Boolean).join(' ');
    }

    function shouldPersistField(field) {
        if (!field) return false;
        const type = String(field.type || '').toLowerCase();
        if (SKIPPED_TYPES.has(type)) return false;
        if (field.disabled || field.readOnly) return false;
        return !SENSITIVE_FIELD.test(fieldIdentity(field));
    }

    function createStore({ storage, now = () => Date.now() } = {}) {
        const backend = storage || (typeof localStorage !== 'undefined' ? localStorage : null);
        const read = (key, fallback) => backend ? safeJsonParse(backend.getItem(key), fallback) : fallback;
        const write = (key, value) => {
            if (!backend) return;
            try {
                backend.setItem(key, JSON.stringify(value));
            } catch (error) {
                // Storage quota or privacy mode must not block form work.
            }
        };

        return {
            getDraft(key) {
                return read(`${DRAFT_PREFIX}:${key}`, {});
            },
            setDraft(key, value) {
                write(`${DRAFT_PREFIX}:${key}`, value || {});
            },
            clearDraft(key) {
                if (backend) backend.removeItem(`${DRAFT_PREFIX}:${key}`);
            },
            getHistory() {
                return read(HISTORY_KEY, []);
            },
            getArchive() {
                return read(ARCHIVE_KEY, []);
            },
            recordHistory(entry) {
                if (!entry || !entry.type) return;
                const item = {
                    id: `${now()}-${Math.random().toString(36).slice(2, 8)}`,
                    at: new Date(now()).toISOString(),
                    type: entry.type,
                    method: entry.method || '',
                    url: entry.url || entry.path || '',
                    status: entry.status ?? null,
                    ok: entry.ok === true,
                };
                const history = [item, ...this.getHistory()].slice(0, MAX_HISTORY);
                write(HISTORY_KEY, history);
                if (item.type === 'archive') {
                    const archived = [item, ...this.getArchive()].slice(0, MAX_ARCHIVE);
                    write(ARCHIVE_KEY, archived);
                }
            },
        };
    }

    function fieldKey(field) {
        const attr = (name) => field.getAttribute(name);
        return field.name || field.id || attr('data-testid') || attr('aria-label') || attr('placeholder') || '';
    }

    function formFields(scope) {
        return Array.from(scope.querySelectorAll('input, textarea, select')).filter(shouldPersistField);
    }

    function hashText(text) {
        let hash = 0;
        for (let i = 0; i < text.length; i += 1) {
            hash = ((hash << 5) - hash) + text.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash).toString(36);
    }

    function pageKey(doc) {
        const activePage = Array.from(doc.querySelectorAll('[data-testid^="page-"]'))
            .find((node) => node.offsetParent !== null);
        return activePage?.getAttribute('data-testid') || globalThis.location?.pathname || 'suxios';
    }

    function scopeKey(scope, doc) {
        if (scope.dataset?.formKey) return scope.dataset.formKey;
        const forms = Array.from(doc.querySelectorAll('form, [role="dialog"], .modal'));
        const index = Math.max(0, forms.indexOf(scope));
        const fields = formFields(scope).slice(0, 8).map(fieldKey).join('|') || scope.tagName || 'form';
        return `${pageKey(doc)}:${index}:${hashText(fields)}`;
    }

    function readField(field) {
        const type = String(field.type || '').toLowerCase();
        if (type === 'checkbox') return field.checked;
        if (type === 'radio') return field.checked ? field.value : undefined;
        return field.value;
    }

    function writeField(field, value) {
        const type = String(field.type || '').toLowerCase();
        if (value === undefined || value === null) return;
        if (type === 'checkbox') {
            field.checked = Boolean(value);
        } else if (type === 'radio') {
            if (String(field.value) === String(value)) field.checked = true;
        } else if (String(field.value || '') === '') {
            field.value = String(value);
        } else {
            return;
        }
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function collectDraft(scope) {
        const draft = {};
        formFields(scope).forEach((field) => {
            const key = fieldKey(field);
            if (!key) return;
            const value = readField(field);
            if (value !== undefined) draft[key] = value;
        });
        return draft;
    }

    function findScope(field) {
        return field.closest('form, [role="dialog"], .modal') || field.closest('main') || field.ownerDocument.body;
    }

    function applyDraft(scope, store, doc) {
        const key = scopeKey(scope, doc);
        const draft = store.getDraft(key);
        if (!draft || typeof draft !== 'object') return;
        formFields(scope).forEach((field) => {
            const keyName = fieldKey(field);
            if (keyName && Object.prototype.hasOwnProperty.call(draft, keyName)) {
                writeField(field, draft[keyName]);
            }
        });
    }

    function persistDraft(scope, store, doc) {
        const draft = collectDraft(scope);
        if (Object.keys(draft).length > 0) {
            store.setDraft(scopeKey(scope, doc), draft);
        }
    }

    function init(appRoot = root) {
        const doc = appRoot.document;
        if (!doc || appRoot.__suxiFormOperationSupportInitialized) return null;
        appRoot.__suxiFormOperationSupportInitialized = true;
        const store = createStore({ storage: appRoot.localStorage });

        const scan = () => {
            doc.querySelectorAll('form, [role="dialog"], .modal').forEach((scope) => applyDraft(scope, store, doc));
        };
        scan();

        doc.addEventListener('input', (event) => {
            const field = event.target;
            if (!shouldPersistField(field)) return;
            persistDraft(findScope(field), store, doc);
        }, true);

        doc.addEventListener('change', (event) => {
            const field = event.target;
            if (!shouldPersistField(field)) return;
            persistDraft(findScope(field), store, doc);
        }, true);

        if (typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(() => scan());
            observer.observe(doc.body, { childList: true, subtree: true });
        }

        if (typeof appRoot.fetch === 'function' && !appRoot.fetch.__suxiFormOperationPatched) {
            const originalFetch = appRoot.fetch.bind(appRoot);
            const patchedFetch = async (...args) => {
                const operation = classifyOperation(args[0], args[1] || {});
                const response = await originalFetch(...args);
                if (operation) {
                    store.recordHistory({
                        ...operation,
                        url: operation.path,
                        status: response.status,
                        ok: response.ok,
                    });
                }
                return response;
            };
            patchedFetch.__suxiFormOperationPatched = true;
            appRoot.fetch = patchedFetch;
        }

        appRoot.SuxiFormOperations = {
            history: () => store.getHistory(),
            archive: () => store.getArchive(),
            clearDraft: (key) => store.clearDraft(key),
        };

        return store;
    }

    return {
        init,
        createStore,
        shouldPersistField,
        classifyOperation,
        writeField,
    };
});
