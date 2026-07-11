(function () {
    'use strict';

    const finiteNumber = (value) => {
        if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return null;
        return Number(value);
    };

    const gapRate = (selfValue, competitorValue) => {
        const self = finiteNumber(selfValue);
        const competitor = finiteNumber(competitorValue);
        if (self === null || competitor === null || competitor === 0) return null;
        return (self - competitor) / competitor * 100;
    };

    const formatCapturedAt = (value) => {
        const raw = String(value || '').trim();
        if (!raw) return '';
        const localMatch = raw.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/);
        if (localMatch) return `${localMatch[1]} ${localMatch[2]}`;
        const date = new Date(raw);
        if (Number.isNaN(date.getTime())) return raw;
        const parts = new Intl.DateTimeFormat('zh-CN', {
            timeZone: 'Asia/Shanghai',
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(date).reduce((result, part) => {
            result[part.type] = part.value;
            return result;
        }, {});
        return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
    };

    const classifyOpportunity = (self = {}, competitor = {}) => {
        const selfUv = finiteNumber(self.uv);
        const competitorUv = finiteNumber(competitor.uv);
        const selfConversion = finiteNumber(self.conversion_rate);
        const competitorConversion = finiteNumber(competitor.conversion_rate);
        if ([selfUv, competitorUv, selfConversion, competitorConversion].some(value => value === null)) {
            return {
                key: 'insufficient', label: '证据不足',
                action: '补齐该日期的酒店与竞争圈流量、转化数据后再判断。',
                className: 'bg-slate-100 text-slate-600',
            };
        }
        const trafficBehind = selfUv < competitorUv;
        const conversionBehind = selfConversion < competitorConversion;
        if (trafficBehind && !conversionBehind) {
            return {
                key: 'traffic_opportunity', label: '拓流机会',
                action: '转化不弱，优先补搜索曝光、排序和可售房覆盖。',
                className: 'bg-blue-100 text-blue-700',
            };
        }
        if (!trafficBehind && conversionBehind) {
            return {
                key: 'conversion_repair', label: '转化修复',
                action: '流量不弱，优先检查价格、房型卖点、库存与取消政策。',
                className: 'bg-amber-100 text-amber-700',
            };
        }
        if (trafficBehind && conversionBehind) {
            return {
                key: 'double_low', label: '双低预警',
                action: '先保可售与基础竞争力，再联动提升搜索流量和页面转化。',
                className: 'bg-rose-100 text-rose-700',
            };
        }
        return {
            key: 'advantage_hold', label: '优势保持',
            action: '维持当前价格与库存策略，关注优势是否连续。',
            className: 'bg-emerald-100 text-emerald-700',
        };
    };

    const buildView = (payload = {}) => {
        const safePayload = payload && typeof payload === 'object' ? payload : {};
        const sourceDates = Array.isArray(safePayload.dates) ? safePayload.dates : [];
        const windows = ['cumulative', 'yesterday'];
        const scopes = ['self', 'competitor_avg', 'self_reference'];
        const metrics = ['pv', 'uv', 'conversion_rate'];
        const rows = sourceDates.map(sourceRow => {
            const row = { target_date: String(sourceRow?.target_date || ''), windows: {} };
            windows.forEach(windowKey => {
                const sourceWindow = sourceRow?.[windowKey] && typeof sourceRow[windowKey] === 'object'
                    ? sourceRow[windowKey]
                    : {};
                const normalized = {};
                scopes.forEach(scopeKey => {
                    const sourceScope = sourceWindow?.[scopeKey] && typeof sourceWindow[scopeKey] === 'object'
                        ? sourceWindow[scopeKey]
                        : {};
                    const pv = finiteNumber(sourceScope.pv);
                    const uv = finiteNumber(sourceScope.uv);
                    normalized[scopeKey] = {
                        pv,
                        uv,
                        conversion_rate: finiteNumber(sourceScope.conversion_rate),
                        order_count: finiteNumber(sourceScope.order_count),
                        browse_intensity: pv === null || uv === null || uv === 0 ? null : pv / uv,
                    };
                });
                normalized.pv_gap_rate = gapRate(normalized.self.pv, normalized.competitor_avg.pv);
                normalized.uv_gap_rate = gapRate(normalized.self.uv, normalized.competitor_avg.uv);
                normalized.conversion_gap = normalized.self.conversion_rate === null || normalized.competitor_avg.conversion_rate === null
                    ? null
                    : normalized.self.conversion_rate - normalized.competitor_avg.conversion_rate;
                normalized.chase_space = normalized.self.uv === null || normalized.competitor_avg.uv === null
                    ? null
                    : Math.max(normalized.competitor_avg.uv - normalized.self.uv, 0);
                normalized.opportunity = classifyOpportunity(normalized.self, normalized.competitor_avg);
                row.windows[windowKey] = normalized;
            });
            const cumulativeUv = row.windows.cumulative.self.uv;
            const yesterdayUv = row.windows.yesterday.self.uv;
            row.yesterday_uv_contribution = cumulativeUv === null || cumulativeUv === 0 || yesterdayUv === null
                ? null
                : yesterdayUv / cumulativeUv * 100;
            return row;
        }).filter(row => row.target_date);

        const aggregate = (windowKey, scopeKey, metricKey, average = false) => {
            const values = rows
                .map(row => finiteNumber(row.windows?.[windowKey]?.[scopeKey]?.[metricKey]))
                .filter(value => value !== null);
            if (!values.length) return null;
            const total = values.reduce((sum, value) => sum + value, 0);
            return average ? total / values.length : total;
        };
        const buildWindowSummary = (windowKey) => {
            const selfPv = aggregate(windowKey, 'self', 'pv');
            const competitorPv = aggregate(windowKey, 'competitor_avg', 'pv');
            const selfUv = aggregate(windowKey, 'self', 'uv');
            const competitorUv = aggregate(windowKey, 'competitor_avg', 'uv');
            const selfConversion = aggregate(windowKey, 'self', 'conversion_rate', true);
            const competitorConversion = aggregate(windowKey, 'competitor_avg', 'conversion_rate', true);
            const hasScopeData = (row, scopeKey) => metrics.some(metricKey => (
                finiteNumber(row.windows?.[windowKey]?.[scopeKey]?.[metricKey]) !== null
            ));
            return {
                self_pv: selfPv,
                competitor_pv: competitorPv,
                pv_gap_rate: gapRate(selfPv, competitorPv),
                self_uv: selfUv,
                competitor_uv: competitorUv,
                uv_gap_rate: gapRate(selfUv, competitorUv),
                self_conversion: selfConversion,
                competitor_conversion: competitorConversion,
                conversion_gap: selfConversion === null || competitorConversion === null
                    ? null
                    : selfConversion - competitorConversion,
                self_days: rows.filter(row => hasScopeData(row, 'self')).length,
                competitor_days: rows.filter(row => hasScopeData(row, 'competitor_avg')).length,
            };
        };
        const windowSummaries = {
            cumulative: buildWindowSummary('cumulative'),
            yesterday: buildWindowSummary('yesterday'),
        };
        const cumulativeSummary = windowSummaries.cumulative;
        const categoryCounts = rows.reduce((result, row) => {
            windows.forEach(windowKey => {
                const key = row.windows?.[windowKey]?.opportunity?.key || 'insufficient';
                result[windowKey][key] = (result[windowKey][key] || 0) + 1;
            });
            return result;
        }, { cumulative: {}, yesterday: {} });
        const maxima = {};
        windows.forEach(windowKey => {
            maxima[windowKey] = {};
            metrics.forEach(metricKey => {
                const values = rows.flatMap(row => scopes.map(scopeKey => (
                    finiteNumber(row.windows?.[windowKey]?.[scopeKey]?.[metricKey])
                ))).filter(value => value !== null);
                maxima[windowKey][metricKey] = values.length ? Math.max(...values, 1) : 1;
            });
        });

        return {
            status: String(safePayload.status || 'not_collected'),
            source_scope: String(safePayload.source_scope || 'ctrip_ota_channel'),
            capture_date: String(safePayload.capture_date || ''),
            captured_at: String(safePayload.captured_at || ''),
            window_start_date: String(safePayload.window_start_date || ''),
            window_end_date: String(safePayload.window_end_date || ''),
            reference_capture_date: String(safePayload.reference_capture_date || ''),
            reference_covered_gap_count: Number(safePayload.reference_covered_gap_count || 0),
            ingestion_methods: Array.isArray(safePayload.ingestion_methods) ? safePayload.ingestion_methods : [],
            order_data_status: String(safePayload.order_data_status || 'not_collected'),
            missing_scopes: Array.isArray(safePayload.missing_scopes) ? safePayload.missing_scopes : [],
            date_gaps: Array.isArray(safePayload.date_gaps) ? safePayload.date_gaps : [],
            rows,
            maxima,
            category_counts: categoryCounts,
            summary: {
                windows: windowSummaries,
                cumulative_self_pv: cumulativeSummary.self_pv,
                cumulative_peer_pv: cumulativeSummary.competitor_pv,
                cumulative_pv_gap_rate: cumulativeSummary.pv_gap_rate,
                cumulative_self_uv: cumulativeSummary.self_uv,
                cumulative_peer_uv: cumulativeSummary.competitor_uv,
                cumulative_uv_gap_rate: cumulativeSummary.uv_gap_rate,
                cumulative_self_conversion: cumulativeSummary.self_conversion,
                cumulative_peer_conversion: cumulativeSummary.competitor_conversion,
                cumulative_conversion_gap: cumulativeSummary.conversion_gap,
                yesterday_opportunity_days: (categoryCounts.yesterday.traffic_opportunity || 0)
                    + (categoryCounts.yesterday.conversion_repair || 0)
                    + (categoryCounts.yesterday.double_low || 0),
            },
        };
    };

    window.SUXI_CTRIP_SEARCH_OPPORTUNITY_STATIC = {
        finiteNumber,
        gapRate,
        formatCapturedAt,
        classifyOpportunity,
        buildView,
    };
})();
