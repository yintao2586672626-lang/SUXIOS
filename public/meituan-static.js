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
        buildMeituanRankDisplayRows,
        buildCompetitorSummaryCoreCards,
        buildHomeCompetitorSummaryCards,
    };
})();
