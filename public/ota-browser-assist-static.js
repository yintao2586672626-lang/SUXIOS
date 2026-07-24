(function (global) {
    'use strict';

    const CONTRACT_VERSION = 'ota_browser_assist_collection_contract.v1';
    const COLLECTION_MODE = 'browser_assist_dom';

    const buildOtaBrowserAssistCollectorScript = () => String.raw`
// ==UserScript==
// @name         SUXIOS OTA browser assist collector
// @namespace    https://suxios.local/
// @version      0.2.0
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
    var state = {
        lastCapture: null,
        captureTemplate: 'auto',
        selectedRegion: null,
        selectedSelector: '',
        selectedAt: '',
        selectedStyle: null,
        hoveredRegion: null,
        hoveredStyle: null,
        selectionCleanup: null
    };

    var clean = function (value) {
        return String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
    };

    var containsSensitiveText = function (value) {
        var text = String(value == null ? '' : value);
        return /(?:password|passwd|pwd|token|authorization|cookie|secret|session[_-]?(?:id|token)|access[_-]?key|api[_-]?key|账号|账户|用户名|密码|口令|令牌|密钥)/i.test(text)
            || /\bBearer\s+[A-Za-z0-9._~+/-]{8,}/i.test(text)
            || /\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}(?:\.[A-Za-z0-9_-]{8,})?\b/.test(text)
            || /\b(?:sk|rk|pk)-(?:proj-)?[A-Za-z0-9_-]{12,}\b/i.test(text);
    };

    var redactEvidenceText = function (value, maxLength) {
        var text = clean(value);
        if (!text) return '';
        if (containsSensitiveText(text)) return '[REDACTED]';
        return text.slice(0, maxLength);
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
        if (!node || typeof node.innerText !== 'string') return '';
        return clean(node.innerText);
    };

    var bodyText = function (root) {
        return textOf(root || document.body);
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

    var occurrenceInsidePhrase = function (sourceText, index, label, phrases) {
        return (phrases || []).some(function (phrase) {
            var windowStart = Math.max(0, index - phrase.length);
            var windowEnd = Math.min(sourceText.length, index + label.length + phrase.length);
            var phraseIndex = sourceText.indexOf(phrase, windowStart);
            while (phraseIndex >= 0 && phraseIndex < windowEnd) {
                if (index >= phraseIndex && index + label.length <= phraseIndex + phrase.length) {
                    return true;
                }
                phraseIndex = sourceText.indexOf(phrase, phraseIndex + 1);
            }
            return false;
        });
    };

    var valueAfter = function (sourceText, labels, excludedPhrases) {
        for (var i = 0; i < labels.length; i += 1) {
            var label = labels[i];
            var searchIndex = 0;
            while (searchIndex < sourceText.length) {
                var index = sourceText.indexOf(label, searchIndex);
                if (index < 0) break;
                searchIndex = index + label.length;
                if (occurrenceInsidePhrase(sourceText, index, label, excludedPhrases)) continue;
                var raw = redactEvidenceText(sourceText.slice(index, index + label.length + 80), label.length + 80);
                var value = numberFrom(sourceText.slice(index + label.length, index + label.length + 80));
                if (value != null) {
                    return { label: label, value: String(value), rawText: raw };
                }
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

    var escapeAttributeValue = function (value) {
        return String(value == null ? '' : value)
            .replace(/\\/g, '\\\\')
            .replace(/"/g, '\\"')
            .replace(/\r?\n/g, ' ');
    };

    var selectorCount = function (selector) {
        if (!selector) return 0;
        try {
            return document.querySelectorAll(selector).length;
        } catch (error) {
            return 0;
        }
    };

    var selectorPointsTo = function (selector, node) {
        if (!selector || !node) return false;
        try {
            return selectorCount(selector) === 1 && document.querySelector(selector) === node;
        } catch (error) {
            return false;
        }
    };

    var buildUniqueSelector = function (node) {
        if (!node || node.nodeType !== 1) return '';
        var tag = String(node.tagName || '').toLowerCase();
        var id = clean(node.getAttribute('id') || '');
        if (id) {
            var idSelector = '[id="' + escapeAttributeValue(id) + '"]';
            if (selectorPointsTo(idSelector, node)) return idSelector;
        }

        var stableAttributes = ['data-testid', 'data-test', 'data-qa', 'aria-label', 'name', 'role'];
        for (var i = 0; i < stableAttributes.length; i += 1) {
            var attribute = stableAttributes[i];
            var value = clean(node.getAttribute(attribute) || '');
            if (!value || value.length > 120) continue;
            var attributeSelector = tag + '[' + attribute + '="' + escapeAttributeValue(value) + '"]';
            if (selectorPointsTo(attributeSelector, node)) return attributeSelector;
        }

        var stableClasses = Array.prototype.slice.call(node.classList || [])
            .filter(function (className) {
                return /^[A-Za-z_][A-Za-z0-9_-]{1,48}$/.test(className)
                    && !/(active|current|selected|hover|focus|open|checked|disabled)$/i.test(className);
            })
            .slice(0, 3);
        for (var classCount = 1; classCount <= stableClasses.length; classCount += 1) {
            var classSelector = tag + stableClasses.slice(0, classCount).map(function (className) {
                return '.' + className;
            }).join('');
            if (selectorPointsTo(classSelector, node)) return classSelector;
        }

        var parts = [];
        var current = node;
        while (current && current.nodeType === 1 && parts.length < 7) {
            var currentTag = String(current.tagName || '').toLowerCase();
            if (!currentTag) break;
            var part = currentTag;
            var parent = current.parentElement;
            if (parent) {
                var siblings = Array.prototype.filter.call(parent.children, function (child) {
                    return String(child.tagName || '').toLowerCase() === currentTag;
                });
                if (siblings.length > 1) {
                    part += ':nth-of-type(' + (siblings.indexOf(current) + 1) + ')';
                }
            }
            parts.unshift(part);
            var pathSelector = parts.join(' > ');
            if (selectorPointsTo(pathSelector, node)) return pathSelector;
            current = parent;
        }
        return parts.join(' > ').slice(0, 320);
    };

    var styleSnapshot = function (node) {
        return {
            outline: node && node.style ? node.style.outline : '',
            outlineOffset: node && node.style ? node.style.outlineOffset : '',
            cursor: node && node.style ? node.style.cursor : ''
        };
    };

    var restoreStyle = function (node, snapshot) {
        if (!node || !node.style || !snapshot) return;
        node.style.outline = snapshot.outline;
        node.style.outlineOffset = snapshot.outlineOffset;
        node.style.cursor = snapshot.cursor;
    };

    var invalidateLastCapture = function () {
        state.lastCapture = null;
        var output = document.getElementById('suxi-ota-browser-assist-output');
        if (output) output.value = '';
    };

    var renderRegionStatus = function () {
        var node = document.getElementById('suxi-ota-browser-assist-region-status');
        if (!node) return;
        if (!state.selectedRegion) {
            node.textContent = '目标区域：未圈选。';
            return;
        }
        var contract = buildTargetRegionContract();
        var labels = {
            ready: '可采集',
            stale: '页面状态已变化，请重新圈选',
            not_unique: '定位不唯一，请重新圈选',
            empty: '区域没有可见文字',
            not_selected: '未圈选'
        };
        node.textContent = '目标区域：' + (labels[contract.status] || contract.status)
            + '｜命中 ' + contract.matchCount + ' 个'
            + (contract.selector ? '｜' + contract.selector : '');
    };

    var setSelectedRegion = function (node) {
        if (!node || node.nodeType !== 1) return;
        invalidateLastCapture();
        if (state.selectedRegion && state.selectedStyle) {
            restoreStyle(state.selectedRegion, state.selectedStyle);
        }
        state.selectedRegion = node;
        state.selectedStyle = styleSnapshot(node);
        state.selectedSelector = buildUniqueSelector(node);
        state.selectedAt = formatDateTime(new Date());
        node.style.outline = '3px solid #10b981';
        node.style.outlineOffset = '2px';
        renderRegionStatus();
    };

    var clearHoveredRegion = function () {
        if (state.hoveredRegion && state.hoveredStyle) {
            restoreStyle(state.hoveredRegion, state.hoveredStyle);
        }
        state.hoveredRegion = null;
        state.hoveredStyle = null;
    };

    var stopRegionSelection = function (message) {
        if (typeof state.selectionCleanup === 'function') {
            state.selectionCleanup();
        }
        state.selectionCleanup = null;
        clearHoveredRegion();
        if (message) setStatus(message);
    };

    var beginRegionSelection = function () {
        stopRegionSelection();
        setStatus('圈选中：点击真实数据所在区域；按 Esc 取消。不要点击账号、密码等敏感区域。');

        var onMove = function (event) {
            var target = event.target && event.target.nodeType === 1 ? event.target : null;
            if (!target || target.closest('#suxi-ota-browser-assist-panel')) return;
            if (target === state.hoveredRegion) return;
            clearHoveredRegion();
            state.hoveredRegion = target;
            state.hoveredStyle = styleSnapshot(target);
            target.style.outline = '3px solid #2563eb';
            target.style.outlineOffset = '2px';
            target.style.cursor = 'crosshair';
        };

        var onClick = function (event) {
            var target = event.target && event.target.nodeType === 1 ? event.target : null;
            if (!target || target.closest('#suxi-ota-browser-assist-panel')) return;
            event.preventDefault();
            event.stopPropagation();
            clearHoveredRegion();
            setSelectedRegion(target);
            stopRegionSelection('已圈选目标区域。若日期面板、下拉层或页面状态变化，请重新圈选当前状态。');
        };

        var onKeydown = function (event) {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            stopRegionSelection('已取消圈选。');
        };

        document.addEventListener('mousemove', onMove, true);
        document.addEventListener('click', onClick, true);
        document.addEventListener('keydown', onKeydown, true);
        state.selectionCleanup = function () {
            document.removeEventListener('mousemove', onMove, true);
            document.removeEventListener('click', onClick, true);
            document.removeEventListener('keydown', onKeydown, true);
        };
    };

    var expandSelectedRegion = function () {
        if (!state.selectedRegion) {
            setStatus('请先圈选目标区域。');
            return;
        }
        var parent = state.selectedRegion.parentElement;
        if (!parent || parent === document.body || parent === document.documentElement
            || parent.closest('#suxi-ota-browser-assist-panel')) {
            setStatus('目标区域已经不能继续扩大，请重新圈选。');
            return;
        }
        setSelectedRegion(parent);
        setStatus('目标区域已扩大一级；请确认绿色边框只覆盖所需数据。');
    };

    var clearSelectedRegion = function () {
        stopRegionSelection();
        if (state.selectedRegion && state.selectedStyle) {
            restoreStyle(state.selectedRegion, state.selectedStyle);
        }
        state.selectedRegion = null;
        state.selectedStyle = null;
        state.selectedSelector = '';
        state.selectedAt = '';
        invalidateLastCapture();
        renderRegionStatus();
        setStatus('已清除目标区域。');
    };

    var buildTargetRegionContract = function () {
        var pageStateNote = '日期面板、下拉层、浮层或页面内容变化后，必须重新圈选当前状态。';
        if (!state.selectedRegion) {
            return {
                status: 'not_selected',
                selector: '',
                matchCount: 0,
                tag: '',
                textPreview: '',
                selectedAt: '',
                pageStateNote: pageStateNote
            };
        }

        var node = state.selectedRegion;
        var connected = typeof node.isConnected === 'boolean'
            ? node.isConnected
            : Boolean(document.documentElement && document.documentElement.contains(node));
        var selector = state.selectedSelector || '';
        var matchCount = selectorCount(selector);
        var rect = node.getBoundingClientRect ? node.getBoundingClientRect() : null;
        var visibleText = textOf(node);
        var textPreview = redactEvidenceText(visibleText, 180);
        var status = 'ready';
        if (!connected || !visible(node)) {
            status = 'stale';
        } else if (!selectorPointsTo(selector, node)) {
            status = 'not_unique';
        } else if (!visibleText) {
            status = 'empty';
        }

        return {
            status: status,
            selector: redactEvidenceText(selector, 320),
            matchCount: matchCount,
            tag: String(node.tagName || '').toLowerCase(),
            textPreview: textPreview,
            selectedAt: state.selectedAt,
            rect: rect ? {
                x: Math.round(rect.x),
                y: Math.round(rect.y),
                width: Math.round(rect.width),
                height: Math.round(rect.height)
            } : null,
            pageStateNote: pageStateNote
        };
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

        var selected = candidates.find(function (item) {
            return Boolean(item.partnerId && item.poiId);
        }) || candidates[0] || null;
        if (!selected) return null;
        return {
            platform: 'meituan',
            updatedAt: formatDateTime(new Date()),
            partnerId: selected.partnerId,
            poiId: selected.poiId,
            evidence: [{
                source: selected.source,
                host: selected.host,
                path: selected.path,
                fields: [
                    selected.partnerId ? 'partnerId' : '',
                    selected.poiId ? 'poiId' : ''
                ].filter(Boolean)
            }]
        };
    };

    var uniqueVisibleNodes = function (selectors, root) {
        var seen = [];
        var nodes = [];
        selectors.forEach(function (selector) {
            var searchRoot = root || document;
            var list = [];
            if (searchRoot !== document && searchRoot.matches && searchRoot.matches(selector)) {
                list.push(searchRoot);
            }
            list = list.concat(Array.prototype.slice.call(searchRoot.querySelectorAll(selector)));
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
            rawText: redactEvidenceText(text, 240)
        };
    };

    var parseInventoryRows = function (platform, warnings, root) {
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
        var roomNodes = uniqueVisibleNodes(roomSelectors, root || document);
        roomNodes = roomNodes.filter(function (candidate) {
            return !roomNodes.some(function (other) {
                return other !== candidate
                    && typeof candidate.contains === 'function'
                    && candidate.contains(other);
            });
        });
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

    var parseRealtimeMetrics = function (platform, warnings, root) {
        var text = bodyText(root);
        if (platform === 'ctrip') {
            var qunarVisitorLabels = ['去哪儿实时访客量', '去哪儿访客', 'Qunar访客'];
            var qunarPeerLabels = ['去哪儿竞争圈平均', '去哪儿同行平均'];
            var qunarConversionLabels = ['去哪儿实时下单转化率', '去哪儿转化率'];
            var qunarRankLabels = ['去哪儿实时排名', '去哪儿排名'];
            var ctrip = {
                realtimeVisitors: valueAfter(text, ['实时访客量', '实时访客', '访客量'], qunarVisitorLabels),
                visitorPeerAvg: valueAfter(text, ['竞争圈平均', '竞品平均', '同行平均'], qunarPeerLabels),
                orderConversionRate: valueAfter(text, ['实时下单转化率', '下单转化率', '转化率'], qunarConversionLabels),
                realtimeRank: valueAfter(text, ['实时排名', '排名'], qunarRankLabels)
            };
            var qunar = {
                realtimeVisitors: valueAfter(text, qunarVisitorLabels),
                visitorPeerAvg: valueAfter(text, qunarPeerLabels),
                orderConversionRate: valueAfter(text, qunarConversionLabels),
                realtimeRank: valueAfter(text, qunarRankLabels)
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
            var exposureBrowseLabels = ['曝光-浏览', '曝光浏览转化率'];
            var browsePayLabels = ['浏览-支付', '浏览支付转化率'];
            var metrics = {
                exposureUsers: valueAfter(text, ['曝光人数', '曝光用户', '曝光'], exposureBrowseLabels),
                browseUsers: valueAfter(text, ['浏览人数', '浏览用户', '浏览'], exposureBrowseLabels.concat(browsePayLabels)),
                paidOrders: valueAfter(text, ['支付订单数', '支付订单', '订单数']),
                exposureBrowseRate: valueAfter(text, exposureBrowseLabels),
                browsePayRate: valueAfter(text, browsePayLabels)
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

    var pushTargetRegionWarning = function (contract, warnings) {
        var codes = {
            not_selected: 'target_region_not_selected',
            stale: 'target_region_stale',
            not_unique: 'target_region_not_unique',
            empty: 'target_region_empty'
        };
        var messages = {
            not_selected: '尚未圈选目标区域，未读取任何业务数据。',
            stale: '圈选区域已离开页面或不可见；页面状态变化后必须重新圈选。',
            not_unique: '圈选区域的定位不再唯一，未读取任何业务数据。',
            empty: '圈选区域没有可见文字，未读取任何业务数据。'
        };
        warnings.push({
            platform: detectPlatform(),
            module: 'target_region',
            code: codes[contract.status] || 'target_region_invalid',
            message: messages[contract.status] || '目标区域不可用，请重新圈选。'
        });
    };

    var shouldParseTemplate = function (template, section) {
        return template === 'auto' || template === section;
    };

    var buildCapture = function (options) {
        options = options || {};
        var scope = options.scope === 'page' ? 'page' : 'region';
        var template = ['auto', 'inventory', 'metrics'].indexOf(options.template) >= 0
            ? options.template
            : 'auto';
        var platform = detectPlatform();
        var warnings = [];
        var now = formatDateTime(new Date());
        var targetRegion = scope === 'region'
            ? buildTargetRegionContract()
            : {
                status: 'not_used',
                selector: '',
                matchCount: 0,
                tag: '',
                textPreview: '',
                selectedAt: '',
                pageStateNote: '当前为整页兼容采集，准确性低于圈选区域。'
            };
        var capture = {
            source_contract: CONTRACT_VERSION,
            collection_mode: COLLECTION_MODE,
            capture_scope: scope,
            capture_template: template,
            generatedAt: now,
            snapshotTime: now,
            page: sanitizedPage(),
            target_region: targetRegion,
            warnings: warnings
        };

        if (platform === 'unknown') {
            warnings.push({
                platform: 'unknown',
                module: 'browser_assist',
                code: 'unsupported_host',
                message: '当前域名不是已配置的携程/美团后台页面。'
            });
            return capture;
        }

        var captureRoot = document.body;
        if (scope === 'region') {
            if (targetRegion.status !== 'ready') {
                pushTargetRegionWarning(targetRegion, warnings);
                return capture;
            }
            captureRoot = state.selectedRegion;
        } else {
            warnings.push({
                platform: platform,
                module: 'target_region',
                code: 'page_scope_broad',
                message: '当前按整页兼容模式识别，页面存在重复标签时可能误命中；优先使用圈选区域。'
            });
        }

        if (platform === 'ctrip') {
            if (shouldParseTemplate(template, 'inventory')) {
                capture.ctrip = parseInventoryRows('ctrip', warnings, captureRoot);
            }
            if (shouldParseTemplate(template, 'metrics')) {
                var ctripStats = parseRealtimeMetrics('ctrip', warnings, captureRoot);
                if (ctripStats) capture.ctripStats = ctripStats;
            }
        } else if (platform === 'meituan') {
            if (shouldParseTemplate(template, 'inventory')) {
                capture.meituan = parseInventoryRows('meituan', warnings, captureRoot);
            }
            if (shouldParseTemplate(template, 'metrics')) {
                var meituanStats = parseRealtimeMetrics('meituan', warnings, captureRoot);
                if (meituanStats) capture.meituanStats = meituanStats;
            }
            var meituanIdentity = collectMeituanIdentity();
            if (meituanIdentity) capture.platformIdentity = meituanIdentity;
        }
        return capture;
    };

    var setStatus = function (message) {
        var node = document.getElementById('suxi-ota-browser-assist-status');
        if (node) node.textContent = message;
    };

    var updateOutput = function (scope) {
        state.lastCapture = buildCapture({
            scope: scope,
            template: state.captureTemplate
        });
        var output = document.getElementById('suxi-ota-browser-assist-output');
        if (output) output.value = JSON.stringify(state.lastCapture, null, 2);
        var warningCount = state.lastCapture.warnings ? state.lastCapture.warnings.length : 0;
        if (scope === 'region' && state.lastCapture.target_region.status !== 'ready') {
            setStatus('目标区域不可用，未读取业务数据；提醒 ' + warningCount + ' 条。');
        } else if (scope === 'region') {
            setStatus('已从圈选区域生成 JSON，提醒 ' + warningCount + ' 条。请先核对预览再导入。');
        } else {
            setStatus('已按整页兼容模式生成 JSON，提醒 ' + warningCount + ' 条。请优先改用圈选区域。');
        }
        renderRegionStatus();
    };

    var copyOutput = function () {
        if (!state.lastCapture) updateOutput('region');
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
        if (!state.lastCapture) updateOutput('region');
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
        var close = createButton('关闭', function () {
            clearSelectedRegion();
            panel.remove();
        });
        header.appendChild(title);
        header.appendChild(close);

        var body = document.createElement('div');
        body.style.cssText = 'padding:10px 12px;';
        var note = document.createElement('div');
        note.textContent = '先圈选真实数据区域，再选择识别模板。只读取圈选区域的可见文字；日期面板、下拉层或页面状态变化后必须重新圈选。';
        note.style.cssText = 'font-size:12px;line-height:1.5;margin-bottom:8px;';

        var templateRow = document.createElement('label');
        templateRow.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:8px;';
        var templateLabel = document.createElement('span');
        templateLabel.textContent = '识别模板';
        var templateSelect = document.createElement('select');
        templateSelect.id = 'suxi-ota-browser-assist-template';
        templateSelect.style.cssText = 'flex:1;border:1px solid #a7f3d0;border-radius:6px;padding:6px;background:#fff;color:#064e3b;';
        [
            { value: 'auto', label: '自动识别（库存 + 实时指标）' },
            { value: 'inventory', label: '房态 / 库存' },
            { value: 'metrics', label: '实时流量指标' }
        ].forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            templateSelect.appendChild(option);
        });
        templateSelect.addEventListener('change', function () {
            state.captureTemplate = templateSelect.value;
            invalidateLastCapture();
            setStatus('已切换识别模板，请重新采集。');
        });
        templateRow.appendChild(templateLabel);
        templateRow.appendChild(templateSelect);

        var regionStatus = document.createElement('div');
        regionStatus.id = 'suxi-ota-browser-assist-region-status';
        regionStatus.style.cssText = 'font-size:11px;line-height:1.45;margin-bottom:8px;padding:6px;background:#d1fae5;border-radius:6px;word-break:break-all;';

        var regionActions = document.createElement('div');
        regionActions.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;';
        regionActions.appendChild(createButton('圈选目标区域', beginRegionSelection));
        regionActions.appendChild(createButton('扩大一级', expandSelectedRegion));
        regionActions.appendChild(createButton('清除圈选', clearSelectedRegion));

        var actions = document.createElement('div');
        actions.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;';
        actions.appendChild(createButton('采集圈选区域', function () { updateOutput('region'); }));
        actions.appendChild(createButton('采集当前页（兼容）', function () { updateOutput('page'); }));
        actions.appendChild(createButton('复制JSON', copyOutput));
        actions.appendChild(createButton('下载JSON', downloadOutput));
        var output = document.createElement('textarea');
        output.id = 'suxi-ota-browser-assist-output';
        output.readOnly = true;
        output.style.cssText = 'box-sizing:border-box;width:100%;height:160px;border:1px solid #a7f3d0;border-radius:6px;padding:8px;font-family:Consolas,monospace;font-size:11px;background:#fff;color:#111827;';
        var status = document.createElement('div');
        status.id = 'suxi-ota-browser-assist-status';
        status.textContent = '等待圈选。';
        status.style.cssText = 'font-size:12px;margin-top:6px;color:#047857;';
        body.appendChild(note);
        body.appendChild(templateRow);
        body.appendChild(regionStatus);
        body.appendChild(regionActions);
        body.appendChild(actions);
        body.appendChild(output);
        body.appendChild(status);
        panel.appendChild(header);
        panel.appendChild(body);
        document.body.appendChild(panel);
        renderRegionStatus();
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
