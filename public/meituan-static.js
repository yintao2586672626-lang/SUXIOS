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

    const buildMeituanTopSummaryFallbackRows = (rankedRows = [], limit = 3) => {
        if (!Array.isArray(rankedRows) || rankedRows.length === 0) {
            return [];
        }
        return rankedRows.slice(0, limit).map(row => ({
            poiId: row?.poiId || '',
            hotelName: row?.hotelName || '',
            positionText: row?.circlePositionText || '',
            rankTrendText: row?.rankTrendText || '',
            platformTagText: row?.platformTagText || '',
            roomNights: row?.roomNights || 0,
            sales: row?.sales || 0,
            gapToNextText: row?.gapToNextText || '',
        }));
    };

    const resolveMeituanTopSummaryRows = ({
        businessSummary = null,
        rankedRows = [],
        limit = 3,
    } = {}) => {
        const rows = businessSummary?.top_summary_rows;
        if (Array.isArray(rows) && rows.length) {
            return rows;
        }
        return buildMeituanTopSummaryFallbackRows(rankedRows, limit);
    };

    const findMeituanDynamicSelfRankRow = (rankedRows = []) => {
        if (!Array.isArray(rankedRows)) {
            return null;
        }
        return rankedRows.find(row => row?.isSelf) || null;
    };

    const buildMeituanDisplayedHotelsList = (rankedRows = [], sortField = 'roomNights', sortOrder = 'desc') => {
        const sourceRows = Array.isArray(rankedRows) ? rankedRows : [];
        const ascending = String(sortOrder || '').toLowerCase() === 'asc';
        return [...sourceRows].sort((a, b) => {
            const aVal = meituanSortMetricValue(a, sortField);
            const bVal = meituanSortMetricValue(b, sortField);
            return ascending ? aVal - bVal : bVal - aVal;
        });
    };

    const resolveMeituanSortState = (currentField = 'roomNights', currentOrder = 'desc', nextField = '') => {
        const field = String(nextField || '').trim() || 'roomNights';
        if (String(currentField || '') === field) {
            return {
                field,
                order: String(currentOrder || '').toLowerCase() === 'asc' ? 'desc' : 'asc',
            };
        }
        return { field, order: 'desc' };
    };

    const resolveMeituanTablePage = (page = 1, totalPages = 1) => Math.min(
        Math.max(1, Number(page) || 1),
        Number(totalPages) || 1,
    );

    const resolveMeituanRankSourceNotice = (summary = {}) => summary?.source_notice || '';

    const buildMeituanRankInsightCards = (summary = {}) => {
        const cards = summary?.rank_insights;
        return Array.isArray(cards) ? cards : [];
    };

    const buildMeituanVisibleRankInsightCards = (cards = []) => (
        Array.isArray(cards) ? cards.filter(card => card?.key !== 'tag-metric-link') : []
    );

    const buildMeituanRankHealthRows = (summary = {}) => {
        const rows = summary?.rank_health_rows;
        return Array.isArray(rows) ? rows : [];
    };

    const getOnlineDataMetricMaybeNumber = (item, keys) => {
        for (const key of keys) {
            const value = item?.[key];
            if (value !== undefined && value !== null && value !== '') {
                const number = Number(String(value).replace(/[,，%￥¥元\s]/g, ''));
                if (Number.isFinite(number)) {
                    return number;
                }
            }
        }
        return null;
    };

    const getOnlineDataMetricNumber = (item, keys) => getOnlineDataMetricMaybeNumber(item, keys) ?? 0;

    const parseMeituanOnlineRawObject = (value) => {
        if (!value) return {};
        if (typeof value === 'object' && !Array.isArray(value)) return value;
        if (typeof value !== 'string') return {};
        try {
            const parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (_error) {
            return {};
        }
    };

    const getMeituanNestedMetricMaybeNumber = (item, keys) => {
        const rowValue = getOnlineDataMetricMaybeNumber(item, keys);
        if (rowValue !== null) {
            return rowValue;
        }
        const raw = parseMeituanOnlineRawObject(item?.raw_data);
        const candidates = [
            raw,
            raw?.metrics,
            raw?.summary,
            raw?.reviewSummary,
            raw?.review_summary,
            raw?.data,
        ];
        for (const candidate of candidates) {
            if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)) {
                continue;
            }
            const value = getOnlineDataMetricMaybeNumber(candidate, keys);
            if (value !== null) {
                return value;
            }
        }
        return null;
    };

    const safeDivideMetric = (numerator, denominator) => {
        const top = Number(numerator);
        const bottom = Number(denominator);
        if (!Number.isFinite(top) || !Number.isFinite(bottom) || bottom === 0) {
            return 0;
        }
        return top / bottom;
    };

    const getMeituanExposureMetricValue = (item) => getOnlineDataMetricMaybeNumber(item, ['list_exposure', 'exposure_count', 'exposure']);
    const getMeituanClickMetricValue = (item) => getOnlineDataMetricMaybeNumber(item, ['detail_exposure', 'click_count', 'clicks', 'order_filling_num']);
    const getMeituanVisitorMetricValue = (item) => getOnlineDataMetricMaybeNumber(item, ['order_filling_num', 'visitor_count', 'unique_visitors', 'detail_exposure']);
    const getMeituanSubmitMetricValue = (item) => getOnlineDataMetricMaybeNumber(item, ['order_submit_num', 'submit_users', 'book_order_num']);
    const getMeituanFlowRateMetricValue = (item) => {
        const explicit = getOnlineDataMetricMaybeNumber(item, ['flow_rate', 'conversion_rate', 'conversionRate']);
        if (explicit !== null) {
            return explicit;
        }
        const exposure = getMeituanExposureMetricValue(item);
        const click = getMeituanClickMetricValue(item);
        if (exposure === null || click === null || exposure === 0) {
            return null;
        }
        return safeDivideMetric(click, exposure) * 100;
    };
    const getMeituanExposureMetric = (item) => getMeituanExposureMetricValue(item) ?? 0;
    const getMeituanClickMetric = (item) => getMeituanClickMetricValue(item) ?? 0;
    const getMeituanVisitorMetric = (item) => getMeituanVisitorMetricValue(item) ?? 0;
    const getMeituanSubmitMetric = (item) => getMeituanSubmitMetricValue(item) ?? 0;
    const getMeituanFlowRateMetric = (item) => getMeituanFlowRateMetricValue(item) ?? 0;
    const hasMeituanExposureMetric = (item) => getMeituanExposureMetricValue(item) !== null;
    const hasMeituanClickMetric = (item) => getMeituanClickMetricValue(item) !== null;
    const hasMeituanVisitorMetric = (item) => getMeituanVisitorMetricValue(item) !== null;
    const hasMeituanFlowRateMetric = (item) => getMeituanFlowRateMetricValue(item) !== null;
    const isMeituanOverviewDataRow = (item) => item?.source === 'meituan' && item?.data_type === 'peer_rank';
    const isMeituanTrafficDataRow = (item) => item?.source === 'meituan' && ['traffic', 'traffic_analysis'].includes(item?.data_type);
    const isMeituanOrderDataRow = (item) => item?.source === 'meituan' && item?.data_type === 'order';
    const isMeituanReviewDataRow = (item) => item?.source === 'meituan' && ['review', 'comment', 'comments'].includes(item?.data_type);
    const isMeituanAdsDataRow = (item) => item?.source === 'meituan' && item?.data_type === 'advertising';
    const getMeituanReviewScoreMetricValue = (item) => {
        const score = getMeituanNestedMetricMaybeNumber(item, [
            'comment_score',
            'commentScore',
            'score',
            'star',
            'rating',
            'rate',
            'totalScore',
            'overallScore',
        ]);
        return score !== null && score > 0 ? score : null;
    };
    const getMeituanReviewCountMetricValue = (item) => getMeituanNestedMetricMaybeNumber(item, [
        'quantity',
        'review_count',
        'reviewCount',
        'comment_count',
        'commentCount',
        'count',
        'reviewTotal',
        'review_total',
        'commentTotal',
        'comment_total',
    ]);
    const getMeituanBadReviewCountMetricValue = (item) => getMeituanNestedMetricMaybeNumber(item, [
        'data_value',
        'dataValue',
        'bad_review_count',
        'badReviewCount',
        'negativeCommentCount',
        'negative_count',
        'negativeCount',
        'badCount',
        'lowScoreCount',
        'noRecommendCount',
    ]);
    const buildMeituanReviewDisplayRow = (item) => {
        const raw = parseMeituanOnlineRawObject(item?.raw_data);
        return {
            ...item,
            review_score_value: getMeituanReviewScoreMetricValue(item),
            review_count_value: getMeituanReviewCountMetricValue(item),
            bad_review_count_value: getMeituanBadReviewCountMetricValue(item),
            review_dimension_label: item?.dimension
                || raw?.dimension
                || raw?.dimName
                || raw?.reviewDimension
                || raw?.review_dimension
                || '点评聚合',
        };
    };

    const buildMeituanDownloadData = (rows = []) => {
        const allRows = [];
        const overviewRows = [];
        const trafficRows = [];
        const orderRows = [];
        const reviewRows = [];
        const adsRows = [];

        const overviewHotels = new Set();
        const overviewDates = new Set();
        let overviewMetricValueCount = 0;
        let overviewRankCount = 0;
        let overviewPercentCount = 0;
        let trafficExposure = 0;
        let trafficClick = 0;
        let trafficExposureAvailable = false;
        let trafficClickAvailable = false;
        let trafficFlowRateSum = 0;
        let trafficFlowRateCount = 0;
        let orderBookOrder = 0;
        let orderQuantity = 0;
        let orderAmount = 0;
        let orderBookOrderAvailable = false;
        let orderQuantityAvailable = false;
        let orderAmountAvailable = false;
        let reviewScoreSum = 0;
        let reviewScoreCount = 0;
        let reviewTotalCount = 0;
        let reviewTotalAvailable = false;
        let reviewBadCount = 0;
        let reviewBadAvailable = false;
        let adsExposure = 0;
        let adsClick = 0;
        let adsExposureAvailable = false;
        let adsClickAvailable = false;

        for (const item of Array.isArray(rows) ? rows : []) {
            if (item?.source !== 'meituan') {
                continue;
            }
            allRows.push(item);
            if (isMeituanOverviewDataRow(item)) {
                overviewRows.push(item);
                const hotelKey = item?.system_hotel_id ?? item?.hotel_id ?? item?.hotel_name;
                if (hotelKey !== undefined && hotelKey !== null && String(hotelKey).trim() !== '') {
                    overviewHotels.add(String(hotelKey));
                }
                if (item?.data_date) overviewDates.add(String(item.data_date));
                if (getOnlineDataMetricMaybeNumber(item, ['data_value']) !== null) overviewMetricValueCount++;
                if (getOnlineDataMetricMaybeNumber(item, ['rank', 'ranking']) !== null) overviewRankCount++;
                if (getOnlineDataMetricMaybeNumber(item, ['rank_percent', 'percent']) !== null) overviewPercentCount++;
            }

            if (isMeituanTrafficDataRow(item)) {
                trafficRows.push(item);
                const exposure = getMeituanExposureMetricValue(item);
                const click = getMeituanClickMetricValue(item);
                if (exposure !== null) {
                    trafficExposure += exposure;
                    trafficExposureAvailable = true;
                }
                if (click !== null) {
                    trafficClick += click;
                    trafficClickAvailable = true;
                }
                const flowRate = getMeituanFlowRateMetricValue(item);
                if (flowRate !== null) {
                    trafficFlowRateSum += flowRate;
                    trafficFlowRateCount++;
                }
            }

            if (isMeituanOrderDataRow(item)) {
                orderRows.push(item);
                const bookOrder = getOnlineDataMetricMaybeNumber(item, ['book_order_num']);
                const quantity = getOnlineDataMetricMaybeNumber(item, ['quantity']);
                const amount = getOnlineDataMetricMaybeNumber(item, ['amount']);
                if (bookOrder !== null) {
                    orderBookOrder += bookOrder;
                    orderBookOrderAvailable = true;
                }
                if (quantity !== null) {
                    orderQuantity += quantity;
                    orderQuantityAvailable = true;
                }
                if (amount !== null) {
                    orderAmount += amount;
                    orderAmountAvailable = true;
                }
            }

            if (isMeituanReviewDataRow(item)) {
                const reviewRow = buildMeituanReviewDisplayRow(item);
                reviewRows.push(reviewRow);
                const score = reviewRow.review_score_value;
                if (score !== null) {
                    reviewScoreSum += score;
                    reviewScoreCount++;
                }
                const reviewCount = reviewRow.review_count_value;
                if (reviewCount !== null) {
                    reviewTotalCount += reviewCount;
                    reviewTotalAvailable = true;
                }
                const badCount = reviewRow.bad_review_count_value;
                if (badCount !== null) {
                    reviewBadCount += badCount;
                    reviewBadAvailable = true;
                }
            }

            if (isMeituanAdsDataRow(item)) {
                adsRows.push(item);
                const exposure = getMeituanExposureMetricValue(item);
                const click = getMeituanClickMetricValue(item);
                if (exposure !== null) {
                    adsExposure += exposure;
                    adsExposureAvailable = true;
                }
                if (click !== null) {
                    adsClick += click;
                    adsClickAvailable = true;
                }
            }
        }

        const trafficAvgFlowRate = trafficFlowRateCount > 0 ? trafficFlowRateSum / trafficFlowRateCount : null;
        const trafficExposureValue = trafficExposureAvailable ? trafficExposure : null;
        const trafficClickValue = trafficClickAvailable ? trafficClick : null;
        const adsExposureValue = adsExposureAvailable ? adsExposure : null;
        const adsClickValue = adsClickAvailable ? adsClick : null;

        return {
            allRows,
            allRowsCount: allRows.length,
            overviewRows,
            trafficRows,
            orderRows,
            adsRows,
            overviewRowsCount: overviewRows.length,
            overviewHotelCount: overviewHotels.size,
            overviewDateCount: overviewDates.size,
            overviewMetricValueCount,
            overviewRankCount,
            overviewPercentCount,
            trafficExposure: trafficExposureValue,
            trafficClick: trafficClickValue,
            trafficAvgFlowRate,
            trafficClickRate: trafficExposureAvailable && trafficClickAvailable && trafficExposure !== 0
                ? safeDivideMetric(trafficClick, trafficExposure) * 100
                : null,
            orderRowsCount: orderRows.length,
            orderBookOrder: orderBookOrderAvailable ? orderBookOrder : null,
            orderQuantity: orderQuantityAvailable ? orderQuantity : null,
            orderAmount: orderAmountAvailable ? orderAmount : null,
            reviewRows,
            reviewRowsCount: reviewRows.length,
            reviewAverageScore: reviewScoreCount > 0 ? reviewScoreSum / reviewScoreCount : null,
            reviewTotalCount: reviewTotalAvailable ? reviewTotalCount : null,
            reviewBadCount: reviewBadAvailable ? reviewBadCount : null,
            adsRowsCount: adsRows.length,
            adsExposure: adsExposureValue,
            adsClick: adsClickValue,
            adsClickRate: adsExposureAvailable && adsClickAvailable && adsExposure !== 0
                ? safeDivideMetric(adsClick, adsExposure) * 100
                : null,
        };
    };

    const defaultMeituanAdsUrl = () => 'https://ebmidas.dianping.com/shopdiy/account/pcCpcEntry?continueUrl=/app/peon-merchant-product-menu/html/index.html';
    const runPostFetchRefresh = (callback, ...args) => {
        try {
            Promise.resolve(callback(...args)).catch(error => {
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[meituan-static] post-fetch refresh failed:', error);
                }
            });
        } catch (error) {
            if (typeof console !== 'undefined' && console.error) {
                console.error('[meituan-static] post-fetch refresh failed:', error);
            }
        }
    };
    const buildMeituanPersistenceOutcome = (data = {}) => {
        const savedCount = Number(data?.saved_count || 0);
        const businessStatus = String(data?.status || data?.business_status || '').trim().toLowerCase();
        const persistenceStatus = String(data?.persistence_status || '').trim().toLowerCase();
        const readbackVerified = data?.readback_verified === true
            || data?.database_readback?.verified === true
            || data?.database_readback?.readback_verified === true
            || persistenceStatus === 'readback_verified';
        const persisted = savedCount > 0 && (
            readbackVerified
            || data?.persisted === true
            || persistenceStatus === 'persisted'
        );
        const businessFailed = ['failed', 'error', 'blocked', 'not_persisted'].includes(businessStatus)
            || ['failed', 'blocked', 'not_persisted', 'readback_failed'].includes(persistenceStatus);
        const businessCompleted = ['success', 'completed', 'complete', 'partial_success'].includes(businessStatus);
        return {
            savedCount,
            businessStatus,
            persistenceStatus,
            readbackVerified,
            persisted,
            businessFailed,
            businessCompleted,
        };
    };
    const buildMeituanPersistenceNotice = ({
        label = '美团数据',
        data = {},
        hasDisplayRows = false,
        failureMessage = '',
    } = {}) => {
        const outcome = buildMeituanPersistenceOutcome(data);
        if (outcome.businessFailed) {
            return {
                ...outcome,
                level: 'error',
                message: `${label}请求已返回，但业务处理未完成：${failureMessage || '请查看返回的失败原因'}`,
            };
        }
        if (outcome.readbackVerified && outcome.savedCount > 0) {
            return {
                ...outcome,
                level: 'success',
                message: `${label}已入库 ${outcome.savedCount} 条，并完成数据库回读核验`,
            };
        }
        if (outcome.persisted) {
            return {
                ...outcome,
                level: 'warning',
                message: `${label}后端明确报告已持久化 ${outcome.savedCount} 条，尚未完成数据库回读核验`,
            };
        }
        if (outcome.savedCount > 0) {
            return {
                ...outcome,
                level: 'warning',
                message: `${label}请求已完成，接口报告处理 ${outcome.savedCount} 条，尚未确认数据库回读`,
            };
        }
        if (hasDisplayRows) {
            return {
                ...outcome,
                level: 'warning',
                message: `${label}请求已完成，已返回可展示数据，但尚未确认入库`,
            };
        }
        return {
            ...outcome,
            level: 'warning',
            message: `${label}请求已完成，未解析到可保存记录`,
        };
    };

    const createMeituanRankingForm = () => ({
        url: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
        hotelId: '',
        partnerId: '',
        poiId: '',
        rankType: 'P_RZ',
        rankTypes: ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'],
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
        csvText: '',
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
        captureSections: ['traffic', 'orders', 'reviews', 'ads'],
        dataPeriod: 'historical_daily',
        payloadJson: '',
    });

    const getMeituanOrderFlowPeriods = () => ([
        { key: 'yesterday', label: '昨天', days: 1 },
        { key: 'last_7_days', label: '近7天', days: 7 },
        { key: 'last_30_days', label: '近30天', days: 30 },
    ]);

    const formatMeituanOrderFlowDate = (date) => {
        const value = date instanceof Date ? date : new Date(date);
        if (Number.isNaN(value.getTime())) return '';
        return [
            value.getFullYear(),
            String(value.getMonth() + 1).padStart(2, '0'),
            String(value.getDate()).padStart(2, '0'),
        ].join('-');
    };

    const resolveMeituanOrderFlowDateRange = (period = 'last_7_days', now = new Date()) => {
        const config = getMeituanOrderFlowPeriods().find(item => item.key === period)
            || getMeituanOrderFlowPeriods()[1];
        const end = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        end.setDate(end.getDate() - 1);
        const start = new Date(end.getTime());
        start.setDate(start.getDate() - Math.max(0, config.days - 1));
        return {
            period: config.key,
            label: config.label,
            startDate: formatMeituanOrderFlowDate(start),
            endDate: formatMeituanOrderFlowDate(end),
        };
    };

    const parseMeituanOrderFlowRaw = (value) => {
        if (value && typeof value === 'object' && !Array.isArray(value)) return value;
        if (typeof value !== 'string' || !value.trim()) return {};
        try {
            const parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (error) {
            return {};
        }
    };

    const firstMeituanOrderFlowValue = (...values) => values.find(value => (
        value !== undefined && value !== null && String(value).trim() !== ''
    ));

    const meituanOrderFlowNumber = (value) => {
        if (value === undefined || value === null || String(value).trim() === '') return null;
        const text = String(value).trim();
        if (text === '-' || text === '--' || /暂无|无数据|更新中/i.test(text)) return null;
        const multiplier = text.includes('亿') ? 100000000 : (text.includes('万') ? 10000 : 1);
        const match = text.replace(/,/g, '').replace(/[%￥¥元万亿\s]/g, '').match(/-?\d+(?:\.\d+)?/);
        if (!match) return null;
        const number = Number(match[0]) * multiplier;
        return Number.isFinite(number) ? number : null;
    };

    const meituanOrderFlowRatioPercent = (value) => {
        const number = meituanOrderFlowNumber(value);
        if (number === null) return null;
        const percent = Math.abs(number) <= 1 ? number * 100 : number;
        return Number(percent.toFixed(2));
    };

    const buildMeituanOrderFlowView = (rows = [], period = 'last_7_days') => {
        const source = Array.isArray(rows) ? rows : [];
        const normalized = source.map(row => {
            const raw = parseMeituanOrderFlowRaw(row?.raw_data);
            return { row: row || {}, raw };
        }).filter(item => (
            String(item.row.data_type || '').toLowerCase() === 'order_flow'
            && String(item.raw.order_flow_period || '').toLowerCase() === period
        ));
        const findSummary = direction => normalized.find(item => (
            String(item.raw.order_flow_direction || '').toLowerCase() === direction
            && String(item.raw.order_flow_row_type || '').toLowerCase() === 'summary'
        ));
        const normalizeSummary = (entry) => {
            if (!entry) return null;
            const { row, raw } = entry;
            return {
                orderCount: meituanOrderFlowNumber(firstMeituanOrderFlowValue(raw.order_count, row.book_order_num)),
                roomNights: meituanOrderFlowNumber(firstMeituanOrderFlowValue(raw.room_nights, row.quantity)),
                amount: meituanOrderFlowNumber(firstMeituanOrderFlowValue(raw.amount, row.amount)),
                periodStart: String(raw.period_start || ''),
                periodEnd: String(raw.period_end || row.data_date || ''),
            };
        };
        const normalizeDetails = direction => normalized.filter(item => (
            String(item.raw.order_flow_direction || '').toLowerCase() === direction
            && String(item.raw.order_flow_row_type || '').toLowerCase() === 'hotel_detail'
        )).map(({ row, raw }, index) => {
            const rooms = Array.isArray(raw.lossRoomList) ? raw.lossRoomList : [];
            return {
                key: String(raw.poiId || raw.poi_id || row.hotel_id || `${direction}-${index}`),
                hotelId: String(raw.poiId || raw.poi_id || row.hotel_id || ''),
                hotelName: String(raw.poiName || raw.poi_name || row.hotel_name || '未返回酒店名称'),
                image: String(raw.frontImg || raw.front_img || ''),
                star: String(raw.lossPoiStar || raw.loss_poi_star || ''),
                circleName: String(raw.circleName || raw.circle_name || ''),
                distance: meituanOrderFlowNumber(raw.distance),
                score: meituanOrderFlowNumber(raw.score),
                lowestPrice: meituanOrderFlowNumber(raw.lowestPrice ?? raw.lowest_price),
                vip: raw.vipTag === true || raw.vip_tag === true || raw.vipTag === 1,
                followStatus: meituanOrderFlowNumber(raw.followStatus ?? raw.follow_status),
                orderCount: meituanOrderFlowNumber(firstMeituanOrderFlowValue(raw.order_count, raw.lossOrderCount, row.book_order_num)),
                orderRatio: meituanOrderFlowRatioPercent(firstMeituanOrderFlowValue(raw.order_ratio, raw.lossOrderRatio)),
                amount: meituanOrderFlowNumber(firstMeituanOrderFlowValue(raw.amount, raw.lossSinglePayAmount, row.amount)),
                rooms: rooms.map(room => ({
                    name: String(room?.lossRoomName || room?.roomName || '').trim(),
                    count: meituanOrderFlowNumber(room?.lossRoomCnt ?? room?.roomCount),
                })).filter(room => room.name),
            };
        }).sort((left, right) => (right.orderCount ?? -1) - (left.orderCount ?? -1));

        const loss = { summary: normalizeSummary(findSummary('loss')), rows: normalizeDetails('loss') };
        const inflow = { summary: normalizeSummary(findSummary('inflow')), rows: normalizeDetails('inflow') };
        const firstSummary = loss.summary || inflow.summary;
        const capturedAt = normalized.map(item => String(item.row.update_time || item.row.create_time || '')).filter(Boolean).sort().at(-1) || '';
        return {
            status: loss.summary && inflow.summary ? 'complete' : (loss.summary || inflow.summary ? 'partial' : 'empty'),
            period,
            periodStart: firstSummary?.periodStart || '',
            periodEnd: firstSummary?.periodEnd || '',
            capturedAt,
            loss,
            inflow,
        };
    };

    const getMeituanBrowserCapturePresets = () => ([
        {
            key: 'realtime',
            label: '实时数据',
            description: '曝光、访问、下单转化的实时快照',
            sections: ['realtime'],
            dataPeriod: 'realtime_snapshot',
            icon: 'fas fa-bolt',
            className: 'border-orange-200 bg-orange-50 text-orange-800 hover:bg-orange-100',
        },
        {
            key: 'reviews',
            label: '评论聚合',
            description: '评分、点评量、差评量；不保存评论正文',
            sections: ['reviews'],
            dataPeriod: 'historical_daily',
            icon: 'fas fa-comment-dots',
            className: 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100',
        },
        {
            key: 'full',
            label: '完整采集',
            description: '流量、订单、评论聚合、推广通广告',
            sections: ['full'],
            dataPeriod: 'historical_daily',
            requiresAdsUrl: true,
            icon: 'fas fa-layer-group',
            className: 'border-blue-200 bg-blue-50 text-blue-800 hover:bg-blue-100',
        },
        {
            key: 'ads',
            label: '广告入口',
            description: '推广通曝光、点击、消耗、成交',
            sections: ['ads'],
            dataPeriod: 'historical_daily',
            requiresAdsUrl: true,
            icon: 'fas fa-bullhorn',
            className: 'border-red-200 bg-red-50 text-red-800 hover:bg-red-100',
        },
    ]);

    const meituanDataFreshnessNotice = '每日9点更新前日数据。数据仅作经营参考，不作结算依据。';
    const shouldShowMeituanPreviousDayUpdateNotice = (dateRanges = [], hour = new Date().getHours()) => {
        const normalizedHour = Number(hour);
        return Array.isArray(dateRanges)
            && dateRanges.map(item => String(item)).includes('1')
            && Number.isFinite(normalizedHour)
            && normalizedHour >= 0
            && normalizedHour < 9;
    };
    const createEmptyMeituanBusinessSummary = () => ({
        status: 'empty',
        metrics: {},
        cards: [],
        data_freshness: {
            update_policy: 'daily_09_previous_day',
            update_time: '09:00',
            settlement_basis: false,
            notice: meituanDataFreshnessNotice,
        },
        source_notice: meituanDataFreshnessNotice,
    });

    const buildMeituanRankingFetchResetState = () => ({
        formPatch: {
            partnerId: '',
            poiId: '',
            cookies: '',
            auth_data: {},
            hotelRoomCount: '',
            competitorRoomCount: '',
        },
        fetchSuccess: false,
        hotelsList: [],
        businessSummary: createEmptyMeituanBusinessSummary(),
        onlineDataResult: null,
        savedCount: 0,
        dataFetchTime: '',
    });

    const isMeituanPendingResult = (result = {}) => ['fetching', 'submitting', 'saving'].includes(String(result?.status || '').toLowerCase());

    const isMeituanBackgroundResult = (result = {}) => ['accepted', 'running', 'queued'].includes(String(result?.status || '').toLowerCase());

    const hasMeituanPendingResults = (results = []) => Array.isArray(results) && results.some(isMeituanPendingResult);

    const hasMeituanBackgroundResults = (results = []) => Array.isArray(results) && results.some(isMeituanBackgroundResult);

    const buildMeituanFetchPresentation = (results = []) => {
        const source = Array.isArray(results) ? results : [];
        const totalCount = source.length;
        const pending = source.filter(isMeituanPendingResult);
        const background = source.filter(isMeituanBackgroundResult);
        const completedCount = source.filter(item => item?.rankDataComplete === true).length;
        const derivedCount = source.filter(item => (
            item?.rankDataComplete === true && String(item?.rankDataMode || '').toLowerCase() === 'derived'
        )).length;
        const selfOnlyCount = source.filter(item => (
            item?.rankDataComplete === true && String(item?.rankDataMode || '').toLowerCase() === 'self_only'
        )).length;
        const returnedCount = source.filter(item => (
            item?.platformResponseReceived === true
            || item?.rankDataComplete === true
            || (!isMeituanPendingResult(item) && !isMeituanBackgroundResult(item) && Number(item?.attemptCount || 0) > 0)
        )).length;
        const failedCount = source.filter(item => Boolean(
            item?.error
            || item?.retryExhausted
            || ['exception', 'failed', 'incomplete', 'partial', 'login_required'].includes(String(item?.status || '').toLowerCase())
        )).length;
        const inProgress = pending.length > 0;
        const backgroundAccepted = background.length > 0;
        const hasErrors = failedCount > 0;
        const isPartial = !inProgress && !backgroundAccepted && completedCount > 0 && hasErrors;
        const activeAttempt = pending.reduce((max, item) => Math.max(max, Number(item?.attemptCount || 0)), 0);
        const activeMaxAttempts = pending.reduce((max, item) => Math.max(max, Number(item?.maxAttempts || 0)), 0);
        let buttonText = '获取数据';
        if (inProgress) {
            buttonText = `获取中 · 已返回${returnedCount}/${totalCount}榜`;
            if (activeAttempt > 0 && activeMaxAttempts > 0) {
                buttonText += ` · 第${activeAttempt}/${activeMaxAttempts}轮`;
            }
        }
        return {
            totalCount,
            completedCount,
            derivedCount,
            selfOnlyCount,
            returnedCount,
            failedCount,
            inProgress,
            backgroundAccepted,
            hasErrors,
            isPartial,
            buttonText,
        };
    };

    const applyMeituanFetchHealthToCards = (cards = [], presentation = {}) => {
        const source = Array.isArray(cards) ? cards : [];
        const totalCount = Number(presentation?.totalCount || 0);
        if (totalCount <= 0) {
            return source;
        }
        const completedCount = Math.max(0, Number(presentation?.completedCount || 0));
        const derivedCount = Math.max(0, Number(presentation?.derivedCount || 0));
        const selfOnlyCount = Math.max(0, Number(presentation?.selfOnlyCount || 0));
        const partial = Boolean(presentation?.isPartial || presentation?.hasErrors);
        const stateText = presentation?.inProgress
            ? '本次抓取中'
            : (partial ? '本次部分返回' : ((derivedCount > 0 || selfOnlyCount > 0) ? '本次可用' : '本次已完整'));
        return source.map(card => {
            const isRankHealth = card?.key === 'rankHealth'
                || card?.key === 'fallback-rank-health'
                || card?.key === 'rank-health'
                || card?.label === '榜单健康度';
            if (!isRankHealth) {
                return card;
            }
            return {
                ...card,
                value: `${completedCount}/${totalCount}`,
                level: stateText,
                note: presentation?.inProgress
                    ? `本次已有 ${completedCount}/${totalCount} 类榜单字段完整`
                    : (partial
                        ? `本次 ${completedCount}/${totalCount} 类榜单可用，其余保持缺失`
                        : (selfOnlyCount > 0
                            ? `本次 ${completedCount}/${totalCount} 类榜单可用，其中 ${selfOnlyCount} 类为本店实时值和同行名次`
                            : (derivedCount > 0
                                ? `本次 ${completedCount}/${totalCount} 类榜单可用，其中 ${derivedCount} 类按本店真实值和平台百分比计算`
                                : `本次 ${completedCount}/${totalCount} 类榜单原始字段完整`))),
                valueClass: presentation?.inProgress ? 'text-blue-700' : (partial ? 'text-amber-700' : (card?.valueClass || 'text-blue-700')),
                panelClass: presentation?.inProgress
                    ? 'bg-blue-50 border border-blue-200'
                    : (partial ? 'bg-amber-50 border border-amber-200' : card?.panelClass),
                className: presentation?.inProgress
                    ? 'bg-blue-50 text-blue-700 border-blue-100'
                    : (partial ? 'bg-amber-50 text-amber-700 border-amber-100' : card?.className),
            };
        });
    };

    const applyMeituanFetchHealthToRows = (rows = [], results = []) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const resultByRankType = new Map(
            (Array.isArray(results) ? results : [])
                .filter(item => item?.rankType)
                .map(item => [String(item.rankType), item])
        );
        return sourceRows.map(row => {
            const result = resultByRankType.get(String(row?.key || ''));
            if (!result) {
                return row;
            }
            if (isMeituanPendingResult(result)) {
                const attempt = Number(result?.attemptCount || 0);
                const maxAttempts = Number(result?.maxAttempts || 0);
                return {
                    ...row,
                    status: 'fetching',
                    statusText: attempt > 0 && maxAttempts > 0 ? `抓取中 ${attempt}/${maxAttempts}` : '抓取中',
                    sourceLabel: '本次平台请求进行中',
                    className: 'bg-blue-50 text-blue-700 border-blue-100',
                };
            }
            if (isMeituanBackgroundResult(result)) {
                return {
                    ...row,
                    status: 'running',
                    statusText: '后台执行中',
                    sourceLabel: '本次平台任务已提交后台',
                    className: 'bg-blue-50 text-blue-700 border-blue-100',
                };
            }
            if (result?.rankDataComplete === true) {
                const isDerived = String(result?.rankDataMode || '').toLowerCase() === 'derived';
                const isSelfOnly = String(result?.rankDataMode || '').toLowerCase() === 'self_only';
                const readbackVerified = result?.readbackVerified === true
                    || result?.readback_verified === true
                    || String(result?.persistenceStatus || result?.persistence_status || '').toLowerCase() === 'readback_verified';
                return {
                    ...row,
                    status: 'ok',
                    statusText: isSelfOnly ? '本店实时值可用' : (isDerived ? '比例结果可用' : '原始完整'),
                    sourceLabel: isSelfOnly
                        ? '本店真实值 + 同行名次；本次同行数值未返回'
                        : (isDerived
                            ? (readbackVerified
                                ? '平台百分比 + 本店真实值锚点；已完成数据库回读核验'
                                : '平台百分比 + 本店真实值锚点；保存状态以数据库回读为准')
                            : '本次平台原始字段完整'),
                    className: 'bg-emerald-50 text-emerald-700 border-emerald-100',
                };
            }
            if (result?.retryExhausted) {
                return {
                    ...row,
                    status: 'missing',
                    statusText: '未抓到',
                    sourceLabel: `已尝试 ${Number(result?.attemptCount || 0)} 轮，平台仍未返回完整榜单`,
                    className: 'bg-red-50 text-red-700 border-red-100',
                };
            }
            return {
                ...row,
                status: 'incomplete',
                statusText: '未完整',
                sourceLabel: '本次榜单仍缺少字段',
                className: 'bg-amber-50 text-amber-700 border-amber-100',
            };
        });
    };

    const getMeituanBrowserCaptureSupplementModules = () => ([
        { key: 'peer_rank', label: '同行排名', endpoint: 'peer/rank/data/detail' },
        { key: 'traffic_analysis', label: '流量分析', endpoint: 'flowConversion / flowTrend / flowTrendDetail' },
        { key: 'search_keywords', label: '搜索关键词', endpoint: 'searchKeyWords' },
        { key: 'traffic_forecast', label: '未来30天预测', endpoint: 'flowForecast' },
    ]);

    const meituanBrowserCaptureCountValue = (value) => {
        if (Array.isArray(value)) return value.length;
        const numeric = Number(value);
        return Number.isFinite(numeric) && numeric > 0 ? numeric : 0;
    };

    const firstMeituanBrowserCaptureCount = (sources, keys) => {
        for (const source of sources) {
            if (!source || typeof source !== 'object') continue;
            for (const key of keys) {
                if (Object.prototype.hasOwnProperty.call(source, key)) {
                    const count = meituanBrowserCaptureCountValue(source[key]);
                    if (count > 0) return count;
                }
            }
        }
        return 0;
    };

    const buildMeituanBrowserCaptureSupplementCounts = (result = {}) => {
        const payload = result?.payload && typeof result.payload === 'object' ? result.payload : {};
        const sources = [
            result?.payload_counts,
            result?.payloadCounts,
            result?.counts,
            result?.sync_summary,
            result?.syncSummary,
            payload?.payload_counts,
            payload?.counts,
            payload?.sync_summary,
            payload,
            result,
        ];
        return [
            {
                key: 'traffic',
                label: '实时流量',
                count: firstMeituanBrowserCaptureCount(sources, ['traffic', 'traffic_count', 'trafficCount']),
            },
            {
                key: 'orders',
                label: '订单数据',
                count: firstMeituanBrowserCaptureCount(sources, ['orders', 'order_count', 'orders_count', 'orderCount', 'ordersCount']),
            },
            {
                key: 'reviews',
                label: '评论聚合',
                count: firstMeituanBrowserCaptureCount(sources, ['reviews', 'review_count', 'reviews_count', 'reviewCount', 'reviewsCount', 'comment_count', 'commentCount']),
            },
            {
                key: 'ads',
                label: '推广通广告',
                count: firstMeituanBrowserCaptureCount(sources, ['ads', 'ad_count', 'ads_count', 'adCount', 'adsCount']),
            },
            {
                key: 'peer_rank',
                label: '同行排名',
                count: firstMeituanBrowserCaptureCount(sources, ['peer_rank', 'peerRank', 'peer_rank_count', 'peerRankCount']),
            },
            {
                key: 'traffic_analysis',
                label: '流量分析',
                count: firstMeituanBrowserCaptureCount(sources, ['traffic_analysis', 'flowAnalysis', 'flow_analysis', 'flow_analysis_count', 'traffic_analysis_count']),
            },
            {
                key: 'search_keywords',
                label: '搜索关键词',
                count: firstMeituanBrowserCaptureCount(sources, ['search_keywords', 'searchKeywords', 'search_keyword', 'search_keyword_count']),
            },
            {
                key: 'traffic_forecast',
                label: '未来30天预测',
                count: firstMeituanBrowserCaptureCount(sources, ['traffic_forecast', 'trafficForecast', 'flowForecast', 'traffic_forecast_count']),
            },
            {
                key: 'responses',
                label: '监听响应',
                count: firstMeituanBrowserCaptureCount(sources, ['responses', 'response_count', 'responseCount']),
            },
        ];
    };

    const normalizeMeituanCaptureSections = (sections) => {
        const aliases = {
            review: 'reviews',
            reviews: 'reviews',
            comment: 'reviews',
            comments: 'reviews',
            traffic: 'traffic',
            flow: 'traffic',
            peer_rank: 'traffic',
            peerrank: 'traffic',
            peer: 'traffic',
            competitor_rank: 'traffic',
            competitorrank: 'traffic',
            ranking: 'traffic',
            flowanalysis: 'traffic',
            flow_analysis: 'traffic',
            traffic_analysis: 'traffic',
            trafficanalysis: 'traffic',
            flowconversion: 'traffic',
            flowtrend: 'traffic',
            flowtrenddetail: 'traffic',
            flowforecast: 'traffic',
            flow_forecast: 'traffic',
            trafficforecast: 'traffic',
            traffic_forecast: 'traffic',
            orderflow: 'order_flow',
            order_flow: 'order_flow',
            orderloss: 'order_flow',
            order_loss: 'order_flow',
            searchkeyword: 'traffic',
            searchkeywords: 'traffic',
            search_keyword: 'traffic',
            search_keywords: 'traffic',
            ads: 'ads',
            ad: 'ads',
            advertising: 'ads',
            orders: 'orders',
            order: 'orders',
            full: 'full',
            complete: 'full',
            all: 'full',
            default: 'default',
            core: 'default',
            realtime: 'traffic',
            realtime_snapshot: 'traffic',
        };
        const raw = Array.isArray(sections) ? sections : String(sections || '').split(/[,\s]+/);
        const normalized = [];
        raw.forEach(item => {
            const value = aliases[String(item || '').trim().toLowerCase()] || '';
            if (value === 'full') {
                normalized.push('traffic', 'orders', 'reviews', 'ads');
            } else if (value === 'default') {
                normalized.push('traffic', 'orders');
            } else if (value) {
                normalized.push(value);
            }
        });
        return Array.from(new Set(normalized));
    };

    const buildMeituanCaptureTabSwitchState = ({
        tab = '',
        sections = [],
    } = {}) => ({
        tab,
        captureSections: normalizeMeituanCaptureSections(sections),
        captureResult: null,
        shouldSyncTrafficConfig: tab === 'meituan-traffic',
    });

    const buildMeituanBrowserCapturePresetState = ({
        preset = {},
        currentDataPeriod = '',
        defaultDataPeriod = 'historical_daily',
    } = {}) => {
        const safePreset = preset && typeof preset === 'object' ? preset : {};
        const dataPeriod = String(
            safePreset.dataPeriod
            || safePreset.data_period
            || currentDataPeriod
            || defaultDataPeriod
            || ''
        ).trim();
        return {
            dataPeriod,
            captureSections: normalizeMeituanCaptureSections(safePreset.sections || []),
        };
    };

    const buildMeituanBrowserCaptureDataPeriodApplyState = (dataPeriod = '') => {
        const normalizedDataPeriod = String(dataPeriod || '').trim();
        return {
            shouldApply: !!normalizedDataPeriod,
            dataPeriod: normalizedDataPeriod,
        };
    };

    const buildMeituanBrowserProfileLoginOnlyRunOptions = () => ({
        loginOnly: true,
        bindDataSource: true,
    });

    const resolveMeituanBrowserCaptureSystemHotelId = ({
        formHotelId = '',
        autoFetchHotelId = '',
        userHotelId = '',
    } = {}) => {
        const candidate = [formHotelId, autoFetchHotelId, userHotelId]
            .map(value => String(value || '').trim())
            .find(value => value);
        return candidate || null;
    };

    const resolveMeituanSelectedHotelConfigAction = ({
        hotels = [],
        hotelId = '',
    } = {}) => {
        const targetHotelId = String(hotelId || '').trim();
        const hotel = Array.isArray(hotels)
            ? hotels.find(item => String(item?.id || '') === targetHotelId)
            : null;
        if (!hotel) {
            return {
                ok: false,
                hotel: null,
                platform: 'meituan',
                message: '请先选择要归属数据的酒店',
                level: 'warning',
            };
        }
        return {
            ok: true,
            hotel,
            platform: 'meituan',
            message: '',
            level: '',
        };
    };

    const buildMeituanRankingReturnTargetState = ({
        hotelId = '',
        currentHotelId = '',
    } = {}) => {
        const targetHotelId = String(hotelId || '').trim();
        if (!targetHotelId) {
            return {
                ok: false,
                targetHotelId: '',
                page: 'meituan-ebooking',
                tab: 'meituan-ranking',
                shouldApplyHotelId: false,
            };
        }
        return {
            ok: true,
            targetHotelId,
            page: 'meituan-ebooking',
            tab: 'meituan-ranking',
            shouldApplyHotelId: String(currentHotelId || '') !== targetHotelId,
        };
    };

    const buildMeituanBrowserSupplementCaptureState = ({
        autoFetchHotelId = '',
        formHotelId = '',
        userHotelId = '',
        sections = ['full'],
        dataPeriod = 'historical_daily',
    } = {}) => {
        const hotelId = String(autoFetchHotelId || formHotelId || userHotelId || '').trim();
        if (!hotelId) {
            return {
                ok: false,
                hotelId: '',
                captureSections: [],
                dataPeriod: '',
                message: '请先选择酒店',
                level: 'error',
            };
        }
        return {
            ok: true,
            hotelId,
            captureSections: normalizeMeituanCaptureSections(sections),
            dataPeriod: String(dataPeriod || 'historical_daily').trim(),
            message: '',
            level: '',
        };
    };

    const buildMeituanBrowserCaptureCopyCommandState = ({
        storeId = '',
        formHotelId = '',
        userHotelId = '',
    } = {}) => {
        const hasStoreId = !!String(storeId || '').trim();
        const hasHotelId = !!String(formHotelId || userHotelId || '').trim();
        if (hasStoreId && hasHotelId) {
            return { canCopy: true, message: '', level: '' };
        }
        return {
            canCopy: false,
            message: '请先选择酒店并填写美团门店标识',
            level: 'warning',
        };
    };

    const buildMeituanBrowserCaptureClearPayloadState = () => ({
        payloadJson: '',
        captureResult: null,
    });

    const firstMeituanNonEmptyText = (...values) => {
        for (const value of values) {
            const text = String(value ?? '').trim();
            if (text) {
                return text;
            }
        }
        return '';
    };

    const firstMeituanDataConfigValue = (...values) => {
        for (const value of values) {
            if (value !== undefined && value !== null && String(value).trim() !== '') {
                return value;
            }
        }
        return '';
    };

    const buildMeituanBrowserCaptureConfigSyncState = ({
        hotelId = '',
        hotelName = '',
        config = null,
        formPoiId = '',
        captureForm = {},
    } = {}) => {
        if (!String(hotelId || '').trim()) {
            return {
                hasHotel: false,
                formUpdates: {
                    storeId: '',
                    poiId: '',
                    poiName: '',
                },
                rankingPoiId: '',
                shouldNotify: false,
            };
        }

        const source = config && typeof config === 'object' ? config : {};
        const poiId = firstMeituanNonEmptyText(
            source.poi_id,
            source.poiId,
            source.store_id,
            source.storeId,
            formPoiId,
            captureForm.storeId
        );
        const poiName = source.name || hotelName || captureForm.poiName || '';
        const adsUrl = String(firstMeituanDataConfigValue(source.ads_url, source.adsUrl, captureForm.adsUrl) || '').trim();
        const dataPeriod = String(firstMeituanDataConfigValue(source.data_period, source.dataPeriod, captureForm.dataPeriod) || '').trim();
        const formUpdates = { poiName };
        if (poiId) {
            formUpdates.storeId = poiId;
            formUpdates.poiId = poiId;
        }
        if (adsUrl) {
            formUpdates.adsUrl = adsUrl;
        }
        if (dataPeriod) {
            formUpdates.dataPeriod = dataPeriod;
        }

        return {
            hasHotel: true,
            formUpdates,
            rankingPoiId: poiId,
            shouldNotify: Boolean(poiId || poiName || adsUrl),
        };
    };

    const buildMeituanBrowserCaptureRunSectionsState = (sections = []) => ({
        captureSections: normalizeMeituanCaptureSections(sections),
    });

    const buildMeituanBrowserCaptureSelectedSectionsText = (sections = []) => {
        const sectionLabels = {
            traffic: '流量',
            orders: '订单',
            reviews: '评论聚合',
            ads: '广告',
            peer_rank: '同行排名',
            traffic_analysis: '流量分析',
            search_keywords: '搜索词',
            traffic_forecast: '未来30天预测',
            order_flow: '订单流向',
        };
        const normalizedSections = normalizeMeituanCaptureSections(sections);
        if (!normalizedSections.length) {
            return '未选择';
        }
        return normalizedSections.map(section => sectionLabels[section] || section).join('、');
    };

    const quoteMeituanCliValue = (value = '') => `"${String(value).replace(/"/g, '\\"')}"`;

    const buildMeituanBrowserCaptureCommand = ({
        form = {},
        rankingForm = {},
        userHotelId = '',
        hotelName = '',
    } = {}) => {
        const storeId = String(form.storeId || rankingForm.poiId || '').trim();
        const hotelId = String(rankingForm.hotelId || userHotelId || '').trim();
        if (!storeId || !hotelId) {
            return '请先选择目标酒店并填写美团门店标识';
        }
        const poiId = String(form.poiId || storeId || '').trim();
        const poiName = String(form.poiName || hotelName || '').trim();
        const adsUrl = String(form.adsUrl || '').trim();
        const dataPeriod = String(form.dataPeriod || '').trim();
        const sections = normalizeMeituanCaptureSections(form.captureSections);
        const args = [
            'node scripts/meituan_browser_capture.mjs',
            `--store-id=${storeId}`,
            `--system-hotel-id=${hotelId}`,
        ];
        if (poiId && poiId !== storeId) {
            args.push(`--poi-id=${poiId}`);
        }
        if (poiName) {
            args.push(`--poi-name=${quoteMeituanCliValue(poiName)}`);
        }
        if (sections.length) {
            args.push(`--sections=${sections.join(',')}`);
        }
        if (dataPeriod) {
            args.push(`--data-period=${dataPeriod}`);
        }
        if ((sections.includes('ads') || sections.length === 0) && adsUrl) {
            args.push(`--ads-url=${quoteMeituanCliValue(adsUrl)}`);
        }
        return args.join(' ');
    };

    const buildMeituanBrowserCaptureReadinessNotice = ({
        form = {},
        rankingForm = {},
        userHotelId = '',
    } = {}) => {
        const hotelId = String(rankingForm.hotelId || userHotelId || '').trim();
        const storeId = String(form.storeId || rankingForm.poiId || '').trim();
        if (!hotelId || !storeId) {
            return {
                status: 'missing_identity',
                level: 'warning',
                className: 'mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800',
                message: '请先选择酒店并补齐门店标识；未满足条件时不会发起采集。',
            };
        }
        const sections = normalizeMeituanCaptureSections(form.captureSections);
        if (sections.includes('ads') && !String(form.adsUrl || '').trim()) {
            return {
                status: 'missing_ads_url',
                level: 'error',
                className: 'mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700',
                message: '当前模块包含广告数据，请补充推广通广告入口 URL。',
            };
        }
        return null;
    };

    const buildMeituanBrowserCaptureRequestContext = ({
        form = {},
        systemHotelId = null,
        fallbackPoiId = '',
        partnerId = '',
        hotelName = '',
        options = {},
    } = {}) => {
        const loginOnly = Boolean(options.loginOnly);
        const bindDataSource = options.bindDataSource !== false;
        const dataPeriod = String(options.dataPeriod || options.data_period || form.dataPeriod || form.data_period || '').trim();
        const storeId = String(form.storeId || form.poiId || fallbackPoiId || '').trim();
        const sections = normalizeMeituanCaptureSections(form.captureSections);
        if (!systemHotelId) {
            return { ok: false, status: 'missing_hotel', level: 'error', message: '请选择目标酒店' };
        }
        if (!storeId) {
            return { ok: false, status: 'missing_store_id', level: 'error', message: '请填写美团门店标识' };
        }
        if (sections.includes('ads') && !String(form.adsUrl || '').trim()) {
            return { ok: false, status: 'missing_ads_url', level: 'error', message: '请填写推广通广告入口 URL' };
        }
        return {
            ok: true,
            status: 'ok',
            loginOnly,
            bindDataSource,
            requestBody: {
                system_hotel_id: systemHotelId,
                store_id: storeId,
                poi_id: form.poiId || storeId,
                poi_name: form.poiName || hotelName,
                partner_id: partnerId || '',
                ads_url: sections.includes('ads') ? (form.adsUrl || '') : '',
                sections,
                login_only: loginOnly,
                bind_data_source: bindDataSource,
                ...(dataPeriod ? { data_period: dataPeriod } : {}),
            },
        };
    };

    const runMeituanBrowserCaptureFlow = async ({
        getForm = () => ({}),
        getSystemHotelId = () => null,
        getFallbackPoiId = () => '',
        getPartnerId = () => '',
        getHotelNameById = () => '',
        options = {},
        notify = () => {},
        setRunning = () => {},
        setFetching = () => {},
        setCaptureResult = () => {},
        setOnlineDataResult = () => {},
        requestCapture = async () => ({}),
        refreshOnlineHistory = async () => {},
        refreshPlatformProfileStatus = async () => {},
        refreshPlatformDataSources = async () => {},
    } = {}) => {
        const systemHotelId = getSystemHotelId();
        const requestContext = buildMeituanBrowserCaptureRequestContext({
            form: getForm() || {},
            systemHotelId,
            fallbackPoiId: getFallbackPoiId(),
            partnerId: getPartnerId(),
            hotelName: getHotelNameById(systemHotelId),
            options,
        });
        if (!requestContext.ok) {
            notify(requestContext.message, requestContext.level);
            return { status: requestContext.status, requestContext };
        }

        setRunning(true);
        setFetching(true);
        setCaptureResult(null);
        try {
            const res = await requestCapture(requestContext.requestBody);
            if (res.code === 200) {
                const data = res.data || {};
                setCaptureResult(data);
                setOnlineDataResult(data);
                const hasDisplayRows = ['rows', 'data', 'display_rows', 'display_hotels'].some(key => Array.isArray(data[key]) && data[key].length > 0)
                    || Number(data.row_count || data.parsed_row_count || 0) > 0;
                const notice = buildMeituanPersistenceNotice({
                    label: '美团 Profile 采集',
                    data,
                    hasDisplayRows,
                    failureMessage: res.message || '',
                });
                if (requestContext.loginOnly) {
                    notify('美团 Profile 登录请求已完成；请刷新状态确认 Profile 可复用', 'info');
                } else {
                    notify(notice.message, notice.level);
                }
                if (!requestContext.loginOnly) {
                    runPostFetchRefresh(refreshOnlineHistory);
                }
                if (requestContext.loginOnly || requestContext.bindDataSource) {
                    runPostFetchRefresh(refreshPlatformProfileStatus, { silent: true });
                    if (requestContext.bindDataSource) {
                        runPostFetchRefresh(refreshPlatformDataSources);
                    }
                }
                return {
                    status: requestContext.loginOnly
                        ? 'success'
                        : (notice.businessFailed
                            ? 'business_failed'
                            : ((notice.savedCount > 0 || notice.businessCompleted || hasDisplayRows) ? 'success' : 'incomplete')),
                    response: res,
                    requestContext,
                    data,
                    persisted: notice.persisted,
                    readback_verified: notice.readbackVerified,
                };
            }

            notify(res.message || '抓取失败', 'error');
            return { status: 'failed', response: res, requestContext };
        } catch (error) {
            const detail = error?.data?.data?.stderr || error?.data?.data?.stdout || '';
            notify('抓取失败: ' + error.message + (detail ? '，请查看结果详情' : ''), 'error');
            const errorResult = error?.data?.data || { error: error.message };
            setCaptureResult(errorResult);
            return { status: 'exception', error, requestContext, errorResult };
        } finally {
            setRunning(false);
            setFetching(false);
        }
    };

    const buildMeituanCapturedPayloadSaveContext = ({
        form = {},
        systemHotelId = null,
        hotelName = '',
    } = {}) => {
        if (!systemHotelId) {
            return { ok: false, status: 'missing_hotel', level: 'error', message: '请选择目标酒店' };
        }
        const rawJson = String(form.payloadJson || '').trim();
        if (!rawJson) {
            return { ok: false, status: 'missing_payload_json', level: 'error', message: '请粘贴抓取结果 JSON' };
        }

        let payload;
        try {
            payload = JSON.parse(rawJson);
        } catch (error) {
            return { ok: false, status: 'invalid_json', level: 'error', message: '抓取结果 JSON 格式不正确: ' + error.message };
        }
        if (!payload || Array.isArray(payload) || typeof payload !== 'object') {
            return { ok: false, status: 'invalid_payload_object', level: 'error', message: '抓取结果必须是 JSON 对象' };
        }

        const storeId = String(form.storeId || '').trim();
        const payloadStoreId = String(payload.store_id || payload.storeId || payload.poi_id || payload.poiId || '').trim();
        if (!payloadStoreId) {
            return { ok: false, status: 'missing_payload_identity', level: 'error', message: '抓取结果缺少美团门店标识' };
        }
        const poiName = String(form.poiName || hotelName || '').trim();
        const enrichedPayload = { ...payload };
        enrichedPayload.poi_name = enrichedPayload.poi_name || poiName;
        enrichedPayload.system_hotel_id = enrichedPayload.system_hotel_id || Number(systemHotelId);

        return {
            ok: true,
            status: 'ok',
            payload: enrichedPayload,
            requestBody: {
                system_hotel_id: systemHotelId,
                profile_key: storeId || payloadStoreId,
                payload: enrichedPayload,
            },
        };
    };

    const runMeituanCapturedPayloadSaveFlow = async ({
        getForm = () => ({}),
        getSystemHotelId = () => null,
        getHotelNameById = () => '',
        notify = () => {},
        setFetching = () => {},
        setCaptureResult = () => {},
        setOnlineDataResult = () => {},
        requestSave = async () => ({}),
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const systemHotelId = getSystemHotelId();
        const saveContext = buildMeituanCapturedPayloadSaveContext({
            form: getForm() || {},
            systemHotelId,
            hotelName: getHotelNameById(systemHotelId),
        });
        if (!saveContext.ok) {
            notify(saveContext.message, saveContext.level);
            return { status: saveContext.status, saveContext };
        }

        setFetching(true);
        setCaptureResult(null);
        try {
            const res = await requestSave(saveContext.requestBody);
            if (res.code === 200) {
                const data = res.data || {};
                setCaptureResult(data);
                setOnlineDataResult(data);
                const notice = buildMeituanPersistenceNotice({
                    label: '美团抓取结果',
                    data,
                    failureMessage: res.message || '',
                });
                notify(notice.message, notice.level);
                runPostFetchRefresh(refreshOnlineHistory);
                return {
                    status: notice.businessFailed
                        ? 'business_failed'
                        : ((notice.savedCount > 0 || notice.businessCompleted) ? 'success' : 'incomplete'),
                    response: res,
                    saveContext,
                    data,
                    persisted: notice.persisted,
                    readback_verified: notice.readbackVerified,
                };
            }

            notify(res.message || '保存失败', 'error');
            return { status: 'failed', response: res, saveContext };
        } catch (error) {
            notify('保存失败: ' + error.message, 'error');
            return { status: 'exception', error, saveContext };
        } finally {
            setFetching(false);
        }
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
        custom: '历史自定义',
    };
    const normalizeDateText = value => String(value || '').trim();
    const todayDateText = () => {
        const now = new Date();
        const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 10);
    };
    const resolveMeituanExecutionConfigId = (config = null) => String(
        config?.config_id || config?.id || ''
    ).trim();
    const validateMeituanBatchFetchInput = ({
        form = {},
        configId = '',
    } = {}) => {
        if (!form.hotelId) {
            return { ok: false, status: 'missing_hotel', level: 'error', message: '请选择目标酒店' };
        }
        if (!String(configId || '').trim()) {
            return { ok: false, status: 'missing_config', level: 'warning', message: '当前酒店未配置可执行的美团凭证' };
        }
        const dateRanges = Array.isArray(form.dateRanges) ? form.dateRanges : [];
        if (dateRanges.length === 0) {
            return { ok: false, level: 'error', message: '请至少选择一个时间维度' };
        }
        if (dateRanges.length > 1) {
            return { ok: false, level: 'warning', message: '每次只获取一个时间周期，请重新选择' };
        }
        if (dateRanges.includes('custom') && (!form.startDate || !form.endDate)) {
            return { ok: false, level: 'error', message: '请填写历史自定义时间的开始和结束日期' };
        }
        if (dateRanges.includes('custom')) {
            const startDate = normalizeDateText(form.startDate);
            const endDate = normalizeDateText(form.endDate);
            const today = todayDateText();
            if (startDate > endDate) {
                return { ok: false, level: 'error', message: '历史自定义时间的开始日期不能晚于结束日期' };
            }
            if (startDate > today || endDate > today) {
                return { ok: false, level: 'warning', message: '美团竞对榜单接口不支持未来日期，请选择今日实时、昨日、近7天、近30天或历史日期' };
            }
        }
        return {
            ok: true,
            level: 'success',
            configId: String(configId || '').trim(),
        };
    };

    const buildMeituanBatchFetchTasks = ({
        form = {},
        configId = '',
    } = {}) => {
        const dateRanges = Array.isArray(form.dateRanges) ? form.dateRanges : [];
        const tasks = [];
        dateRanges.forEach(dateRange => {
            meituanBatchRankTypes.forEach((rankType, rankIndex) => {
                const rangeName = meituanBatchDateRangeNames[dateRange] || dateRange;
                const rankName = meituanBatchRankTypeNames[rankType] || rankType;
                const includeSelfMetrics = rankIndex === 0;
                const includeSelfTradeMetrics = ['P_RZ', 'P_XS'].includes(rankType);
                const body = {
                    config_id: String(configId || '').trim(),
                    rank_type: rankType,
                    date_range: dateRange,
                    include_self_trade_metrics: includeSelfTradeMetrics,
                    include_self_traffic_metrics: includeSelfMetrics,
                    include_self_business_metrics: includeSelfMetrics,
                    auto_save: false,
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
            response,
            platformResponseReceived: true,
        };
        if (response.code === 200) {
            const responseData = response.data || {};
            const responseStatus = String(responseData.status || '').toLowerCase();
            const displayHotels = Array.isArray(responseData.display_hotels) ? responseData.display_hotels : [];
            const persistenceOutcome = buildMeituanPersistenceOutcome(responseData);
            const savedCount = persistenceOutcome.savedCount;
            const displayCount = Number(responseData.display_hotel_count ?? displayHotels.length) || 0;
            if (['accepted', 'running', 'queued'].includes(responseStatus)) {
                return {
                    ...base,
                    message: response.message || '',
                    status: responseData.status || responseStatus || 'running',
                    taskId: responseData.task_id || '',
                    platform: responseData.platform || 'meituan',
                    async: responseData.async !== false,
                    savedCount,
                    selfMetricValues: responseData.self_metric_values || responseData.selfMetricValues || {},
                    selfMetricStatus: responseData.self_metric_status || responseData.selfMetricStatus || '',
                    displayHotels,
                    displaySummary: responseData.display_summary || null,
                    displayCount,
                    persistenceStatus: persistenceOutcome.persistenceStatus,
                    persisted: persistenceOutcome.persisted,
                    readbackVerified: persistenceOutcome.readbackVerified,
                };
            }
            return {
                ...base,
                message: response.message || '',
                status: responseData.status || (savedCount > 0 ? 'processed' : 'response_received'),
                taskId: responseData.task_id || '',
                data: responseData.data,
                savedCount,
                selfMetricValues: responseData.self_metric_values || responseData.selfMetricValues || {},
                selfMetricStatus: responseData.self_metric_status || responseData.selfMetricStatus || '',
                displayHotels,
                displaySummary: responseData.display_summary || null,
                displayCount,
                persistenceStatus: persistenceOutcome.persistenceStatus,
                persisted: persistenceOutcome.persisted,
                readbackVerified: persistenceOutcome.readbackVerified,
            };
        }
        return {
            ...base,
            status: response.data?.reason || response.data?.credential_status || 'failed',
            credentialStatus: response.data?.credential_status || '',
            businessCode: response.data?.business_code ?? null,
            businessMessage: response.data?.business_message || '',
            message: response.message || response.data?.business_message || '获取失败',
            error: response.message || response.data?.business_message || '获取失败',
        };
    };
    const buildMeituanBatchFetchPendingEntry = (task) => ({
        rankType: task.rankType,
        rankName: task.rankName,
        dateRange: task.dateRange,
        dateRangeName: task.dateRangeName,
        status: 'fetching',
        message: '正在请求平台接口',
        taskId: '',
        savedCount: 0,
        displayHotels: [],
        displaySummary: null,
        displayCount: 0,
        attemptCount: 0,
        retryCount: 0,
        maxAttempts: meituanRankMaxAttempts(task),
        rankDataComplete: false,
        retryExhausted: false,
    });
    const mergeMeituanSelfMetricValues = (...sources) => {
        const result = {};
        sources.forEach(source => {
            if (!source || Array.isArray(source) || typeof source !== 'object') return;
            Object.entries(source).forEach(([key, value]) => {
                if (value === undefined || value === null || value === '') return;
                const number = Number(String(value).replace(/,/g, ''));
                if (!Number.isFinite(number)) {
                    result[key] = value;
                    return;
                }
                const existingNumber = Number(String(result[key] ?? '').replace(/,/g, ''));
                if (number <= 0 && Number.isFinite(existingNumber) && existingNumber > 0) {
                    return;
                }
                result[key] = number;
            });
        });
        return result;
    };
    const mergeMeituanSelfMetricStatus = (...sources) => {
        const statuses = sources
            .map(value => String(value || '').trim())
            .filter(Boolean);
        return statuses.find(value => /(?:returned|provided)/i.test(value))
            || statuses.find(value => value.toLowerCase() !== 'missing')
            || statuses[0]
            || '';
    };
    const buildMeituanDisplayModelGroups = ({ results = [], form = {} } = {}) => {
        const groupsByRange = new Map();
        (Array.isArray(results) ? results : []).forEach(result => {
            const dateRange = String(result?.dateRange ?? result?.date_range ?? '').trim();
            const key = dateRange || '__unknown__';
            if (!groupsByRange.has(key)) {
                groupsByRange.set(key, {
                    date_range: dateRange,
                    date_range_name: result?.dateRangeName || result?.date_range_name || '',
                    display_hotels: [],
                    self_metric_values: mergeMeituanSelfMetricValues(form.selfMetricValues),
                });
            }
            const group = groupsByRange.get(key);
            if (Array.isArray(result?.displayHotels)) {
                group.display_hotels.push(...result.displayHotels);
            }
            group.self_metric_values = mergeMeituanSelfMetricValues(group.self_metric_values, result?.selfMetricValues);
        });
        return Array.from(groupsByRange.values()).filter(group => group.display_hotels.length > 0);
    };
    const buildMeituanDisplayModelRows = (results = []) => (
        (Array.isArray(results) ? results : [])
            .flatMap(result => (Array.isArray(result?.displayHotels) ? result.displayHotels : []))
    );
    const isMeituanBackgroundAcceptedResponse = (response = {}) => {
        if (response.code !== 200) {
            return false;
        }
        const status = String(response.data?.status || '').toLowerCase();
        return ['accepted', 'running', 'queued'].includes(status);
    };
    const meituanPeerRankDimensions = (value = {}) => {
        const candidates = [
            value?.data?.data?.data?.peerRankData,
            value?.data?.data?.peerRankData,
            value?.response?.data?.data?.data?.peerRankData,
            value?.response?.data?.data?.peerRankData,
        ];
        const dimensions = candidates.find(item => Array.isArray(item));
        return Array.isArray(dimensions) ? dimensions : [];
    };
    const meituanTodayExtendedRetryRankTypes = new Set(['P_RZ', 'P_XS']);
    const meituanRealtimeDerivableRankTypes = new Set(['P_RZ']);
    const meituanRankMaxAttempts = () => 3;
    const meituanRetryDelayMs = (attempt = 1) => Math.min(2500, Math.max(600, Number(attempt || 1) * 600));
    const isMeituanNonRetryableFetchError = (error) => (
        /登录态|登录失效|重新登录|login|required|unauthorized|forbidden|credential|配置.*不一致|跨门店|权限/i
            .test(String(error?.message || error || ''))
    );
    const meituanRankResponseQuality = (response = {}) => {
        const dimensions = meituanPeerRankDimensions(response);
        let rowCount = 0;
        let absoluteValueCount = 0;
        let positivePercentCount = 0;
        let signalDimensionCount = 0;
        dimensions.forEach(dimension => {
            const rows = Array.isArray(dimension?.roundRanks) ? dimension.roundRanks : [];
            rowCount += rows.length;
            const dimensionAbsoluteCount = rows.filter(row => row?.dataValue !== null && row?.dataValue !== undefined && row?.dataValue !== '').length;
            const dimensionPositivePercentCount = rows.filter(row => Number(row?.percent) > 0).length;
            absoluteValueCount += dimensionAbsoluteCount;
            positivePercentCount += dimensionPositivePercentCount;
            if (dimensionAbsoluteCount > 0 || dimensionPositivePercentCount > 0) {
                signalDimensionCount += 1;
            }
        });
        const displayHotels = Array.isArray(response?.data?.display_hotels)
            ? response.data.display_hotels
            : (Array.isArray(response?.displayHotels) ? response.displayHotels : []);
        const selfRowCount = displayHotels.filter(row => row?.isSelf === true).length;
        return {
            dimensionCount: dimensions.length,
            signalDimensionCount,
            rowCount,
            absoluteValueCount,
            positivePercentCount,
            selfRowCount,
            score: absoluteValueCount * 10000
                + signalDimensionCount * 1000
                + dimensions.length * 100
                + positivePercentCount * 10
                + rowCount
                + selfRowCount,
        };
    };
    const meituanRankCandidateValueMode = (response = {}) => String(
        response?.data?.rank_candidate?.value_mode
        || response?.data?.rank_candidate?.valueMode
        || ''
    ).trim().toLowerCase();
    const hasMeituanCompleteAbsoluteRankRows = (response = {}) => {
        const dimensions = meituanPeerRankDimensions(response);
        return dimensions.length >= 2 && dimensions.every(dimension => {
            const rows = Array.isArray(dimension?.roundRanks) ? dimension.roundRanks : [];
            return rows.length > 0 && rows.every(row => (
                row?.dataValue !== null
                && row?.dataValue !== undefined
                && row?.dataValue !== ''
            ));
        });
    };
    const isMeituanRankResponseComplete = (response = {}, task = {}) => {
        if (response?.code !== 200 || isMeituanBackgroundAcceptedResponse(response)) {
            return false;
        }
        const quality = meituanRankResponseQuality(response);
        if (quality.dimensionCount > 0) {
            const rankType = String(task?.rankType ?? task?.rank_type ?? '');
            const isStayOrSales = meituanTodayExtendedRetryRankTypes.has(rankType);
            if (isStayOrSales) {
                if (hasMeituanCompleteAbsoluteRankRows(response)) {
                    return true;
                }
                if (String(task?.dateRange ?? task?.date_range ?? '') === '0') {
                    return meituanRealtimeDerivableRankTypes.has(rankType)
                        && ['derived', 'self_only'].includes(meituanRankCandidateValueMode(response));
                }
                return meituanRankCandidateValueMode(response) === 'derived';
            }
            return quality.dimensionCount >= 2 && quality.signalDimensionCount === quality.dimensionCount;
        }
        const fallbackRows = [
            response?.data?.display_hotels,
            response?.data?.data,
            response?.displayHotels,
        ].find(value => Array.isArray(value) && value.length > 0);
        return Boolean(fallbackRows) || Number(response?.data?.display_hotel_count || 0) > 0;
    };
    const isMeituanHistoricalPercentOnlyStayOrSales = (response = {}, task = {}) => {
        if (String(task?.dateRange ?? task?.date_range ?? '') === '0'
            || !meituanTodayExtendedRetryRankTypes.has(String(task?.rankType ?? task?.rank_type ?? ''))
        ) {
            return false;
        }
        const dimensions = meituanPeerRankDimensions(response);
        if (dimensions.length < 2) return false;
        return dimensions.every(dimension => {
            const rows = Array.isArray(dimension?.roundRanks) ? dimension.roundRanks : [];
            return rows.length > 0
                && rows.every(row => row?.dataValue === null || row?.dataValue === undefined || row?.dataValue === '')
                && rows.some(row => Number(row?.percent) > 0);
        });
    };
    const selectBetterMeituanRankResponse = (current = null, candidate = null) => {
        if (!current) return candidate;
        if (!candidate) return current;
        return meituanRankResponseQuality(candidate).score >= meituanRankResponseQuality(current).score
            ? candidate
            : current;
    };
    const buildMeituanDisplayModelPayload = ({ results = [], form = {} } = {}) => {
        const displayGroups = buildMeituanDisplayModelGroups({ results, form });
        return {
            display_hotels: displayGroups.length > 0 ? [] : buildMeituanDisplayModelRows(results),
            display_groups: displayGroups,
            competitor_room_count: form.competitorRoomCount,
            target_poi_id: form.poiId,
            system_hotel_id: form.hotelId,
            date_ranges: form.dateRanges,
            start_date: form.startDate,
            end_date: form.endDate,
        };
    };

    const normalizeMeituanCookieText = (value) => String(value || '').replace(/^[\s\n]+|[\s\n]+$/g, '').replace(/\n/g, '');

    const firstMeituanConfigText = (...values) => {
        const value = values.find(item => item !== undefined && item !== null && String(item).trim() !== '');
        return value === undefined ? '' : String(value).trim();
    };

    const resolveMeituanConfigSaveCookieState = (cookies = '', options = {}) => {
        const normalizedCookies = String(cookies || '').trim();
        if (!normalizedCookies) {
            if (options.keepExisting === true) {
                return {
                    canSave: true,
                    cookies: '',
                    keepExisting: true,
                    message: '',
                    level: '',
                };
            }
            return {
                canSave: false,
                cookies: '',
                message: '请输入临时 Cookie/API 辅助内容',
                level: 'error',
            };
        }
        return {
            canSave: true,
            cookies: normalizedCookies,
            message: '',
            level: '',
        };
    };

    const buildMeituanConfigAutoName = (form = {}, options = {}) => {
        const explicit = String(form?.name || '').trim();
        if (explicit) return explicit;
        const hotelName = String(options?.hotelName || '').trim();
        const poiId = String(form?.poi_id || form?.poiId || '').trim();
        const fallbackDate = String(options?.fallbackDate || '').trim() || todayDateText();
        if (hotelName) return `${hotelName}美团Cookie`;
        if (poiId) return `美团${poiId}Cookie`;
        return `美团Cookie ${fallbackDate}`;
    };

    const buildMeituanConfigSaveRequestBody = ({
        form = {},
        requestHotelId = '',
        name = '',
        cookies = '',
    } = {}) => ({
        id: form?.id ?? null,
        name: String(name || '').trim(),
        hotel_id: String(requestHotelId || '').trim(),
        partner_id: form?.partner_id || '',
        poi_id: form?.poi_id || '',
        hotel_room_count: form?.hotel_room_count || '',
        competitor_room_count: form?.competitor_room_count || '',
        ...(String(cookies || '').trim() ? { cookies: String(cookies || '').trim() } : {}),
    });

    const resolveMeituanConfigSaveRequestHotelId = ({
        formHotelId = '',
        rankingHotelId = '',
        filterHotelId = '',
        userHotelId = '',
    } = {}) => firstMeituanConfigText(
        formHotelId,
        rankingHotelId,
        filterHotelId,
        userHotelId
    );

    const createEmptyMeituanConfigForm = () => ({
        id: null,
        name: '',
        hotel_id: '',
        partner_id: '',
        poi_id: '',
        cookies: '',
        has_cookies: false,
        credential_status: '',
        hotel_room_count: '',
        competitor_room_count: '',
    });

    const buildMeituanConfigDeleteUrl = (id = '') => {
        const normalizedId = String(id ?? '').trim();
        return `/online-data/delete-meituan-config?id=${encodeURIComponent(normalizedId)}`;
    };

    const buildMeituanConfigDeleteSuccessState = (id = '') => ({
        toastMessage: '删除成功',
        toastLevel: 'success',
        clearConfigDetailId: id,
        shouldReloadConfigList: true,
        reloadOptions: {},
    });

    const buildMeituanConfigDeleteFailureState = ({
        response = null,
        error = null,
    } = {}) => {
        if (error) {
            return {
                toastMessage: `删除失败: ${error?.message || '未知错误'}`,
                toastLevel: 'error',
            };
        }
        return {
            toastMessage: response?.message || '删除失败',
            toastLevel: 'error',
        };
    };

    const resolveSavedMeituanConfigHotelId = ({
        responseData = {},
        requestBody = {},
        fallbackHotelId = '',
    } = {}) => String(
        responseData?.hotel_id
        || responseData?.system_hotel_id
        || requestBody?.hotel_id
        || fallbackHotelId
        || ''
    ).trim();

    const resolveMeituanConfigSaveToastLevel = (responseData = {}) => {
        const credentialStatus = String(
            responseData?.credential_status
            || responseData?.credential_requirement?.credential_status
            || ''
        ).trim();
        return credentialStatus === 'missing_resource_id' ? 'warning' : 'success';
    };

    const buildMeituanConfigSaveSuccessState = ({
        response = {},
        requestBody = {},
        fallbackHotelId = '',
        form = {},
    } = {}) => {
        const responseData = response?.data || {};
        const savedHotelId = resolveSavedMeituanConfigHotelId({
            responseData,
            requestBody,
            fallbackHotelId,
        });
        return {
            savedHotelId,
            toastMessage: response?.message || '配置保存成功',
            toastLevel: resolveMeituanConfigSaveToastLevel(responseData),
            clearConfigDetailId: form?.id ?? null,
            resetForm: createEmptyMeituanConfigForm(),
            shouldReturnToRanking: !!savedHotelId,
            shouldReloadConfigList: !savedHotelId,
        };
    };

    const buildMeituanConfigSaveFailureState = ({
        response = null,
        error = null,
    } = {}) => {
        if (error) {
            return {
                toastMessage: `保存失败: ${error?.message || '未知错误'}`,
                toastLevel: 'error',
            };
        }
        return {
            toastMessage: response?.message || '保存失败',
            toastLevel: 'error',
        };
    };

    const buildMeituanRankingFormPatchFromConfig = (config = {}, fallbackHotelId = '') => ({
        hotelId: config?.hotel_id || config?.system_hotel_id || fallbackHotelId || '',
        partnerId: config?.partner_id || '',
        poiId: config?.poi_id || '',
        hotelRoomCount: config?.hotel_room_count || '',
        competitorRoomCount: config?.competitor_room_count || '',
    });

    const buildMeituanConfigUseState = ({
        config = {},
        fallbackHotelId = '',
    } = {}) => ({
        formPatch: buildMeituanRankingFormPatchFromConfig(config, fallbackHotelId),
        toastMessage: `已应用配置: ${config?.name || ''}`,
        targetTab: 'meituan-ranking',
    });

    const buildMeituanConfigEditForm = (config = {}) => ({
        id: config?.id ?? null,
        name: config?.name || '',
        hotel_id: config?.hotel_id || config?.system_hotel_id || '',
        partner_id: config?.partner_id || '',
        poi_id: config?.poi_id || '',
        cookies: '',
        has_cookies: config?.has_cookies === true,
        credential_status: config?.credential_status || '',
        hotel_room_count: config?.hotel_room_count || '',
        competitor_room_count: config?.competitor_room_count || '',
    });

    const buildMeituanConfigEditState = ({
        config = {},
    } = {}) => ({
        form: buildMeituanConfigEditForm(config),
    });

    const buildMeituanBookmarkletSuccessState = (response = {}) => ({
        bookmarklet: response?.data?.bookmarklet || '',
        toastMessage: response?.data?.message || '旧版美团 Cookie 书签已禁用',
        toastLevel: 'warning',
    });

    const buildMeituanBookmarkletFailureState = ({
        error = null,
    } = {}) => ({
        toastMessage: `生成失败: ${error?.message || '未知错误'}`,
        toastLevel: 'error',
    });

    const hasObjectValue = (value) => value && typeof value === 'object' && Object.keys(value).length > 0;

    const isSameJsonObject = (left = {}, right = {}) => {
        try {
            return JSON.stringify(left || {}) === JSON.stringify(right || {});
        } catch (error) {
            return false;
        }
    };

    const isMeituanRankingFormAlignedWithConfig = (form = {}, config = {}) => {
        if (!form || !config) return false;
        const formHotelId = String(form.hotelId || '').trim();
        const configHotelId = firstMeituanConfigText(config.hotel_id, config.system_hotel_id);
        if (formHotelId && configHotelId && formHotelId !== configHotelId) return false;
        return isMeituanExecutionConfigReady(config);
    };

    const isMeituanConfigBoundToFormHotel = (form = {}, config = {}) => {
        if (!form || !config) return false;
        const formHotelId = String(form.hotelId || '').trim();
        const configHotelId = firstMeituanConfigText(config.hotel_id, config.system_hotel_id);
        return !!formHotelId && (!configHotelId || formHotelId === configHotelId);
    };

    const normalizeMeituanConfigHotelName = (value = '') => String(value || '')
        .trim()
        .replace(/\s+/g, '')
        .replace(/(?:\u7f8e\u56e2|\u643a\u7a0b)?\u6570\u636e\u6e90$/u, '');

    const selectLatestSuccessfulMeituanConfig = (configs = []) => {
        const candidates = (Array.isArray(configs) ? configs : []).filter(Boolean);
        if (!candidates.length) return null;
        const successful = candidates.filter(item => (
            resolveMeituanExecutionConfigId(item)
            && item?.has_cookies === true
            && String(item?.credential_status || '') === 'ready'
        ));
        const source = successful.length ? successful : candidates;
        const recencyText = item => String(
            item?.update_time || item?.updated_at || item?.created_at || item?.create_time || ''
        ).trim();
        return [...source].sort((left, right) => {
            const leftTime = recencyText(left);
            const rightTime = recencyText(right);
            if (leftTime !== rightTime) return rightTime > leftTime ? 1 : -1;
            const leftId = String(left?.config_id || left?.id || '');
            const rightId = String(right?.config_id || right?.id || '');
            if (leftId === rightId) return 0;
            return rightId > leftId ? 1 : -1;
        })[0] || null;
    };

    const findMeituanConfigForHotel = ({
        hotelId = '',
        hotelName = '',
        configs = [],
        normalizeHotelName = normalizeMeituanConfigHotelName,
    } = {}) => {
        const sourceConfigs = Array.isArray(configs) ? configs : [];
        const normalizeName = typeof normalizeHotelName === 'function' ? normalizeHotelName : normalizeMeituanConfigHotelName;
        const idText = String(hotelId || '').trim();
        if (idText) {
            const idMatched = selectLatestSuccessfulMeituanConfig(sourceConfigs.filter(
                item => String(item?.system_hotel_id || item?.hotel_id || '').trim() === idText
            ));
            if (idMatched) return idMatched;
        }

        const normalizedHotelName = normalizeName(hotelName);
        if (!normalizedHotelName) return null;
        const nameFallbackConfigs = idText
            ? sourceConfigs.filter(item => !firstMeituanConfigText(item?.system_hotel_id, item?.hotel_id))
            : sourceConfigs;
        return selectLatestSuccessfulMeituanConfig(nameFallbackConfigs.filter(item => {
            const configHotelName = normalizeName(item?.hotel_name || item?.hotelName || '');
            const configName = normalizeName(item?.name || '');
            return configHotelName === normalizedHotelName || configName === normalizedHotelName;
        }));
    };

    const resolveMeituanConfigStatus = ({
        config = null,
        missingFields = [],
    } = {}) => {
        const fields = Array.isArray(missingFields)
            ? missingFields.filter(Boolean)
            : [];
        return {
            hasConfig: !!config,
            configured: !!config && fields.length === 0,
            name: String(config?.name || '').trim(),
            missingFields: fields,
            missingText: fields.join(' / '),
        };
    };

    const isMeituanExecutionConfigReady = (config = null) => Boolean(
        resolveMeituanExecutionConfigId(config)
        && config?.has_cookies === true
        && String(config?.credential_status || '') === 'ready'
    );
    const buildMeituanManualCredentialState = (config = null) => {
        const status = String(config?.credential_status || '').trim().toLowerCase();
        if (isMeituanExecutionConfigReady(config)) {
            const profileVerified = config?.configuration_verified === true;
            return {
                key: 'ready',
                canFetch: true,
                label: '美团凭据已就绪',
                detail: profileVerified
                    ? '凭据已就绪，且当前门店 Profile 登录验证成功。'
                    : '凭据已就绪，可以直接获取数据验证；Profile 自动采集仍需单独完成登录验证。',
                tone: 'success',
            };
        }
        if (config && (config.migration_required === true || config.migration_required === 1 || status === 'migration_required')) {
            return {
                key: 'migration_required',
                canFetch: false,
                label: '旧版美团凭据待安全迁移',
                detail: '完成凭据安全迁移或重新保存授权后，才能获取数据。',
                tone: 'warning',
            };
        }
        if (!config) {
            return {
                key: 'missing_config',
                canFetch: false,
                label: '未配置美团数据源',
                detail: '请先保存美团授权配置。',
                tone: 'warning',
            };
        }
        return {
            key: status || 'not_ready',
            canFetch: false,
            label: '美团凭据未就绪',
            detail: '请重新登录、更新授权或完成凭据迁移后再获取数据。',
            tone: 'warning',
        };
    };

    const resolveCanFetchMeituanRankingData = ({
        form = {},
        selectedConfig = null,
    } = {}) => {
        const safeForm = form && typeof form === 'object' ? form : {};
        return !!String(safeForm.hotelId || '').trim() && buildMeituanManualCredentialState(selectedConfig).canFetch;
    };

    const resolveMeituanManualFetchConfigProofPending = ({
        form = {},
        selectedConfig = null,
    } = {}) => {
        const safeForm = form && typeof form === 'object' ? form : {};
        return !!String(safeForm.hotelId || '').trim() && isMeituanExecutionConfigReady(selectedConfig);
    };

    const resolveMeituanManualFetchConfigCandidate = ({
        config = null,
        form = {},
        selectedConfig = null,
    } = {}) => {
        if (config) return isMeituanExecutionConfigReady(config) ? config : null;
        const safeForm = form && typeof form === 'object' ? form : {};
        if (!String(safeForm.hotelId || '').trim()) return null;
        return isMeituanExecutionConfigReady(selectedConfig) ? selectedConfig : null;
    };

    const resolveMeituanConfigListResponse = (res = {}) => {
        if (res?.code === 200) {
            return {
                ok: true,
                list: res?.data || [],
                message: '',
            };
        }
        return {
            ok: false,
            list: [],
            message: res?.message || '',
        };
    };

    const resolveMeituanConfigListApplyAction = ({
        hotelId = '',
        shouldApplySelectedConfig = false,
    } = {}) => ({
        shouldApply: !!String(hotelId || '').trim() && shouldApplySelectedConfig === true,
    });

    const resolveMeituanConfigListCachedResult = ({
        force = false,
        loaded = false,
        failed = false,
        cacheFresh = false,
        list = [],
    } = {}) => {
        if (!force && loaded && !failed && cacheFresh) {
            return { hit: true, list };
        }
        return { hit: false, list: null };
    };

    const resolveMeituanConfigListLoadingAction = ({
        force = false,
        loadingPromise = null,
    } = {}) => {
        if (!loadingPromise) return { status: 'idle', promise: null };
        if (!force) return { status: 'reuse', promise: loadingPromise };
        return { status: 'await_previous', promise: loadingPromise };
    };

    const buildMeituanConfigListSuccessState = ({
        list = [],
        loadedAt = 0,
    } = {}) => ({
        list,
        loaded: true,
        loadedAt,
    });

    const buildMeituanConfigListFailureAction = ({
        type = 'api',
        message = '',
        error = null,
    } = {}) => {
        const exception = type === 'exception';
        return {
            failed: true,
            label: exception ? '[Debug] 加载美团配置列表失败:' : '[Debug] API 返回错误:',
            detail: exception ? error : message,
        };
    };

    const buildMeituanConfigListStartState = () => ({
        loading: true,
        failed: false,
    });

    const buildMeituanConfigListFinishState = () => ({
        loading: false,
        loadingPromise: null,
    });

    const getMeituanConfigDetailVersion = (config = {}) => String(
        config?.update_time || config?.updated_at || config?.created_at || ''
    );

    const buildMeituanConfigDetailCacheKey = (id = '') => (id ? String(id) : '');

    const resolveMeituanConfigDetailClearTarget = (id = '') => {
        const cacheKey = buildMeituanConfigDetailCacheKey(id);
        if (!cacheKey) return { clearAll: true, cacheKey: '' };
        return { clearAll: false, cacheKey };
    };

    const resolveMeituanConfigDetailLoadTarget = ({
        id = '',
        loadingPromises = null,
    } = {}) => {
        const cacheKey = buildMeituanConfigDetailCacheKey(id);
        if (!cacheKey) {
            return { status: 'missing_key', cacheKey: '', promise: null };
        }
        if (
            loadingPromises
            && typeof loadingPromises.has === 'function'
            && typeof loadingPromises.get === 'function'
            && loadingPromises.has(cacheKey)
        ) {
            return {
                status: 'loading',
                cacheKey,
                promise: loadingPromises.get(cacheKey),
            };
        }
        return { status: 'ready', cacheKey, promise: null };
    };

    const buildMeituanConfigDetailRequestUrl = (cacheKey = '') => (
        `/online-data/get-meituan-config-detail?id=${encodeURIComponent(String(cacheKey || ''))}`
    );

    const resolveMeituanConfigDetailResponse = (res = {}) => {
        const safeRes = res && typeof res === 'object' ? res : {};
        if (safeRes.code !== 200) {
            return {
                ok: false,
                message: safeRes.message || '加载美团完整配置失败',
                data: null,
            };
        }
        return {
            ok: true,
            message: '',
            data: safeRes.data || null,
        };
    };

    const shouldSkipMeituanConfigDetailLoad = (config = null) => (
        !config || !!config.cookies || !config.id || config.has_cookies === false
    );

    const resolveMeituanConfigDetailCachedResult = ({
        cached = null,
        listVersion = '',
    } = {}) => {
        if (!cached || typeof cached !== 'object') {
            return { hit: false, data: null };
        }
        const expectedVersion = String(listVersion || '');
        if (expectedVersion && cached.version !== expectedVersion) {
            return { hit: false, data: null };
        }
        return { hit: true, data: cached.data ?? null };
    };

    const resolveMeituanConfigDetailCacheLookup = ({
        config = null,
        cache = null,
    } = {}) => {
        const cacheKey = buildMeituanConfigDetailCacheKey(config?.id);
        const listVersion = getMeituanConfigDetailVersion(config);
        const cached = cacheKey && cache && typeof cache.get === 'function'
            ? cache.get(cacheKey)
            : null;
        const cachedResult = resolveMeituanConfigDetailCachedResult({ cached, listVersion });
        return { cacheKey, listVersion, cachedResult };
    };

    const buildMeituanConfigDetailCacheEntry = ({
        detail = null,
        listVersion = '',
    } = {}) => {
        if (!detail) return null;
        return {
            version: getMeituanConfigDetailVersion(detail) || String(listVersion || ''),
            data: detail,
        };
    };

    const resolveMeituanConfigDetailCacheStorePlan = ({
        cacheKey = '',
        cacheEntry = null,
    } = {}) => ({
        shouldStore: !!(cacheKey && cacheEntry),
        cacheKey: cacheKey ? String(cacheKey) : '',
        cacheEntry,
    });

    const resolveMeituanConfigDetailFailureAction = ({
        error = null,
        silent = false,
    } = {}) => {
        const message = error?.message || '加载美团完整配置失败';
        if (silent) {
            return {
                type: 'log',
                label: '[Meituan] 预热完整配置失败:',
                message,
                error,
            };
        }
        return {
            type: 'toast',
            message,
            level: 'error',
            error,
        };
    };

    const resolveMeituanConfigDetailPrewarmPlan = ({
        config = null,
        delayMs = 80,
    } = {}) => {
        if (shouldSkipMeituanConfigDetailLoad(config)) {
            return { shouldPrewarm: false, config: null, delayMs: 0 };
        }
        return { shouldPrewarm: true, config, delayMs };
    };

    const resolveMeituanManualDefaultHotelIdFromState = ({
        currentHotelId = '',
        autoFetchHotelId = '',
        selectedCtripHotelId = '',
        onlineDataHotelId = '',
        userHotelId = '',
        hotelPool = [],
    } = {}) => {
        const firstHotelId = Array.isArray(hotelPool) ? hotelPool?.[0]?.id : '';
        return String(
            currentHotelId
            || autoFetchHotelId
            || selectedCtripHotelId
            || onlineDataHotelId
            || userHotelId
            || firstHotelId
            || ''
        ).trim();
    };

    const meituanFallbackMetricNumber = (value) => {
        const normalized = typeof value === 'string'
            ? value.replace(/[,，¥￥%\s]/g, '')
            : value;
        const number = Number(normalized);
        return Number.isFinite(number) ? number : 0;
    };

    const meituanFallbackHasPositiveMetric = (rows, field) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        return sourceRows.some(row => meituanFallbackMetricNumber(row?.[field]) > 0);
    };

    const meituanFallbackSum = (rows, field) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        return sourceRows.reduce((sum, row) => sum + Math.max(0, meituanFallbackMetricNumber(row?.[field])), 0);
    };

    const meituanFallbackAverage = (rows, field, percent = false) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const values = sourceRows
            .map(row => meituanFallbackMetricNumber(row?.[field]))
            .filter(value => value > 0)
            .map(value => (percent && value <= 1 ? value * 100 : value));
        if (!values.length) {
            return 0;
        }
        return values.reduce((sum, value) => sum + value, 0) / values.length;
    };

    const meituanFallbackHhi = (rows, field) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const values = sourceRows.map(row => Math.max(0, meituanFallbackMetricNumber(row?.[field]))).filter(value => value > 0);
        const total = values.reduce((sum, value) => sum + value, 0);
        if (total <= 0) {
            return 0;
        }
        return values.reduce((sum, value) => sum + Math.pow((value / total) * 100, 2), 0);
    };

    const meituanFallbackPriceSigma = (rows) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const prices = sourceRows
            .map(row => {
                const explicit = meituanFallbackMetricNumber(row?.avgRoomPrice);
                if (explicit > 0) {
                    return explicit;
                }
                const revenue = meituanFallbackMetricNumber(row?.roomRevenue);
                const nights = meituanFallbackMetricNumber(row?.roomNights);
                return revenue > 0 && nights > 0 ? revenue / nights : 0;
            })
            .filter(value => value > 0);
        if (prices.length < 2) {
            return 0;
        }
        const avg = prices.reduce((sum, value) => sum + value, 0) / prices.length;
        if (avg <= 0) {
            return 0;
        }
        const variance = prices.reduce((sum, value) => sum + Math.pow(value - avg, 2), 0) / prices.length;
        return Math.sqrt(variance) / avg * 100;
    };

    const meituanFallbackRankHealth = (rows) => {
        const groups = [
            ['roomNights', 'roomRevenue', 'avgRoomPrice'],
            ['salesRoomNights', 'sales', 'avgSalesPrice'],
            ['exposure', 'views', 'orderCount'],
            ['viewConversion', 'payConversion', 'absoluteConversion'],
        ];
        const readyCount = groups.filter(fields => fields.some(field => meituanFallbackHasPositiveMetric(rows, field))).length;
        return { readyCount, totalCount: groups.length };
    };

    const meituanFallbackPlatformTags = (rows) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const taggedRows = sourceRows.filter(row => Array.isArray(row?.platformTags) && row.platformTags.length > 0);
        const vipRows = sourceRows.filter(row => {
            if (row?.hasVipTag) {
                return true;
            }
            const tags = Array.isArray(row?.platformTags) ? row.platformTags : [];
            return tags.some(tag => String(tag || '').toUpperCase() === 'VIP');
        });
        return { returnedCount: taggedRows.length, vipCount: vipRows.length };
    };

    const meituanFallbackMarketPriceSignal = (avgRoomPrice, avgSalesPrice) => {
        if (!(avgRoomPrice > 0) || !(avgSalesPrice > 0)) {
            return '数据不足';
        }
        const ratio = avgSalesPrice / avgRoomPrice;
        if (ratio < 0.92) {
            return '销售价偏低';
        }
        if (ratio > 1.08) {
            return '销售价偏高';
        }
        return '价格稳定';
    };

    const meituanFallbackNumberText = (value, decimals = 0, formatNumber = item => String(item)) => {
        if (!(value > 0)) {
            return '-';
        }
        const normalized = decimals > 0 ? Number(value.toFixed(decimals)) : Math.round(value);
        return formatNumber(normalized);
    };

    const meituanFallbackMoneyText = (value, formatNumber = item => String(item)) => (
        value > 0 ? `¥${formatNumber(Math.floor(value))}` : '-'
    );

    const meituanFallbackPercentText = (value, toFixedSafe = (item, decimals = 2) => Number(item).toFixed(decimals)) => (
        value > 0 ? `${toFixedSafe(value, 2, '0.00')}%` : '-'
    );

    const meituanFallbackMetricText = (rows, field, decimals = 0, formatNumber = item => String(item)) => (
        meituanFallbackHasPositiveMetric(rows, field)
            ? meituanFallbackNumberText(meituanFallbackSum(rows, field), decimals, formatNumber)
            : '-'
    );

    const resolveMeituanFallbackMarketInventory = ({
        form = {},
        selectedConfig = {},
    } = {}) => meituanFallbackMetricNumber(
        form?.competitorRoomCount
        || selectedConfig?.competitor_room_count
        || selectedConfig?.competitorRoomCount
    );

    const meituanFallbackCard = (key, label, value, valueClass, panelClass, level = '', levelClass = 'text-gray-500') => ({
        key,
        label,
        value,
        level,
        panelClass,
        valueClass,
        levelClass,
    });

    const buildMeituanBusinessSummaryFallbackCards = ({
        rows = [],
        marketInventory = 0,
        formatNumber = item => String(item),
        toFixedSafe = (item, decimals = 2) => Number(item).toFixed(decimals),
    } = {}) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        if (!sourceRows.length) {
            return [];
        }

        const selfRow = sourceRows.find(row => row?.isSelf) || null;
        const rankHealth = meituanFallbackRankHealth(sourceRows);
        const platformTags = meituanFallbackPlatformTags(sourceRows);
        const totalRoomNights = meituanFallbackSum(sourceRows, 'roomNights');
        const totalRoomRevenue = meituanFallbackSum(sourceRows, 'roomRevenue');
        const totalSalesRoomNights = meituanFallbackSum(sourceRows, 'salesRoomNights');
        const totalSales = meituanFallbackSum(sourceRows, 'sales');
        const totalExposure = meituanFallbackSum(sourceRows, 'exposure');
        const totalViews = meituanFallbackSum(sourceRows, 'views');
        const avgRoomPrice = totalRoomRevenue > 0 && totalRoomNights > 0 ? totalRoomRevenue / totalRoomNights : 0;
        const avgSalesPrice = totalSales > 0 && totalSalesRoomNights > 0 ? totalSales / totalSalesRoomNights : 0;
        const marketVitalityRate = marketInventory > 0 ? totalRoomNights / marketInventory * 100 : 0;
        const priceSigma = meituanFallbackPriceSigma(sourceRows);
        const inventoryTurnoverRate = totalRoomNights > 0 && totalSalesRoomNights > 0 ? totalSalesRoomNights / totalRoomNights * 100 : 0;
        const revenueConcentration = meituanFallbackHhi(sourceRows, 'sales');
        const visitConcentration = meituanFallbackHhi(sourceRows, 'views');
        const operationFocus = revenueConcentration > 0 && visitConcentration > 0
            ? (revenueConcentration > visitConcentration ? '提高转化率' : '抢曝光流量')
            : '数据不足';
        const marketPriceSignal = meituanFallbackMarketPriceSignal(avgRoomPrice, avgSalesPrice);

        return [
            meituanFallbackCard('fallback-hotel-count', '酒店总数', String(sourceRows.length), 'text-gray-900', 'bg-blue-50 border border-blue-200', '当前表内'),
            meituanFallbackCard('fallback-rank-health', '榜单健康度', `${rankHealth.readyCount}/${rankHealth.totalCount}`, 'text-blue-700', 'bg-blue-50 border border-blue-200', '四类榜单'),
            meituanFallbackCard('fallback-self-position', '本店圈内位置', selfRow?.circlePositionText || '本店未返回', selfRow ? 'text-emerald-700' : 'text-gray-500', 'bg-emerald-50 border border-emerald-200', selfRow?.gapToLeaderText || '检查 POI 映射', selfRow ? 'text-emerald-600' : 'text-gray-500'),
            meituanFallbackCard('fallback-platform-vip-tags', 'VIP竞对标签', platformTags.returnedCount > 0 ? `VIP ${formatNumber(platformTags.vipCount)}家` : '未返回', platformTags.vipCount > 0 ? 'text-orange-700' : 'text-gray-500', 'bg-orange-50 border border-orange-200', platformTags.returnedCount > 0 ? '平台标签' : '平台未返回'),
            meituanFallbackCard('fallback-market-inventory', '市场总库存', marketInventory > 0 ? formatNumber(marketInventory) : '-', 'text-indigo-700', 'bg-indigo-50 border border-indigo-200', marketInventory > 0 ? '配置库存' : '需配置库存'),
            meituanFallbackCard('fallback-market-vitality', '市场活力', meituanFallbackPercentText(marketVitalityRate, toFixedSafe), 'text-blue-700', 'bg-blue-50 border border-blue-200', marketInventory > 0 ? '间夜/库存' : '数据不足'),
            meituanFallbackCard('fallback-price-sigma', '竞争健康度', meituanFallbackPercentText(priceSigma, toFixedSafe), 'text-orange-700', 'bg-orange-50 border border-orange-200', priceSigma > 25 ? '分化' : (priceSigma > 0 ? '稳定' : '数据不足'), priceSigma > 25 ? 'text-red-500' : 'text-gray-500'),
            meituanFallbackCard('fallback-market-price-signal', '市场价格预估', marketPriceSignal, marketPriceSignal === '数据不足' ? 'text-gray-500' : 'text-amber-700', 'bg-amber-50 border border-amber-200', avgRoomPrice > 0 && avgSalesPrice > 0 ? '销售/入住' : '数据不足'),
            meituanFallbackCard('fallback-inventory-turnover', '库存周转率', meituanFallbackPercentText(inventoryTurnoverRate, toFixedSafe), 'text-cyan-700', 'bg-cyan-50 border border-cyan-200', totalRoomNights > 0 ? '销售/入住' : '数据不足'),
            meituanFallbackCard('fallback-revenue-concentration', '收益集中度', meituanFallbackNumberText(revenueConcentration, 2, formatNumber), 'text-orange-700', 'bg-orange-50 border border-orange-200', totalSales > 0 ? '销售额HHI' : '数据不足'),
            meituanFallbackCard('fallback-visit-concentration', '浏览/访客集中度', meituanFallbackNumberText(visitConcentration, 2, formatNumber), 'text-orange-700', 'bg-orange-50 border border-orange-200', totalViews > 0 ? '浏览HHI' : '数据不足'),
            meituanFallbackCard('fallback-operation-focus', '运营重心', operationFocus, operationFocus === '数据不足' ? 'text-gray-500' : 'text-indigo-700', 'bg-indigo-50 border border-indigo-200'),
            meituanFallbackCard('fallback-total-room-nights', '总入住间夜', meituanFallbackMetricText(sourceRows, 'roomNights', 0, formatNumber), 'text-red-700', 'bg-red-50 border border-red-200'),
            meituanFallbackCard('fallback-total-room-revenue', '总房费收入', meituanFallbackMoneyText(totalRoomRevenue, formatNumber), 'text-red-700', 'bg-red-50 border border-red-200'),
            meituanFallbackCard('fallback-avg-room-price', '商圈平均房价', meituanFallbackMoneyText(avgRoomPrice, formatNumber), 'text-red-700', 'bg-red-50 border border-red-200'),
            meituanFallbackCard('fallback-total-sales-room-nights', '总销售间夜', meituanFallbackMetricText(sourceRows, 'salesRoomNights', 0, formatNumber), 'text-green-700', 'bg-green-50 border border-green-200'),
            meituanFallbackCard('fallback-total-sales', '总销售额', meituanFallbackMoneyText(totalSales, formatNumber), 'text-green-700', 'bg-green-50 border border-green-200'),
            meituanFallbackCard('fallback-avg-sales-price', '商圈平均销售房价', meituanFallbackMoneyText(avgSalesPrice, formatNumber), 'text-green-700', 'bg-green-50 border border-green-200'),
            meituanFallbackCard('fallback-total-exposure', '总曝光量', meituanFallbackMetricText(sourceRows, 'exposure', 0, formatNumber), 'text-blue-700', 'bg-blue-50 border border-blue-200'),
            meituanFallbackCard('fallback-total-views', '总浏览量', meituanFallbackMetricText(sourceRows, 'views', 0, formatNumber), 'text-blue-700', 'bg-blue-50 border border-blue-200'),
            meituanFallbackCard('fallback-total-order-count', '总订单量', meituanFallbackMetricText(sourceRows, 'orderCount', 0, formatNumber), 'text-blue-700', 'bg-blue-50 border border-blue-200'),
            meituanFallbackCard('fallback-avg-view-conversion', '平均浏览转化率', meituanFallbackPercentText(meituanFallbackAverage(sourceRows, 'viewConversion', true), toFixedSafe), 'text-purple-700', 'bg-purple-50 border border-purple-200'),
            meituanFallbackCard('fallback-avg-pay-conversion', '平均支付转化率', meituanFallbackPercentText(meituanFallbackAverage(sourceRows, 'payConversion', true), toFixedSafe), 'text-purple-700', 'bg-purple-50 border border-purple-200'),
            meituanFallbackCard('fallback-avg-absolute-conversion', '绝对转化率', meituanFallbackPercentText(meituanFallbackAverage(sourceRows, 'absoluteConversion', true), toFixedSafe), 'text-purple-700', 'bg-purple-50 border border-purple-200'),
        ];
    };

    const resolveMeituanBusinessSummaryCards = ({
        businessSummary = null,
        rankedRows = [],
        marketInventory = 0,
        formatNumber = item => String(item),
        toFixedSafe = (item, decimals = 2) => Number(item).toFixed(decimals),
    } = {}) => {
        const cards = businessSummary?.cards;
        if (Array.isArray(cards) && cards.length) {
            return cards;
        }
        const rows = Array.isArray(rankedRows) ? rankedRows : [];
        if (!rows.length) {
            return [];
        }
        return buildMeituanBusinessSummaryFallbackCards({
            rows,
            marketInventory,
            formatNumber,
            toFixedSafe,
        });
    };

    const runMeituanManualTabSwitch = async ({
        tab = '',
        getCurrentPage = () => '',
        getCurrentTab = () => '',
        loadConfigList = async () => {},
        syncTrafficConfig = async () => {},
        syncOrderConfig = async () => {},
        syncAdsConfig = async () => {},
        loadOrderFlow = async () => {},
        applyRankingConfig = async () => {},
    } = {}) => {
        const isActive = () => getCurrentPage() === 'meituan-ebooking' && getCurrentTab() === tab;
        if (!isActive()) return { status: 'stale_before_load', tab };

        await loadConfigList();
        if (!isActive()) return { status: 'stale_after_load', tab };

        if (tab === 'meituan-traffic') {
            await syncTrafficConfig();
            return { status: 'synced', tab, target: 'traffic' };
        }
        if (tab === 'meituan-orders') {
            await syncOrderConfig();
            return { status: 'synced', tab, target: 'orders' };
        }
        if (tab === 'meituan-ads') {
            await syncAdsConfig();
            return { status: 'synced', tab, target: 'ads' };
        }
        if (tab === 'meituan-order-flow') {
            await loadOrderFlow();
            return { status: 'synced', tab, target: 'order_flow' };
        }
        await applyRankingConfig();
        return { status: 'synced', tab, target: 'ranking' };
    };

    const normalizeMeituanTrafficFetchForm = (form = {}) => {
        form.url = String(form.url || '').trim();
        form.partnerId = String(form.partnerId || '').trim();
        form.poiId = String(form.poiId || '').trim();
        return form;
    };

    const validateMeituanTrafficFetchInput = (form = {}) => {
        if (!form.url) {
            return { ok: false, status: 'missing_url', level: 'error', message: '需 Network 请求信息：请输入接口地址' };
        }
        if (!form.partnerId) {
            return { ok: false, status: 'missing_partner_id', level: 'error', message: '需一次性平台接口标识：请输入平台接口标识' };
        }
        if (!form.poiId) {
            return { ok: false, status: 'missing_poi_id', level: 'error', message: '需一次性平台门店标识：请输入平台门店标识' };
        }
        return { ok: true, status: 'ok' };
    };

    const buildMeituanTrafficFetchRequestBody = ({
        form = {},
        configId = '',
        systemHotelId = null,
    } = {}) => ({
        config_id: String(configId || '').trim(),
        url: form.url,
        partner_id: form.partnerId,
        poi_id: form.poiId,
        start_date: form.startDate,
        end_date: form.endDate,
        auto_save: true,
        system_hotel_id: systemHotelId,
    });

    const runMeituanTrafficFetchFlow = async ({
        getForm = () => ({}),
        getConfigId = () => '',
        getSystemHotelId = () => null,
        notify = () => {},
        setFetching = () => {},
        setOnlineDataResult = () => {},
        setLatestTrafficData = () => {},
        requestFetch = async () => ({}),
        refreshOnlineHistory = async () => {},
        getOnlineDataTab = () => '',
        refreshOnlineData = () => {},
    } = {}) => {
        const form = normalizeMeituanTrafficFetchForm(getForm() || {});
        const configId = String(getConfigId() || '').trim();
        if (!configId) {
            const validation = { ok: false, status: 'missing_config', level: 'warning', message: '当前酒店未配置可执行的美团凭证' };
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }
        const validation = validateMeituanTrafficFetchInput(form);
        if (!validation.ok) {
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }

        setFetching(true);
        setOnlineDataResult(null);
        const requestBody = buildMeituanTrafficFetchRequestBody({
            form,
            configId,
            systemHotelId: getSystemHotelId(),
        });
        const directRequestBody = { ...requestBody, async: false, background: false };
        try {
            const res = await requestFetch(directRequestBody);
            if (isMeituanBackgroundAcceptedResponse(res)) {
                const data = res.data || {};
                const runningPayload = {
                    status: data.status || 'running',
                    task_id: data.task_id || '',
                    platform: data.platform || 'meituan',
                    async: true,
                    saved_count: data.saved_count || 0,
                    request_start_date: data.request_start_date || requestBody.start_date || '',
                    request_end_date: data.request_end_date || requestBody.end_date || '',
                };
                setOnlineDataResult(runningPayload);
                setLatestTrafficData(runningPayload);
                notify(res.message || '美团流量手动获取已提交后台执行，完成后会更新数据列表和通知', 'info');
                runPostFetchRefresh(refreshOnlineHistory);
                if (getOnlineDataTab() === 'data') {
                    refreshOnlineData();
                }
                return { status: 'accepted', response: res, requestBody: directRequestBody, data: runningPayload, savedCount: 0 };
            }
            if (res.code === 200) {
                const data = res.data || {};
                const trafficData = data.data;
                setOnlineDataResult(trafficData);
                setLatestTrafficData(trafficData);
                const hasDisplayRows = Array.isArray(trafficData)
                    ? trafficData.length > 0
                    : Boolean(trafficData && typeof trafficData === 'object' && Object.keys(trafficData).length > 0);
                const notice = buildMeituanPersistenceNotice({
                    label: '美团流量数据',
                    data,
                    hasDisplayRows,
                    failureMessage: res.message || '',
                });
                notify(notice.message, notice.level);
                if (notice.savedCount > 0) {
                    runPostFetchRefresh(refreshOnlineHistory);
                    if (getOnlineDataTab() === 'data') {
                        refreshOnlineData();
                    }
                }
                return {
                    status: notice.businessFailed
                        ? 'business_failed'
                        : ((notice.savedCount > 0 || notice.businessCompleted || hasDisplayRows) ? 'success' : 'incomplete'),
                    response: res,
                    requestBody: directRequestBody,
                    data: trafficData,
                    savedCount: notice.savedCount,
                    persisted: notice.persisted,
                    readback_verified: notice.readbackVerified,
                };
            }

            notify(res.message || '获取失败', 'error');
            return { status: 'failed', response: res, requestBody: directRequestBody };
        } catch (error) {
            notify('请求失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody: directRequestBody };
        } finally {
            setFetching(false);
        }
    };

    const normalizeMeituanOrderFetchForm = (form = {}) => {
        form.url = String(form.url || '').trim();
        form.method = String(form.method || 'GET').toUpperCase();
        form.partnerId = String(form.partnerId || '').trim();
        form.poiId = String(form.poiId || '').trim();
        return form;
    };

    const validateMeituanOrderFetchInput = (form = {}) => {
        if (!form.url) {
            return { ok: false, status: 'missing_url', level: 'error', message: '需 Network 请求信息：请填写订单接口 Request URL' };
        }
        if (form.url.includes('/order-eb/index.html')) {
            return { ok: false, status: 'invalid_page_url', level: 'error', message: '请填写 Network 中 /orders/list 的接口 URL，不是订单页面 URL' };
        }
        if (!form.partnerId) {
            return { ok: false, status: 'missing_partner_id', level: 'error', message: '请输入平台接口标识' };
        }
        if (!form.poiId) {
            return { ok: false, status: 'missing_poi_id', level: 'error', message: '请输入平台门店标识' };
        }
        return { ok: true, status: 'ok' };
    };

    const buildMeituanOrderFetchRequestBody = ({
        form = {},
        configId = '',
        systemHotelId = null,
        hotelName = '',
    } = {}) => ({
        config_id: String(configId || '').trim(),
        url: form.url,
        method: form.method,
        partner_id: form.partnerId,
        poi_id: form.poiId,
        start_date: form.startDate,
        end_date: form.endDate,
        auto_save: true,
        system_hotel_id: systemHotelId,
        hotel_name: hotelName,
    });

    const buildMeituanOrderDomCollectorScript = () => String.raw`// ==UserScript==
// @name         SUXIOS 美团订单页 CSV 导出
// @namespace    suxios.hotel.ota
// @version      1.0.0
// @description  在已授权登录的美团 eBooking 订单页导出订单号、房型、入住日期、离店日期、购买时间、底价 CSV，用于宿析OS临时补录。
// @match        https://eb.meituan.com/ebooking/order-eb/*
// @match        https://me.meituan.com/ebooking/merchant/ebIframe*
// @grant        none
// @run-at       document-idle
// ==/UserScript==

(function () {
  'use strict';

  var PANEL_ID = 'suxi-meituan-order-dom-panel';
  var rows = [];

  function status(text) {
    var el = document.querySelector('#' + PANEL_ID + ' [data-role="status"]');
    if (el) el.textContent = text;
  }

  function currentYearDate(mmdd) {
    if (!/^\d{2}-\d{2}$/.test(mmdd || '')) return mmdd || '';
    return new Date().getFullYear() + '-' + mmdd;
  }

  function localDateText() {
    var date = new Date();
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    return date.getFullYear() + '-' + month + '-' + day;
  }

  function nearestOrderContainer(anchor) {
    var node = anchor;
    for (var i = 0; i < 12 && node && node.parentElement; i++) {
      node = node.parentElement;
      var text = node.innerText || '';
      if (/订单号/.test(text) && /购买时间/.test(text) && /底价/.test(text)) return node;
    }
    return anchor;
  }

  function roomTypeFrom(container) {
    var roomEl = container.querySelector('.order-room-info-wrapper');
    if (!roomEl) return '';
    for (var i = 0; i < roomEl.childNodes.length; i++) {
      var child = roomEl.childNodes[i];
      if (child.nodeType === Node.TEXT_NODE && child.textContent.trim()) {
        return child.textContent.trim().split('+')[0].trim();
      }
    }
    return (roomEl.innerText || '').split('+')[0].split('\n')[0].trim();
  }

  function extractPageRows() {
    var result = [];
    var seen = new Set();
    var dateNodes = Array.prototype.slice.call(document.querySelectorAll('.order-date-wrapper'));
    dateNodes.forEach(function (dateEl) {
      var dateText = dateEl.innerText || '';
      var dateMatch = dateText.match(/(\d{2}-\d{2})\s+至\s+(\d{2}-\d{2})/);
      var container = nearestOrderContainer(dateEl);
      var text = container.innerText || '';
      var orderMatch = text.match(/订单号[：:]\s*(\d{10,30})/);
      var orderNo = orderMatch ? orderMatch[1] : '';
      if (!orderNo || seen.has(orderNo)) return;
      seen.add(orderNo);
      var priceMatch = text.match(/底价[：:]\s*([0-9.]+)/);
      var buyMatch = text.match(/购买时间[：:]\s*(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?)/);
      result.push({
        orderNo: orderNo,
        roomType: roomTypeFrom(container),
        checkIn: dateMatch ? currentYearDate(dateMatch[1]) : '',
        checkOut: dateMatch ? currentYearDate(dateMatch[2]) : '',
        buyTime: buyMatch ? buyMatch[1] : '',
        bottomPrice: priceMatch ? priceMatch[1] : ''
      });
    });
    return result;
  }

  function csvEscape(value) {
    return '"' + String(value || '').replace(/"/g, '""') + '"';
  }

  function exportCsv(data) {
    var headers = ['订单号', '房型', '入住日期', '离店日期', '购买时间', '底价(元)'];
    var body = data.map(function (row) {
      return [row.orderNo, row.roomType, row.checkIn, row.checkOut, row.buyTime, row.bottomPrice].map(csvEscape).join(',');
    });
    var csv = '\uFEFF' + headers.join(',') + '\n' + body.join('\n');
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = '美团订单_订单页导出_' + localDateText() + '_共' + data.length + '条.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

  function collectCurrentPage() {
    var pageRows = extractPageRows();
    var known = new Set(rows.map(function (row) { return row.orderNo; }));
    pageRows.forEach(function (row) {
      if (!known.has(row.orderNo)) rows.push(row);
    });
    status(pageRows.length > 0 ? '当前页解析 ' + pageRows.length + ' 条，累计 ' + rows.length + ' 条' : '当前页未找到可解析订单，请确认在美团订单列表页');
  }

  function mountPanel() {
    if (document.getElementById(PANEL_ID)) return;
    var panel = document.createElement('div');
    panel.id = PANEL_ID;
    panel.style.cssText = 'position:fixed;right:16px;top:72px;z-index:2147483647;width:300px;padding:14px;background:#fff;border:2px solid #10b981;border-radius:10px;box-shadow:0 8px 28px rgba(16,185,129,.18);font:13px/1.5 Arial,Microsoft YaHei,sans-serif;color:#1f2937;';
    panel.innerHTML = '<div style="font-weight:700;color:#047857;margin-bottom:8px">SUXIOS 美团订单 CSV 导出</div>' +
      '<div style="font-size:12px;color:#4b5563;margin-bottom:10px">仅读取当前已授权订单页可见内容，导出后回宿析OS导入。</div>' +
      '<button data-role="collect" style="width:100%;padding:8px 0;margin-bottom:8px;border:0;border-radius:7px;background:#10b981;color:#fff;cursor:pointer">采集当前页</button>' +
      '<button data-role="export" style="width:100%;padding:8px 0;margin-bottom:8px;border:1px solid #10b981;border-radius:7px;background:#ecfdf5;color:#047857;cursor:pointer">导出 CSV</button>' +
      '<button data-role="clear" style="width:100%;padding:7px 0;margin-bottom:8px;border:1px solid #e5e7eb;border-radius:7px;background:#f9fafb;color:#4b5563;cursor:pointer">清空累计</button>' +
      '<div data-role="status" style="font-size:12px;color:#047857;word-break:break-all">就绪：先采集当前页，再翻页重复采集。</div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-role="collect"]').onclick = collectCurrentPage;
    panel.querySelector('[data-role="export"]').onclick = function () {
      if (!rows.length) {
        status('暂无数据，请先采集当前页');
        return;
      }
      exportCsv(rows);
    };
    panel.querySelector('[data-role="clear"]').onclick = function () {
      rows = [];
      status('已清空累计数据');
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(mountPanel, 800); });
  } else {
    setTimeout(mountPanel, 800);
  }
})();`;

    const parseCsvTextRows = (text = '') => {
        const rows = [];
        let row = [];
        let cell = '';
        let inQuotes = false;
        const source = String(text || '').replace(/^\uFEFF/, '');
        for (let i = 0; i < source.length; i++) {
            const ch = source[i];
            if (ch === '"') {
                if (inQuotes && source[i + 1] === '"') {
                    cell += '"';
                    i++;
                } else {
                    inQuotes = !inQuotes;
                }
                continue;
            }
            if (ch === ',' && !inQuotes) {
                row.push(cell);
                cell = '';
                continue;
            }
            if ((ch === '\n' || ch === '\r') && !inQuotes) {
                if (ch === '\r' && source[i + 1] === '\n') {
                    i++;
                }
                row.push(cell);
                if (row.some(value => String(value || '').trim() !== '')) {
                    rows.push(row);
                }
                row = [];
                cell = '';
                continue;
            }
            cell += ch;
        }
        row.push(cell);
        if (row.some(value => String(value || '').trim() !== '')) {
            rows.push(row);
        }
        return rows;
    };

    const normalizeMeituanOrderCsvHeader = (value) => String(value || '')
        .replace(/^\uFEFF/, '')
        .replace(/\s+/g, '')
        .replace(/[（）()]/g, '')
        .toLowerCase();

    const buildMeituanOrderCsvIndex = (headers = []) => {
        const index = new Map();
        headers.forEach((header, position) => {
            const key = normalizeMeituanOrderCsvHeader(header);
            if (key && !index.has(key)) {
                index.set(key, position);
            }
        });
        return index;
    };

    const readMeituanOrderCsvValue = (row = [], index, aliases = []) => {
        for (const alias of aliases) {
            const position = index.get(normalizeMeituanOrderCsvHeader(alias));
            if (position === undefined) {
                continue;
            }
            const value = String(row[position] ?? '').trim();
            if (value !== '') {
                return value;
            }
        }
        return '';
    };

    const parseMeituanOrderCsvText = (csvText = '') => {
        const rows = parseCsvTextRows(csvText);
        if (rows.length <= 1) {
            return [];
        }
        const index = buildMeituanOrderCsvIndex(rows[0]);
        return rows.slice(1).map((row, rowIndex) => {
            const orderNo = readMeituanOrderCsvValue(row, index, ['订单号', 'orderNo', 'order_no', 'orderId', 'order_id']);
            const roomType = readMeituanOrderCsvValue(row, index, ['房型', 'roomType', 'room_type', 'roomName', 'room_name']);
            const checkIn = readMeituanOrderCsvValue(row, index, ['入住日期', 'checkIn', 'check_in', 'checkInDate', 'check_in_date']);
            const checkOut = readMeituanOrderCsvValue(row, index, ['离店日期', 'checkOut', 'check_out', 'checkOutDate', 'check_out_date']);
            const buyTime = readMeituanOrderCsvValue(row, index, ['购买时间', 'buyTime', 'buy_time', 'purchaseTime', 'purchase_time', 'createTime']);
            const bottomPrice = readMeituanOrderCsvValue(row, index, ['底价元', '底价', '底价(元)', 'basePrice', 'base_price', 'bottomPrice', 'bottom_price', 'price']);
            if (!orderNo && !roomType && !checkIn && !checkOut && !buyTime && !bottomPrice) {
                return null;
            }
            return {
                orderNo,
                roomType,
                checkIn,
                checkOut,
                buyTime,
                bottomPrice,
                _ingestion_method: 'manual_dom_csv',
                _source_path: `manual_dom_csv.orders.${rowIndex}`,
            };
        }).filter(Boolean);
    };

    const buildMeituanOrderCsvImportRequestBody = ({
        csvText = '',
        form = {},
        configId = '',
        systemHotelId = null,
        hotelName = '',
    } = {}) => {
        const orders = parseMeituanOrderCsvText(csvText);
        const poiId = String(form.poiId || '').trim();
        return {
            config_id: String(configId || '').trim(),
            system_hotel_id: systemHotelId,
            hotel_id: systemHotelId,
            payload: {
                store_id: poiId,
                poi_id: poiId,
                poi_name: hotelName,
                system_hotel_id: systemHotelId,
                config_id: String(configId || '').trim(),
                default_data_date: form.endDate || form.startDate || todayDateText(),
                data_period: 'manual_dom_csv',
                orders,
            },
            parsed_count: orders.length,
        };
    };

    const runMeituanOrderCsvImportFlow = async ({
        getForm = () => ({}),
        getConfigId = () => '',
        getSystemHotelId = () => null,
        getHotelNameById = () => '',
        notify = () => {},
        setFetching = () => {},
        setOrderResult = () => {},
        setOnlineDataResult = () => {},
        requestSave = async () => ({}),
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const form = normalizeMeituanOrderFetchForm(getForm() || {});
        const csvText = String(form.csvText || '').trim();
        const systemHotelId = getSystemHotelId();
        const configId = String(getConfigId() || '').trim();
        if (!systemHotelId) {
            notify('请选择目标酒店后再导入 CSV', 'error');
            return { status: 'missing_system_hotel_id', form };
        }
        if (!csvText) {
            notify('请先粘贴美团订单 CSV 内容', 'error');
            return { status: 'missing_csv_text', form };
        }
        if (!configId) {
            notify('请先选择已绑定的美团配置', 'error');
            return { status: 'missing_config_id', form };
        }
        const requestBody = buildMeituanOrderCsvImportRequestBody({
            csvText,
            form,
            configId,
            systemHotelId,
            hotelName: getHotelNameById(systemHotelId),
        });
        if ((requestBody.parsed_count || 0) <= 0) {
            notify('未解析到可导入的美团订单 CSV 行', 'warning');
            return { status: 'empty_csv_rows', requestBody };
        }

        setFetching(true);
        setOrderResult(null);
        setOnlineDataResult(null);
        try {
            const res = await requestSave(requestBody);
            if (res.code === 200) {
                const data = { ...(res.data || {}), import_row_count: requestBody.parsed_count };
                setOrderResult(data);
                setOnlineDataResult(data);
                const notice = buildMeituanPersistenceNotice({
                    label: '美团 CSV 订单导入',
                    data,
                    failureMessage: res.message || '',
                });
                notify(notice.message, notice.level);
                runPostFetchRefresh(refreshOnlineHistory);
                return {
                    status: notice.businessFailed
                        ? 'business_failed'
                        : ((notice.savedCount > 0 || notice.businessCompleted) ? 'success' : 'incomplete'),
                    response: res,
                    requestBody,
                    data,
                    savedCount: notice.savedCount,
                    persisted: notice.persisted,
                    readback_verified: notice.readbackVerified,
                };
            }
            notify(res.message || 'CSV订单导入失败', 'error');
            return { status: 'failed', response: res, requestBody };
        } catch (error) {
            notify('CSV订单导入失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody };
        } finally {
            setFetching(false);
        }
    };

    const runMeituanOrderFetchFlow = async ({
        getForm = () => ({}),
        getConfigId = () => '',
        getSystemHotelId = () => null,
        getHotelNameById = () => '',
        notify = () => {},
        setFetching = () => {},
        setOrderResult = () => {},
        setOnlineDataResult = () => {},
        requestFetch = async () => ({}),
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const form = normalizeMeituanOrderFetchForm(getForm() || {});
        const configId = String(getConfigId() || '').trim();
        if (!configId) {
            const validation = { ok: false, status: 'missing_config', level: 'warning', message: '当前酒店未配置可执行的美团凭证' };
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }
        const validation = validateMeituanOrderFetchInput(form);
        if (!validation.ok) {
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }

        setFetching(true);
        setOrderResult(null);
        setOnlineDataResult(null);
        const systemHotelId = getSystemHotelId();
        const requestBody = buildMeituanOrderFetchRequestBody({
            form,
            configId,
            systemHotelId,
            hotelName: getHotelNameById(systemHotelId),
        });
        const directRequestBody = { ...requestBody, async: false, background: false };
        try {
            const res = await requestFetch(directRequestBody);
            if (isMeituanBackgroundAcceptedResponse(res)) {
                const data = res.data || {};
                const runningPayload = {
                    status: data.status || 'running',
                    ui_flow_status: 'accepted',
                    task_id: data.task_id || '',
                    platform: data.platform || 'meituan',
                    async: true,
                    saved_count: data.saved_count || 0,
                    request_start_date: data.request_start_date || requestBody.start_date || '',
                    request_end_date: data.request_end_date || requestBody.end_date || '',
                };
                setOrderResult(runningPayload);
                setOnlineDataResult(runningPayload);
                notify(res.message || '美团订单手动获取已提交后台执行，完成后会更新数据列表和通知', 'info');
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'accepted', response: res, requestBody: directRequestBody, data: runningPayload, savedCount: 0 };
            }
            if (res.code === 200) {
                const data = res.data || {};
                const hasDisplayRows = ['rows', 'orders', 'data'].some(key => Array.isArray(data[key]) && data[key].length > 0)
                    || Number(data.row_count || data.parsed_row_count || 0) > 0;
                const notice = buildMeituanPersistenceNotice({
                    label: '美团订单数据',
                    data,
                    hasDisplayRows,
                    failureMessage: res.message || '',
                });
                const flowStatus = notice.businessFailed
                    ? 'business_failed'
                    : ((notice.savedCount > 0 || notice.businessCompleted || hasDisplayRows) ? 'success' : 'incomplete');
                const visibleData = {
                    ...data,
                    ui_flow_status: flowStatus,
                    ui_message: notice.message,
                    persisted: notice.persisted,
                    readback_verified: notice.readbackVerified,
                };
                setOrderResult(visibleData);
                setOnlineDataResult(visibleData);
                notify(notice.message, notice.level);
                runPostFetchRefresh(refreshOnlineHistory);
                return {
                    status: flowStatus,
                    response: res,
                    requestBody: directRequestBody,
                    data: visibleData,
                    savedCount: notice.savedCount,
                    persisted: notice.persisted,
                    readback_verified: notice.readbackVerified,
                };
            }

            const failureMessage = res.message || '订单数据获取失败';
            const failureResult = { ...(res.data || {}), ui_flow_status: 'failed', error: failureMessage };
            setOrderResult(failureResult);
            setOnlineDataResult(failureResult);
            notify(failureMessage, 'error');
            return { status: 'failed', response: res, requestBody: directRequestBody };
        } catch (error) {
            const failureResult = { ...(error?.data?.data || {}), ui_flow_status: 'exception', error: error.message };
            setOrderResult(failureResult);
            setOnlineDataResult(failureResult);
            notify('订单数据获取失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody: directRequestBody };
        } finally {
            setFetching(false);
        }
    };

    const normalizeMeituanAdsFetchForm = (form = {}) => {
        form.url = String(form.url || '').trim();
        form.method = String(form.method || 'GET').toUpperCase();
        form.partnerId = String(form.partnerId || '').trim();
        form.poiId = String(form.poiId || '').trim();
        form.shopId = String(form.shopId || '').trim();
        return form;
    };

    const validateMeituanAdsFetchInput = (form = {}) => {
        if (!form.url) {
            return { ok: false, status: 'missing_url', level: 'error', message: '需 Network 请求信息：请填写广告接口 Request URL' };
        }
        if (form.url.includes('/shopdiy/account/pcCpcEntry')) {
            return { ok: false, status: 'invalid_page_url', level: 'error', message: '请填写 Network 中 cureShops 的接口 URL，不是推广通入口页面 URL' };
        }
        if (!form.shopId && !form.poiId) {
            return { ok: false, status: 'missing_shop_or_poi_id', level: 'error', message: '请输入推广店铺或门店标识' };
        }
        return { ok: true, status: 'ok' };
    };

    const buildMeituanAdsFetchRequestBody = ({
        form = {},
        configId = '',
        systemHotelId = null,
        hotelName = '',
    } = {}) => ({
        config_id: String(configId || '').trim(),
        url: form.url,
        method: form.method,
        partner_id: form.partnerId,
        poi_id: form.poiId || form.shopId,
        shop_id: form.shopId || form.poiId,
        start_date: form.startDate,
        end_date: form.endDate,
        auto_save: true,
        system_hotel_id: systemHotelId,
        hotel_name: hotelName,
    });

    const runMeituanAdsFetchFlow = async ({
        getForm = () => ({}),
        getConfigId = () => '',
        getSystemHotelId = () => null,
        getHotelNameById = () => '',
        notify = () => {},
        setFetching = () => {},
        setAdsResult = () => {},
        setOnlineDataResult = () => {},
        requestFetch = async () => ({}),
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const form = normalizeMeituanAdsFetchForm(getForm() || {});
        const configId = String(getConfigId() || '').trim();
        if (!configId) {
            const validation = { ok: false, status: 'missing_config', level: 'warning', message: '当前酒店未配置可执行的美团凭证' };
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }
        const validation = validateMeituanAdsFetchInput(form);
        if (!validation.ok) {
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }

        setFetching(true);
        setAdsResult(null);
        setOnlineDataResult(null);
        const systemHotelId = getSystemHotelId();
        const requestBody = buildMeituanAdsFetchRequestBody({
            form,
            configId,
            systemHotelId,
            hotelName: getHotelNameById(systemHotelId),
        });
        const directRequestBody = { ...requestBody, async: false, background: false };
        try {
            const res = await requestFetch(directRequestBody);
            if (isMeituanBackgroundAcceptedResponse(res)) {
                const data = res.data || {};
                const runningPayload = {
                    status: data.status || 'running',
                    ui_flow_status: 'accepted',
                    task_id: data.task_id || '',
                    platform: data.platform || 'meituan',
                    async: true,
                    saved_count: data.saved_count || 0,
                    request_start_date: data.request_start_date || requestBody.start_date || '',
                    request_end_date: data.request_end_date || requestBody.end_date || '',
                };
                setAdsResult(runningPayload);
                setOnlineDataResult(runningPayload);
                notify(res.message || '美团广告手动获取已提交后台执行，完成后会更新数据列表和通知', 'info');
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'accepted', response: res, requestBody: directRequestBody, data: runningPayload, savedCount: 0 };
            }
            if (res.code === 200) {
                const data = res.data || {};
                const hasDisplayRows = ['rows', 'ads', 'campaigns', 'data'].some(key => Array.isArray(data[key]) && data[key].length > 0)
                    || Number(data.row_count || data.parsed_row_count || 0) > 0;
                const notice = buildMeituanPersistenceNotice({
                    label: '美团广告数据',
                    data,
                    hasDisplayRows,
                    failureMessage: res.message || '',
                });
                const flowStatus = notice.businessFailed
                    ? 'business_failed'
                    : ((notice.savedCount > 0 || notice.businessCompleted || hasDisplayRows) ? 'success' : 'incomplete');
                const visibleData = {
                    ...data,
                    ui_flow_status: flowStatus,
                    ui_message: notice.message,
                    persisted: notice.persisted,
                    readback_verified: notice.readbackVerified,
                };
                setAdsResult(visibleData);
                setOnlineDataResult(visibleData);
                notify(notice.message, notice.level);
                runPostFetchRefresh(refreshOnlineHistory);
                return {
                    status: flowStatus,
                    response: res,
                    requestBody: directRequestBody,
                    data: visibleData,
                    savedCount: notice.savedCount,
                    persisted: notice.persisted,
                    readback_verified: notice.readbackVerified,
                };
            }

            const failureMessage = res.message || '广告数据获取失败';
            const failureResult = { ...(res.data || {}), ui_flow_status: 'failed', error: failureMessage };
            setAdsResult(failureResult);
            setOnlineDataResult(failureResult);
            notify(failureMessage, 'error');
            return { status: 'failed', response: res, requestBody: directRequestBody };
        } catch (error) {
            const failureResult = { ...(error?.data?.data || {}), ui_flow_status: 'exception', error: error.message };
            setAdsResult(failureResult);
            setOnlineDataResult(failureResult);
            notify('广告数据获取失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody: directRequestBody };
        } finally {
            setFetching(false);
        }
    };

    const runMeituanBatchFetchFlow = async ({
        getForm = () => ({}),
        getSelectedConfig = () => null,
        applyMeituanHotelConfig = async () => {},
        notify = () => {},
        setFetching = () => {},
        setOnlineDataResult = () => {},
        setFetchSuccess = () => {},
        setHotelsList = () => {},
        getEmptyBusinessSummary = () => ({}),
        setBusinessSummary = () => {},
        isActive = () => true,
        requestFetch = async () => ({}),
        requestCommit = null,
        waitForRetry = async () => {},
        requestDisplayModel = async () => ({}),
        useDisplayModel = rows => rows,
        setSavedCount = () => {},
        setDataFetchTime = () => {},
        getFetchTime = () => new Date().toLocaleString('zh-CN'),
        updateAiAnalysisHotelList = () => {},
        refreshOnlineHistory = async () => {},
        getOnlineDataTab = () => '',
        refreshOnlineData = () => {},
        background = false,
        suppressPostFetchRefresh = false,
    } = {}) => {
        const runIsActive = () => {
            try {
                return isActive() !== false;
            } catch (_) {
                return false;
            }
        };
        let form = getForm() || {};
        const selectedMeituanConfig = form.hotelId
            ? getSelectedConfig()
            : null;
        if (selectedMeituanConfig && !isMeituanConfigBoundToFormHotel(form, selectedMeituanConfig)) {
            notify('当前选择门店与美团配置归属不一致，已阻止跨门店获取数据', 'error');
            return { status: 'config_hotel_mismatch', form, selectedConfig: selectedMeituanConfig };
        }
        if (!isMeituanRankingFormAlignedWithConfig(form, selectedMeituanConfig)) {
            if (selectedMeituanConfig) {
                await applyMeituanHotelConfig(false, {
                    resolvedConfig: selectedMeituanConfig,
                    refreshList: false,
                    skipIfAligned: true,
                });
                if (!runIsActive()) {
                    return { status: 'stale', results: [], totalSavedCount: 0 };
                }
                form = getForm() || form;
            }
        }
        if (selectedMeituanConfig && !isMeituanRankingFormAlignedWithConfig(form, selectedMeituanConfig)) {
            notify('当前门店美团配置未同步完成，已阻止本次获取，避免拿到其他门店数据', 'warning');
            return { status: 'selected_config_not_applied', form, selectedConfig: selectedMeituanConfig };
        }
        const configId = isMeituanExecutionConfigReady(selectedMeituanConfig)
            ? resolveMeituanExecutionConfigId(selectedMeituanConfig)
            : '';
        const batchInput = validateMeituanBatchFetchInput({
            form,
            configId,
        });
        if (!batchInput.ok) {
            notify(batchInput.message, batchInput.level);
            return { status: batchInput.status || 'invalid_input', batchInput };
        }

        if (!runIsActive()) {
            return { status: 'stale', results: [], totalSavedCount: 0 };
        }
        setFetching(true);
        setOnlineDataResult(null);
        setFetchSuccess(false);
        const fetchTasks = buildMeituanBatchFetchTasks({
            form,
            configId,
        });
        const results = fetchTasks.map(task => buildMeituanBatchFetchPendingEntry(task));
        let resultUpdateTimer = null;
        let cancelResultUpdate = null;
        const scheduleResultUpdate = () => {
            if (resultUpdateTimer) return;
            const commit = () => {
                resultUpdateTimer = null;
                cancelResultUpdate = null;
                if (!runIsActive()) return;
                setOnlineDataResult([...results]);
            };
            if (typeof requestAnimationFrame === 'function') {
                resultUpdateTimer = requestAnimationFrame(commit);
                cancelResultUpdate = () => {
                    if (typeof cancelAnimationFrame === 'function') cancelAnimationFrame(resultUpdateTimer);
                };
                return;
            }
            if (typeof setTimeout === 'function') {
                resultUpdateTimer = setTimeout(commit, 0);
                cancelResultUpdate = () => {
                    if (typeof clearTimeout === 'function') clearTimeout(resultUpdateTimer);
                };
                return;
            }
            commit();
        };
        const flushResultUpdate = () => {
            if (resultUpdateTimer && typeof cancelResultUpdate === 'function') {
                cancelResultUpdate();
            }
            resultUpdateTimer = null;
            cancelResultUpdate = null;
            if (!runIsActive()) return;
            setOnlineDataResult([...results]);
        };
        if (results.length > 0) {
            setOnlineDataResult([...results]);
        }
        let totalSavedCount = 0;
        let acceptedCount = 0;

        try {
            if (fetchTasks.length > 0) {
                notify(fetchTasks.length === 1 ? fetchTasks[0].toastText : `正在获取 ${fetchTasks.length} 个美团榜单任务...`);
            }
            await Promise.all(fetchTasks.map(async (task, index) => {
                const requestBody = { ...task.body, async: background === true, background: background === true };
                const maxAttempts = meituanRankMaxAttempts(task);
                let attemptCount = 0;
                try {
                    const attemptEntries = [];
                    let bestResponse = null;
                    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
                        const attemptRequestBody = attempt === 1
                            ? requestBody
                            : {
                                ...requestBody,
                                include_self_trade_metrics: false,
                                include_self_traffic_metrics: false,
                                include_self_business_metrics: false,
                            };
                        let attemptResponse;
                        try {
                            attemptResponse = await requestFetch(attemptRequestBody);
                            attemptCount = attempt;
                            if (!runIsActive()) return;
                        } catch (error) {
                            if (!runIsActive()) return;
                            attemptCount = attempt;
                            const retryable = attempt < maxAttempts && !isMeituanNonRetryableFetchError(error);
                            if (!retryable) {
                                throw error;
                            }
                            const delayMs = meituanRetryDelayMs(attempt);
                            results[index] = {
                                ...buildMeituanBatchFetchPendingEntry(task),
                                status: 'fetching',
                                message: `${task.dateRangeName || '所选区间'}第 ${attempt} / ${maxAttempts} 轮请求暂时失败，${delayMs}ms 后重试`,
                                attemptCount: attempt,
                                retryCount: Math.max(0, attempt - 1),
                                maxAttempts,
                                rankDataComplete: false,
                                retryExhausted: false,
                                lastRetryError: String(error?.message || error || '请求失败'),
                            };
                            setOnlineDataResult([...results]);
                            await waitForRetry(delayMs);
                            if (!runIsActive()) return;
                            continue;
                        }
                        bestResponse = selectBetterMeituanRankResponse(bestResponse, attemptResponse);
                        const attemptEntry = buildMeituanBatchFetchResultEntry(task, attemptResponse);
                        attemptEntries.push(attemptEntry);
                        if (attemptResponse?.code !== 200 || isMeituanBackgroundAcceptedResponse(attemptResponse)) {
                            break;
                        }
                        const attemptComplete = isMeituanRankResponseComplete(attemptResponse, task);
                        if (attemptComplete) {
                            break;
                        }
                        results[index] = {
                            ...attemptEntry,
                            status: 'fetching',
                            message: `${task.dateRangeName || '所选区间'}第 ${attempt} / ${maxAttempts} 轮未完整，继续抓取`,
                            attemptCount: attempt,
                            retryCount: Math.max(0, attempt - 1),
                            maxAttempts,
                            rankDataComplete: false,
                            retryExhausted: false,
                        };
                        setOnlineDataResult([...results]);
                    }
                    const res = bestResponse || {};
                    const accepted = isMeituanBackgroundAcceptedResponse(res);
                    const rankDataComplete = isMeituanRankResponseComplete(res, task);
                    const rankCandidate = res?.data?.rank_candidate;
                    const rankDataMode = meituanRankCandidateValueMode(res)
                        || (hasMeituanCompleteAbsoluteRankRows(res) ? 'raw' : 'platform');
                    if (rankDataComplete
                        && typeof requestCommit === 'function'
                        && !rankCandidate?.candidate_id) {
                        const candidateError = res?.data?.rank_candidate_error;
                        throw new Error(candidateError?.message || 'Meituan server rejected this complete ranking candidate');
                    }
                    if (rankDataComplete
                        && rankCandidate?.candidate_id
                        && typeof requestCommit === 'function') {
                        const savingEntry = buildMeituanBatchFetchResultEntry(task, res);
                        results[index] = {
                            ...savingEntry,
                            status: 'saving',
                            message: rankDataMode === 'self_only'
                                ? '本店实时值已返回，正在保存榜单名次并核对数据库'
                                : (rankDataMode === 'derived'
                                    ? '平台仅返回百分比，已按本店真实值和平台百分比计算；正在保存并核对数据库'
                                    : '平台榜单原始字段已返回，正在保存并核对数据库'),
                            attemptCount,
                            retryCount: Math.max(0, attemptCount - 1),
                            maxAttempts,
                            rankDataComplete: true,
                            rankDataMode,
                            retryExhausted: false,
                        };
                        if (runIsActive()) {
                            setOnlineDataResult([...results]);
                        }
                        const commitResponse = await requestCommit({ ...rankCandidate });
                        if (!runIsActive()) return;
                        if (commitResponse?.code !== 200) {
                            throw new Error(commitResponse?.message || 'Meituan rank candidate commit failed');
                        }
                        if (!res.data || typeof res.data !== 'object') {
                            res.data = {};
                        }
                        res.data.saved_count = Number(commitResponse?.data?.saved_count || 0);
                        res.data.persistence_status = commitResponse?.data?.persistence_status || '';
                        res.data.database_readback = commitResponse?.data?.database_readback || null;
                        res.data.readback_verified = commitResponse?.data?.readback_verified === true;
                    }
                    const retryExhausted = !rankDataComplete
                        && !accepted
                        && res?.code === 200
                        && attemptCount >= maxAttempts;
                    const percentOnlyWithoutAnchor = isMeituanHistoricalPercentOnlyStayOrSales(res, task);
                    const incompleteMessage = retryExhausted
                        ? (percentOnlyWithoutAnchor
                            ? `${task.dateRangeName || '所选区间'}已尝试 ${attemptCount} 轮，仍只有排名百分比且本店真实值锚点不足，未抓到可保存的完整榜单`
                            : `${task.dateRangeName || '所选区间'}已尝试 ${attemptCount} 轮，未抓到完整榜单`)
                        : '';
                    if (accepted) {
                        acceptedCount += 1;
                    }
                    const bestEntry = buildMeituanBatchFetchResultEntry(task, res);
                    const mergedSelfMetricValues = mergeMeituanSelfMetricValues(
                        ...attemptEntries.map(entry => entry?.selfMetricValues)
                    );
                    const mergedSelfMetricStatus = mergeMeituanSelfMetricStatus(
                        ...attemptEntries.map(entry => entry?.selfMetricStatus)
                    );
                    results[index] = {
                        ...bestEntry,
                        ...(Object.keys(mergedSelfMetricValues).length > 0 ? { selfMetricValues: mergedSelfMetricValues } : {}),
                        ...(mergedSelfMetricStatus ? { selfMetricStatus: mergedSelfMetricStatus } : {}),
                        attemptCount,
                        retryCount: Math.max(0, attemptCount - 1),
                        maxAttempts,
                        rankDataComplete,
                        rankDataMode,
                        retryExhausted,
                        ...(retryExhausted ? {
                            status: 'incomplete',
                            message: incompleteMessage,
                            error: incompleteMessage,
                        } : {}),
                    };
                    if (res.code === 200 && !accepted) {
                        totalSavedCount += res.data.saved_count || 0;
                    }
                    if (runIsActive()) {
                        setOnlineDataResult([...results]);
                    }
                } catch (error) {
                    if (!runIsActive()) return;
                    results[index] = {
                        ...buildMeituanBatchFetchPendingEntry(task),
                        status: 'exception',
                        attemptCount,
                        retryCount: Math.max(0, attemptCount - 1),
                        maxAttempts,
                        message: error.message || '请求异常',
                        error: error.message || '请求异常',
                    };
                    setOnlineDataResult([...results]);
                }
                scheduleResultUpdate();
            }));

            if (!runIsActive()) {
                if (resultUpdateTimer && typeof cancelResultUpdate === 'function') {
                    cancelResultUpdate();
                }
                resultUpdateTimer = null;
                cancelResultUpdate = null;
                return { status: 'stale', results, totalSavedCount };
            }
            flushResultUpdate();
            setSavedCount(totalSavedCount);
            const verifiedSavedCount = results.reduce((sum, item) => (
                item?.readbackVerified === true ? sum + Number(item?.savedCount || 0) : sum
            ), 0);
            const failedCount = results.filter(item => item?.error).length;
            const incompleteCount = results.filter(item => (
                item?.rankDataComplete !== true
                && !item?.error
                && !isMeituanPendingResult(item)
                && !isMeituanBackgroundResult(item)
            )).length;
            const loginFailed = results.some(item => item?.credentialStatus === 'login_required' || item?.status === 'login_required' || /未登录|登录态|Cookie|授权/.test(String(item?.error || item?.message || '')));
            if (acceptedCount > 0) {
                setFetchSuccess(true);
                setDataFetchTime(getFetchTime());
                notify(
                    acceptedCount === fetchTasks.length
                        ? `美团手动获取已提交后台执行（${acceptedCount} 个任务），完成后会更新数据列表和通知`
                        : `美团手动获取已提交 ${acceptedCount} 个后台任务，其余任务已返回结果`,
                    'info'
                );
                if (!suppressPostFetchRefresh) {
                    runPostFetchRefresh(refreshOnlineHistory);
                    if (getOnlineDataTab() === 'data') {
                        refreshOnlineData();
                    }
                }
                return { status: 'accepted', results, acceptedCount, totalSavedCount };
            }
            if (fetchTasks.length > 0 && failedCount === fetchTasks.length) {
                setFetchSuccess(false);
                setBusinessSummary(getEmptyBusinessSummary());
                notify(loginFailed ? '美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容' : `美团获取失败：${failedCount} 个任务未返回有效数据`, loginFailed ? 'error' : 'warning');
                return { status: loginFailed ? 'login_required' : 'failed', results, totalSavedCount, failedCount };
            }
            const modelRes = await requestDisplayModel(buildMeituanDisplayModelPayload({ results, form }));
            if (!runIsActive()) {
                return { status: 'stale', results, totalSavedCount };
            }
            if (modelRes.code !== 200) {
                throw new Error(modelRes.message || '构建美团展示模型失败');
            }
            const allHotels = useDisplayModel(modelRes.data || {});
            setFetchSuccess(failedCount < fetchTasks.length);
            setDataFetchTime(getFetchTime());
            updateAiAnalysisHotelList();

            if (verifiedSavedCount > 0) {
                notify(
                    failedCount + incompleteCount > 0
                        ? `已入库 ${verifiedSavedCount} 条完整榜单数据并完成数据库回读核验，但有 ${failedCount + incompleteCount} 个榜单只返回部分字段`
                        : `美团榜单已入库 ${verifiedSavedCount} 条，并完成数据库回读核验`,
                    failedCount + incompleteCount > 0 ? 'warning' : undefined
                );
                if (!suppressPostFetchRefresh) {
                    runPostFetchRefresh(refreshOnlineHistory);
                    if (getOnlineDataTab() === 'data') {
                        refreshOnlineData();
                    }
                }
            } else if (totalSavedCount > 0) {
                notify(
                    failedCount + incompleteCount > 0
                        ? `批量请求已完成，接口报告处理 ${totalSavedCount} 条；${failedCount + incompleteCount} 个榜单字段不完整，且尚未确认数据库回读`
                        : `批量请求已完成，接口报告处理 ${totalSavedCount} 条，尚未确认数据库回读`,
                    'warning'
                );
                if (!suppressPostFetchRefresh) {
                    runPostFetchRefresh(refreshOnlineHistory);
                    if (getOnlineDataTab() === 'data') {
                        refreshOnlineData();
                    }
                }
            } else if (allHotels.length > 0) {
                notify(
                    incompleteCount > 0
                        ? `平台返回了 ${allHotels.length} 家酒店的排名/百分比，但实际数值不完整，未按完整数据保存`
                        : `平台已返回 ${allHotels.length} 家酒店的可展示数据，尚未确认入库`,
                    'warning'
                );
            } else if (failedCount > 0) {
                notify(loginFailed ? '美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容' : `美团获取失败：${failedCount} 个任务未返回有效数据`, loginFailed ? 'error' : 'warning');
            } else {
                notify('请求已完成，但未解析到有效数据', 'warning');
            }
            return {
                status: failedCount + incompleteCount > 0 ? 'partial' : 'success',
                results,
                totalSavedCount,
                verifiedSavedCount,
                allHotels,
            };
        } catch (error) {
            if (!runIsActive()) {
                return { status: 'stale', results, totalSavedCount };
            }
            notify('请求失败: ' + error.message, 'error');
            return { status: 'error', error, results, totalSavedCount };
        } finally {
            if (runIsActive()) {
                setFetching(false);
            }
        }
    };

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
        sourceNotice = '',
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
        buildMeituanTopSummaryFallbackRows,
        findMeituanDynamicSelfRankRow,
        buildMeituanDisplayedHotelsList,
        resolveMeituanSortState,
        resolveMeituanTablePage,
        resolveMeituanRankSourceNotice,
        buildMeituanRankInsightCards,
        buildMeituanVisibleRankInsightCards,
        buildMeituanRankHealthRows,
        getOnlineDataMetricMaybeNumber,
        getOnlineDataMetricNumber,
        getMeituanExposureMetricValue,
        getMeituanClickMetricValue,
        getMeituanVisitorMetricValue,
        getMeituanSubmitMetricValue,
        getMeituanFlowRateMetricValue,
        getMeituanExposureMetric,
        getMeituanClickMetric,
        getMeituanVisitorMetric,
        getMeituanSubmitMetric,
        getMeituanFlowRateMetric,
        hasMeituanExposureMetric,
        hasMeituanClickMetric,
        hasMeituanVisitorMetric,
        hasMeituanFlowRateMetric,
        isMeituanOverviewDataRow,
        isMeituanTrafficDataRow,
        isMeituanOrderDataRow,
        isMeituanReviewDataRow,
        isMeituanAdsDataRow,
        buildMeituanDownloadData,
        defaultMeituanAdsUrl,
        createMeituanRankingForm,
        createMeituanTrafficForm,
        createMeituanOrderForm,
        createMeituanAdsForm,
        createMeituanBrowserCaptureForm,
        getMeituanOrderFlowPeriods,
        resolveMeituanOrderFlowDateRange,
        buildMeituanOrderFlowView,
        createEmptyMeituanBusinessSummary,
        buildMeituanRankingFetchResetState,
        resolveMeituanTopSummaryRows,
        isMeituanPendingResult,
        isMeituanBackgroundResult,
        hasMeituanPendingResults,
        hasMeituanBackgroundResults,
        buildMeituanFetchPresentation,
        applyMeituanFetchHealthToCards,
        applyMeituanFetchHealthToRows,
        shouldShowMeituanPreviousDayUpdateNotice,
        getMeituanBrowserCapturePresets,
        getMeituanBrowserCaptureSupplementModules,
        buildMeituanBrowserCaptureSupplementCounts,
        normalizeMeituanCaptureSections,
        buildMeituanCaptureTabSwitchState,
        buildMeituanBrowserCapturePresetState,
        buildMeituanBrowserCaptureDataPeriodApplyState,
        buildMeituanBrowserProfileLoginOnlyRunOptions,
        resolveMeituanBrowserCaptureSystemHotelId,
        resolveMeituanSelectedHotelConfigAction,
        buildMeituanRankingReturnTargetState,
        buildMeituanBrowserSupplementCaptureState,
        buildMeituanBrowserCaptureCopyCommandState,
        buildMeituanBrowserCaptureClearPayloadState,
        buildMeituanBrowserCaptureConfigSyncState,
        buildMeituanBrowserCaptureRunSectionsState,
        buildMeituanBrowserCaptureSelectedSectionsText,
        buildMeituanBrowserCaptureCommand,
        buildMeituanBrowserCaptureReadinessNotice,
        buildMeituanBrowserCaptureRequestContext,
        runMeituanBrowserCaptureFlow,
        buildMeituanCapturedPayloadSaveContext,
        runMeituanCapturedPayloadSaveFlow,
        resolveMeituanExecutionConfigId,
        validateMeituanBatchFetchInput,
        buildMeituanBatchFetchTasks,
        buildMeituanBatchFetchResultEntry,
        buildMeituanDisplayModelPayload,
        normalizeMeituanCookieText,
        resolveMeituanConfigSaveCookieState,
        buildMeituanConfigAutoName,
        buildMeituanConfigSaveRequestBody,
        resolveMeituanConfigSaveRequestHotelId,
        createEmptyMeituanConfigForm,
        buildMeituanConfigDeleteUrl,
        buildMeituanConfigDeleteSuccessState,
        buildMeituanConfigDeleteFailureState,
        resolveSavedMeituanConfigHotelId,
        resolveMeituanConfigSaveToastLevel,
        buildMeituanConfigSaveSuccessState,
        buildMeituanConfigSaveFailureState,
        buildMeituanRankingFormPatchFromConfig,
        buildMeituanConfigUseState,
        buildMeituanConfigEditForm,
        buildMeituanConfigEditState,
        buildMeituanBookmarkletSuccessState,
        buildMeituanBookmarkletFailureState,
        isMeituanRankingFormAlignedWithConfig,
        findMeituanConfigForHotel,
        resolveMeituanConfigStatus,
        isMeituanExecutionConfigReady,
        buildMeituanManualCredentialState,
        resolveCanFetchMeituanRankingData,
        resolveMeituanManualFetchConfigProofPending,
        resolveMeituanManualFetchConfigCandidate,
        resolveMeituanConfigListResponse,
        resolveMeituanConfigListApplyAction,
        resolveMeituanConfigListCachedResult,
        resolveMeituanConfigListLoadingAction,
        buildMeituanConfigListSuccessState,
        buildMeituanConfigListFailureAction,
        buildMeituanConfigListStartState,
        buildMeituanConfigListFinishState,
        getMeituanConfigDetailVersion,
        buildMeituanConfigDetailCacheKey,
        resolveMeituanConfigDetailClearTarget,
        resolveMeituanConfigDetailLoadTarget,
        buildMeituanConfigDetailRequestUrl,
        resolveMeituanConfigDetailResponse,
        shouldSkipMeituanConfigDetailLoad,
        resolveMeituanConfigDetailCachedResult,
        resolveMeituanConfigDetailCacheLookup,
        buildMeituanConfigDetailCacheEntry,
        resolveMeituanConfigDetailCacheStorePlan,
        resolveMeituanConfigDetailFailureAction,
        resolveMeituanConfigDetailPrewarmPlan,
        resolveMeituanManualDefaultHotelIdFromState,
        meituanFallbackMetricNumber,
        meituanFallbackHasPositiveMetric,
        meituanFallbackSum,
        meituanFallbackAverage,
        meituanFallbackHhi,
        meituanFallbackPriceSigma,
        meituanFallbackRankHealth,
        meituanFallbackPlatformTags,
        meituanFallbackMarketPriceSignal,
        meituanFallbackNumberText,
        meituanFallbackMoneyText,
        meituanFallbackPercentText,
        meituanFallbackMetricText,
        meituanFallbackCard,
        resolveMeituanFallbackMarketInventory,
        buildMeituanBusinessSummaryFallbackCards,
        resolveMeituanBusinessSummaryCards,
        runMeituanManualTabSwitch,
        normalizeMeituanTrafficFetchForm,
        validateMeituanTrafficFetchInput,
        buildMeituanTrafficFetchRequestBody,
        runMeituanTrafficFetchFlow,
        normalizeMeituanOrderFetchForm,
        validateMeituanOrderFetchInput,
        buildMeituanOrderFetchRequestBody,
        buildMeituanOrderDomCollectorScript,
        parseMeituanOrderCsvText,
        buildMeituanOrderCsvImportRequestBody,
        runMeituanOrderCsvImportFlow,
        runMeituanOrderFetchFlow,
        normalizeMeituanAdsFetchForm,
        validateMeituanAdsFetchInput,
        buildMeituanAdsFetchRequestBody,
        runMeituanAdsFetchFlow,
        runMeituanBatchFetchFlow,
        buildMeituanRankDisplayRows,
        buildCompetitorSummaryCoreCards,
        buildHomeCompetitorSummaryCards,
    };
})();
