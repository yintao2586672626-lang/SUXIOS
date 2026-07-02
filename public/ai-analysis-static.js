window.SUXI_AI_ANALYSIS_STATIC = (() => {
    const htmlEscape = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const sanitizeAiReportHtml = (value) => {
        const raw = typeof value === 'string'
            ? value
            : (value == null ? '' : JSON.stringify(value, null, 2));
        if (!raw) return '';
        const template = document.createElement('template');
        template.innerHTML = raw;
        const allowedTags = new Set(['SECTION', 'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'H3', 'H4', 'PRE', 'CODE', 'HR', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD']);
        const allowedAttrs = new Set(['colspan', 'rowspan']);
        const walk = document.createTreeWalker(template.content, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_COMMENT);
        const nodes = [];
        while (walk.nextNode()) nodes.push(walk.currentNode);
        nodes.forEach((node) => {
            if (node.nodeType === Node.COMMENT_NODE) {
                node.remove();
                return;
            }
            if (!allowedTags.has(node.tagName)) {
                node.replaceWith(document.createTextNode(node.textContent || ''));
                return;
            }
            Array.from(node.attributes).forEach((attr) => {
                if (!allowedAttrs.has(attr.name.toLowerCase())) {
                    node.removeAttribute(attr.name);
                }
            });
        });
        return template.innerHTML;
    };

    const aiReportHtmlToText = (value) => {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = sanitizeAiReportHtml(value);
        return tempDiv.innerText || tempDiv.textContent || '';
    };

    const toNumber = (value, fallback = 0) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : fallback;
    };

    const toNullableNumber = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const pickNullableNumber = (...values) => {
        for (const value of values) {
            const num = toNullableNumber(value);
            if (num !== null) return num;
        }
        return null;
    };

    const maxNullableNumber = (...values) => {
        const nums = values.map(toNullableNumber).filter(value => value !== null);
        return nums.length > 0 ? Math.max(...nums) : null;
    };

    const getAiAnalysisHotelKey = (hotel) => `${hotel.poiId}_${hotel.hotelName}`;

    const resolveAiSelectedData = (selectedKeys = [], hotels = []) => {
        if (!Array.isArray(selectedKeys) || !Array.isArray(hotels)) return [];
        return selectedKeys
            .map(key => hotels.find(hotel => getAiAnalysisHotelKey(hotel) === key))
            .filter(Boolean);
    };

    const validateCapturedOtaAiAnalysisStart = ({
        selectedKeys = [],
        selectedData = [],
        startDate = '',
        endDate = '',
    } = {}) => {
        if (!Array.isArray(selectedKeys) || selectedKeys.length === 0) {
            return { ok: false, level: 'error', message: '请先选择要分析的酒店' };
        }
        if (!Array.isArray(selectedData) || selectedData.length === 0) {
            return { ok: false, level: 'error', message: '未找到选中的酒店数据' };
        }
        if (!startDate || !endDate) {
            return { ok: false, level: 'error', message: '请选择分析日期范围' };
        }
        if (startDate > endDate) {
            return { ok: false, level: 'error', message: '开始日期不能晚于结束日期' };
        }
        return { ok: true, level: 'success', message: '' };
    };

    const aiAnalysisStatusText = (status) => {
        const map = {
            pending: '等待中',
            running: '分析中',
            success: '成功',
            empty: '暂无数据',
            failed: '失败',
        };
        return map[status] || '待分析';
    };

    const aiAnalysisStatusClass = (status) => {
        if (status === 'running') return 'bg-indigo-50 text-indigo-700 border-indigo-200';
        if (status === 'success') return 'bg-green-50 text-green-700 border-green-200';
        if (status === 'empty') return 'bg-gray-50 text-gray-600 border-gray-200';
        if (status === 'failed') return 'bg-red-50 text-red-700 border-red-200';
        return 'bg-slate-50 text-slate-600 border-slate-200';
    };

    const aiAnalysisPriorityClass = (priority) => {
        const value = String(priority || '').toLowerCase();
        if (value === 'high') return 'bg-red-50 text-red-700 border-red-200';
        if (value === 'medium') return 'bg-orange-50 text-orange-700 border-orange-200';
        if (value === 'low') return 'bg-green-50 text-green-700 border-green-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const aiAnalysisPriorityText = (priority) => {
        const value = String(priority || '').toLowerCase();
        if (value === 'high') return '高优先级';
        if (value === 'medium') return '中优先级';
        if (value === 'low') return '低优先级';
        return '未分级';
    };

    const normalizeAiCopyList = (value) => {
        if (Array.isArray(value)) {
            return value.map(item => String(item || '').trim()).filter(Boolean);
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return [value.trim()];
        }
        return ['暂无'];
    };

    const buildAiAnalysisCopyHtml = (items) => {
        if (!items || items.length === 0) return '';
        return items.map(item => {
            const result = item.result || {};
            const listHtml = (title, values) => `<h4>${htmlEscape(title)}</h4><ul>${normalizeAiCopyList(values).map(text => `<li>${htmlEscape(text)}</li>`).join('')}</ul>`;
            if (item.status === 'empty') {
                return `<section><h3>${htmlEscape(item.hotelName)}（${htmlEscape(item.hotelId)}）</h3><p>暂无数据</p></section>`;
            }
            if (item.status === 'failed') {
                return `<section><h3>${htmlEscape(item.hotelName)}（${htmlEscape(item.hotelId)}）</h3><p>失败：${htmlEscape(item.error || '分析失败')}</p></section>`;
            }
            return `<section><h3>${htmlEscape(item.hotelName)}（${htmlEscape(item.hotelId)}）</h3><p><strong>优先级：</strong>${htmlEscape(aiAnalysisPriorityText(result.priority))}</p><p><strong>核心结论：</strong>${htmlEscape(result.core_conclusion || '-')}</p>${listHtml('主要问题', result.main_problems)}${listHtml('可能原因', result.possible_reasons)}${listHtml('建议动作', result.recommended_actions)}${listHtml('数据异常', result.data_anomalies_needing_confirmation)}${result.raw_text ? `<pre>${htmlEscape(result.raw_text)}</pre>` : ''}</section>`;
        }).join('<hr>');
    };

    const normalizeAiAnalysisList = (value) => {
        if (Array.isArray(value)) {
            const items = value.map(item => {
                if (item && typeof item === 'object') {
                    return Object.entries(item)
                        .filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== '')
                        .map(([k, v]) => `${k}: ${v}`)
                        .join('；');
                }
                return String(item || '').trim();
            }).filter(Boolean);
            return items.length > 0 ? items : ['暂无'];
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return [value.trim()];
        }
        return ['暂无'];
    };

    const splitAiProblemMetrics = (value) => {
        if (Array.isArray(value)) {
            return value.map(item => String(item || '').trim()).filter(Boolean);
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return value.split(/[、,，；;]\s*/).map(item => item.trim()).filter(Boolean);
        }
        return [];
    };

    const parseAiProblemHotelText = (text) => {
        const result = { hotel_name: '', problem: '', key_metrics: [], suggestion: '' };
        const fieldMap = {
            hotel_name: 'hotel_name',
            '酒店': 'hotel_name',
            problem: 'problem',
            '问题': 'problem',
            key_metrics: 'key_metrics',
            '关键指标': 'key_metrics',
            suggestion: 'suggestion',
            '建议': 'suggestion',
        };
        const fieldKeys = Object.keys(fieldMap).join('|');
        const pattern = new RegExp(`(${fieldKeys})\\s*[:：]\\s*([\\s\\S]*?)(?=\\s*(?:${fieldKeys})\\s*[:：]|[；;\\r\\n]+|$)`, 'g');
        let match;
        while ((match = pattern.exec(String(text || ''))) !== null) {
            const key = match[1].trim();
            const value = match[2].trim();
            const target = fieldMap[key];
            if (!target) continue;
            if (target === 'key_metrics') {
                result.key_metrics = splitAiProblemMetrics(value);
            } else {
                result[target] = value;
            }
        }
        if (!result.hotel_name && !result.problem && result.key_metrics.length === 0 && !result.suggestion && String(text || '').trim()) {
            result.problem = String(text).trim();
        }
        return result;
    };

    const normalizeAiProblemHotels = (value) => {
        const items = Array.isArray(value) ? value : (value ? [value] : []);
        const hotels = items.map(item => {
            if (item && typeof item === 'object') {
                return {
                    hotel_name: String(item.hotel_name || item['酒店'] || item.name || '').trim(),
                    problem: String(item.problem || item['问题'] || '').trim(),
                    key_metrics: splitAiProblemMetrics(item.key_metrics || item['关键指标']),
                    suggestion: String(item.suggestion || item['建议'] || '').trim(),
                };
            }
            return parseAiProblemHotelText(item);
        }).filter(item => item.hotel_name || item.problem || item.key_metrics.length || item.suggestion);
        return hotels.length > 0 ? hotels : [{ hotel_name: '', problem: '暂无', key_metrics: [], suggestion: '' }];
    };

    const problemHotelKey = (hotel, index = 0) => [index, hotel.hotel_name, hotel.problem, (hotel.key_metrics || []).join('|'), hotel.suggestion].join('_');

    const formatAiProblemHotelText = (hotel) => {
        const parts = [];
        if (hotel.hotel_name) parts.push(`酒店：${hotel.hotel_name}`);
        if (hotel.problem) parts.push(`问题：${hotel.problem}`);
        if (hotel.key_metrics && hotel.key_metrics.length) parts.push(`关键指标：${hotel.key_metrics.join('、')}`);
        if (hotel.suggestion) parts.push(`建议：${hotel.suggestion}`);
        return parts.join('；') || '暂无';
    };

    const aiAnalysisDataNoticeTitle = (report) => {
        const quality = report?.data_quality || {};
        return (quality.is_cross_day_window || (quality.warning && quality.is_reliable !== false)) ? '数据口径提示' : '数据异常';
    };

    const aiAnalysisDataNoticeList = (report) => {
        const quality = report?.data_quality || {};
        if (quality.is_cross_day_window) {
            return ['当前可能处于OTA跨日统计窗口，曝光、访客、浏览率、订单率、转化率等流量指标可能尚未完成统计。本次报告优先参考订单、间夜、收入、ADR、评分等已返回指标，流量类指标建议待平台更新后复查。'];
        }
        if (quality.warning && quality.is_reliable !== false) {
            return [quality.warning];
        }
        return normalizeAiAnalysisList(report?.data_anomalies);
    };

    const maskAiAnalysisError = (message) => String(message || '分析失败')
        .replace(/sk-[A-Za-z0-9_-]{8,}/g, 'sk-****')
        .replace(/Bearer\s+[A-Za-z0-9._-]+/gi, 'Bearer ****')
        .replace(/(api[_-]?key|authorization|cookie|spidertoken)\s*[:=]\s*[^,\s;]+/gi, '$1=****')
        .slice(0, 300);

    const formatAiAnalysisError = (details) => {
        const lines = ['模型返回异常'];
        if (details.model) lines.push(`模型：${details.model}`);
        if (details.model_key) lines.push(`model_key：${details.model_key}`);
        if (details.config_source) lines.push(`配置来源：${details.config_source}`);
        if (details.http_status) lines.push(`HTTP状态：${details.http_status}`);
        if (details.error_type) lines.push(`错误类型：${details.error_type}`);
        if (details.error_message) lines.push(`原因：${details.error_message}`);
        if (details.response_preview) lines.push(`响应预览：${details.response_preview}`);
        lines.push('建议：减少单组酒店数量或检查字段摘要');
        return lines.join('\n');
    };

    const chunkArray = (items, size) => {
        const chunks = [];
        for (let i = 0; i < items.length; i += size) {
            chunks.push(items.slice(i, i + size));
        }
        return chunks;
    };

    const buildCapturedOtaHotelPayload = (hotel) => {
        const roomNights = toNumber(hotel.roomNights);
        const revenue = toNumber(hotel.roomRevenue || hotel.sales);
        const visitors = pickNullableNumber(hotel.views, hotel.totalDetailNum, hotel.qunarDetailVisitors);
        const exposure = pickNullableNumber(hotel.exposure);
        const orders = toNumber(hotel.totalOrderNum || hotel.bookOrderNum);
        const viewConversion = pickNullableNumber(hotel.viewConversion, hotel.convertionRate);
        const payConversion = pickNullableNumber(hotel.payConversion);
        const conversionRate = pickNullableNumber(hotel.qunarDetailCR, hotel.conversionRate);
        const price = roomNights > 0 ? Number((revenue / roomNights).toFixed(2)) : 0;
        const rankValues = [hotel.amountRank, hotel.quantityRank, hotel.commentScoreRank, hotel.qunarDetailCRRank]
            .map(value => toNumber(value))
            .filter(value => value > 0);
        const rank = rankValues.length > 0 ? Math.min(...rankValues) : 0;
        const score = toNumber(hotel.commentScore || hotel.qunarCommentScore);
        const tags = [];
        if (rank > 0) tags.push(`最好排名${rank}`);
        if (price > 0) tags.push(`ADR ${price}`);
        if (viewConversion !== null && viewConversion > 0) tags.push(`浏览转化${viewConversion}%`);
        if (payConversion !== null && payConversion > 0) tags.push(`支付转化${payConversion}%`);
        return {
            hotel_id: String(hotel.poiId || ''),
            hotel_name: hotel.hotelName || '未知酒店',
            rank,
            price,
            score,
            comments_count: toNumber(hotel.commentsCount || hotel.commentCount),
            exposure,
            visitors,
            orders,
            revenue,
            room_nights: roomNights,
            view_conversion: viewConversion,
            pay_conversion: payConversion,
            conversion_rate: conversionRate,
            tags: tags.slice(0, 6),
            short_summary: `间夜${roomNights}，收入${revenue}，曝光${exposure ?? '未返回'}，访客${visitors ?? '未返回'}，订单${orders}`,
        };
    };

    const buildCtripAiAnalysisHotelSelection = ({
        ctripHotels = [],
        selectedKeys = [],
    } = {}) => {
        const hotelMap = new Map();
        ctripHotels.forEach(h => {
            const key = `${h.hotelId || h.id}_${h.hotelName || h.name}`;
            if (!hotelMap.has(key)) {
                hotelMap.set(key, {
                    poiId: h.hotelId || h.id || '',
                    hotelName: h.hotelName || h.name || '',
                    roomNights: h.quantity || h.roomNights || 0,
                    roomRevenue: h.amount || h.roomRevenue || 0,
                    salesRoomNights: h.salesRoomNights || 0,
                    sales: h.sales || h.amount || 0,
                    viewConversion: pickNullableNumber(h.viewConversion, h.convertionRate),
                    payConversion: pickNullableNumber(h.payConversion),
                    exposure: pickNullableNumber(h.exposure),
                    views: pickNullableNumber(h.views, h.totalDetailNum, h.qunarDetailVisitors),
                    commentScore: h.commentScore || 0,
                    qunarCommentScore: h.qunarCommentScore || 0,
                    convertionRate: pickNullableNumber(h.convertionRate),
                    qunarDetailCR: pickNullableNumber(h.qunarDetailCR),
                    totalOrderNum: h.totalOrderNum || 0,
                    bookOrderNum: h.bookOrderNum || 0,
                    amountRank: h.amountRank || 0,
                    quantityRank: h.quantityRank || 0,
                    commentScoreRank: h.commentScoreRank || 0,
                    qunarDetailCRRank: h.qunarDetailCRRank || 0,
                });
                return;
            }
            const existing = hotelMap.get(key);
            existing.roomNights = Math.max(existing.roomNights, h.quantity || h.roomNights || 0);
            existing.roomRevenue = Math.max(existing.roomRevenue, h.amount || h.roomRevenue || 0);
            existing.salesRoomNights = Math.max(existing.salesRoomNights, h.salesRoomNights || 0);
            existing.sales = Math.max(existing.sales, h.sales || h.amount || 0);
            existing.viewConversion = maxNullableNumber(existing.viewConversion, h.viewConversion, h.convertionRate);
            existing.payConversion = maxNullableNumber(existing.payConversion, h.payConversion);
            existing.exposure = maxNullableNumber(existing.exposure, h.exposure);
            existing.views = maxNullableNumber(existing.views, h.views, h.totalDetailNum, h.qunarDetailVisitors);
            existing.commentScore = Math.max(existing.commentScore || 0, h.commentScore || 0);
            existing.qunarCommentScore = Math.max(existing.qunarCommentScore || 0, h.qunarCommentScore || 0);
            existing.convertionRate = maxNullableNumber(existing.convertionRate, h.convertionRate);
            existing.qunarDetailCR = maxNullableNumber(existing.qunarDetailCR, h.qunarDetailCR);
            existing.totalOrderNum = Math.max(existing.totalOrderNum || 0, h.totalOrderNum || 0);
            existing.bookOrderNum = Math.max(existing.bookOrderNum || 0, h.bookOrderNum || 0);
            existing.amountRank = existing.amountRank === 0 ? (h.amountRank || 0) : Math.min(existing.amountRank, h.amountRank || existing.amountRank);
            existing.quantityRank = existing.quantityRank === 0 ? (h.quantityRank || 0) : Math.min(existing.quantityRank, h.quantityRank || existing.quantityRank);
            existing.commentScoreRank = existing.commentScoreRank === 0 ? (h.commentScoreRank || 0) : Math.min(existing.commentScoreRank, h.commentScoreRank || existing.commentScoreRank);
            existing.qunarDetailCRRank = existing.qunarDetailCRRank === 0 ? (h.qunarDetailCRRank || 0) : Math.min(existing.qunarDetailCRRank, h.qunarDetailCRRank || existing.qunarDetailCRRank);
        });
        const hotels = Array.from(hotelMap.values());
        const visibleKeys = new Set(hotels.map(getAiAnalysisHotelKey));
        return {
            hotels,
            selectedKeys: selectedKeys.filter(key => visibleKeys.has(key)),
        };
    };

    const buildCapturedGroupSummary = (item) => ({
        group_index: item.groupIndex,
        hotel_count: item.hotelCount,
        report: item.result ? {
            overall_conclusion: item.result.overall_conclusion || '',
            key_findings: normalizeAiAnalysisList(item.result.key_findings).slice(0, 5),
            competitor_insights: normalizeAiAnalysisList(item.result.competitor_insights).slice(0, 5),
            problem_hotels: normalizeAiProblemHotels(item.result.problem_hotels).slice(0, 8),
            recommended_actions: normalizeAiAnalysisList(item.result.recommended_actions).slice(0, 6),
            priority: item.result.priority || 'medium',
            data_anomalies: normalizeAiAnalysisList(item.result.data_anomalies).slice(0, 5),
            data_quality: item.result.data_quality || item.result.summary?.data_quality || {},
            summary: item.result.summary || { hotel_count: item.hotelCount, data_quality: item.result.data_quality || {} },
        } : null,
    });

    const mergeCapturedGroupReports = (reports, hotelCount, note = '') => {
        const list = (field, limit) => reports.flatMap(report => normalizeAiAnalysisList(report[field])).filter(text => text && text !== '暂无').slice(0, limit);
        const priorityRank = { high: 3, medium: 2, low: 1 };
        const priority = reports.reduce((current, report) => {
            const next = report.priority || 'medium';
            return (priorityRank[next] || 2) > (priorityRank[current] || 2) ? next : current;
        }, 'medium');
        return {
            overall_conclusion: note || reports.map(report => report.overall_conclusion).filter(Boolean).join('；') || '拆分重试完成',
            key_findings: list('key_findings', 8),
            competitor_insights: list('competitor_insights', 8),
            problem_hotels: reports.flatMap(report => normalizeAiProblemHotels(report.problem_hotels)).slice(0, 10),
            recommended_actions: list('recommended_actions', 10),
            priority,
            data_anomalies: list('data_anomalies', 8),
            data_quality: reports.find(report => report?.data_quality?.is_cross_day_window)?.data_quality || reports.find(report => report?.data_quality)?.data_quality || {},
            summary: { hotel_count: hotelCount },
        };
    };

    const buildCapturedOtaReportCopyHtml = (report) => {
        if (!report) return '';
        const listHtml = (title, values) => `<h4>${htmlEscape(title)}</h4><ul>${normalizeAiAnalysisList(values).map(text => `<li>${htmlEscape(text)}</li>`).join('')}</ul>`;
        const problemHtml = `<h4>问题酒店</h4><ul>${normalizeAiProblemHotels(report.problem_hotels).map(hotel => `<li>${htmlEscape(formatAiProblemHotelText(hotel))}</li>`).join('')}</ul>`;
        const fallbackHtml = report.fallback
            ? `<p><strong>生成状态：</strong>AI综合汇总失败；当前内容为基础汇总，不作为自动执行依据。</p><p><strong>失败原因：</strong>${htmlEscape(report.fallback_reason || '汇总失败')}</p><p><strong>执行状态：</strong>${htmlEscape(report.action_gate_text || '已阻断，需人工复核后再创建动作。')}</p>`
            : '';
        return `<section><h3>批量OTA AI综合诊断报告</h3>${fallbackHtml}<p><strong>优先级：</strong>${htmlEscape(aiAnalysisPriorityText(report.priority))}</p><p><strong>总体结论：</strong>${htmlEscape(report.overall_conclusion || '-')}</p>${listHtml('关键发现', report.key_findings)}${listHtml('竞对洞察', report.competitor_insights)}${problemHtml}${listHtml(report.fallback ? '待人工复核动作' : '建议动作', report.recommended_actions)}${listHtml(aiAnalysisDataNoticeTitle(report), aiAnalysisDataNoticeList(report))}${report.raw_text ? `<pre>${htmlEscape(report.raw_text)}</pre>` : ''}</section>`;
    };

    const buildCapturedFallbackSummaryReport = ({
        successGroups = [],
        failedGroups = [],
        selectedCount = 0,
        completedHotels = 0,
        failedHotels = 0,
        groupCount = 0,
        reason = '',
    } = {}) => {
        const reports = successGroups.map(item => item.result).filter(Boolean);
        const report = mergeCapturedGroupReports(reports, completedHotels, 'AI综合汇总失败，已自动生成基础综合报告。');
        report.fallback = true;
        report.fallback_reason = maskAiAnalysisError(reason);
        report.decision_allowed = false;
        report.decision_status = 'blocked_by_ai_summary_failure';
        report.execution_blocked = true;
        report.action_gate_text = 'AI综合汇总失败，基础报告仅供排查，不可直接创建执行动作。';
        report.summary = {
            selected_hotel_count: selectedCount,
            success_hotel_count: completedHotels,
            failed_hotel_count: failedHotels,
            hotel_count: completedHotels,
            group_count: groupCount,
            failed_group_count: failedGroups.length,
            decision_allowed: false,
            decision_status: 'blocked_by_ai_summary_failure',
        };
        report.data_anomalies = [
            ...normalizeAiAnalysisList(report.data_anomalies).filter(text => text !== '暂无'),
            'AI综合汇总失败，已自动生成基础综合报告。',
            '基础报告不可直接作为执行依据，需人工复核失败原因和成功分组结果。',
        ];
        return report;
    };

    const buildAiAnalysisProgress = ({ hotelCount = 0, groupCount = 0 } = {}) => ({
        totalHotels: hotelCount,
        completedHotels: 0,
        failedHotels: 0,
        currentGroup: 0,
        totalGroups: groupCount,
        summarizing: false,
    });

    const buildAiAnalysisBatchResults = (groups = [], timestamp = Date.now()) => groups.map((group, index) => ({
        key: `group_${timestamp}_${index}`,
        groupIndex: index + 1,
        totalGroups: groups.length,
        hotelCount: group.length,
        hotelNames: group.map(h => h.hotel_name).filter(Boolean),
        status: 'pending',
        result: null,
        error: '',
        errorDetails: null,
        retried: false,
    }));

    const buildCapturedOtaAnalysisRunPlan = ({
        selectedData = [],
        isDeepSeekPro = false,
        timestamp = Date.now(),
    } = {}) => {
        const hotelsPayload = selectedData
            .map(buildCapturedOtaHotelPayload)
            .filter(item => item.hotel_id || item.hotel_name);
        const groupSize = isDeepSeekPro ? 3 : 5;
        const groups = chunkArray(hotelsPayload, groupSize);
        return {
            hotelsPayload,
            groups,
            progress: buildAiAnalysisProgress({
                hotelCount: hotelsPayload.length,
                groupCount: groups.length,
            }),
            batchResults: buildAiAnalysisBatchResults(groups, timestamp),
        };
    };
    const buildCapturedOtaAnalysisStartContext = ({
        selectedKeys = [],
        hotels = [],
        startDate = '',
        endDate = '',
    } = {}) => {
        const selectedData = resolveAiSelectedData(selectedKeys, hotels);
        const validation = validateCapturedOtaAiAnalysisStart({
            selectedKeys,
            selectedData,
            startDate,
            endDate,
        });
        return {
            ok: validation.ok,
            selectedData,
            message: validation.message,
            level: validation.level,
            validation,
        };
    };
    const buildCapturedOtaAnalysisRunContext = ({
        selectedData = [],
        isDeepSeekPro = false,
        timestamp = Date.now(),
    } = {}) => {
        const runPlan = buildCapturedOtaAnalysisRunPlan({ selectedData, isDeepSeekPro, timestamp });
        const { hotelsPayload, groups } = runPlan;
        if (hotelsPayload.length === 0) {
            return {
                ok: false,
                message: '暂无抓取数据',
                level: 'error',
                ...runPlan,
            };
        }
        return {
            ok: true,
            message: `开始分析 ${hotelsPayload.length} 家酒店，共 ${groups.length} 组`,
            level: 'success',
            ...runPlan,
        };
    };

    const buildCapturedOtaGroupOutcome = (batchResults = []) => {
        const rows = Array.isArray(batchResults) ? batchResults : [];
        const successGroups = rows.filter(item => item.status === 'success' && item.result);
        const failedGroups = rows.filter(item => item.status === 'failed' || item.error).map(item => ({
            group_index: item.groupIndex,
            hotel_count: item.hotelCount,
            error: item.error,
        }));
        const failedReason = failedGroups.map(item => `第 ${item.group_index} 组：${item.error || '分析失败'}`).join('\n\n');
        return { successGroups, failedGroups, failedReason };
    };

    const applyCapturedOtaGroupRunState = ({
        groupState = null,
        progressState = null,
        group = [],
        result = {},
        retryResult = null,
    } = {}) => {
        if (result?.ok) {
            if (groupState) {
                groupState.status = 'success';
                groupState.result = result.result;
            }
            if (progressState) {
                progressState.completedHotels += Array.isArray(group) ? group.length : 0;
            }
            return;
        }
        if (groupState) {
            groupState.error = result?.error || '';
            groupState.errorDetails = result?.errorDetails || null;
        }
        if (retryResult && progressState) {
            progressState.completedHotels += toNumber(retryResult.successCount);
            progressState.failedHotels += toNumber(retryResult.failedCount);
        }
    };

    const buildCapturedOtaSummaryRequestBody = ({
        platform = 'ctrip',
        modelKey = '',
        startDate = '',
        endDate = '',
        selectedHotelCount = 0,
        completedHotels = 0,
        failedHotels = 0,
        successGroups = [],
        failedGroups = [],
    } = {}) => ({
        platform,
        model_key: modelKey,
        date_range: {
            start_date: startDate,
            end_date: endDate,
        },
        selected_hotel_count: selectedHotelCount,
        success_hotel_count: completedHotels,
        failed_hotel_count: failedHotels,
        group_summaries: successGroups.map(buildCapturedGroupSummary),
        failed_groups: failedGroups,
    });

    const buildCapturedOtaSummaryContext = ({
        hotelsPayload = [],
        progress = {},
        batchResults = [],
        successGroups = [],
        failedGroups = [],
    } = {}) => {
        const selectedHotelCount = Array.isArray(hotelsPayload) ? hotelsPayload.length : 0;
        return {
            selectedHotelCount,
            selectedCount: selectedHotelCount,
            completedHotels: toNumber(progress?.completedHotels),
            failedHotels: toNumber(progress?.failedHotels),
            groupCount: Array.isArray(batchResults) ? batchResults.length : 0,
            successGroups: Array.isArray(successGroups) ? successGroups : [],
            failedGroups: Array.isArray(failedGroups) ? failedGroups : [],
        };
    };

    const buildCapturedOtaSummaryResponseResult = ({
        response = {},
        successGroups = [],
        failedGroups = [],
        selectedCount = 0,
        completedHotels = 0,
        failedHotels = 0,
        groupCount = 0,
        fallbackReason = '汇总失败',
    } = {}) => {
        if (response?.code === 200) {
            const summaryData = response.data || {};
            return {
                report: summaryData.report || summaryData || {},
                process: summaryData.process || null,
            };
        }
        return {
            report: buildCapturedFallbackSummaryReport({
                successGroups,
                failedGroups,
                selectedCount,
                completedHotels,
                failedHotels,
                groupCount,
                reason: response?.message || fallbackReason,
            }),
            process: null,
        };
    };

    const buildAiAnalysisHistoryRecord = ({
        selectedData = [],
        capturedReport = null,
        completedHotels = 0,
        failedHotels = 0,
        reportHtml = '',
        now = new Date(),
    } = {}) => ({
        id: now.getTime(),
        hotel_names: selectedData.slice(0, 3).map(h => h.hotelName).join('、') + (selectedData.length > 3 ? '等' : ''),
        hotel_count: selectedData.length,
        summary: capturedReport?.overall_conclusion || `完成 ${completedHotels} 家，失败 ${failedHotels} 家`,
        report: reportHtml,
        create_time: now.toLocaleString('zh-CN'),
    });

    const buildCapturedOtaAnalysisCompletion = ({
        selectedData = [],
        capturedReport = null,
        successGroups = [],
        completedHotels = 0,
        failedHotels = 0,
        existingHistory = [],
        historyLimit = 10,
        now = new Date(),
    } = {}) => {
        const reportHtml = capturedReport
            ? buildCapturedOtaReportCopyHtml(capturedReport)
            : (Array.isArray(successGroups) ? successGroups : [])
                .map(item => buildCapturedOtaReportCopyHtml(item.result))
                .join('<hr>');
        const history = [
            buildAiAnalysisHistoryRecord({
                selectedData,
                capturedReport,
                completedHotels,
                failedHotels,
                reportHtml,
                now,
            }),
            ...(Array.isArray(existingHistory) ? existingHistory : []),
        ].slice(0, historyLimit);
        return { reportHtml, history };
    };

    const runCapturedOtaAnalysisExecution = async ({
        groups = [],
        batchResults = [],
        progressState = null,
        hotelsPayload = [],
        selectedData = [],
        existingHistory = [],
        requestGroup = null,
        retryGroup = null,
        requestSummary = null,
        maskError = maskAiAnalysisError,
    } = {}) => {
        if (typeof requestGroup !== 'function') {
            throw new Error('缺少AI分组分析请求函数');
        }
        if (typeof retryGroup !== 'function') {
            throw new Error('缺少AI分组重试函数');
        }
        if (typeof requestSummary !== 'function') {
            throw new Error('缺少AI汇总请求函数');
        }

        const normalizedGroups = Array.isArray(groups) ? groups : [];
        const normalizedBatchResults = Array.isArray(batchResults) ? batchResults : [];
        const normalizedProgress = progressState || {};
        for (let index = 0; index < normalizedGroups.length; index++) {
            const group = normalizedGroups[index];
            const groupState = normalizedBatchResults[index];
            normalizedProgress.currentGroup = index + 1;
            if (groupState) {
                groupState.status = 'running';
            }
            const result = await requestGroup(group);
            if (result.ok) {
                applyCapturedOtaGroupRunState({
                    groupState,
                    progressState: normalizedProgress,
                    group,
                    result,
                });
            } else {
                applyCapturedOtaGroupRunState({ groupState, result });
                const retryResult = await retryGroup(group, groupState);
                applyCapturedOtaGroupRunState({
                    progressState: normalizedProgress,
                    result,
                    retryResult,
                });
            }
        }

        const { successGroups, failedGroups, failedReason } = buildCapturedOtaGroupOutcome(normalizedBatchResults);
        const summaryContext = buildCapturedOtaSummaryContext({
            hotelsPayload,
            progress: normalizedProgress,
            batchResults: normalizedBatchResults,
            successGroups,
            failedGroups,
        });

        let capturedReport = null;
        let process = null;
        let capturedError = '';
        if (successGroups.length > 0) {
            const summaryResult = await requestSummary(summaryContext);
            capturedReport = summaryResult.report;
            process = summaryResult.process;
        } else {
            capturedError = '全部分析失败，无法生成综合报告。\n' + maskError(failedReason);
        }

        const completion = buildCapturedOtaAnalysisCompletion({
            selectedData,
            capturedReport,
            completedHotels: summaryContext.completedHotels,
            failedHotels: summaryContext.failedHotels,
            successGroups,
            existingHistory,
        });

        return {
            capturedReport,
            process,
            capturedError,
            reportHtml: completion.reportHtml,
            history: completion.history,
            summaryContext,
            successGroups,
            failedGroups,
            failedReason,
        };
    };

    const runCapturedOtaAnalysisStartFlow = async ({
        selectedKeys = [],
        hotels = [],
        startDate = '',
        endDate = '',
        isDeepSeekPro = false,
        existingHistory = [],
        notify = () => {},
        setAnalyzing = () => {},
        resetState = () => {},
        setProgress = () => {},
        setBatchResults = () => {},
        setCompletion = () => {},
        setCapturedError = () => {},
        requestGroup = null,
        retryGroup = null,
        requestSummary = null,
        maskError = maskAiAnalysisError,
    } = {}) => {
        const startContext = buildCapturedOtaAnalysisStartContext({
            selectedKeys,
            hotels,
            startDate,
            endDate,
        });
        if (!startContext.ok) {
            notify(startContext.message, startContext.level);
            return { status: 'invalid_start', startContext };
        }

        const { selectedData } = startContext;
        setAnalyzing(true);
        resetState();

        const runContext = buildCapturedOtaAnalysisRunContext({
            selectedData,
            isDeepSeekPro,
        });
        if (!runContext.ok) {
            setAnalyzing(false);
            notify(runContext.message, runContext.level);
            return { status: 'empty_payload', startContext, runContext };
        }

        const { hotelsPayload, groups } = runContext;
        setProgress(runContext.progress);
        setBatchResults(runContext.batchResults);
        notify(runContext.message);

        try {
            const execution = await runCapturedOtaAnalysisExecution({
                groups,
                batchResults: runContext.batchResults,
                progressState: runContext.progress,
                hotelsPayload,
                selectedData,
                existingHistory,
                requestGroup,
                retryGroup,
                requestSummary,
                maskError,
            });
            setCompletion(execution);
            notify('AI分析完成');
            return { status: 'success', startContext, runContext, execution };
        } catch (error) {
            const message = error.message || '网络错误';
            setCapturedError(message);
            notify('AI分析失败: ' + message, 'error');
            return { status: 'exception', startContext, runContext, error, message };
        } finally {
            setAnalyzing(false);
        }
    };

    const getMeituanAiAnalysisHotelKey = (hotel = {}) => `${hotel.poiId}_${hotel.hotelName}`;

    const buildMeituanAiAnalysisHotelList = (rows = []) => {
        const hotelMap = new Map();
        if (Array.isArray(rows)) {
            rows.forEach(hotel => {
                const key = getMeituanAiAnalysisHotelKey(hotel);
                if (!hotelMap.has(key)) {
                    hotelMap.set(key, {
                        poiId: hotel.poiId,
                        hotelName: hotel.hotelName,
                        roomNights: hotel.roomNights || 0,
                        roomRevenue: hotel.roomRevenue || 0,
                        salesRoomNights: hotel.salesRoomNights || 0,
                        sales: hotel.sales || 0,
                        viewConversion: hotel.viewConversion || 0,
                        payConversion: hotel.payConversion || 0,
                        exposure: hotel.exposure || 0,
                        views: hotel.views || 0,
                    });
                }
            });
        }
        return Array.from(hotelMap.values());
    };

    const resolveMeituanAiSelectedData = (selectedKeys = [], hotels = []) => {
        if (!Array.isArray(selectedKeys) || !Array.isArray(hotels)) return [];
        return selectedKeys
            .map(key => hotels.find(hotel => getMeituanAiAnalysisHotelKey(hotel) === key))
            .filter(Boolean);
    };

    const validateMeituanAiAnalysisStart = ({
        selectedKeys = [],
        hotels = [],
    } = {}) => {
        if (!Array.isArray(selectedKeys) || selectedKeys.length === 0) {
            return { ok: false, status: 'missing_selection', level: 'error', message: '请先选择要分析的酒店', selectedData: [] };
        }
        const selectedData = resolveMeituanAiSelectedData(selectedKeys, hotels);
        if (selectedData.length === 0) {
            return { ok: false, status: 'missing_selected_data', level: 'error', message: '未找到选中的酒店数据', selectedData };
        }
        return { ok: true, status: 'ok', selectedData };
    };

    const buildMeituanAiAnalysisRequestBody = (selectedData = []) => ({
        hotels: selectedData,
        total_hotels: selectedData.length,
        analysis_type: 'business_overview',
        source: 'meituan',
        include_suggestions: true,
    });

    const buildMeituanAiAnalysisHistoryRecord = ({
        selectedData = [],
        summary = '',
        report = '',
        now = new Date(),
    } = {}) => ({
        id: now.getTime(),
        hotel_names: selectedData.slice(0, 3).map(h => h.hotelName).join('、') + (selectedData.length > 3 ? '等' : ''),
        hotel_count: selectedData.length,
        summary: summary || 'AI分析报告',
        report,
        create_time: now.toLocaleString('zh-CN'),
    });

    const runMeituanAiAnalysisFlow = async ({
        selectedKeys = [],
        hotels = [],
        requestAnalysis = async () => ({}),
        notify = () => {},
        setAnalyzing = () => {},
        setResult = () => {},
        getHistory = () => [],
        setHistory = () => {},
        logError = () => {},
        sanitizeReport = sanitizeAiReportHtml,
        now = () => new Date(),
        historyLimit = 10,
    } = {}) => {
        const startContext = validateMeituanAiAnalysisStart({ selectedKeys, hotels });
        if (!startContext.ok) {
            notify(startContext.message, startContext.level);
            return { status: startContext.status, startContext };
        }

        const { selectedData } = startContext;
        setAnalyzing(true);
        setResult('');
        try {
            const requestBody = buildMeituanAiAnalysisRequestBody(selectedData);
            notify('AI正在分析数据，请稍候...');
            const res = await requestAnalysis(requestBody);

            if (res.code === 200 && res.data) {
                const report = sanitizeReport(res.data.report || res.data.analysis || res.data);
                setResult(report);
                const nextHistory = [
                    buildMeituanAiAnalysisHistoryRecord({
                        selectedData,
                        summary: res.data.summary,
                        report,
                        now: now(),
                    }),
                    ...((getHistory() || [])),
                ].slice(0, historyLimit);
                setHistory(nextHistory);
                notify('AI分析完成！');
                return { status: 'success', response: res, requestBody, selectedData, report, history: nextHistory };
            }

            setResult('');
            notify(res.message || '美团 AI 分析接口返回失败，请修复后端接口后重试', 'error');
            return { status: 'failed', response: res, requestBody, selectedData };
        } catch (error) {
            logError('美团AI分析请求失败:', error);
            setResult('');
            notify('美团 AI 分析请求失败，请修复后端接口后重试', 'error');
            return { status: 'exception', error, selectedData };
        } finally {
            setAnalyzing(false);
        }
    };

    return {
        htmlEscape,
        sanitizeAiReportHtml,
        aiReportHtmlToText,
        toNullableNumber,
        pickNullableNumber,
        maxNullableNumber,
        getAiAnalysisHotelKey,
        resolveAiSelectedData,
        validateCapturedOtaAiAnalysisStart,
        aiAnalysisStatusText,
        aiAnalysisStatusClass,
        aiAnalysisPriorityClass,
        aiAnalysisPriorityText,
        buildAiAnalysisCopyHtml,
        normalizeAiAnalysisList,
        splitAiProblemMetrics,
        parseAiProblemHotelText,
        normalizeAiProblemHotels,
        problemHotelKey,
        formatAiProblemHotelText,
        aiAnalysisDataNoticeTitle,
        aiAnalysisDataNoticeList,
        maskAiAnalysisError,
        formatAiAnalysisError,
        chunkArray,
        buildCapturedOtaHotelPayload,
        buildCtripAiAnalysisHotelSelection,
        buildCapturedGroupSummary,
        mergeCapturedGroupReports,
        buildCapturedOtaReportCopyHtml,
        buildCapturedFallbackSummaryReport,
        buildAiAnalysisProgress,
        buildAiAnalysisBatchResults,
        buildCapturedOtaAnalysisRunPlan,
        buildCapturedOtaAnalysisStartContext,
        buildCapturedOtaAnalysisRunContext,
        buildCapturedOtaGroupOutcome,
        applyCapturedOtaGroupRunState,
        buildCapturedOtaSummaryRequestBody,
        buildCapturedOtaSummaryContext,
        buildCapturedOtaSummaryResponseResult,
        buildAiAnalysisHistoryRecord,
        buildCapturedOtaAnalysisCompletion,
        runCapturedOtaAnalysisExecution,
        runCapturedOtaAnalysisStartFlow,
        getMeituanAiAnalysisHotelKey,
        buildMeituanAiAnalysisHotelList,
        resolveMeituanAiSelectedData,
        validateMeituanAiAnalysisStart,
        buildMeituanAiAnalysisRequestBody,
        buildMeituanAiAnalysisHistoryRecord,
        runMeituanAiAnalysisFlow,
    };
})();
