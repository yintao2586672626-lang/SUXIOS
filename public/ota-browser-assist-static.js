(function (global) {
    'use strict';

    const CONTRACT_VERSION = 'ota_browser_assist_collection_contract.v1';
    const COLLECTION_MODE = 'browser_assist_dom';

    const buildOtaBrowserAssistCollectorScript = () => String.raw`
// ==UserScript==
// @name         SUXIOS OTA browser assist collector
// @namespace    https://suxios.local/
// @version      0.1.0
// @description  Read visible Ctrip/Meituan OTA page text and produce supplemental JSON for SUXIOS import.
// @match        https://ebooking.ctrip.com/*
// @match        https://me.meituan.com/*
// @match        https://eb.meituan.com/*
// @grant        none
// @run-at       document-idle
// ==/UserScript==

(function () {
    'use strict';

    var CONTRACT_VERSION = 'ota_browser_assist_collection_contract.v1';
    var COLLECTION_MODE = 'browser_assist_dom';
    var state = { lastCapture: null };

    var clean = function (value) {
        return String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
    };

    var pad = function (value) {
        return String(value).padStart(2, '0');
    };

    var formatDate = function (date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    };

    var formatDateTime = function (date) {
        return formatDate(date) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
    };

    var visible = function (node) {
        if (!node || !node.getBoundingClientRect) return false;
        var rect = node.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return false;
        if (!window.getComputedStyle) return true;
        var style = window.getComputedStyle(node);
        return style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) !== 0;
    };

    var textOf = function (node) {
        return clean(node && (node.innerText || node.textContent || ''));
    };

    var bodyText = function () {
        return textOf(document.body);
    };

    var detectPlatform = function () {
        var host = String(window.location.hostname || '').toLowerCase();
        if (host.indexOf('ctrip.com') >= 0) return 'ctrip';
        if (host.indexOf('meituan.com') >= 0) return 'meituan';
        return 'unknown';
    };

    var numberFrom = function (value) {
        var text = clean(value).replace(/,/g, '');
        var match = text.match(/-?\d+(?:\.\d+)?%?/);
        if (!match) return null;
        var number = Number(match[0].replace('%', ''));
        return Number.isFinite(number) ? number : null;
    };

    var valueAfter = function (sourceText, labels) {
        for (var i = 0; i < labels.length; i += 1) {
            var label = labels[i];
            var index = sourceText.indexOf(label);
            if (index < 0) continue;
            var raw = clean(sourceText.slice(index, index + label.length + 80));
            var value = numberFrom(sourceText.slice(index + label.length, index + label.length + 80));
            if (value != null) {
                return { label: label, value: String(value), rawText: raw };
            }
        }
        return null;
    };

    var normalizeDateText = function (value) {
        var text = clean(value);
        var full = text.match(/(20\d{2})[-/.年](\d{1,2})[-/.月](\d{1,2})/);
        if (full) return full[1] + '-' + pad(full[2]) + '-' + pad(full[3]);
        var monthDay = text.match(/(^|[^\d])(\d{1,2})月(\d{1,2})日/);
        if (monthDay) return new Date().getFullYear() + '-' + pad(monthDay[2]) + '-' + pad(monthDay[3]);
        var shortDate = text.match(/(^|[^\d])(\d{1,2})[-/.](\d{1,2})([^\d]|$)/);
        if (shortDate) return new Date().getFullYear() + '-' + pad(shortDate[2]) + '-' + pad(shortDate[3]);
        return '';
    };

    var statusFrom = function (text) {
        if (/关房|关闭|停售|下架/.test(text)) return 'closed';
        if (/满房|售罄|无房/.test(text)) return 'sold_out';
        if (/开房|开放|可售|售卖中/.test(text)) return 'open';
        return '';
    };

    var numberAfterLabels = function (text, labels) {
        var item = valueAfter(text, labels);
        return item ? item.value : '';
    };

    var hasMetricValue = function (metrics) {
        return Object.keys(metrics || {}).some(function (key) {
            return Boolean(metrics[key]);
        });
    };

    var safeUrlEvidence = function (url) {
        try {
            var parsed = new URL(String(url || ''), window.location.href);
            return {
                host: parsed.hostname,
                path: parsed.pathname
            };
        } catch (error) {
            return { host: '', path: '' };
        }
    };

    var identityFromUrl = function (url, source) {
        try {
            var parsed = new URL(String(url || ''), window.location.href);
            var partnerId = clean(parsed.searchParams.get('partnerId') || parsed.searchParams.get('partner_id') || '');
            var poiId = clean(parsed.searchParams.get('poiId') || parsed.searchParams.get('poi_id') || parsed.searchParams.get('storeId') || parsed.searchParams.get('store_id') || '');
            if (!partnerId && !poiId) return null;
            var evidence = safeUrlEvidence(parsed.href);
            return {
                source: source,
                host: evidence.host,
                path: evidence.path,
                partnerId: partnerId,
                poiId: poiId
            };
        } catch (error) {
            return null;
        }
    };

    var collectMeituanIdentity = function () {
        var candidates = [];
        var current = identityFromUrl(window.location.href, 'location_search');
        if (current) candidates.push(current);
        if (window.performance && typeof window.performance.getEntriesByType === 'function') {
            window.performance.getEntriesByType('resource').slice(-120).forEach(function (entry) {
                var name = String(entry && entry.name || '');
                if (!/^https:\/\/(?:eb\.meituan\.com|meituan\.com|www\.meituan\.com)\/api\//i.test(name)) return;
                var item = identityFromUrl(name, 'performance_resource');
                if (item) candidates.push(item);
            });
        }

        var partnerId = '';
        var poiId = '';
        var evidence = [];
        candidates.forEach(function (item) {
            if (!partnerId && item.partnerId) partnerId = item.partnerId;
            if (!poiId && item.poiId) poiId = item.poiId;
            evidence.push({
                source: item.source,
                host: item.host,
                path: item.path,
                fields: [
                    item.partnerId ? 'partnerId' : '',
                    item.poiId ? 'poiId' : ''
                ].filter(Boolean)
            });
        });

        if (!partnerId && !poiId) return null;
        return {
            platform: 'meituan',
            updatedAt: formatDateTime(new Date()),
            partnerId: partnerId,
            poiId: poiId,
            evidence: evidence.slice(0, 12)
        };
    };

    var uniqueVisibleNodes = function (selectors, root) {
        var seen = [];
        var nodes = [];
        selectors.forEach(function (selector) {
            var list = Array.prototype.slice.call((root || document).querySelectorAll(selector));
            list.forEach(function (node) {
                if (seen.indexOf(node) >= 0 || !visible(node)) return;
                seen.push(node);
                nodes.push(node);
            });
        });
        return nodes;
    };

    var roomNameFrom = function (node) {
        var selectors = [
            '[class*="room-name"]',
            '[class*="roomName"]',
            '[class*="room-title"]',
            '[class*="RoomName"]',
            '[class*="product-name"]',
            '[class*="name"]'
        ];
        for (var i = 0; i < selectors.length; i += 1) {
            var child = node.querySelector(selectors[i]);
            var text = textOf(child);
            if (text && text.length <= 80) return text;
        }
        return textOf(node).split(' ').filter(Boolean).slice(0, 8).join(' ').slice(0, 80);
    };

    var parseDayNode = function (node) {
        var text = textOf(node);
        if (!text) return null;
        var date = normalizeDateText(
            node.getAttribute('data-date') ||
            node.getAttribute('data-day') ||
            node.getAttribute('title') ||
            text
        );
        var state = statusFrom(text);
        var remain = numberAfterLabels(text, ['剩余', '可售', '库存', '余量', '剩']);
        var reserved = numberAfterLabels(text, ['预留', '保留', '锁房']);
        var sold = numberAfterLabels(text, ['已售', '售出', '销量']);
        if (!date && !state && !remain && !reserved && !sold) return null;
        return {
            date: date,
            state: state,
            remain: remain,
            remainText: remain ? '剩余' + remain : '',
            reserved: reserved,
            sold: sold,
            rawText: text.slice(0, 240)
        };
    };

    var parseInventoryRows = function (platform, warnings) {
        var roomSelectors = [
            '#roomlistContainer .son-room-list',
            '.son-room-list',
            '.physics-room-box',
            '[class*="room-list"]',
            '[class*="roomList"]',
            '[class*="RoomList"]',
            '[class*="room-row"]',
            '[class*="RoomRow"]'
        ];
        var daySelectors = [
            '[data-date]',
            '[data-day]',
            '.son-room-box',
            '[class*="calendar"] td',
            '[class*="date-cell"]',
            '[class*="day-cell"]',
            'td',
            'li'
        ];
        var roomNodes = uniqueVisibleNodes(roomSelectors, document);
        var rooms = [];
        roomNodes.slice(0, 80).forEach(function (roomNode) {
            var name = roomNameFrom(roomNode);
            var days = uniqueVisibleNodes(daySelectors, roomNode)
                .map(parseDayNode)
                .filter(Boolean)
                .slice(0, 60);
            if (!days.length) {
                var wholeNodeDay = parseDayNode(roomNode);
                if (wholeNodeDay) days = [wholeNodeDay];
            }
            if (name || days.length) {
                rooms.push({ name: name, days: days });
            }
        });
        if (!rooms.length) {
            warnings.push({
                platform: platform,
                module: platform + '_inventory',
                code: 'selector_not_found',
                message: '当前页未识别房态行；请确认已打开并展开 OTA 房态日历页面。'
            });
        }
        return {
            updatedAt: formatDateTime(new Date()),
            pageTitle: clean(document.title),
            rooms: rooms
        };
    };

    var parseRealtimeMetrics = function (platform, warnings) {
        var text = bodyText();
        if (platform === 'ctrip') {
            var ctrip = {
                realtimeVisitors: valueAfter(text, ['实时访客量', '实时访客', '访客量']),
                visitorPeerAvg: valueAfter(text, ['竞争圈平均', '竞品平均', '同行平均']),
                orderConversionRate: valueAfter(text, ['实时下单转化率', '下单转化率', '转化率']),
                realtimeRank: valueAfter(text, ['实时排名', '排名'])
            };
            var qunar = {
                realtimeVisitors: valueAfter(text, ['去哪儿实时访客量', '去哪儿访客', 'Qunar访客']),
                visitorPeerAvg: valueAfter(text, ['去哪儿竞争圈平均', '去哪儿同行平均']),
                orderConversionRate: valueAfter(text, ['去哪儿实时下单转化率', '去哪儿转化率']),
                realtimeRank: valueAfter(text, ['去哪儿实时排名', '去哪儿排名'])
            };
            var ctripFound = hasMetricValue(ctrip);
            var qunarFound = hasMetricValue(qunar);
            if (!ctripFound && !qunarFound) {
                warnings.push({
                    platform: 'ctrip',
                    module: 'ctrip_stats',
                    code: 'metrics_not_found',
                    message: '当前页未识别携程/去哪儿实时指标。'
                });
                return null;
            }
            var ctripMetrics = {};
            if (ctripFound) ctripMetrics.ctrip = ctrip;
            if (qunarFound) ctripMetrics.qunar = qunar;
            return {
                updatedAt: formatDateTime(new Date()),
                metrics: ctripMetrics
            };
        }
        if (platform === 'meituan') {
            var metrics = {
                exposureUsers: valueAfter(text, ['曝光人数', '曝光用户', '曝光']),
                browseUsers: valueAfter(text, ['浏览人数', '浏览用户', '浏览']),
                paidOrders: valueAfter(text, ['支付订单数', '支付订单', '订单数']),
                exposureBrowseRate: valueAfter(text, ['曝光-浏览', '曝光浏览转化率']),
                browsePayRate: valueAfter(text, ['浏览-支付', '浏览支付转化率'])
            };
            if (!hasMetricValue(metrics)) {
                warnings.push({
                    platform: 'meituan',
                    module: 'meituan_stats',
                    code: 'metrics_not_found',
                    message: '当前页未识别美团实时流量指标。'
                });
                return null;
            }
            return {
                updatedAt: formatDateTime(new Date()),
                metrics: metrics
            };
        }
        return null;
    };

    var sanitizedPage = function () {
        return {
            host: window.location.hostname,
            path: window.location.pathname,
            title: clean(document.title)
        };
    };

    var buildCapture = function () {
        var platform = detectPlatform();
        var warnings = [];
        var now = formatDateTime(new Date());
        var capture = {
            source_contract: CONTRACT_VERSION,
            collection_mode: COLLECTION_MODE,
            generatedAt: now,
            snapshotTime: now,
            page: sanitizedPage(),
            warnings: warnings
        };
        if (platform === 'ctrip') {
            capture.ctrip = parseInventoryRows('ctrip', warnings);
            var ctripStats = parseRealtimeMetrics('ctrip', warnings);
            if (ctripStats) capture.ctripStats = ctripStats;
        } else if (platform === 'meituan') {
            capture.meituan = parseInventoryRows('meituan', warnings);
            var meituanStats = parseRealtimeMetrics('meituan', warnings);
            if (meituanStats) capture.meituanStats = meituanStats;
            var meituanIdentity = collectMeituanIdentity();
            if (meituanIdentity) capture.platformIdentity = meituanIdentity;
        } else {
            warnings.push({
                platform: 'unknown',
                module: 'browser_assist',
                code: 'unsupported_host',
                message: '当前域名不是已配置的携程/美团后台页面。'
            });
        }
        return capture;
    };

    var setStatus = function (message) {
        var node = document.getElementById('suxi-ota-browser-assist-status');
        if (node) node.textContent = message;
    };

    var updateOutput = function () {
        state.lastCapture = buildCapture();
        var output = document.getElementById('suxi-ota-browser-assist-output');
        if (output) output.value = JSON.stringify(state.lastCapture, null, 2);
        var warningCount = state.lastCapture.warnings ? state.lastCapture.warnings.length : 0;
        setStatus('已生成当前页 JSON，提醒 ' + warningCount + ' 条。');
    };

    var copyOutput = function () {
        if (!state.lastCapture) updateOutput();
        var text = JSON.stringify(state.lastCapture, null, 2);
        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            setStatus('浏览器不支持自动复制，请手动复制文本框内容。');
            return;
        }
        navigator.clipboard.writeText(text).then(function () {
            setStatus('已复制 JSON，可回到宿析OS导入。');
        }).catch(function () {
            setStatus('复制失败，请手动复制文本框内容。');
        });
    };

    var downloadOutput = function () {
        if (!state.lastCapture) updateOutput();
        var blob = new Blob([JSON.stringify(state.lastCapture, null, 2)], { type: 'application/json' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'suxios-ota-browser-assist-' + formatDate(new Date()) + '.json';
        link.click();
        URL.revokeObjectURL(link.href);
    };

    var createButton = function (label, action) {
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.style.cssText = 'border:1px solid #047857;background:#fff;color:#047857;border-radius:6px;padding:6px 8px;font-size:12px;cursor:pointer;';
        button.addEventListener('click', action);
        return button;
    };

    var mountPanel = function () {
        if (document.getElementById('suxi-ota-browser-assist-panel')) return;
        var panel = document.createElement('div');
        panel.id = 'suxi-ota-browser-assist-panel';
        panel.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:2147483647;width:360px;max-width:calc(100vw - 36px);background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;box-shadow:0 16px 40px rgba(15,23,42,.18);font-family:Arial,"Microsoft YaHei",sans-serif;color:#064e3b;';

        var header = document.createElement('div');
        header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid #a7f3d0;';
        var title = document.createElement('div');
        title.textContent = 'SUXIOS OTA 辅助采集';
        title.style.cssText = 'font-weight:700;font-size:13px;';
        var close = createButton('关闭', function () { panel.remove(); });
        header.appendChild(title);
        header.appendChild(close);

        var body = document.createElement('div');
        body.style.cssText = 'padding:10px 12px;';
        var note = document.createElement('div');
        note.textContent = '只读取当前已授权页面的可见文字和必要平台标识，生成 OTA 渠道补充 JSON；不读取 Cookie，选择器未命中会保留提醒。';
        note.style.cssText = 'font-size:12px;line-height:1.5;margin-bottom:8px;';
        var actions = document.createElement('div');
        actions.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;';
        actions.appendChild(createButton('采集当前页', updateOutput));
        actions.appendChild(createButton('复制JSON', copyOutput));
        actions.appendChild(createButton('下载JSON', downloadOutput));
        var output = document.createElement('textarea');
        output.id = 'suxi-ota-browser-assist-output';
        output.readOnly = true;
        output.style.cssText = 'box-sizing:border-box;width:100%;height:160px;border:1px solid #a7f3d0;border-radius:6px;padding:8px;font-family:Consolas,monospace;font-size:11px;background:#fff;color:#111827;';
        var status = document.createElement('div');
        status.id = 'suxi-ota-browser-assist-status';
        status.textContent = '等待采集。';
        status.style.cssText = 'font-size:12px;margin-top:6px;color:#047857;';
        body.appendChild(note);
        body.appendChild(actions);
        body.appendChild(output);
        body.appendChild(status);
        panel.appendChild(header);
        panel.appendChild(body);
        document.body.appendChild(panel);
        updateOutput();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountPanel, { once: true });
    } else {
        mountPanel();
    }
})();
`.trim();

    global.SUXI_OTA_BROWSER_ASSIST_STATIC = Object.freeze({
        CONTRACT_VERSION,
        COLLECTION_MODE,
        buildOtaBrowserAssistCollectorScript,
    });
})(typeof window !== 'undefined' ? window : globalThis);
