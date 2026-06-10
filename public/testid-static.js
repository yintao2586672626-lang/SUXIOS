window.SUXI_TESTID_STATIC = (() => {
    const stableHashSegment = (value) => {
        let hash = 0;
        Array.from(String(value || '')).forEach((char) => {
            hash = ((hash << 5) - hash + char.charCodeAt(0)) >>> 0;
        });
        return hash.toString(36);
    };

    const normalizeTestIdSegment = (value, testIdNameMap = {}) => {
        const raw = String(value || '').trim().replace(/\s+/g, ' ');
        if (!raw) return 'unknown';
        if (testIdNameMap[raw]) return testIdNameMap[raw];
        const ascii = raw
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        return ascii || `zh-${stableHashSegment(raw)}`;
    };

    const createPageTestIdController = ({
        testIdNameMap = {},
        getMenuItemName,
        getCurrentPage,
        nextTick,
    }) => {
        if (typeof getCurrentPage !== 'function') {
            throw new Error('Missing getCurrentPage for test id controller.');
        }
        if (typeof nextTick !== 'function') {
            throw new Error('Missing nextTick for test id controller.');
        }

        const normalize = (value) => normalizeTestIdSegment(value, testIdNameMap);
        const pageTestId = (page) => `page-${normalize(page)}`;
        const menuTestId = (item) => {
            if (!item) return 'nav-unknown';
            if (item.testid) return item.testid;
            const menuName = typeof getMenuItemName === 'function' ? getMenuItemName(item) : item.name;
            return `nav-${normalize(item.path || menuName || item.name)}`;
        };

        let pageControlTestIdObserver = null;
        let pageControlTestIdObserverRoot = null;
        let pageControlTestIdPending = false;

        const pageControlTestIdsEnabled = () => {
            try {
                const host = window.location.hostname;
                if (host === 'localhost' || host === '127.0.0.1' || host === '::1') return true;
                const params = new URLSearchParams(window.location.search || '');
                if (params.get('testids') === '1' || params.get('e2e') === '1') return true;
                return localStorage.getItem('enablePageTestIds') === '1';
            } catch (error) {
                return false;
            }
        };

        const stopPageControlTestIdObserver = () => {
            if (pageControlTestIdObserver) {
                pageControlTestIdObserver.disconnect();
                pageControlTestIdObserver = null;
                pageControlTestIdObserverRoot = null;
            }
        };

        const elementVisibleForTestId = (element) => {
            const style = window.getComputedStyle(element);
            return style.visibility !== 'hidden'
                && style.display !== 'none'
                && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
        };

        const nearbyControlLabel = (element) => {
            const attrLabel = element.getAttribute('aria-label') || element.getAttribute('title') || element.getAttribute('placeholder') || element.getAttribute('name');
            if (attrLabel) return attrLabel;
            if (element.id) {
                const explicitLabel = document.querySelector(`label[for="${CSS.escape(element.id)}"]`);
                if (explicitLabel?.innerText) return explicitLabel.innerText;
            }
            const wrappedLabel = element.closest('label');
            if (wrappedLabel?.innerText) return wrappedLabel.innerText;
            const container = element.closest('div');
            const label = container?.querySelector('label');
            if (label?.innerText) return label.innerText;
            return element.innerText || element.textContent || element.type || element.tagName;
        };

        const pageControlTestIdRoot = () => {
            const pageId = pageTestId(getCurrentPage());
            return document.querySelector(`[data-testid="${pageId}"]`)
                || document.querySelector('main[data-current-page]')
                || document.querySelector('main');
        };

        const assignPageControlTestIds = () => {
            const root = pageControlTestIdRoot();
            if (!root) return;
            const used = new Set(Array.from(root.querySelectorAll('[data-testid]')).map(el => el.dataset.testid));
            if (root.dataset?.testid) used.add(root.dataset.testid);
            const uniqueId = (base) => {
                let id = base;
                let index = 2;
                while (used.has(id)) {
                    id = `${base}-${index}`;
                    index += 1;
                }
                used.add(id);
                return id;
            };

            root.querySelectorAll('button').forEach((button) => {
                if (button.dataset.testid || !elementVisibleForTestId(button)) return;
                const label = nearbyControlLabel(button);
                button.dataset.testid = uniqueId(`button-${normalize(getCurrentPage())}-${normalize(label)}`);
            });

            root.querySelectorAll('input, textarea, select').forEach((field) => {
                if (field.dataset.testid || !elementVisibleForTestId(field)) return;
                const label = nearbyControlLabel(field);
                field.dataset.testid = uniqueId(`field-${normalize(getCurrentPage())}-${normalize(label)}`);
            });
        };

        const scheduleTestIdRefresh = () => {
            if (!pageControlTestIdsEnabled()) return;
            if (pageControlTestIdPending) return;
            pageControlTestIdPending = true;
            nextTick(() => {
                pageControlTestIdPending = false;
                assignPageControlTestIds();
            });
        };

        const startPageControlTestIdObserver = () => {
            if (!pageControlTestIdsEnabled()) {
                stopPageControlTestIdObserver();
                return;
            }
            nextTick(() => {
                const root = pageControlTestIdRoot();
                if (!root) {
                    scheduleTestIdRefresh();
                    return;
                }
                if (pageControlTestIdObserver && pageControlTestIdObserverRoot !== root) {
                    pageControlTestIdObserver.disconnect();
                    pageControlTestIdObserver = null;
                    pageControlTestIdObserverRoot = null;
                }
                if (pageControlTestIdObserver) {
                    scheduleTestIdRefresh();
                    return;
                }
                pageControlTestIdObserver = new MutationObserver(scheduleTestIdRefresh);
                pageControlTestIdObserverRoot = root;
                pageControlTestIdObserver.observe(root, { childList: true, subtree: true });
                assignPageControlTestIds();
            });
        };

        return {
            pageTestId,
            menuTestId,
            startPageControlTestIdObserver,
            stopPageControlTestIdObserver,
            assignPageControlTestIds,
            scheduleTestIdRefresh,
        };
    };

    return {
        stableHashSegment,
        normalizeTestIdSegment,
        createPageTestIdController,
    };
})();
