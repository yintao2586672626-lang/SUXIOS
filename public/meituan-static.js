window.SUXI_MEITUAN_STATIC = (() => {
    const meituanDisplayMetricLabel = (field) => ({
        roomNights: '入住间夜',
        roomRevenue: '房费收入',
        avgRoomPrice: '平均房价',
        salesRoomNights: '销售间夜',
        sales: '销售额',
        avgSalesPrice: '平均销售房价',
        exposure: '曝光',
        views: '浏览',
        orderCount: '订单量',
        viewConversion: '浏览转化',
        payConversion: '支付转化',
        absoluteConversion: '绝对转化',
    }[field] || field);

    const meituanSortMetricValue = (row, field) => {
        const value = Number(row?.[field] || 0);
        return Number.isFinite(value) ? value : 0;
    };

    const formatMeituanSortGapValue = (value, field) => {
        const numeric = Math.max(0, Number(value) || 0);
        if (['roomRevenue', 'sales', 'avgRoomPrice', 'avgSalesPrice'].includes(field)) {
            return `￥${Math.round(numeric).toLocaleString()}`;
        }
        if (['viewConversion', 'payConversion', 'absoluteConversion'].includes(field)) {
            return `${(numeric * 100).toFixed(2)}%`;
        }
        return Math.round(numeric).toLocaleString();
    };

    const meituanDisplayRowKey = (row, index) => String(row?.poiId || row?.hotelName || index);

    const defaultMeituanAdsUrl = () => 'https://ebmidas.dianping.com/shopdiy/account/pcCpcEntry?continueUrl=/app/peon-merchant-product-menu/html/index.html';

    const createMeituanRankingForm = () => ({
        url: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
        hotelId: '',
        partnerId: '',
        poiId: '',
        rankType: 'P_RZ',
        rankTypes: ['P_RZ', 'P_XS', 'P_ZH', 'P_LL_EXPOSE'],
        dateRanges: ['1'],
        startDate: '',
        endDate: '',
        cookies: '',
        auth_data: {},
        hotelRoomCount: '',
        competitorRoomCount: '',
    });

    const createMeituanTrafficForm = () => ({
        url: '',
        partnerId: '',
        poiId: '',
        startDate: '',
        endDate: '',
        cookies: '',
        extraParams: '',
    });

    const createMeituanOrderForm = () => ({
        url: '',
        method: 'GET',
        partnerId: '',
        poiId: '',
        startDate: '',
        endDate: '',
        cookies: '',
        payloadJson: '',
        extraParams: '',
    });

    const createMeituanAdsForm = () => ({
        url: '',
        method: 'GET',
        partnerId: '',
        poiId: '',
        shopId: '',
        startDate: '',
        endDate: '',
        cookies: '',
        payloadJson: '',
        extraParams: '',
    });

    const createMeituanBrowserCaptureForm = () => ({
        storeId: '',
        poiId: '',
        poiName: '',
        adsUrl: defaultMeituanAdsUrl(),
        captureSections: ['traffic'],
        payloadJson: '',
    });

    const normalizeMeituanCaptureSections = (sections) => {
        const aliases = {
            review: 'reviews',
            reviews: 'reviews',
            comment: 'reviews',
            comments: 'reviews',
            traffic: 'traffic',
            flow: 'traffic',
            ads: 'ads',
            ad: 'ads',
            advertising: 'ads',
            orders: 'orders',
            order: 'orders',
        };
        const raw = Array.isArray(sections) ? sections : String(sections || '').split(/[,\s]+/);
        const normalized = raw
            .map(item => aliases[String(item || '').trim().toLowerCase()] || '')
            .filter(Boolean);
        return Array.from(new Set(normalized));
    };
    const meituanBatchRankTypes = ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'];
    const meituanBatchRankTypeNames = {
        P_RZ: '入住榜（入住间夜+房费收入）',
        P_XS: '销售榜（销售间夜+销售额）',
        P_ZH: '转化榜（浏览转化+支付转化）',
        P_LL: '流量榜（曝光+浏览）',
    };
    const meituanBatchDateRangeNames = {
        0: '今日实时',
        1: '昨日',
        7: '近7天',
        30: '近30天',
        custom: '自定义时间',
    };
    const validateMeituanBatchFetchInput = ({
        form = {},
        cookies = '',
        partnerId = '',
        poiId = '',
    } = {}) => {
        if (!form.hotelId) {
            return { ok: false, level: 'error', message: '请选择目标酒店' };
        }
        if (!String(cookies || '').trim()) {
            return { ok: false, level: 'error', message: '平台授权缺失：请提供美团平台授权内容' };
        }
        const missingResourceFields = [];
        if (!String(partnerId || '').trim()) {
            missingResourceFields.push('平台接口标识');
        }
        if (!String(poiId || '').trim()) {
            missingResourceFields.push('平台门店标识');
        }
        if (missingResourceFields.length > 0) {
            return {
                ok: false,
                level: 'warning',
                message: `需补充一次性门店标识：${missingResourceFields.join(' / ')}。请在酒店管理中补充后再获取美团榜单。`,
            };
        }
        const dateRanges = Array.isArray(form.dateRanges) ? form.dateRanges : [];
        if (dateRanges.length === 0) {
            return { ok: false, level: 'error', message: '请至少选择一个时间维度' };
        }
        if (dateRanges.includes('custom') && (!form.startDate || !form.endDate)) {
            return { ok: false, level: 'error', message: '请填写自定义时间的开始和结束日期' };
        }
        return {
            ok: true,
            level: 'success',
            cookies: String(cookies || '').trim(),
            partnerId: String(partnerId || '').trim(),
            poiId: String(poiId || '').trim(),
        };
    };

    const buildMeituanBatchFetchTasks = ({
        form = {},
        partnerId = '',
        poiId = '',
        cookies = '',
    } = {}) => {
        const dateRanges = Array.isArray(form.dateRanges) ? form.dateRanges : [];
        const tasks = [];
        dateRanges.forEach(dateRange => {
            meituanBatchRankTypes.forEach(rankType => {
                const rangeName = meituanBatchDateRangeNames[dateRange] || dateRange;
                const rankName = meituanBatchRankTypeNames[rankType] || rankType;
                const body = {
                    url: form.url,
                    partner_id: partnerId,
                    poi_id: poiId,
                    rank_type: rankType,
                    date_range: dateRange,
                    cookies,
                    auth_data: form.auth_data,
                    auto_save: true,
                    system_hotel_id: form.hotelId,
                };
                if (dateRange === 'custom') {
                    body.start_date = form.startDate;
                    body.end_date = form.endDate;
                }
                tasks.push({
                    rankType,
                    rankName,
                    dateRange,
                    dateRangeName: rangeName,
                    toastText: `正在获取 ${rangeName} - ${rankName}...`,
                    body,
                });
            });
        });
        return tasks;
    };
    const buildMeituanBatchFetchResultEntry = (task, response = {}) => {
        const base = {
            rankType: task.rankType,
            rankName: task.rankName,
            dateRange: task.dateRange,
            dateRangeName: task.dateRangeName,
        };
        if (response.code === 200) {
            return {
                ...base,
                data: response.data.data,
                savedCount: response.data.saved_count || 0,
                displayHotels: response.data.display_hotels || [],
                displaySummary: response.data.display_summary || null,
                displayCount: response.data.display_hotel_count || (response.data.display_hotels || []).length,
            };
        }
        return {
            ...base,
            error: response.message || '获取失败',
        };
    };
    const buildMeituanDisplayModelPayload = ({ results = [], form = {} } = {}) => ({
        display_hotels: (Array.isArray(results) ? results : []).flatMap(result => Array.isArray(result.displayHotels) ? result.displayHotels : []),
        competitor_room_count: form.competitorRoomCount,
        target_poi_id: form.poiId,
        date_ranges: form.dateRanges,
        start_date: form.startDate,
        end_date: form.endDate,
    });

    const buildMeituanRankDisplayRows = (rows, field) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const metricLabel = meituanDisplayMetricLabel(field);
        const ranked = sourceRows
            .map((row, index) => ({ row, index, value: meituanSortMetricValue(row, field) }))
            .sort((a, b) => b.value - a.value || a.index - b.index);
        const total = ranked.length;
        const leaderValue = ranked[0]?.value || 0;
        const rowMap = new Map();
        ranked.forEach((item, rankIndex) => {
            const prev = ranked[rankIndex - 1] || null;
            const next = ranked[rankIndex + 1] || null;
            const gapToPrev = prev ? Math.max(0, prev.value - item.value) : 0;
            const gapToNext = next ? Math.max(0, item.value - next.value) : 0;
            const gapToLeader = Math.max(0, leaderValue - item.value);
            const gapToPrevText = prev
                ? `距前一名 ${formatMeituanSortGapValue(gapToPrev, field)}`
                : '当前表内领先';
            const gapToLeaderText = rankIndex === 0
                ? '当前TOP1'
                : `距TOP1 ${formatMeituanSortGapValue(gapToLeader, field)}`;
            const row = {
                ...item.row,
                currentPlatformRank: rankIndex + 1,
                circlePositionText: total > 0 ? `第 ${rankIndex + 1} / ${total} 名` : '平台未返回',
                gapMetric: field,
                gapMetricLabel: metricLabel,
                gapToPrev,
                gapToNext,
                gapToLeader,
                gapToPrevText,
                gapToNextText: next
                    ? `领先后一名 ${formatMeituanSortGapValue(gapToNext, field)}`
                    : '尾部酒店',
                gapToLeaderText,
                rankGapSummaryText: [gapToPrevText, gapToLeaderText, item.row?.rank30RangeText || '近30天未返回'].filter(Boolean).join(' / '),
            };
            rowMap.set(meituanDisplayRowKey(item.row, item.index), row);
        });
        return sourceRows.map((row, index) => rowMap.get(meituanDisplayRowKey(row, index)) || row);
    };

    const buildCompetitorSummaryCoreCards = ({
        summary = null,
        rows = [],
        insightByKey = () => null,
        limit = 5,
    } = {}) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const selfRow = sourceRows.find(row => row?.isSelf);
        const topRow = sourceRows[0] || null;
        const selfInsight = insightByKey('self-position');
        const topInsight = insightByKey('top-summary');
        const healthInsight = insightByKey('rank-health');
        const rankGapInsight = insightByKey('rank-gap');
        const tagInsight = insightByKey('platform-tags');
        const tagMetricInsight = insightByKey('tag-metric-link');
        const missing = !summary || summary.data_status === 'missing';
        const baseEntry = { page: 'meituan-ebooking', tab: 'meituan-ranking' };
        return [
            {
                key: 'self-position',
                label: '本店第几',
                value: selfRow?.circlePositionText || selfInsight?.value || (missing ? '待同步' : '未返回'),
                note: selfRow?.gapToLeaderText || selfInsight?.note || '目标 POI 未配置或未出现在本次榜单',
                className: selfRow ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: baseEntry,
            },
            {
                key: 'top-summary',
                label: 'TOP1 是谁',
                value: topRow?.hotelName || topInsight?.value || (missing ? '待同步' : '-'),
                note: topRow ? `${topRow.circlePositionText || '榜首'} · ${topRow.gapToNextText || topRow.rankTrendText || '暂无差距'}` : (topInsight?.note || '暂无可展示榜首'),
                className: 'bg-indigo-50 text-indigo-700 border-indigo-100',
                entry: baseEntry,
            },
            {
                key: 'gap-to-prev',
                label: '与前一名差多少',
                value: rankGapInsight?.value || selfRow?.gapToPrevText || selfRow?.gapToLeaderText || (missing ? '待同步' : '未返回'),
                note: rankGapInsight?.note || (selfRow?.gapMetricLabel ? `按${selfRow.gapMetricLabel}口径计算` : '缺少本店行时不计算差距'),
                className: selfRow ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: baseEntry,
            },
            {
                key: 'platform-tags',
                label: 'VIP/平台标签',
                value: tagInsight?.value || (missing ? '待同步' : '未返回'),
                note: tagMetricInsight?.value && tagMetricInsight.value !== '未返回'
                    ? `${tagInsight?.note || '平台标签已返回'}；${tagMetricInsight.value}`
                    : (tagInsight?.note || '平台未返回标签时不判断权益差距'),
                className: tagInsight?.className || 'bg-gray-50 text-gray-500 border-gray-200',
                entry: baseEntry,
            },
            {
                key: 'rank-trend',
                label: '榜单升降',
                value: selfRow?.rankTrendText || healthInsight?.value || (missing ? '待同步' : '暂无变化'),
                note: selfRow?.rankSummaryText || healthInsight?.note || '旧数据缺少时间维度时不倒推升降',
                className: selfRow?.rankTrendClass ? `bg-white border-gray-200 ${selfRow.rankTrendClass}` : (healthInsight?.className || 'bg-gray-50 text-gray-500 border-gray-200'),
                entry: baseEntry,
            },
        ].slice(0, limit);
    };

    const buildHomeCompetitorSummaryCards = ({
        competitorSummary = null,
        coreCards = [],
        insightCards = [],
        healthRows = [],
        defaultRankTypes = [],
        sourceNotice = '仅展示美团榜单已返回字段；未返回字段保留缺失状态。',
        limit = 5,
    } = {}) => {
        const baseEntry = { page: 'meituan-ebooking', tab: 'meituan-ranking' };
        if (competitorSummary) {
            return (Array.isArray(coreCards) ? coreCards : []).slice(0, limit);
        }
        const safeInsightCards = Array.isArray(insightCards) ? insightCards : [];
        if (safeInsightCards.length) {
            return safeInsightCards.slice(0, limit).map(card => ({
                key: card.key,
                label: card.key === 'platform-tags' ? 'VIP/平台标签' : card.label,
                value: card.value || '-',
                note: card.note || sourceNotice,
                className: card.className || 'bg-white text-gray-700 border-gray-200',
                entry: baseEntry,
            }));
        }
        const safeHealthRows = Array.isArray(healthRows) ? healthRows : [];
        return (Array.isArray(defaultRankTypes) ? defaultRankTypes : []).map(item => {
            const row = safeHealthRows.find(health => health.key === item.key);
            const returned = row?.status === 'ok';
            return {
                key: item.key,
                label: item.label,
                value: row?.statusText || '待同步',
                note: row?.sourceLabel || '进入美团排名采集后返回榜单状态',
                className: returned ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: baseEntry,
            };
        }).slice(0, limit);
    };

    return {
        meituanDisplayMetricLabel,
        meituanSortMetricValue,
        formatMeituanSortGapValue,
        meituanDisplayRowKey,
        defaultMeituanAdsUrl,
        createMeituanRankingForm,
        createMeituanTrafficForm,
        createMeituanOrderForm,
        createMeituanAdsForm,
        createMeituanBrowserCaptureForm,
        normalizeMeituanCaptureSections,
        validateMeituanBatchFetchInput,
        buildMeituanBatchFetchTasks,
        buildMeituanBatchFetchResultEntry,
        buildMeituanDisplayModelPayload,
        buildMeituanRankDisplayRows,
        buildCompetitorSummaryCoreCards,
        buildHomeCompetitorSummaryCards,
    };
})();
