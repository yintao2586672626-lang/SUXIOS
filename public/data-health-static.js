window.SUXI_DATA_HEALTH_STATIC = (() => {
    const onlineDataQualityStatusText = (quality) => {
        const status = quality?.status || 'ok';
        if (status === 'error') return '异常';
        if (status === 'warning') return '需复核';
        return '完整';
    };

    const onlineDataQualityStatusClass = (quality) => {
        const status = quality?.status || 'ok';
        if (status === 'error') return 'bg-red-50 text-red-700 border-red-200';
        if (status === 'warning') return 'bg-amber-50 text-amber-700 border-amber-200';
        return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    };

    const onlineDataQualityPromptList = (quality, limit = 3) => {
        const prompts = quality?.prompts || quality?.top_prompts || [];
        return Array.isArray(prompts) ? prompts.filter(Boolean).slice(0, limit) : [];
    };

    const onlineDataQualityScopeText = (quality) => {
        if (!quality) return '质量摘要未加载。';
        const scope = quality.calculation_scope || 'selected_rows';
        const sampleSize = Number(quality.sample_size ?? quality.checked_records ?? 0);
        const totalRecords = Number(quality.total_records ?? sampleSize);
        const page = Number(quality.page || 1);
        if (scope === 'current_page') {
            return `质量摘要仅统计当前第 ${page} 页 ${sampleSize} 条样本，筛选范围共 ${totalRecords} 条，不是全量质量结论。`;
        }
        return `质量摘要统计已加载样本 ${sampleSize} 条。`;
    };

    const autoFetchRecordStatusClass = (status) => {
        if (status === 'success') return 'bg-green-100 text-green-700';
        if (status === 'skipped') return 'bg-gray-200 text-gray-600';
        if (status === 'running') return 'bg-blue-100 text-blue-700';
        if (status === 'pending') return 'bg-amber-100 text-amber-700';
        return 'bg-red-100 text-red-700';
    };

    const manualOneClickFetchPlatformText = platform => (platform === 'ctrip' ? '携程' : '美团');

    const manualOneClickFetchNowText = (date = new Date()) => date.toLocaleString('zh-CN', { hour12: false });

    const normalizeManualOneClickFetchStoredRows = (rows = []) => {
        return (Array.isArray(rows) ? rows : [])
            .filter(row => row && typeof row === 'object')
            .map(row => {
                const normalized = { ...row };
                const status = String(normalized.status || '').trim();
                if (['running', 'queued'].includes(status)) {
                    normalized.status = 'failed';
                    normalized.statusText = '未完成';
                    normalized.message = normalized.message || '页面刷新前该项未完成，可重新获取。';
                }
                normalized.savedCount = Number(normalized.savedCount || 0);
                normalized.existingCount = Number(normalized.existingCount || 0);
                normalized.retryCount = Number(normalized.retryCount || 0);
                normalized.qunarVisitorTotal = Number(normalized.qunarVisitorTotal || 0);
                return normalized;
            });
    };

    const summarizeManualOneClickFetchRows = (rows = []) => {
        return (Array.isArray(rows) ? rows : []).reduce((summary, row) => {
            if (row.status === 'success') summary.savedHotels += 1;
            if (row.status === 'no_saved') summary.noSaved += 1;
            if (row.status === 'failed') summary.failed += 1;
            if (row.status === 'skipped') summary.skipped += 1;
            if (row.status === 'running' || row.status === 'queued') summary.pending += 1;
            if (row.status === 'success') summary.savedCount += Number(row.savedCount || 0);
            return summary;
        }, {
            savedHotels: 0,
            noSaved: 0,
            failed: 0,
            skipped: 0,
            pending: 0,
            savedCount: 0,
        });
    };

    const buildManualOneClickFetchCards = ({
        ctripReadyCount = 0,
        meituanReadyCount = 0,
        summary = {},
        lastRunAt = '',
    } = {}) => {
        const safeSummary = {
            noSaved: Number(summary.noSaved || 0),
            failed: Number(summary.failed || 0),
            pending: Number(summary.pending || 0),
            skipped: Number(summary.skipped || 0),
            savedCount: Number(summary.savedCount || 0),
        };
        const ctripCount = Number(ctripReadyCount || 0);
        const meituanCount = Number(meituanReadyCount || 0);
        return [
            {
                key: 'ctrip-ready',
                label: '携程Cookie配置',
                value: `${ctripCount}`,
                rawValue: String(ctripCount),
                detail: '有Cookie不等于授权有效',
                className: ctripCount ? 'text-blue-700' : 'text-gray-900',
            },
            {
                key: 'meituan-ready',
                label: '美团完整配置',
                value: `${meituanCount}`,
                rawValue: String(meituanCount),
                detail: 'Cookie + partner/poi',
                className: meituanCount ? 'text-emerald-700' : 'text-gray-900',
            },
            {
                key: 'not-saved',
                label: '未入库/失败',
                value: `${safeSummary.noSaved + safeSummary.failed}`,
                rawValue: String(safeSummary.noSaved + safeSummary.failed),
                detail: safeSummary.pending ? `${safeSummary.pending} 个执行中` : (safeSummary.skipped ? `${safeSummary.skipped} 个已入库跳过` : `${safeSummary.noSaved} 个未入库 / ${safeSummary.failed} 个失败`),
                className: safeSummary.noSaved || safeSummary.failed ? 'text-amber-700' : 'text-gray-900',
            },
            {
                key: 'saved',
                label: '本次入库',
                value: `${safeSummary.savedCount}`,
                rawValue: String(safeSummary.savedCount),
                detail: lastRunAt || '尚未执行',
                className: safeSummary.savedCount ? 'text-emerald-700' : 'text-gray-900',
            },
        ];
    };

    const buildManualOneClickFetchEmptyText = ({
        running = '',
        ctripReadyCount = 0,
        meituanReadyCount = 0,
    } = {}) => {
        if (running) return '正在执行手动一键获取...';
        if (!Number(ctripReadyCount || 0) && !Number(meituanReadyCount || 0)) {
            return '暂无可手动获取的门店。请先在平台账号里为门店配置携程或美团 Cookie/API。';
        }
        return '还没有执行记录。选择上方按钮后，会按已配置门店逐个获取并写入数据库。';
    };

    const manualOneClickFetchStatusClass = (status) => ({
        running: 'border-blue-100 bg-blue-50 text-blue-700',
        queued: 'border-amber-100 bg-amber-50 text-amber-700',
        success: 'border-emerald-100 bg-emerald-50 text-emerald-700',
        no_saved: 'border-amber-100 bg-amber-50 text-amber-700',
        failed: 'border-red-100 bg-red-50 text-red-700',
        skipped: 'border-gray-200 bg-gray-50 text-gray-600',
    }[String(status || '')] || 'border-gray-200 bg-gray-50 text-gray-600');

    const manualOneClickFetchActionableStatus = (status = '') => ['failed', 'no_saved'].includes(String(status || '').trim());

    const manualOneClickFetchRowHasHotel = (row = {}) => Boolean(String(row?.hotelId || '').trim());

    const manualOneClickFetchCanEditRow = (row = {}, canManage = false) => Boolean(canManage)
        && manualOneClickFetchActionableStatus(row?.status);

    const manualOneClickFetchCanRetryRow = (row = {}) => manualOneClickFetchRowHasHotel(row)
        && manualOneClickFetchActionableStatus(row?.status);

    const manualOneClickFetchCanDeleteRow = (row = {}, canManage = false) => Boolean(canManage)
        && manualOneClickFetchRowHasHotel(row)
        && manualOneClickFetchActionableStatus(row?.status);

    const manualOneClickFetchCanSupplementRow = (row = {}, canManage = false) => manualOneClickFetchCanDeleteRow(row, canManage);

    const sortManualOneClickFetchRows = (rows = []) => {
        const statusRank = {
            failed: 0,
            no_saved: 1,
            running: 2,
            queued: 3,
            skipped: 4,
            success: 5,
        };
        return [...(Array.isArray(rows) ? rows : [])].sort((left, right) => {
            const rankDiff = (statusRank[String(left?.status || '')] ?? 9) - (statusRank[String(right?.status || '')] ?? 9);
            if (rankDiff !== 0) return rankDiff;
            return String(right?.timeText || '').localeCompare(String(left?.timeText || ''));
        });
    };

    const manualOneClickFetchMessageIsQunarVisitorZero = (message = '') => /去哪儿?访客.*(?:为|=)?\s*0|qunar.*visitor.*0/i.test(String(message || ''));

    const manualOneClickFetchHasQunarVisitorZeroFailureInRows = ({
        rows = [],
        platform = '',
        hotelId = '',
    } = {}) => {
        const wantedPlatform = platform === 'meituan' ? 'meituan' : 'ctrip';
        const wantedHotelId = String(hotelId || '').trim();
        if (wantedPlatform !== 'ctrip' || !wantedHotelId) return false;
        return (Array.isArray(rows) ? rows : []).some(row => {
            return (row?.platform === 'meituan' ? 'meituan' : 'ctrip') === wantedPlatform
                && String(row?.hotelId || '').trim() === wantedHotelId
                && manualOneClickFetchMessageIsQunarVisitorZero(row?.message);
        });
    };

    const findManualOneClickFetchExistingStoredRow = ({
        rows = [],
        hotelId = '',
    } = {}) => {
        const id = String(hotelId || '').trim();
        if (!id) return null;
        return (Array.isArray(rows) ? rows : []).find(row => String(row?.hotelId || '').trim() === id && Number(row?.sourceRows || 0) > 0) || null;
    };

    const buildManualOneClickFetchTasks = ({
        platform = 'all',
        ctripHotels = [],
        meituanHotels = [],
        storedRows = [],
        hasCtripQunarVisitorZeroFailure = () => false,
    } = {}) => {
        const normalizedPlatform = ['ctrip', 'meituan'].includes(platform) ? platform : 'all';
        const tasks = [];
        if (normalizedPlatform === 'all' || normalizedPlatform === 'ctrip') {
            (Array.isArray(ctripHotels) ? ctripHotels : []).forEach(hotel => tasks.push({
                platform: 'ctrip',
                hotel,
                existingStoredRow: hasCtripQunarVisitorZeroFailure(hotel?.id)
                    ? null
                    : findManualOneClickFetchExistingStoredRow({ rows: storedRows, hotelId: hotel?.id }),
            }));
        }
        if (normalizedPlatform === 'all' || normalizedPlatform === 'meituan') {
            (Array.isArray(meituanHotels) ? meituanHotels : []).forEach(hotel => tasks.push({
                platform: 'meituan',
                hotel,
                existingStoredRow: findManualOneClickFetchExistingStoredRow({ rows: storedRows, hotelId: hotel?.id }),
            }));
        }
        return tasks;
    };

    const buildManualOneClickFetchBaseRow = ({
        platform = '',
        hotel = {},
        runId = '',
        rowKey = '',
        status = 'queued',
        statusText = '待获取',
        message = '等待执行手动获取',
        savedCount = 0,
        existingCount = 0,
        getHotelNameById = () => '',
        nowText = manualOneClickFetchNowText(),
    } = {}) => {
        const normalizedPlatform = platform === 'meituan' ? 'meituan' : 'ctrip';
        const hotelId = String(hotel?.id || '').trim();
        const hotelName = String(hotel?.name || hotel?.hotel_name || getHotelNameById(hotelId) || hotelId || '未命名门店');
        return {
            key: rowKey || `${runId}:${normalizedPlatform}:${hotelId}`,
            platform: normalizedPlatform,
            platformText: manualOneClickFetchPlatformText(normalizedPlatform),
            hotelId,
            hotelName,
            status,
            statusText,
            message,
            savedCount: Number(savedCount || 0),
            existingCount: Number(existingCount || 0),
            timeText: nowText,
        };
    };

    const buildManualOneClickFetchRunningRow = ({
        baseRow = {},
        isRetryAttempt = false,
        savedCount = 0,
        retryCount = 0,
        retryLimit = 0,
        nowText = manualOneClickFetchNowText(),
    } = {}) => ({
        ...baseRow,
        status: 'running',
        statusText: isRetryAttempt ? '重抓中' : '获取中',
        message: isRetryAttempt
            ? `去哪儿访客仍为 0，自动重抓 ${Number(retryCount || 0)}/${Number(retryLimit || 0)}`
            : '正在调用手动获取接口',
        savedCount: Number(savedCount || 0),
        retryCount: Number(retryCount || 0),
        timeText: nowText,
    });

    const buildManualOneClickFetchResultRow = ({
        baseRow = {},
        resultSummary = {},
        savedCount = 0,
        retryCount = 0,
        attemptCount = 0,
        ctripQunarQuality = null,
        nowText = manualOneClickFetchNowText(),
    } = {}) => ({
        ...baseRow,
        status: resultSummary.status,
        statusText: resultSummary.statusText,
        message: resultSummary.message,
        savedCount: Number(savedCount || 0),
        retryCount: Number(retryCount || 0),
        attemptCount: Number(attemptCount || 0),
        qunarVisitorTotal: ctripQunarQuality ? ctripQunarQuality.total : undefined,
        qunarVisitorIncomplete: resultSummary.qunarVisitorIncomplete,
        timeText: nowText,
    });

    const buildManualOneClickFetchFailureRow = ({
        baseRow = {},
        error = null,
        nowText = manualOneClickFetchNowText(),
    } = {}) => ({
        ...baseRow,
        status: 'failed',
        statusText: '失败',
        message: error?.message || '手动获取失败',
        savedCount: 0,
        timeText: nowText,
    });

    const manualOneClickFetchQunarVisitorNumber = (row = {}) => {
        const candidates = [
            row?.qunarDetailVisitors,
            row?.qunar_detail_visitors,
            row?.views,
            row?.uv,
            row?.visitorCount,
            row?.detailUv,
        ];
        for (const candidate of candidates) {
            if (candidate === null || candidate === undefined || String(candidate).trim() === '') continue;
            const value = Number(candidate);
            if (Number.isFinite(value) && value >= 0) return value;
        }
        return 0;
    };

    const summarizeManualOneClickFetchQunarVisitorQuality = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        const total = safeRows.reduce((sum, row) => sum + Math.max(0, manualOneClickFetchQunarVisitorNumber(row)), 0);
        return {
            rowCount: safeRows.length,
            total,
            ready: safeRows.length > 0 && total > 0,
        };
    };

    const manualOneClickFetchQunarVisitorNeedsRetry = () => false;

    const manualOneClickFetchSavedCount = (result = {}) => {
        const candidates = [
            result?.saved_count,
            result?.savedCount,
            result?.totalSavedCount,
            result?.data?.saved_count,
            result?.data?.savedCount,
            result?.data?.totalSavedCount,
            result?.data?.summary?.saved_count,
            result?.data?.summary?.savedCount,
            result?.response?.data?.saved_count,
            result?.response?.data?.savedCount,
            result?.total_saved,
            result?.data?.total_saved,
            result?.response?.data?.total_saved,
        ];
        const value = candidates.map(item => Number(item)).find(item => Number.isFinite(item) && item >= 0);
        return Number.isFinite(value) ? value : 0;
    };

    const manualOneClickFetchResultMessage = (result = {}) => {
        const directMessage = String(result?.message || result?.data?.message || result?.response?.message || result?.msg || result?.data?.msg || result?.response?.msg || '').trim();
        if (directMessage) return directMessage;
        const resultRows = Array.isArray(result?.results) ? result.results : [];
        const failures = resultRows
            .map(row => {
                const label = String(row?.rankName || row?.dateRangeName || row?.rankType || '').trim();
                const message = String(row?.error || row?.message || row?.businessMessage || row?.credentialStatus || row?.status || '').trim();
                const code = row?.businessCode || row?.httpCode || row?.http_code || '';
                return message ? `${label ? `${label}: ` : ''}${message}${code ? ` (${code})` : ''}` : '';
            })
            .filter(Boolean);
        return failures.slice(0, 3).join('；');
    };

    const summarizeManualOneClickFetchResult = ({
        platform = '',
        result = {},
        savedCount = 0,
        ctripQunarQuality = null,
        qunarRetryCount = 0,
        qunarVisitorNeedsRetry = false,
    } = {}) => {
        const normalizedPlatform = platform === 'ctrip' ? 'ctrip' : 'meituan';
        const count = Number(savedCount || 0);
        const retryCount = Number(qunarRetryCount || 0);
        const responseStatus = String(result?.status || result?.data?.status || '').toLowerCase();
        const responseCode = Number(result?.code ?? result?.data?.code ?? 0);
        const ctripRowsReturned = normalizedPlatform === 'ctrip' && Number(ctripQunarQuality?.rowCount || 0) > 0;
        const qunarVisitorIncomplete = normalizedPlatform === 'ctrip'
            && ctripRowsReturned
            && Boolean(ctripQunarQuality)
            && ctripQunarQuality.ready !== true;
        let status = 'failed';
        if (normalizedPlatform === 'ctrip' && count > 0) {
            status = 'success';
        } else if (normalizedPlatform === 'ctrip' && ctripRowsReturned && (['success', 'ok', 'partial_qunar_visitor_gap'].includes(responseStatus) || responseCode === 200)) {
            status = 'success';
        } else if (count > 0) {
            status = 'success';
        } else if (['failed', 'error', 'invalid_request', 'login_required', 'not_logged_in'].includes(responseStatus)) {
            status = 'failed';
        } else if (['accepted', 'queued', 'running'].includes(responseStatus)) {
            status = 'queued';
        } else if (['success', 'ok'].includes(responseStatus) || responseCode === 200) {
            status = 'no_saved';
        }

        const statusText = status === 'queued'
            ? '已提交'
            : (status === 'success' ? '已入库' : (status === 'no_saved' ? '未入库' : '失败'));
        const responseMessage = manualOneClickFetchResultMessage(result);
        const retryText = normalizedPlatform === 'ctrip' && retryCount > 0 ? `，已自动重抓 ${retryCount} 次` : '';
        const ctripSavedText = count > 0 ? `本次入库 ${count} 条` : '本次未新增入库';
        let message = '';

        if (normalizedPlatform === 'ctrip' && qunarVisitorIncomplete) {
            message = `携程竞争圈已返回${count > 0 ? `并入库 ${count} 条` : ''}；去哪儿访客为 0 仅作为字段缺口提示，不阻断携程补采成功。`;
        } else if (normalizedPlatform === 'ctrip' && status === 'success') {
            message = `手动获取完成：携程和去哪儿均已返回${retryText}；${ctripSavedText}；去哪儿访客 ${ctripQunarQuality ? ctripQunarQuality.total : '未校验'}。`;
        } else if (normalizedPlatform === 'ctrip' && !ctripRowsReturned) {
            message = '携程竞争圈未返回可展示行，不能按成功处理。';
        } else if (status === 'no_saved') {
            message = `${responseMessage || '接口调用完成'}；本次入库 0 条，不等于入库成功`;
        } else if (responseMessage) {
            message = responseMessage;
        } else if (status === 'queued') {
            message = '接口已提交后台，暂未确认入库';
        } else if (status === 'success') {
            message = `手动获取并入库完成；竞争圈来源可能少于 26 条，已按实际返回 ${count} 条入库`;
        } else {
            message = '手动获取失败';
        }

        return {
            status,
            statusText,
            message,
            ctripRowsReturned,
            qunarVisitorIncomplete,
        };
    };

    const buildOnlineHistoryQueryParams = ({ page = 1, pageSize = 20, filter = {} } = {}) => {
        const params = new URLSearchParams({
            page: String(page || 1),
            page_size: String(pageSize || 20),
        });
        const currentFilter = filter || {};
        if (currentFilter.platform && currentFilter.platform !== 'all') {
            params.append('platform', currentFilter.platform);
        }
        if (currentFilter.data_type && currentFilter.data_type !== 'all') {
            params.append('data_type', currentFilter.data_type);
        }
        if (currentFilter.hotel_scope) {
            const hotelScope = String(currentFilter.hotel_scope);
            if (['all', 'mine', 'competitor_avg'].includes(hotelScope)) {
                params.append('hotel_scope', hotelScope);
            } else {
                params.append('hotel_scope', 'hotel');
                params.append('hotel_id', hotelScope);
            }
        }
        if (currentFilter.keyword) {
            params.append('keyword', currentFilter.keyword);
        }
        if (currentFilter.start_date) {
            params.append('start_date', currentFilter.start_date);
        }
        if (currentFilter.end_date) {
            params.append('end_date', currentFilter.end_date);
        }
        return params;
    };

    const isDirtyQuestionMarkText = (text) => {
        const value = String(text || '').replace(/\s+/g, '');
        if (!value) return false;
        const count = (value.match(/\?/g) || []).length;
        return count >= 4 && count / Math.max(1, value.length) >= 0.35;
    };

    const formatOnlineHistoryHotelOption = (hotel) => {
        const id = hotel?.id ? String(hotel.id) : '';
        const name = String(hotel?.name || '').trim();
        if (!id || !name || isDirtyQuestionMarkText(name)) {
            return null;
        }
        const otaHotelId = String(hotel?.ota_hotel_id || hotel?.platform_hotel_id || hotel?.hotel_id || '').trim();
        const code = String(hotel?.code || '').trim();
        const suffix = otaHotelId || code;
        return {
            value: id,
            label: suffix ? `${name} (${suffix})` : name,
            ota_hotel_id: otaHotelId,
        };
    };

    const formatOnlineHistoryRaw = (raw) => {
        if (!raw) return '\u65e0\u539f\u59cb\u6570\u636e';
        try {
            const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
            return JSON.stringify(parsed, null, 2);
        } catch (error) {
            return String(raw);
        }
    };

    const buildHotelDataDashboardRequests = ({ selectedHotelId = '', days = 30 } = {}) => {
        const accountParams = new URLSearchParams();
        accountParams.append('days', String(days || 30));
        const portraitParams = new URLSearchParams(accountParams);
        const sourceParams = new URLSearchParams(accountParams);
        if (selectedHotelId) {
            portraitParams.append('hotel_id', selectedHotelId);
        }

        return {
            accountOverviewUrl: `/dashboard/account-overview?${accountParams.toString()}`,
            hotelPortraitUrl: `/dashboard/hotel-portrait?${portraitParams.toString()}`,
            dataSourcesUrl: `/dashboard/data-sources?${sourceParams.toString()}`,
        };
    };

    const collectionHealthCookieLightClass = (row) => ({
        green: 'bg-green-500',
        red: 'bg-red-500',
    }[String(row?.light_status || (row?.is_usable ? 'green' : 'red')).toLowerCase()] || 'bg-red-500');

    const collectionHealthCookieLightText = (row) => row?.light_label || (row?.is_usable ? '可用' : '不可用');

    const dataHealthNormalizeStatus = (status) => {
        const value = String(status || '').toLowerCase();
        if (['ok', 'success'].includes(value)) return 'ok';
        if (['expired', 'failed', 'error', 'auth_failed', 'request_failed'].includes(value)) return 'failed';
        if (['warning', 'partial_success', 'unknown'].includes(value)) return 'warning';
        if (['waiting_config', 'not_collected', 'missing_file'].includes(value)) return 'waiting_config';
        return value || 'unknown';
    };

    const dataHealthPriorityClass = (priority = 'medium') => ({
        high: 'bg-red-50 text-red-700 border-red-200',
        medium: 'bg-amber-50 text-amber-700 border-amber-200',
        low: 'bg-blue-50 text-blue-700 border-blue-200',
        ok: 'bg-green-50 text-green-700 border-green-200',
    }[priority] || 'bg-gray-50 text-gray-600 border-gray-200');

    const dataHealthPriorityText = (priority = 'medium') => ({
        high: '高优先级',
        medium: '中优先级',
        low: '低优先级',
        ok: '正常',
    }[priority] || '待确认');

    const dataHealthPlatformText = (platform = '') => ({
        ctrip: '携程',
        meituan: '美团',
        qunar: '去哪儿',
    }[String(platform || '').toLowerCase()] || (platform || 'OTA'));

    const platformBatchHealthBadgeClass = (level) => ({
        ok: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        medium: 'bg-amber-50 text-amber-700 border-amber-200',
        high: 'bg-red-50 text-red-700 border-red-200',
        unknown: 'bg-gray-50 text-gray-500 border-gray-200',
    }[level] || 'bg-gray-50 text-gray-500 border-gray-200');

    const platformBatchHealthSourceHotelId = (source) => String(source?.system_hotel_id || source?.hotel_id || '').trim();
    const platformBatchHealthSourceActive = (source) => source?.enabled !== false && Number(source?.enabled ?? 1) !== 0 && String(source?.status || '') !== 'disabled';
    const platformBatchHealthSourceTime = (source) => String(source?.last_sync_time || source?.last_capture_time || source?.update_time || '').trim();

    const buildPlatformBatchHealthRows = ({
        hotelPool = [],
        platformDataSources = [],
        hotelCompetitorSummaries = {},
        getHotelNameById = () => '',
        competitorSummaryReadiness = () => ({}),
        hotelCompetitorSummaryMeta = () => '',
    } = {}) => {
        const safeHotelName = typeof getHotelNameById === 'function' ? getHotelNameById : () => '';
        const safeCompetitorReadiness = typeof competitorSummaryReadiness === 'function' ? competitorSummaryReadiness : () => ({});
        const safeCompetitorMeta = typeof hotelCompetitorSummaryMeta === 'function' ? hotelCompetitorSummaryMeta : () => '';
        const sources = (Array.isArray(platformDataSources) ? platformDataSources : [])
            .filter(platformBatchHealthSourceActive);
        const sourceMap = new Map();
        for (const source of sources) {
            const hotelId = platformBatchHealthSourceHotelId(source);
            if (!hotelId) continue;
            if (!sourceMap.has(hotelId)) sourceMap.set(hotelId, []);
            sourceMap.get(hotelId).push(source);
        }

        return (Array.isArray(hotelPool) ? hotelPool : [])
            .filter(hotel => hotel && hotel.id)
            .slice(0, 50)
            .map((hotel) => {
                const hotelId = String(hotel.id || '').trim();
                const hotelName = hotel.name || hotel.hotel_name || safeHotelName(hotelId) || `酒店 ${hotelId}`;
                const hotelSources = sourceMap.get(hotelId) || [];
                const failedSource = hotelSources.find(source => String(source.last_sync_status || source.status || '') === 'failed');
                const partialSource = hotelSources.find(source => String(source.last_sync_status || source.status || '') === 'partial_success');
                const readySource = hotelSources.find(source => ['success', 'ready'].includes(String(source.last_sync_status || source.status || '')));
                const profileCount = hotelSources.filter(source => String(source.ingestion_method || '') === 'browser_profile').length;
                const apiCount = hotelSources.filter(source => String(source.ingestion_method || '') === 'api').length;
                const latestSyncTime = hotelSources
                    .map(platformBatchHealthSourceTime)
                    .filter(Boolean)
                    .sort()
                    .pop() || '';

                let bindingLevel = 'unknown';
                let bindingText = '待绑定';
                let bindingDetail = '未发现该门店的有效平台数据源';
                if (hotelSources.length > 0) {
                    bindingLevel = profileCount > 0 || apiCount > 0 ? 'ok' : 'medium';
                    bindingText = profileCount > 0 || apiCount > 0 ? '已绑定' : '仅手工/导入';
                    bindingDetail = `Profile ${profileCount} / API ${apiCount} / 数据源 ${hotelSources.length}`;
                }

                let collectionLevel = 'unknown';
                let collectionText = '未采集';
                let collectionDetail = '暂无最近采集证据';
                if (failedSource) {
                    collectionLevel = 'high';
                    collectionText = '采集失败';
                    collectionDetail = failedSource.last_error || failedSource.message || '最近同步失败，需查看同步日志';
                } else if (partialSource) {
                    collectionLevel = 'medium';
                    collectionText = '部分模块成功';
                    collectionDetail = partialSource.last_error || latestSyncTime || '有模块成功，但仍有模块缺失或未入库，需复核字段和日志';
                } else if (readySource || latestSyncTime) {
                    collectionLevel = 'ok';
                    collectionText = '已采集';
                    collectionDetail = latestSyncTime || '有成功状态，但未返回采集时间';
                } else if (hotelSources.length > 0) {
                    collectionLevel = 'medium';
                    collectionText = '待试采';
                    collectionDetail = '已绑定数据源，暂无试采集结果';
                }

                const competitorSummaryForHotel = hotelCompetitorSummaries?.[hotelId] || null;
                const competitorReadiness = safeCompetitorReadiness(competitorSummaryForHotel, hotel) || {};
                const competitorDetail = competitorReadiness.detail || safeCompetitorMeta(hotel);
                const competitorOk = ['ok', 'success'].includes(String(competitorReadiness.status || ''));

                let actionLevel = 'ok';
                let nextAction = '暂无处理动作';
                if (!hotelSources.length) {
                    actionLevel = 'medium';
                    nextAction = '配置平台账号绑定';
                } else if (failedSource) {
                    actionLevel = 'high';
                    nextAction = '查看同步日志并重试采集';
                } else if (collectionLevel === 'medium') {
                    actionLevel = 'medium';
                    nextAction = '执行一次试采集';
                } else if (!competitorOk) {
                    actionLevel = competitorReadiness.status === 'missing' ? 'medium' : 'high';
                    nextAction = competitorReadiness.next_action || '复核竞对榜单';
                }

                return {
                    key: `platform-batch-health-${hotelId}`,
                    hotelId,
                    hotelName,
                    bindingLevel,
                    bindingText,
                    bindingDetail,
                    collectionLevel,
                    collectionText,
                    collectionDetail,
                    competitorReadiness,
                    competitorDetail,
                    nextAction,
                    actionLevel,
                    evidenceText: latestSyncTime ? `最近采集 ${latestSyncTime}` : '缺少最近采集证据',
                };
            });
    };

    const buildPlatformBatchHealthSummaryCards = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        const unbound = safeRows.filter(row => row.bindingLevel !== 'ok').length;
        const collectionIssues = safeRows.filter(row => row.collectionLevel !== 'ok').length;
        const competitorIssues = safeRows.filter(row => !['ok', 'success'].includes(String(row.competitorReadiness?.status || ''))).length;
        const highActions = safeRows.filter(row => row.actionLevel === 'high').length;
        return [
            { key: 'hotels', label: '体检门店', value: safeRows.length, badge: safeRows.length ? '已加载' : '无门店', level: safeRows.length ? 'ok' : 'unknown' },
            { key: 'binding', label: '绑定待处理', value: unbound, badge: unbound ? '待处理' : '正常', level: unbound ? 'medium' : 'ok' },
            { key: 'collection', label: '采集待处理', value: collectionIssues, badge: collectionIssues ? '待试采' : '正常', level: collectionIssues ? 'medium' : 'ok' },
            { key: 'competitor', label: '竞对待复核', value: competitorIssues + highActions, badge: highActions ? '高优先' : (competitorIssues ? '待复核' : '正常'), level: highActions ? 'high' : (competitorIssues ? 'medium' : 'ok') },
        ];
    };

    const collectionHealthAuthorizationPlatformText = (platform) => {
        const normalized = String(platform || '').trim().toLowerCase();
        if (['ctrip', 'meituan', 'qunar'].includes(normalized)) return dataHealthPlatformText(normalized);
        return normalized ? '未识别 OTA 平台' : 'OTA 平台';
    };
    const collectionHealthAuthorizationMachineText = (value) => /[a-z]+[_-][a-z]+|\/api\/|https?:|[{}[\]=]/i.test(String(value || ''));
    const collectionHealthAuthorizationMessageText = (row = {}) => {
        const raw = String(row?.message || '').trim();
        const status = String(row?.status || '').trim().toLowerCase();
        const actionHint = String(row?.action_hint || '').trim();
        const text = `${raw} ${status} ${actionHint}`;
        if (row?.is_usable || ['ok', 'ready', 'valid', 'success', 'usable', 'active', 'logged_in'].includes(status)) {
            return '登录态可用，仍以目标日入库行为采集证明';
        }
        if (['waiting_config', 'missing', 'unbound', 'not_configured', 'profile_missing'].includes(status) || /未配置|缺失|待补|missing|unbound|not[_-]?configured|waiting[_-]?config/i.test(text)) {
            return '登录或 Cookie/API 辅助配置待补齐';
        }
        if (['expired', 'failed', 'auth_failed', 'invalid', 'unauthorized', 'forbidden', 'login_required', 'blocked'].includes(status) || /cookie|login|auth|401|403|unauthorized|forbidden|expired|invalid|登录|授权|过期|失效|异常/i.test(text)) {
            return '登录态或 Cookie/API 辅助异常，需要重新登录/更新后再采集';
        }
        if (raw && !collectionHealthAuthorizationMachineText(raw)) return raw;
        return '登录态待确认';
    };
    const collectionHealthAuthorizationActionHintText = (row = {}) => {
        const raw = String(row?.action_hint || '').trim();
        const text = `${raw} ${row?.message || ''} ${row?.status || ''}`;
        if (row?.is_usable || /ready|ok|success|usable|valid|已登录|可用|正常/i.test(text)) {
            return '可作为临时 Cookie/API 上下文，仍需目标日入库证明';
        }
        if (/missing|unbound|not[_-]?configured|waiting[_-]?config|未配置|缺失|待补/i.test(text)) {
            return '补齐登录或 Cookie/API 辅助配置';
        }
        if (/delete|remove|expired|failed|invalid|401|403|unauthorized|forbidden|cookie|login|auth|删除|重新|登录|授权|过期|失效/i.test(text)) {
            return '重新登录或清理失效记录';
        }
        if (raw && !collectionHealthAuthorizationMachineText(raw)) return raw;
        return '待复核';
    };

    const collectionHealthFailureTypeText = (type) => {
        const raw = String(type || '').trim();
        const normalized = raw.toLowerCase();
        const map = {
            authorization: '登录/Cookie',
            auth: '登录/Cookie',
            cookie: '授权 Cookie',
            collection: '采集请求',
            capture: '采集请求',
            browser_profile: '浏览器 Profile',
            data_quality: '数据质量',
            field_missing: '字段缺失',
            request_failed: '请求失败',
            etl: '标准事实层',
            metric: '指标计算',
            unknown: '待确认',
        };
        if (map[normalized]) return map[normalized];
        return raw ? '未识别类型' : '待确认';
    };
    const collectionHealthFailureReasonText = (reason) => {
        const raw = String(reason || '').trim();
        if (!raw) return '失败原因待确认';
        if (/cookie|login|auth|401|403|登录|授权|过期|失效|unauthorized|forbidden/i.test(raw)) {
            return '登录态或 Cookie/API 辅助异常，需要重新登录/更新后再采集';
        }
        if (/source[_\s-]*rows|target[_\s-]*date|no\s+same|no\s+data|empty|未采集|无数据|入库行缺失/i.test(raw)) {
            return '目标日 OTA 源数据缺失，不能证明当天已采到';
        }
        if (/field|schema|mapping|字段|结构|口径/i.test(raw)) {
            return '字段结构或指标口径异常，需要按字段资产复核';
        }
        if (/traffic|conversion|flow|流量|转化/i.test(raw)) {
            return '流量/转化事实缺失，不能输出确定漏斗判断';
        }
        if (/etl|standard|metric|revenue|标准|收益|指标/i.test(raw)) {
            return '标准事实或收益指标未就绪，需要复核入库与指标输入';
        }
        return raw;
    };
    const collectionHealthFailureNextActionText = (nextAction, item = {}) => {
        const raw = String(nextAction || '').trim();
        const reason = `${raw} ${item?.reason || ''} ${item?.type || ''}`;
        if (/cookie|login|auth|401|403|登录|授权|过期|失效|unauthorized|forbidden/i.test(reason)) {
            return '更新授权或登录状态后，使用现有采集入口重试';
        }
        if (/source[_\s-]*rows|target[_\s-]*date|no\s+same|no\s+data|empty|未采集|无数据|入库行缺失/i.test(reason)) {
            return '补齐目标日 OTA 源数据，再复跑数据健康巡检';
        }
        if (/field|schema|mapping|字段|结构|口径/i.test(reason)) {
            return '按字段资产核对平台返回和入库字段';
        }
        if (/traffic|conversion|flow|流量|转化/i.test(reason)) {
            return '补齐流量/转化事实，再复核收益诊断和 AI 建议';
        }
        if (/etl|standard|metric|revenue|标准|收益|指标/i.test(reason)) {
            return '复核标准事实层和收益指标输入';
        }
        return raw || '检查授权、字段结构和平台接口返回后重试采集';
    };

    const buildCollectionHealthFailureReasonRanking = (failureReasons = [], platformText = dataHealthPlatformText) => {
        const groups = new Map();
        const rows = Array.isArray(failureReasons) ? failureReasons : [];
        rows.forEach((item) => {
            const reason = String(item?.reason || '采集失败原因待确认').trim();
            const key = reason.toLowerCase();
            const platform = platformText(item?.platform);
            const nextAction = String(item?.next_action || '').trim();
            const occurredAt = String(item?.occurred_at || '').trim();
            if (!groups.has(key)) {
                groups.set(key, {
                    key,
                    reason,
                    count: 0,
                    platforms: new Set(),
                    latest_at: '',
                    next_action: '',
                    priority: 'medium',
                });
            }
            const row = groups.get(key);
            row.count += 1;
            if (platform) row.platforms.add(platform);
            if (nextAction && !row.next_action) row.next_action = nextAction;
            if (occurredAt && (!row.latest_at || occurredAt > row.latest_at)) row.latest_at = occurredAt;
            if (String(item?.type || '').toLowerCase() === 'authorization' || /cookie|login|auth|401|403|登录|授权|过期|失效/i.test(reason)) {
                row.priority = 'high';
            }
        });

        return Array.from(groups.values())
            .map(row => ({
                ...row,
                platformsText: Array.from(row.platforms).join(' / ') || 'OTA',
            }))
            .sort((left, right) => {
                const priorityWeight = { high: 0, medium: 1, low: 2 };
                const priorityDiff = (priorityWeight[left.priority] ?? 9) - (priorityWeight[right.priority] ?? 9);
                if (priorityDiff !== 0) return priorityDiff;
                if (right.count !== left.count) return right.count - left.count;
                return String(right.latest_at || '').localeCompare(String(left.latest_at || ''));
            })
            .slice(0, 5);
    };

    const buildDataHealthTodayWorkOrders = ({
        cookieAlertRows = [],
        qualityTaskRows = [],
        highRiskActionRows = [],
        todayCollectionReminderRows = [],
        platformText = dataHealthPlatformText,
    } = {}) => {
        const priorityWeight = { high: 0, medium: 1, low: 2, ok: 3 };
        const rows = [];
        (Array.isArray(todayCollectionReminderRows) ? todayCollectionReminderRows : [])
            .filter(row => row?.priority !== 'ok' && row?.status !== 'ready')
            .forEach((row, index) => {
                rows.push({
                    key: row?.key || `ota-today-${index}-${row?.platform || ''}`,
                    priority: row?.priority || 'high',
                    source_label: row?.sourceLabel || '当日采集',
                    platform_label: row?.platformLabel || row?.platform_label || platformText(row?.platform),
                    title: row?.title || 'OTA 当日采集待处理',
                    detail: row?.detail || row?.nextActionText || '目标日 OTA 入库证据不足，需先补齐采集证明。',
                    action_type: 'today_collection',
                    action_tab: row?.actionTab || '',
                    button_text: row?.buttonText || '查看采集入口',
                });
            });
        (Array.isArray(cookieAlertRows) ? cookieAlertRows : []).forEach((row, index) => {
            rows.push({
                key: `cookie-${index}-${row?.platform || ''}-${row?.hotel_id || ''}-${row?.config_id || row?.name || ''}`,
                priority: row?.priority || 'medium',
                source_label: '登录/Cookie',
                platform_label: row?.platform_label || platformText(row?.platform),
                title: row?.title || 'OTA 登录/Cookie 待处理',
                detail: row?.message || row?.action_text || 'Cookie 状态异常，需重新登录或更新后再采集。',
                action_type: 'cookie',
            });
        });
        (Array.isArray(qualityTaskRows) ? qualityTaskRows : []).forEach((row, index) => {
            rows.push({
                key: `quality-${index}-${row?.key || row?.title || ''}`,
                priority: row?.priority || 'medium',
                source_label: '数据质量',
                platform_label: row?.platform_label || platformText(row?.platform),
                title: row?.title || '数据质量任务待处理',
                detail: row?.action || '复核登录/Cookie、字段映射和平台返回。',
                action_type: row?.actionTab ? 'fetch' : 'history',
                action_tab: row?.actionTab || '',
                button_text: row?.actionLabel || '补抓数据',
            });
        });
        (Array.isArray(highRiskActionRows) ? highRiskActionRows : []).forEach((row, index) => {
            rows.push({
                key: `risk-${index}-${row?.id || row?.action || ''}`,
                priority: row?.priority || 'medium',
                source_label: '后台动作',
                platform_label: row?.hotel || '后台',
                title: row?.title || '高风险后台动作待复核',
                detail: row?.error || `${row?.user || '-'} / ${row?.time || '-'}`,
                action_type: 'log',
            });
        });
        const seen = new Set();
        return rows
            .filter(row => {
                const key = `${row.source_label}|${row.platform_label}|${row.title}|${row.detail}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            })
            .sort((left, right) => (priorityWeight[left.priority] ?? 9) - (priorityWeight[right.priority] ?? 9))
            .slice(0, 8);
    };

    const buildDataHealthDiagnosticBoundary = (fullDiagnosticsLoaded = false) => {
        if (fullDiagnosticsLoaded) {
            return {
                title: '完整诊断已加载',
                detail: '当前已包含账号级驾驶舱、单店画像、数据源诊断、登录/Cookie、采集失败、字段缺口和后台高风险动作；仍仅代表 OTA 渠道数据质量，不代表全酒店经营口径。',
                badges: ['账号级驾驶舱', '单店画像', '数据源诊断', 'OTA渠道口径'],
                className: 'border-emerald-200 bg-emerald-50 text-emerald-800',
            };
        }

        return {
            title: '当前为轻量刷新',
            detail: '只展示登录/Cookie、采集失败、字段缺口和高风险动作摘要；未拉取账号级驾驶舱、单店画像和数据源完整诊断，缺证据项保持未知状态。',
            badges: ['登录/Cookie状态', '失败原因', '字段缺口', '高风险动作'],
            className: 'border-amber-200 bg-amber-50 text-amber-800',
        };
    };

    const dataHealthRefreshModeText = (mode) => ({
        full: '完整诊断',
        light: '轻量刷新',
        not_loaded: '未刷新',
    }[String(mode || 'not_loaded')] || '状态待确认');

    const buildDataHealthDiagnosticStatusRows = ({
        refreshMode = 'not_loaded',
        refreshSource = '未刷新',
        refreshAt = '',
        pendingModules = [],
        fullDiagnosticsLoaded = false,
    } = {}) => {
        const modules = Array.isArray(pendingModules) ? pendingModules.filter(Boolean) : [];
        const moduleText = fullDiagnosticsLoaded || modules.length <= 0
            ? '完整诊断模块已加载'
            : modules.join('、');
        return [
            { key: 'mode', label: '当前模式', value: dataHealthRefreshModeText(refreshMode) },
            { key: 'source', label: '触发来源', value: `${refreshSource || '未刷新'}${refreshAt ? ` · ${refreshAt}` : ''}` },
            { key: 'modules', label: '诊断模块', value: moduleText },
        ];
    };

    const normalizeDataHealthRefreshRequest = (mode = 'light', options = {}) => {
        const normalizedMode = mode === 'full' ? 'full' : 'light';
        const force = !!options?.force || normalizedMode === 'full';
        return {
            normalizedMode,
            force,
            source: options?.source || (force ? '手动刷新' : '页面自动刷新/缓存'),
        };
    };

    const createDataHealthRefreshRequestState = ({ lightCacheTtlMs = 45000 } = {}) => {
        let lightCache = {
            key: '',
            expiresAt: 0,
            promise: null,
        };

        const reset = () => {
            lightCache = {
                key: '',
                expiresAt: 0,
                promise: null,
            };
        };

        const lightCacheKey = ({ hotelId = '', userId = '', isSuperAdmin = false } = {}) => [
            String(hotelId || ''),
            String(userId || ''),
            isSuperAdmin ? 'super' : 'normal',
        ].join('|');

        const resolveLightRequest = ({ cacheKey = '', normalizedMode = 'light', force = false, now = Date.now() } = {}) => {
            if (normalizedMode !== 'light' || force || lightCache.key !== cacheKey) {
                return { status: 'miss' };
            }
            if (lightCache.promise) {
                return { status: 'in_flight', promise: lightCache.promise };
            }
            if (lightCache.expiresAt > now) {
                return { status: 'fresh' };
            }
            return { status: 'expired' };
        };

        const rememberLightRequest = ({ cacheKey = '', promise, now = Date.now() } = {}) => {
            if (!promise) return;
            lightCache = {
                key: cacheKey,
                expiresAt: now + lightCacheTtlMs,
                promise,
            };
        };

        const settleLightRequest = ({ cacheKey = '', promise, now = Date.now() } = {}) => {
            if (lightCache.key === cacheKey && lightCache.promise === promise) {
                lightCache.promise = null;
                lightCache.expiresAt = now + lightCacheTtlMs;
            }
        };

        return {
            reset,
            lightCacheKey,
            resolveLightRequest,
            rememberLightRequest,
            settleLightRequest,
        };
    };

    const requireDataHealthPanelLoader = (fn, name) => {
        if (typeof fn !== 'function') {
            throw new Error(`缺少数据健康面板刷新任务：${name}`);
        }
        return fn;
    };

    const buildDataHealthPanelRefreshJobs = ({
        normalizedMode = 'light',
        loadAutoFetchStatus,
        loadDailyWorkbench,
        loadCollectionReliability,
        loadDataHealthOperationLogs,
        loadPublicEndpointSecurity,
        loadReleaseEvidenceStatus,
        loadHotelDataDashboard,
        loadPlatformCollectionResources,
    } = {}) => {
        const isFull = normalizedMode === 'full';
        const jobs = [
            requireDataHealthPanelLoader(loadAutoFetchStatus, 'loadAutoFetchStatus')({ detail: isFull }),
            requireDataHealthPanelLoader(loadDailyWorkbench, 'loadDailyWorkbench')({ limit: 10 }),
        ];
        if (isFull) {
            jobs.push(
                requireDataHealthPanelLoader(loadCollectionReliability, 'loadCollectionReliability')('full'),
                requireDataHealthPanelLoader(loadDataHealthOperationLogs, 'loadDataHealthOperationLogs')(),
                requireDataHealthPanelLoader(loadPublicEndpointSecurity, 'loadPublicEndpointSecurity')(),
                requireDataHealthPanelLoader(loadReleaseEvidenceStatus, 'loadReleaseEvidenceStatus')(),
                requireDataHealthPanelLoader(loadHotelDataDashboard, 'loadHotelDataDashboard')(),
                requireDataHealthPanelLoader(loadPlatformCollectionResources, 'loadPlatformCollectionResources')()
            );
        }
        return jobs;
    };

    const scheduleDataHealthLightDiagnosticsRefresh = ({
        schedulePostFetchRefresh,
        shouldRun = () => true,
        loadDataHealthOperationLogs,
        loadPublicEndpointSecurity,
        delayMs = 360,
    } = {}) => {
        return requireDataHealthPanelLoader(schedulePostFetchRefresh, 'schedulePostFetchRefresh')('data-health-light-diagnostics', () => {
            if (!shouldRun()) return null;
            return Promise.allSettled([
                requireDataHealthPanelLoader(loadDataHealthOperationLogs, 'loadDataHealthOperationLogs')(),
                requireDataHealthPanelLoader(loadPublicEndpointSecurity, 'loadPublicEndpointSecurity')(),
            ]);
        }, delayMs);
    };

    const dataHealthFieldGapActionStatusText = (status) => ({
        forbidden: '禁止采集',
        not_returned_visible: '平台未返回',
        not_returned: '平台未返回',
        missing: '字段缺口',
        unknown: '待核验',
    }[String(status || '').toLowerCase()] || '待核验');

    const dataHealthFieldGapActionStatusClass = (status) => {
        const value = String(status || '').toLowerCase();
        if (value === 'forbidden') return 'border-red-200 bg-red-50 text-red-700';
        if (['missing', 'not_returned', 'not_returned_visible'].includes(value)) return 'border-amber-200 bg-amber-50 text-amber-700';
        return 'border-gray-200 bg-gray-50 text-gray-600';
    };

    const buildDataHealthFieldGapActionRows = ({
        missingFieldRows = [],
        fieldAssetSummary = {},
        fieldRows = [],
        platformText = dataHealthPlatformText,
    } = {}) => {
        const rows = [];
        const seen = new Set();
        const normalizeAssetEntry = (entry) => {
            if (entry && typeof entry === 'object') return entry;
            const text = String(entry || '').trim();
            return text ? { field: text, label: text } : null;
        };
        const pushRow = ({
            platform = 'ota',
            module = '字段缺口',
            field = '',
            label = '',
            status = 'missing',
            sourceRef = 'missing_field_codes',
            nextAction = '按字段缺口清单补齐平台返回、字段定义或入库证据。',
        } = {}) => {
            const fieldText = String(label || field || '').trim();
            if (!fieldText) return;
            const key = `${platform}|${module}|${fieldText}|${status}`;
            if (seen.has(key)) return;
            seen.add(key);
            rows.push({
                key,
                platform: platformText(platform),
                module: collectionHealthFieldModuleText(module) || String(module || '字段缺口'),
                field: fieldText,
                status,
                statusText: dataHealthFieldGapActionStatusText(status),
                statusClass: dataHealthFieldGapActionStatusClass(status),
                sourceRef: sourceRef || 'missing_field_codes',
                nextAction,
            });
        };

        (Array.isArray(missingFieldRows) ? missingFieldRows : []).forEach((row) => {
            pushRow({
                platform: row?.platform || row?.source || 'ota',
                module: row?.module || row?.sourceText || '数据缺口',
                field: row?.code || row?.field || row?.label,
                label: row?.label,
                status: 'missing',
                sourceRef: row?.source_ref || row?.source || 'missing_field_codes',
                nextAction: row?.nextActionText || row?.next_action || '补齐目标日字段证据后复跑 OTA 收益与 AI 诊断。',
            });
        });

        (Array.isArray(fieldAssetSummary?.not_returned_fields) ? fieldAssetSummary.not_returned_fields : [])
            .map(normalizeAssetEntry)
            .filter(Boolean)
            .forEach((field) => pushRow({
                platform: field.source || field.platform || 'ota',
                module: field.module || '字段资产',
                field: field.field || field.key || field.label,
                label: field.label,
                status: 'not_returned_visible',
                sourceRef: field.source_ref || 'field_asset_summary.not_returned_fields',
                nextAction: field.next_action || '核对平台页面/接口是否真实返回该字段；未返回时保持缺口，不用默认值代替。',
            }));

        (Array.isArray(fieldAssetSummary?.forbidden_fields) ? fieldAssetSummary.forbidden_fields : [])
            .map(normalizeAssetEntry)
            .filter(Boolean)
            .forEach((field) => pushRow({
                platform: field.source || field.platform || 'ota',
                module: field.module || '字段资产',
                field: field.field || field.key || field.label,
                label: field.label,
                status: 'forbidden',
                sourceRef: field.source_ref || 'field_asset_summary.forbidden_fields',
                nextAction: field.next_action || '保持禁止采集边界，仅展示字段缺口和影响链路。',
            }));

        (Array.isArray(fieldRows) ? fieldRows : []).forEach((field) => {
            const status = String(field?.asset_status || '').toLowerCase();
            const storageTable = String(field?.storage_table || '').toLowerCase();
            if (!['not_returned_visible', 'not_returned', 'forbidden'].includes(status) && storageTable !== 'not_collected') return;
            pushRow({
                platform: field?.source || 'ota',
                module: field?.module || '字段定义',
                field: field?.field || field?.fieldRawText,
                label: field?.label || field?.labelText,
                status: status || (storageTable === 'not_collected' ? 'forbidden' : 'missing'),
                sourceRef: `${field?.source || 'ota'}.${field?.module || 'field_definitions'}.${field?.field || 'field_missing'}`,
                nextAction: status === 'forbidden' || storageTable === 'not_collected'
                    ? '保持禁止采集边界，仅在影响链路中标记不可得。'
                    : '核对平台返回和入库字段；未返回时继续显示缺口。',
            });
        });

        return rows.slice(0, 12);
    };

    const summarizeDataHealthFieldGapActions = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        const statusCounts = safeRows.reduce((acc, row) => {
            const status = String(row?.status || 'unknown').toLowerCase();
            acc[status] = (acc[status] || 0) + 1;
            return acc;
        }, {});
        const sourceCount = new Set(safeRows.map(row => row?.sourceRef).filter(Boolean)).size;
        const forbiddenCount = Number(statusCounts.forbidden || 0);
        const missingCount = safeRows.length - forbiddenCount;
        const parts = [
            missingCount > 0 ? `待补 ${missingCount}` : '',
            forbiddenCount > 0 ? `禁止采集 ${forbiddenCount}` : '',
            sourceCount > 0 ? `来源 ${sourceCount}` : '',
        ].filter(Boolean);
        return {
            title: 'OTA 字段缺口行动台',
            boundaryText: '只读展示字段缺口、来源路径和下一步；未返回字段不按成功处理。',
            countText: `${safeRows.length} 项缺口`,
            detailText: parts.length ? parts.join(' / ') : '暂无字段缺口',
            hasForbidden: forbiddenCount > 0,
        };
    };

    const employeeOtaChecklistPriorityRank = (priority) => ({
        high: 0,
        medium: 1,
        low: 2,
        ok: 3,
    }[String(priority || '').toLowerCase()] ?? 4);

    const employeeOtaChecklistCategoryClass = (category) => ({
        health: 'border-slate-200 bg-slate-50 text-slate-700',
        gap: 'border-amber-200 bg-amber-50 text-amber-700',
        anomaly: 'border-red-200 bg-red-50 text-red-700',
        action: 'border-blue-200 bg-blue-50 text-blue-700',
    }[String(category || '')] || 'border-gray-200 bg-gray-50 text-gray-600');

    const employeeOtaChecklistCategoryText = (category) => ({
        health: '健康',
        gap: '缺口',
        anomaly: '异常',
        action: '今日动作',
    }[String(category || '')] || '待确认');

    const buildEmployeeOtaChecklistHeadline = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        const hasHighPriority = safeRows.some(row => String(row?.priority || '').toLowerCase() === 'high');
        if (hasHighPriority) {
            return { text: '先处理高优先级', className: dataHealthPriorityClass('high') };
        }
        if (safeRows.length) {
            return { text: `${safeRows.length} 项待处理`, className: dataHealthPriorityClass('medium') };
        }
        return { text: '暂无待处理', className: dataHealthPriorityClass('ok') };
    };

    const otaFieldGapQueueStatusText = (status = '') => ({
        complete: '已闭合',
        ready: '已闭合',
        missing: '缺失',
        incomplete: '待补',
        no_target_date_traffic_rows: '目标日 traffic 未入库',
        missing_target_date_traffic_rows: '目标日 traffic 未入库',
        requires_p0_verifier: '待 P0 复核',
        not_loaded: '未加载',
        unknown: '未知',
    }[String(status || '').trim()] || String(status || '').trim() || '未知');

    const buildOtaFieldGapQueueRows = ({
        sourceDateEvidence = {},
        missingFieldRows = [],
        platformText = dataHealthPlatformText,
    } = {}) => {
        const rows = [];
        const targetDate = String(sourceDateEvidence?.target_date || sourceDateEvidence?.date || '').trim();
        const platformRows = Array.isArray(sourceDateEvidence?.platforms) ? sourceDateEvidence.platforms : [];
        const statusPriority = (status) => {
            const value = String(status || '').trim();
            if (['complete', 'ready'].includes(value)) return 'ok';
            if (['missing', 'no_target_date_traffic_rows', 'missing_target_date_traffic_rows'].includes(value) || value.endsWith('_missing')) return 'high';
            return 'medium';
        };
        const push = (row) => {
            const status = String(row.status || 'unknown').trim();
            const metricKey = String(row.metricKey || '').trim();
            const platform = String(row.platform || 'ota').trim().toLowerCase();
            rows.push({
                key: row.key || `${platform}-${row.targetDate || targetDate || 'target-date'}-${metricKey || rows.length}-${status}`,
                platform,
                platformLabel: platformText(platform),
                targetDate: String(row.targetDate || targetDate || '目标日待确认').trim(),
                metricKey: metricKey || 'metric_key_missing',
                storageField: String(row.storageField || '').trim() || 'storage_field_missing',
                sourcePath: String(row.sourcePath || '').trim() || 'source_path_missing',
                uiStatus: String(row.uiStatus || '').trim() || 'not_loaded',
                verifierStatus: String(row.verifierStatus || status).trim() || status,
                status,
                statusText: otaFieldGapQueueStatusText(status),
                priority: row.priority || statusPriority(status),
                nextAction: String(row.nextAction || '').trim() || '按 P0 verifier 补齐 source_path / metric_key / storage_field / UI 状态证据。',
                sourceRef: String(row.sourceRef || '').trim() || 'source_date_evidence.p0_field_loop_matrix',
            });
        };

        platformRows.forEach((platformRow) => {
            const platform = String(platformRow?.platform || '').trim().toLowerCase() || 'ota';
            const matrix = Array.isArray(platformRow?.p0_field_loop_matrix) ? platformRow.p0_field_loop_matrix : [];
            const gateStatus = String(platformRow?.p0_traffic_gate_status || platformRow?.p0_standard_fact_status || '').trim();
            const uiStatus = String(platformRow?.p0_traffic_field_fact_status || platformRow?.field_fact_status || '').trim();
            matrix.forEach((item, index) => {
                const status = String(item?.status || gateStatus || 'unknown').trim();
                if (['complete', 'ready'].includes(status)) return;
                const metricKey = String(item?.metric_key || '').trim();
                const expectedStorage = String(item?.expected_storage_field || item?.storage_field || '').trim();
                const sourcePath = String(item?.source_path || item?.sample_source_path || '').trim()
                    || (item?.source_path_structured ? 'structured_source_path_present' : 'source_path_missing');
                push({
                    key: `${platform}-${platformRow?.target_date || targetDate || 'target-date'}-${metricKey || index}`,
                    platform,
                    targetDate: platformRow?.target_date || targetDate,
                    metricKey,
                    storageField: expectedStorage,
                    sourcePath,
                    uiStatus: item?.ui_status_ready ? 'ready' : (uiStatus || 'not_loaded'),
                    verifierStatus: gateStatus || status,
                    status,
                    nextAction: ['missing_target_date_traffic_rows', 'no_target_date_traffic_rows'].includes(status)
                        ? '先补齐目标日 traffic 行，再复核 field_facts 证据链。'
                        : '补齐 source_path、metric_key、storage_field、stored_value 与 UI ready 证据后重跑 P0 verifier。',
                });
            });

            if (!matrix.length && gateStatus && gateStatus !== 'ready') {
                const missingKeys = Array.isArray(platformRow?.p0_missing_metric_keys) ? platformRow.p0_missing_metric_keys : [];
                const storageFields = Array.isArray(platformRow?.p0_required_storage_fields) ? platformRow.p0_required_storage_fields : [];
                (missingKeys.length ? missingKeys : ['traffic_field_facts']).forEach((metricKey, index) => {
                    push({
                        key: `${platform}-${platformRow?.target_date || targetDate || 'target-date'}-${metricKey}`,
                        platform,
                        targetDate: platformRow?.target_date || targetDate,
                        metricKey,
                        storageField: storageFields[index] || '',
                        status: gateStatus,
                        uiStatus: uiStatus || 'not_loaded',
                        verifierStatus: gateStatus,
                    });
                });
            }
        });

        if (!rows.length) {
            (Array.isArray(missingFieldRows) ? missingFieldRows : []).slice(0, 12).forEach((item, index) => {
                push({
                    key: `missing-field-${index}-${item?.code || item?.field || ''}`,
                    platform: item?.platform || 'ota',
                    targetDate,
                    metricKey: item?.code || item?.field || item?.label || 'missing_field',
                    storageField: item?.storageField || '',
                    status: 'missing',
                    uiStatus: 'not_loaded',
                    verifierStatus: 'requires_p0_verifier',
                    sourceRef: item?.sourceRef || item?.sourceText || 'missing_field_summary',
                    nextAction: item?.nextActionText || '先补齐字段资产与目标日 source_date_evidence，再生成 P0 field-loop 证据链。',
                });
            });
        }

        return rows.slice(0, 24);
    };

    const summarizeOtaFieldGapQueue = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        const openRows = safeRows.filter(row => row.priority !== 'ok');
        const sourcePathMissing = safeRows.filter(row => String(row?.sourcePath || '').includes('missing')).length;
        const storageMissing = safeRows.filter(row => String(row?.storageField || '').includes('missing')).length;
        const uiOpen = safeRows.filter(row => !['ready', 'complete'].includes(String(row?.uiStatus || '').trim())).length;
        return {
            title: 'OTA 字段缺口队列',
            status: openRows.length ? 'high' : 'ok',
            text: openRows.length ? `${openRows.length} 项待闭合` : '字段链路已闭合',
            total: safeRows.length,
            openCount: openRows.length,
            sourcePathMissing,
            storageMissing,
            uiOpen,
            boundaryText: '只展示 OTA 渠道字段证据链；source_path、metric_key、storage_field、UI 状态和 verifier 任一缺失都不能按成功处理。',
        };
    };

    const buildDataHealthCookieAlertRows = (
        authorizationRows = [],
        normalizeStatus = dataHealthNormalizeStatus,
        platformText = dataHealthPlatformText,
    ) => (Array.isArray(authorizationRows) ? authorizationRows : [])
        .filter(row => normalizeStatus(row?.status) !== 'ok')
        .map(row => {
            const status = normalizeStatus(row?.status);
            return {
                ...row,
                priority: status === 'failed' ? 'high' : 'medium',
                status,
                platform_label: platformText(row?.platform),
                title: `${platformText(row?.platform)} / ${row?.name || row?.config_id || '未命名配置'}`,
                message: row?.message || row?.action_hint || '登录/Cookie 状态待复核',
                action_text: row?.next_action || row?.action_hint || '重新登录或更新后刷新数据健康',
            };
        });

    const summarizeDataHealthCookieAlerts = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        return {
            total: safeRows.length,
            high: safeRows.filter(row => row.priority === 'high').length,
            warning: safeRows.filter(row => row.priority !== 'high').length,
        };
    };

    const buildDataHealthQualityTaskRows = ({
        pendingActions = [],
        failureReasons = [],
        dashboardDiagnostics = [],
        ctripMissingActionRows = [],
        normalizeStatus = dataHealthNormalizeStatus,
        platformText = dataHealthPlatformText,
    } = {}) => {
        const rows = [];
        (Array.isArray(pendingActions) ? pendingActions : []).forEach((item, index) => {
            const status = normalizeStatus(item?.status);
            rows.push({
                key: `pending-${index}-${item?.type || ''}-${item?.platform || ''}`,
                priority: status === 'failed' ? 'high' : 'medium',
                type: item?.type || 'pending',
                platform: item?.platform || '',
                platform_label: platformText(item?.platform),
                title: item?.reason || item?.type || '待处理数据质量任务',
                action: item?.action || '复核授权、字段映射和平台返回',
                status,
                actionTab: item?.actionTab || '',
            });
        });
        (Array.isArray(failureReasons) ? failureReasons : []).slice(0, 6).forEach((item, index) => {
            rows.push({
                key: `failure-${index}-${item?.type || ''}-${item?.platform || ''}`,
                priority: 'high',
                type: item?.type || 'failure',
                platform: item?.platform || '',
                platform_label: platformText(item?.platform),
                title: item?.reason || '采集失败原因待处理',
                action: item?.next_action || '先处理失败原因，再重新采集对应模块',
                status: 'failed',
                actionTab: '',
            });
        });
        (Array.isArray(dashboardDiagnostics) ? dashboardDiagnostics : []).slice(0, 6).forEach((item, index) => {
            rows.push({
                key: `dashboard-${index}-${item?.problem || ''}`,
                priority: item?.risk === 'high' || item?.status === 'auth_failed' || item?.status === 'request_failed' ? 'high' : 'medium',
                type: 'dashboard',
                platform: 'ota',
                platform_label: 'OTA',
                title: item?.problem || '数据源诊断',
                action: item?.action || '复核数据源状态',
                status: normalizeStatus(item?.status),
                actionTab: '',
            });
        });
        (Array.isArray(ctripMissingActionRows) ? ctripMissingActionRows : []).slice(0, 8).forEach((item, index) => {
            rows.push({
                key: `ctrip-missing-${index}-${item?.diagnosisType || ''}-${item?.actionTab || ''}`,
                priority: item?.diagnosisType === 'request_failed' || item?.diagnosisType === 'config' ? 'high' : 'medium',
                type: item?.diagnosisType || 'field_missing',
                platform: 'ctrip',
                platform_label: '携程',
                title: `${item?.module || '携程模块'}：${item?.count || 0}项未抓到`,
                action: item?.reasonText || item?.actionLabel || '补抓或复核字段映射',
                status: item?.diagnosisType === 'ok' ? 'ok' : 'warning',
                actionTab: item?.actionTab || '',
            });
        });
        const seen = new Set();
        return rows.filter(row => {
            const key = `${row.type}|${row.platform}|${row.title}|${row.action}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        }).slice(0, 12);
    };

    const buildDataHealthHighRiskActionRows = (operationLogs = []) => (Array.isArray(operationLogs) ? operationLogs : [])
        .map((log) => {
            const action = String(log?.action || '').toLowerCase();
            const module = String(log?.module || '').toLowerCase();
            const backendPriority = String(log?.risk_priority || '').trim();
            const hasError = !!String(log?.error_info || '').trim();
            const isDelete = action.includes('delete') || action.includes('clear') || action.includes('archive');
            const isExecution = action.includes('auto_fetch') || action.includes('sync') || action.includes('execute') || action.includes('approve') || action.includes('apply');
            const isConfig = action.includes('config') || action.includes('save_cookies') || action.includes('save_data_source');
            const isAgent = module === 'agent' || action.includes('analysis') || action.includes('analyze');
            const priority = backendPriority || (hasError || isDelete ? 'high' : (isExecution || isConfig || isAgent ? 'medium' : 'low'));
            return {
                id: log?.id,
                priority,
                module: log?.module || '-',
                action: log?.action || '-',
                title: log?.risk_title || log?.description || `${log?.module || '-'} / ${log?.action || '-'}`,
                user: log?.user?.realname || log?.user?.username || log?.user_name || '-',
                hotel: log?.hotel?.name || log?.hotel_name || '-',
                time: log?.create_time || '-',
                error: log?.error_info || '',
            };
        })
        .filter(row => row.priority !== 'low')
        .slice(0, 8);

    const summarizeDataHealthHighRiskActions = ({
        isSuperAdmin = false,
        loading = false,
        error = '',
        rows = [],
    } = {}) => {
        if (!isSuperAdmin) {
            return {
                status: 'unknown',
                text: '无权限',
                detail: '当前账号无权查看后台高风险动作摘要；未展示不代表暂无风险。',
            };
        }
        if (loading) {
            return { status: 'unknown', text: '加载中', detail: '高风险动作摘要正在加载。' };
        }
        if (error) {
            return { status: 'high', text: '加载失败', detail: error };
        }
        const safeRows = Array.isArray(rows) ? rows : [];
        const hasHigh = safeRows.some(row => row.priority === 'high');
        return {
            status: hasHigh ? 'high' : (safeRows.length ? 'medium' : 'ok'),
            text: safeRows.length ? `${safeRows.length} 项` : '暂无风险',
            detail: safeRows.length ? `${safeRows.length} 项高风险后台动作待复核` : '已加载近 7 天摘要，暂无需要重点复核的高风险动作。',
        };
    };

    const summarizePublicEndpointSecurity = ({
        isSuperAdmin = false,
        loading = false,
        error = '',
        payload = null,
        rows = null,
    } = {}) => {
        if (!isSuperAdmin) {
            return { status: 'unknown', text: '无权限', failureCount: 0, rateLimitedCount: 0, unconfiguredTokenCount: 0, period: {}, scanScope: {} };
        }
        if (loading) {
            return { status: 'unknown', text: '加载中', failureCount: 0, rateLimitedCount: 0, unconfiguredTokenCount: 0, period: {}, scanScope: {} };
        }
        if (error) {
            return { status: 'high', text: '加载失败', failureCount: 0, rateLimitedCount: 0, unconfiguredTokenCount: 0, period: {}, scanScope: {} };
        }
        if (!payload) {
            return { status: 'unknown', text: '未加载', failureCount: 0, rateLimitedCount: 0, unconfiguredTokenCount: 0, period: {}, scanScope: {} };
        }
        const safeRows = Array.isArray(rows) ? rows : (Array.isArray(payload?.endpoints) ? payload.endpoints : []);
        if (!safeRows.length) {
            return { status: 'unknown', text: '未加载', failureCount: 0, rateLimitedCount: 0, unconfiguredTokenCount: 0, period: payload?.period || {}, scanScope: payload?.scan_scope || {} };
        }
        const failureCount = safeRows.reduce((sum, row) => sum + Number(row?.recent_failure_count || 0), 0);
        const rateLimitedCount = safeRows.reduce((sum, row) => sum + Number(row?.rate_limited_count || 0), 0);
        const unconfiguredTokenCount = safeRows.filter(row => row?.token_configured === false).length;
        const status = unconfiguredTokenCount > 0 ? 'high' : (failureCount > 0 || rateLimitedCount > 0 ? 'medium' : 'ok');
        return {
            status,
            text: status === 'high' ? '高优先复核' : (status === 'medium' ? '有失败' : '暂无风险'),
            failureCount,
            rateLimitedCount,
            unconfiguredTokenCount,
            period: payload?.period || {},
            scanScope: payload?.scan_scope || {},
        };
    };

    const publicEndpointTokenText = (value) => {
        if (value === true) return '已配置';
        if (value === false) return '未配置';
        return '登录令牌';
    };

    const publicEndpointDisplayName = (endpoint) => ({
        receive_cookies: 'receive-cookies',
        cron_trigger: 'cron-trigger',
        daily_workbench_patrol_cron: 'daily-workbench-patrol-cron',
        competitor_task: 'competitor-task',
        competitor_report: 'competitor-report',
    }[endpoint] || endpoint || '-');

    const publicEndpointSecurityBoundaryText = () => 'receive-cookies 旧版书签入口已禁用；cron-trigger、daily-workbench-patrol-cron 与 competitor task/report 不走常规登录中间件，仅展示脱敏审计、限流和令牌配置状态。';

    const publicEndpointSecurityEvidenceText = () => '证据来自 operation_logs 中的公开入口失败审计；Cookie、token、Authorization、spidertoken、mtgsig 等敏感值只保留遮罩状态，不展示原文。';

    const publicEndpointPathText = (row = {}) => `${row.method || '-'} ${row.path || '-'}`;

    const releaseEvidenceInputLabel = (id = '') => ({
        design_handoff_manifest: '设计交付清单',
        'design-handoff-missing': '设计交付清单',
        ota_credential_rotation_attestation: 'OTA 凭据轮换证明',
        'ota-credential-rotation-attestation-missing': 'OTA 凭据轮换证明',
        final_release_pr_and_local_state: '最终 PR / 本地状态',
        'local-git-state-open': '最终 PR / 本地状态',
    }[String(id || '').trim()] || String(id || '').trim() || '发布证据输入');

    const releaseEvidenceStatusText = (status = '') => ({
        missing: '缺少证据',
        open: '未关闭',
        failed: '未通过',
        pending: '待复核',
        stale: '已过期',
        blocked: '阻断',
        blocked_until_clean_or_isolated: '需清理或隔离',
        closed: '已关闭',
        passed: '已通过',
        pass: '已通过',
        clean: '干净',
    }[String(status || '').trim()] || String(status || '').trim() || '未知');

    const releaseEvidencePriority = (status = '') => {
        const value = String(status || '').trim();
        if (['closed', 'passed', 'pass', 'clean'].includes(value)) return 'ok';
        if (['pending', 'stale'].includes(value)) return 'medium';
        if (!value || value === 'unknown') return 'medium';
        return 'high';
    };

    const releaseEvidenceNoClosureText = () => '该面板只展示 release gap pack / operator intake 的证据缺口和下一步；不替代最终设计交付、OTA 凭据轮换证明、PR 外部状态或 review:release-readiness。';

    const releaseEvidenceProjectedInputState = (id = '', payload = {}) => {
        if (String(id || '').trim() !== 'final_release_pr_and_local_state') return null;
        const sourceStatus = payload?.source_status || {};
        const externalState = sourceStatus?.external_state_check || {};
        const worktree = sourceStatus?.local_worktree_close_plan || {};
        const externalStatus = String(externalState?.status || '').trim();
        const worktreeStatus = String(worktree?.status || '').trim();
        if (externalStatus === 'passing_from_clean_verification_worktree' || worktreeStatus === 'passing_from_clean_verification_worktree' || worktreeStatus === 'clean') {
            return {
                status: 'passed',
                priority: 'ok',
                acceptanceCommand: 'npm run review:release-external-state',
                evidenceText: 'review:release-external-state passed from a clean checkout matching the selected release PR head.',
                nextAction: 'Rerun review:release-pr-candidates, review:release-staged-scope, and review:release-external-state after every PR update.',
            };
        }
        return null;
    };

    const buildReleaseEvidencePanelRows = (payload = {}) => {
        const packet = payload?.operator_intake_packet || payload || {};
        const requirements = Array.isArray(payload?.blocking_requirements) ? payload.blocking_requirements : [];
        const inputs = Array.isArray(packet?.required_external_inputs) ? packet.required_external_inputs : [];
        const byId = new Map();

        for (const input of inputs) {
            const id = String(input?.id || '').trim();
            if (!id) continue;
            const projectedState = releaseEvidenceProjectedInputState(id, payload);
            const inputStatus = String(input?.status || '').trim();
            const status = inputStatus || projectedState?.status || 'missing';
            byId.set(id, {
                key: id,
                id,
                label: releaseEvidenceInputLabel(id),
                status,
                statusText: releaseEvidenceStatusText(status),
                priority: inputStatus ? releaseEvidencePriority(inputStatus) : (projectedState?.priority || 'high'),
                requiredFile: input?.required_file || input?.required_result_file || '',
                creationCommand: input?.creation_command || input?.selection_command || '',
                acceptanceCommand: projectedState?.acceptanceCommand || input?.isolated_review_command || '',
                evidenceText: input?.success_evidence || projectedState?.evidenceText || input?.success_condition || input?.description || '',
                nextAction: input?.next_action || projectedState?.nextAction || input?.creation_command || input?.selection_command || input?.isolated_review_command || '',
                doesNotCloseReleaseReadiness: true,
            });
        }

        for (const requirement of requirements) {
            const id = String(requirement?.id || '').trim();
            if (!id) continue;
            const inputId = id === 'design-handoff-missing'
                ? 'design_handoff_manifest'
                : (id === 'ota-credential-rotation-attestation-missing'
                    ? 'ota_credential_rotation_attestation'
                    : (id === 'local-git-state-open' ? 'final_release_pr_and_local_state' : id));
            const status = String(requirement?.status || 'open').trim();
            byId.set(inputId, {
                ...(byId.get(inputId) || {}),
                key: inputId,
                id: inputId,
                blockerId: id,
                label: releaseEvidenceInputLabel(id),
                status,
                statusText: releaseEvidenceStatusText(status),
                priority: releaseEvidencePriority(status),
                requiredFile: byId.get(inputId)?.requiredFile || requirement?.required_file || '',
                creationCommand: byId.get(inputId)?.creationCommand || requirement?.creation_command || '',
                acceptanceCommand: requirement?.acceptance_command || byId.get(inputId)?.acceptanceCommand || '',
                evidenceText: requirement?.evidence || requirement?.success_evidence || byId.get(inputId)?.evidenceText || '',
                nextAction: requirement?.next_action || requirement?.acceptance_command || byId.get(inputId)?.nextAction || '',
                doesNotCloseReleaseReadiness: true,
            });
        }

        return Array.from(byId.values());
    };

    const summarizeReleaseEvidencePanel = (payload = {}) => {
        const rows = buildReleaseEvidencePanelRows(payload);
        const blockerCount = rows.filter(row => row.priority !== 'ok').length;
        const packet = payload?.operator_intake_packet || payload || {};
        const worktree = payload?.source_status?.local_worktree_close_plan || packet?.worktree_staging_summary || {};
        const changedEntries = Number(worktree?.changed_entries ?? worktree?.changedEntries ?? 0);
        const releaseReady = payload?.release_ready === true || payload?.final_release_ready === true;
        const doesNotClose = payload?.does_not_close_release_readiness === true
            || packet?.does_not_close_release_readiness === true
            || rows.some(row => row.doesNotCloseReleaseReadiness === true);
        const status = releaseReady && blockerCount === 0 ? 'medium' : (blockerCount > 0 ? 'high' : 'medium');
        const text = releaseReady && blockerCount === 0
            ? '待最终门禁复核'
            : (blockerCount > 0 ? `${blockerCount} 项阻断` : '待加载证据');
        return {
            status,
            text,
            blockerCount,
            requiredInputCount: rows.length,
            releaseReady,
            doesNotCloseReleaseReadiness: doesNotClose,
            worktreeStatus: worktree?.status || '',
            changedEntries,
            rows,
            boundaryText: releaseEvidenceNoClosureText(),
        };
    };

    const dashboardStateText = (state) => ({
        ok: '已采集',
        zero: '0',
        null: '空值',
        not_collected: '未采集',
        auth_failed: '授权失败',
        request_failed: '请求失败',
        field_missing: '字段缺失',
        warning: '需复核',
    }[state] || state || '未知');

    const dashboardStateClass = (state) => {
        if (['ok', 'zero'].includes(state)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['warning', 'null', 'field_missing', 'not_collected'].includes(state)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (['auth_failed', 'request_failed'].includes(state)) return 'bg-red-50 text-red-700 border-red-100';
        return 'bg-gray-50 text-gray-600 border-gray-100';
    };

    const dashboardMetricText = (metric) => {
        if (!metric) return '-';
        if (metric.state && metric.state !== 'ok' && metric.state !== 'zero') return dashboardStateText(metric.state);
        return metric.display_value ?? metric.value ?? '-';
    };

    const dashboardEvidenceText = (evidence) => {
        if (!evidence) return '-';
        if (typeof evidence === 'string') return evidence;
        try {
            return JSON.stringify(evidence);
        } catch (error) {
            return '-';
        }
    };

    const collectionHealthStatusText = (status) => ({
        ok: '正常',
        success: '成功',
        zero: '0',
        null: 'null',
        not_collected: '未采集',
        auth_failed: '授权失败',
        request_failed: '请求失败',
        field_missing: '字段缺失',
        warning: '预警',
        expired: '授权过期',
        unknown: '未知',
        waiting_config: '待配置',
        failed: '失败',
        partial_success: '部分模块成功',
        error: '异常',
        no_data: '暂无数据',
    }[status] || status || '未知');

    const collectionHealthStatusClass = (status) => {
        if (['ok', 'success', 'zero'].includes(status)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['warning', 'partial_success', 'waiting_config', 'null', 'not_collected', 'field_missing'].includes(status)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (['expired', 'failed', 'error', 'auth_failed', 'request_failed'].includes(status)) return 'bg-red-50 text-red-700 border-red-100';
        return 'bg-gray-50 text-gray-600 border-gray-100';
    };

    const platformCollectionResourceLabel = (resource) => ({
        businessData: '经营核心',
        peerRank: '竞对榜单',
        flowData: '流量漏斗',
        trafficForecast: '未来预测',
        flowAnalysis: '流量分析',
        searchKeywords: '搜索词',
        reviewData: '点评摘要',
        roomTypes: '房型目录',
    }[String(resource || '')] || resource || '-');

    const platformCollectionResourceStatusText = (status) => ({
        ready: '可展示',
        stale: '已过期',
        collecting: '采集中',
        failed: '采集失败',
        partial_success: '部分模块成功',
        login_required: '需登录',
        manual_intervention_required: '需人工处理',
        unbound: '未绑定',
        ready_to_sync: '待同步',
        unknown: '待确认',
    }[String(status || '').toLowerCase()] || collectionHealthStatusText(status));

    const platformCollectionResourceStatusClass = (status) => {
        const normalized = String(status || '').toLowerCase();
        if (['ready', 'stored_displayable', 'fresh', 'authorized'].includes(normalized)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['stale', 'partial_success', 'ready_to_sync', 'not_started', 'pending', 'configured'].includes(normalized)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (['failed', 'capture_failed', 'login_required', 'manual_intervention_required'].includes(normalized)) return 'bg-red-50 text-red-700 border-red-100';
        if (['unbound', 'missing', 'not_stored', 'unknown'].includes(normalized)) return 'bg-gray-50 text-gray-600 border-gray-100';
        return collectionHealthStatusClass(normalized);
    };

    const platformCollectionEtlStatusText = (status) => ({
        stored_displayable: '已入库可展示',
        stored_from_previous_task: '历史数据可展示',
        capture_success_not_stored: '采集成功未入库',
        normalized_not_stored: '已解析未入库',
        capture_failed: '采集失败',
        pending: '待入库',
        not_started: '未开始',
        not_stored: '未入库',
    }[String(status || '').toLowerCase()] || status || '-');

    const platformCollectionFreshnessText = (freshness) => ({
        fresh: '有效',
        stale: '超过24小时未更新',
        unknown: '待确认',
        no_data: '暂无数据',
    }[String(freshness || '').toLowerCase()] || freshness || '-');

    const collectionHealthPendingActionPlatformText = (platform) => {
        const parts = String(platform || '').split(/[、,，\s]+/).map(item => item.trim()).filter(Boolean);
        if (!parts.length) return 'OTA 平台';
        return parts.map(dataHealthPlatformText).filter(Boolean).join('、') || 'OTA 平台';
    };

    const collectionHealthPendingActionTypeText = (item) => {
        const type = String(item?.type || '').trim();
        return ({
            authorization: '授权/账号',
            failure_reason: '登录/Cookie告警',
            collection: '采集状态',
            collection_gap: '源数据缺口',
            field_quality: '字段质量',
        }[type] || '待处理动作');
    };

    const collectionHealthPendingActionText = (item) => {
        const code = String(item?.action_code || '').trim();
        if (code.startsWith('ota_authorization_')) return '复核登录/Cookie、账号/Profile 绑定，并按现有入口重跑同步';
        if (code.startsWith('ota_collection_')) return '复查采集日志、平台响应和登录/Cookie 状态后，按现有手动或自动入口重试';
        if (code === 'ota_same_period_source_rows_missing') return '补齐携程/美团同日期 OTA 入库数据，再复核字段、指标、AI 和执行动作';
        if (code.startsWith('ota_field_quality_')) return '复核缺失字段、原始响应路径和字段映射，缺口继续保留为 data_gaps';
        return String(item?.action || item?.next_action || '').trim() || '查看待处理动作并按数据健康明细复核';
    };

    const collectionHealthPendingActionReasonText = (item) => {
        const code = String(item?.action_code || '').trim();
        const platformText = collectionHealthPendingActionPlatformText(item?.platform);
        if (code.startsWith('ota_authorization_')) return `${platformText}授权或账号上下文需要复核`;
        if (code.startsWith('ota_collection_')) return `${platformText}采集状态不是稳定成功，需要复查失败、部分模块成功或待配置原因`;
        if (code === 'ota_same_period_source_rows_missing') return '选定周期缺少可证明经营诊断的 OTA 同日期入库数据';
        if (code.startsWith('ota_field_quality_')) return `${platformText}字段质量存在缺口，不能把缺字段指标显示成可信`;
        return String(item?.reason || '').trim();
    };

    const collectionHealthPendingActionEvidenceText = (item) => {
        const code = String(item?.action_code || '').trim();
        if (code.startsWith('ota_authorization_')) return '登录/Cookie 状态、账号/Profile 绑定、重跑同步日志';
        if (code.startsWith('ota_collection_')) return '采集日志、平台响应状态、validation_flags、source_trace_id 或 raw_data';
        if (code === 'ota_same_period_source_rows_missing') return 'online_daily_data 同日期源数据行、data_source_id/sync_task_id、source_trace_id 或 raw_data';
        if (code.startsWith('ota_field_quality_')) return '缺失字段列表、raw_data.field_facts、source_path、metric_key、storage_field、source_trace_id、validation_flags';
        const evidence = Array.isArray(item?.evidence_needed) ? item.evidence_needed : [];
        return evidence.map(value => String(value || '').trim()).filter(Boolean).slice(0, 4).join('、');
    };

    const collectionHealthPendingActionProtectedBoundaryText = (item) => {
        const code = String(item?.action_code || '').trim();
        if (code.startsWith('ota_authorization_')) return '只处理授权和账号绑定；不改变携程/美团采集字段、字段映射或获取逻辑';
        if (code.startsWith('ota_collection_')) return '只复查下游状态和响应证据；不改变携程/美团手动或自动获取逻辑';
        if (code === 'ota_same_period_source_rows_missing') return '不改变采集字段、字段映射或携程/美团获取逻辑；不能用空数据生成经营结论';
        if (code.startsWith('ota_field_quality_')) return '不使用兜底值掩盖字段缺失，不把缺字段指标显示成可信';
        return String(item?.protected_boundary || '').trim();
    };

    const collectionHealthPendingActionOwnerText = (item) => {
        const code = String(item?.action_code || '').trim();
        if (code.startsWith('ota_authorization_')) return '酒店运营人员';
        if (code.startsWith('ota_collection_')) return '产品/技术 + 酒店运营人员';
        if (code === 'ota_same_period_source_rows_missing') return '酒店运营人员';
        if (code.startsWith('ota_field_quality_')) return '产品/技术';
        return String(item?.owner || '').trim();
    };

    const collectionHealthCtripCatalogStatusText = (status) => {
        const raw = String(status || '').trim();
        const normalized = raw.toLowerCase();
        return ({
            pass: '已通过',
            ok: '已通过',
            success: '已通过',
            fail: '未通过',
            failed: '未通过',
            missing: '待验证',
            unknown: '待确认',
            snapshot_ready: '诊断快照可用',
        }[normalized] || (raw ? '待确认' : '待验证'));
    };

    const collectionHealthCtripCatalogAuthStatusText = (status) => {
        const raw = String(status || '').trim();
        const normalized = raw.toLowerCase();
        return ({
            logged_in: '登录态已验证',
            ok: '登录态可用',
            ok_or_unverified: '已有临时 Cookie/API 辅助内容，登录态待复核',
            login_required: '需要重新登录',
            expired: '登录已失效',
            unknown: '登录态待确认',
            snapshot_ready: '诊断快照可用',
        }[normalized] || (raw ? '登录态待确认' : '登录态待确认'));
    };

    const collectionHealthCtripCatalogCodeText = (value) => {
        const raw = String(value || '').trim();
        const normalized = raw.toLowerCase();
        const directMap = {
            business_overview: '收益经营',
            business_weekly_overview: '周度经营',
            sales_report: '销售报表',
            traffic_report: '流量漏斗',
            competitor_overview: '竞争表现',
            competitor_rank: '竞争圈动态-竞争圈榜单',
            im_board: '用户行为-IM看板',
            quality_psi: '服务质量 PSI',
            ads: '广告投放',
            advertising: '广告投放',
            homepage: '首页快照',
            hotel_homepage: '酒店首页',
            auth_session: '浏览器 Profile',
            response_count: '业务响应数',
            standard_rows: '标准入库行',
            endpoint_coverage: '采集规则覆盖',
            field_coverage: '字段覆盖',
            capture_gate_missing: '采集门禁缺失',
            missing_formal_endpoint: '采集规则未命中',
            missing_fields: '字段值缺失',
            no_p3_evidence: '缺少候选证据方向',
        };
        if (directMap[normalized]) return directMap[normalized];
        if (normalized.includes('traffic') || normalized.includes('flow')) return '流量漏斗';
        if (normalized.includes('competitor') || normalized.includes('rank')) return '竞争表现';
        if (normalized.includes('quality') || normalized.includes('psi')) return '服务质量';
        if (normalized.includes('ad')) return '广告投放';
        if (normalized.includes('business') || normalized.includes('sales') || normalized.includes('overview')) return '收益经营';
        if (normalized.includes('auth') || normalized.includes('login')) return '浏览器 Profile';
        if (normalized.includes('endpoint')) return '采集规则覆盖';
        if (normalized.includes('field')) return '字段覆盖';
        if (normalized.includes('standard')) return '标准入库行';
        if (normalized.includes('response')) return '业务响应数';
        return raw || '-';
    };

    const collectionHealthCtripCatalogCodeListText = (values) => {
        const list = Array.isArray(values) ? values : (values ? [values] : []);
        const mapped = list.map(collectionHealthCtripCatalogCodeText).filter(Boolean);
        return mapped.length ? Array.from(new Set(mapped)).join('、') : '-';
    };

    const collectionHealthCtripSectionText = (sections) => (
        collectionHealthCtripCatalogCodeListText(sections)
    );

    const collectionHealthCtripCatalogActionReasonText = (reason) => {
        const raw = String(reason || '').trim();
        const normalized = raw.toLowerCase();
        if (!raw) return '';
        if (normalized.includes('auth') || normalized.includes('login') || normalized.includes('cookie')) return '授权或登录态需先恢复';
        if (normalized.includes('endpoint')) return '采集规则未命中，需要补抓对应模块';
        if (normalized.includes('field')) return '字段值未返回，需要复核平台响应';
        if (normalized.includes('evidence')) return '缺少可复核响应证据';
        return raw;
    };

    const collectionHealthCtripModuleStatusText = (status) => ({
        captured: '已抓到',
        needs_mapping: '待映射',
        empty: '无有效数据',
        failed: '抓取失败',
        missing_file: '未抓到',
    }[status] || status || '-');

    const collectionHealthCtripModuleStatusClass = (status) => ({
        captured: 'bg-green-50 text-green-700 border-green-200',
        needs_mapping: 'bg-amber-50 text-amber-700 border-amber-200',
        empty: 'bg-gray-50 text-gray-500 border-gray-200',
        failed: 'bg-red-50 text-red-700 border-red-200',
        missing_file: 'bg-red-50 text-red-700 border-red-200',
    }[status] || 'bg-gray-50 text-gray-500 border-gray-200');

    const collectionHealthCtripShortList = (items, limit = 5) => {
        if (!Array.isArray(items) || !items.length) return '-';
        const head = items.slice(0, limit).join('、');
        return items.length > limit ? `${head} 等 ${items.length} 项` : head;
    };

    const collectionHealthCtripMetricText = (metric) => {
        const examples = Array.isArray(metric?.examples) ? metric.examples.filter(item => item !== null && item !== '') : [];
        const exampleText = examples.length ? examples.slice(0, 3).join(' / ') : '-';
        const count = metric?.count || 0;
        return `样例：${exampleText} · ${count} 次`;
    };

    const collectionHealthCtripValueText = (item) => {
        const value = item?.value === null || item?.value === undefined || item?.value === '' ? '-' : String(item.value);
        const unit = item?.unit ? String(item.unit) : '';
        return unit && value !== '-' ? `${value}${unit}` : value;
    };

    const collectionHealthCtripMetricDisplay = (value, unit = '') => {
        if (value === null || value === undefined || value === '') return '未抓到';
        const numeric = typeof value === 'number' ? value : (isNaN(Number(String(value).replace(/[,￥¥%]/g, ''))) ? null : Number(String(value).replace(/[,￥¥%]/g, '')));
        if (numeric === null) return String(value);
        const formatted = Math.abs(numeric) >= 1000 ? numeric.toLocaleString('zh-CN', { maximumFractionDigits: 2 }) : String(Number(numeric.toFixed(2)));
        return unit ? `${formatted}${unit}` : formatted;
    };

    const collectionHealthCtripNumberValue = (value) => {
        if (value === null || value === undefined || value === '') return null;
        if (typeof value === 'string') {
            value = value.replace(/[,￥¥%]/g, '').trim();
        }
        return isNaN(Number(value)) ? null : Number(value);
    };

    const collectionHealthCtripEffectivenessClass = (status) => ({
        effective: 'bg-green-50 text-green-700 border-green-200',
        needs_mapping: 'bg-amber-50 text-amber-700 border-amber-200',
        missing: 'bg-gray-50 text-gray-500 border-gray-200',
        fresh: 'bg-green-50 text-green-700 border-green-200',
        aging: 'bg-amber-50 text-amber-700 border-amber-200',
        stale: 'bg-red-50 text-red-700 border-red-200',
    }[status] || 'bg-gray-50 text-gray-500 border-gray-200');

    const collectionHealthFieldSourceText = (source) => {
        const raw = String(source || '').trim();
        const map = {
            ctrip: '携程',
            meituan: '美团',
            privacy_boundary: '隐私边界',
        };
        if (map[raw]) return map[raw];
        return raw ? '未识别来源' : '未标注来源';
    };

    const collectionHealthFieldModuleText = (module) => {
        const raw = String(module || '').trim();
        const map = {
            business: '经营概况',
            traffic: '流量/转化',
            order: '订单',
            orders: '订单',
            advertising: '广告',
            forbidden: '禁止采集范围',
            privacy_boundary: '隐私边界',
        };
        if (map[raw]) return map[raw];
        return raw ? '未识别模块' : '未标注模块';
    };

    const collectionHealthFieldStorageTableText = (storageTable) => {
        const raw = String(storageTable || '').trim();
        const map = {
            online_daily_data: 'OTA 数据入库表',
            not_collected: '不采集/不入库',
        };
        if (map[raw]) return map[raw];
        return raw ? '未识别入库位置' : '未标注入库位置';
    };

    const collectionHealthFieldAssetStatusText = (field) => {
        const status = String(field?.asset_status || '').trim();
        const storageTable = String(field?.storage_table || '').trim();
        if (status === 'forbidden' || storageTable === 'not_collected') return '禁止采集';
        if (status === 'not_returned_visible') return '平台未返回可见';
        if (status === 'stable') return '稳定字段';
        if (field?.required) return '必填字段';
        return '字段定义';
    };

    const collectionHealthFieldAssetStatusClass = (field) => {
        const status = String(field?.asset_status || '').trim();
        const storageTable = String(field?.storage_table || '').trim();
        if (status === 'forbidden' || storageTable === 'not_collected') return 'bg-red-50 text-red-700 border-red-200';
        if (status === 'not_returned_visible') return 'bg-amber-50 text-amber-700 border-amber-200';
        if (status === 'stable') return 'bg-green-50 text-green-700 border-green-200';
        if (field?.required) return 'bg-blue-50 text-blue-700 border-blue-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const collectionHealthFieldAssetListText = (rows) => {
        const items = Array.isArray(rows) ? rows : [];
        if (!items.length) return '-';
        return items
            .slice(0, 4)
            .map(item => item.label || item.field || '-')
            .filter(Boolean)
            .join(' / ') + (items.length > 4 ? ` 等${items.length}项` : '');
    };

    const buildCollectionHealthAuthorizationRowsReadable = (rows = []) => (
        (Array.isArray(rows) ? rows : []).map(row => {
            const platform = String(row?.platform || '').trim();
            const platformKey = platform.toLowerCase();
            const statusRawText = String(row?.status || '').trim();
            const messageRawText = String(row?.message || '').trim();
            const actionHintRawText = String(row?.action_hint || '').trim();
            const nameText = String(row?.name || '').trim() || '未命名授权';
            const configText = String(row?.config_id || row?.id || row?.hotel_id || '').trim();
            return {
                ...row,
                platformKey,
                platformText: collectionHealthAuthorizationPlatformText(platform),
                nameText,
                statusRawText,
                messageText: collectionHealthAuthorizationMessageText(row),
                messageRawText,
                actionHintText: collectionHealthAuthorizationActionHintText(row),
                actionHintRawText,
                metaRawText: `${platform || 'platform_missing'} / ${statusRawText || 'status_missing'} / ${configText || 'config_missing'}`,
            };
        })
    );

    const buildCollectionHealthFailureReasonRows = (items = []) => (
        (Array.isArray(items) ? items : []).map(item => {
            const platformText = dataHealthPlatformText(item?.platform) || 'OTA 平台';
            const typeText = collectionHealthFailureTypeText(item?.type);
            return {
                ...item,
                platformText,
                typeText,
                metaRawText: `${item?.platform || 'platform_missing'} / ${item?.type || 'type_missing'}`,
                reasonText: collectionHealthFailureReasonText(item?.reason),
                reasonRawText: String(item?.reason || ''),
                nextActionText: collectionHealthFailureNextActionText(item?.next_action, item),
                nextActionRawText: String(item?.next_action || ''),
            };
        })
    );

    const buildCollectionHealthPendingActionRows = (items = []) => (
        (Array.isArray(items) ? items : []).map(item => {
            const evidenceNeededRawText = Array.isArray(item?.evidence_needed)
                ? item.evidence_needed.map(value => String(value || '').trim()).filter(Boolean).join('、')
                : String(item?.evidence_needed || '').trim();
            return {
                ...item,
                typeText: collectionHealthPendingActionTypeText(item),
                typeRawText: String(item?.type || ''),
                platformText: collectionHealthPendingActionPlatformText(item?.platform),
                reasonText: collectionHealthPendingActionReasonText(item),
                reasonRawText: String(item?.reason || ''),
                actionText: collectionHealthPendingActionText(item),
                actionRawText: String(item?.action || item?.next_action || ''),
                ownerText: collectionHealthPendingActionOwnerText(item),
                ownerRawText: String(item?.owner || ''),
                evidenceNeededText: collectionHealthPendingActionEvidenceText(item),
                evidenceNeededRawText,
                protectedBoundaryText: collectionHealthPendingActionProtectedBoundaryText(item),
                protectedBoundaryRawText: String(item?.protected_boundary || ''),
            };
        })
    );

    const buildCollectionHealthFieldAssetCards = (summary = {}) => [
        { key: 'stable', label: '稳定字段', value: summary.stable_field_count || summary.required_field_count || 0 },
        { key: 'not-returned', label: '未返回字段', value: summary.not_returned_field_count || 0 },
        { key: 'forbidden', label: '禁止采集', value: summary.forbidden_field_count || 0 },
        { key: 'collectable', label: '可采集字段', value: summary.collectable_field_count ?? summary.field_count ?? 0 },
    ];

    const collectionHealthLifecycleStageStatus = (stage, context = {}) => {
        const key = String(stage?.stage || '');
        const auth = context.authorization || {};
        const latest = context.latestLog || {};
        const quality = context.quality || {};
        const catalog = context.ctripCatalog || {};
        const profileSummary = context.platformProfileSummary || {};
        if (key === 'platform_binding') {
            if (Number(profileSummary.ready_to_collect || 0) > 0) return 'ok';
            if (Number(profileSummary.identity_blocked || 0) > 0) return 'failed';
            if (Number(profileSummary.needs_identity_check || 0) > 0) return 'warning';
            return auth.total ? 'warning' : 'unknown';
        }
        if (key === 'authorization') {
            return auth.overall_status || (auth.total ? 'warning' : 'unknown');
        }
        if (key === 'trial_capture') {
            return latest.status || catalog.capture_gate_status || 'unknown';
        }
        if (key === 'field_assets') {
            return context.fieldAssetStatus || 'unknown';
        }
        if (key === 'quality_gate') {
            return quality.status || 'unknown';
        }
        return 'unknown';
    };

    const collectionHealthLifecycleReadyCount = (rows = []) => (
        (Array.isArray(rows) ? rows : []).filter(row => ['ok', 'success'].includes(String(row.status || '').toLowerCase())).length
    );

    const buildCollectionHealthCtripCatalogCards = (catalog = {}) => {
        const valueOrZero = (key) => catalog[key] || 0;
        return [
            { key: 'sections', label: '覆盖模块', value: `${valueOrZero('section_count')}`, sub: '经营、流量、竞争等' },
            { key: 'rules', label: '采集规则', value: `${valueOrZero('endpoint_count')}`, sub: '可请求的数据接口' },
            { key: 'metrics', label: '指标口径', value: `${valueOrZero('field_count')}`, sub: '已定义核心指标' },
            { key: 'responses', label: '接口响应', value: `${valueOrZero('response_count')}`, sub: valueOrZero('response_count') > 0 ? '本轮已返回' : '本轮未返回' },
            { key: 'rows', label: '入库快照', value: `${valueOrZero('standard_row_count')}`, sub: valueOrZero('standard_row_count') > 0 ? '已形成标准数据' : '未形成标准数据' },
            { key: 'coverage', label: '覆盖率', value: catalog.coverage_rate === null || catalog.coverage_rate === undefined ? '-' : `${catalog.coverage_rate}%`, sub: '按已抓/待补统计' },
        ];
    };

    const collectionHealthCtripCatalogStatus = (catalog = {}) => {
        if (!catalog.available) return 'waiting_config';
        if (catalog.is_live_capture_ready) return 'ok';
        const gate = String(catalog.capture_gate_status || '').toLowerCase();
        if (gate === 'fail') return 'failed';
        if (['pass', 'ok', 'success'].includes(gate)) return 'ok';
        if (gate === 'missing') return 'warning';
        return 'unknown';
    };

    const collectionHealthCtripCatalogMessage = (catalog = {}) => {
        if (!catalog.available) return catalog.message || '等待携程采集目录生成';
        if (catalog.is_live_capture_ready) return '携程真实采集已通过基础校验，可用于判断快照可信度。';
        const gate = String(catalog.capture_gate_status || '').toLowerCase();
        if (gate === 'fail') return '携程真实采集未通过，当前快照只保留聚合状态。';
        if (gate === 'missing') return '尚未形成完整采集校验结果。';
        return catalog.message || '携程采集覆盖统计已更新。';
    };

    const collectionHealthCtripCatalogGateText = (catalog = {}) => {
        if (catalog.is_live_capture_ready) return '采集状态：可用';
        const gate = String(catalog.capture_gate_status || '').toLowerCase();
        if (gate === 'fail') return '采集状态：未通过';
        if (gate === 'missing') return '采集状态：待验证';
        return '采集状态：待处理';
    };

    const collectionHealthCtripCatalogDiagnosticScopeText = () => '经营、流量、竞争、PSI、广告';

    const collectionHealthCtripCatalogAuthText = (catalog = {}) => {
        const authStatus = String(catalog.auth_status || '').toLowerCase();
        if (authStatus === 'login_required') return '需要重新登录';
        return catalog.is_live_capture_ready ? '登录态可用' : '待验证';
    };

    const collectionHealthCtripCatalogPendingFetchText = (catalog = {}) => `${catalog.capture_gap_missing_formal_endpoint_count || 0} 项`;

    const collectionHealthCtripCatalogPendingFieldText = (catalog = {}) => `${catalog.capture_gap_missing_field_count || 0} 项`;

    const buildCollectionHealthCtripCatalogVisibleNotes = ({
        diagnosticScope = '',
        authText = '',
        pendingFetchText = '',
        pendingFieldText = '',
    } = {}) => [
        { label: '诊断口径', value: diagnosticScope },
        { label: '登录/Cookie 状态', value: authText },
        { label: '待补采集', value: pendingFetchText },
        { label: '待补字段', value: pendingFieldText },
    ];

    const collectionHealthCtripCatalogActionText = (catalog = {}) => {
        if (!catalog.available) return '等待携程采集目录生成后再判断。';
        if (catalog.is_live_capture_ready) return '';
        const authStatus = String(catalog.auth_status || '').toLowerCase();
        const blockers = Array.isArray(catalog.capture_gap_blockers) ? catalog.capture_gap_blockers : [];
        if (authStatus === 'login_required' || blockers.includes('auth_session')) {
            return 'Cookie 不可用或登录态失效，请先更新携程 Cookie。';
        }
        const missingEndpoints = Number(catalog.capture_gap_missing_formal_endpoint_count || 0);
        const missingFields = Number(catalog.capture_gap_missing_field_count || 0);
        if (missingEndpoints > 0 || missingFields > 0) {
            return '本轮采集不完整，建议重新采集目标门店数据。';
        }
        return '采集状态待确认，请查看失败原因或重新采集。';
    };

    const buildCollectionHealthCtripCatalogDetailRows = (catalog = {}) => [
        {
            key: 'default-sections',
            label: '默认采集范围',
            valueText: collectionHealthCtripCatalogCodeListText(catalog.default_sections),
            rawText: Array.isArray(catalog.default_sections) ? catalog.default_sections.join('、') : '',
        },
        {
            key: 'wide-sections',
            label: '扩展采集范围',
            valueText: collectionHealthCtripCatalogCodeListText(catalog.wide_sections),
            rawText: Array.isArray(catalog.wide_sections) ? catalog.wide_sections.join('、') : '',
        },
        {
            key: 'capture-gate',
            label: '采集门禁',
            valueText: collectionHealthCtripCatalogStatusText(catalog.capture_gate_status),
            rawText: String(catalog.capture_gate_status || 'missing'),
        },
        {
            key: 'auth-status',
            label: '登录/Cookie 状态',
            valueText: collectionHealthCtripCatalogAuthStatusText(catalog.auth_status),
            rawText: String(catalog.auth_status || 'unknown'),
        },
        {
            key: 'failed-checks',
            label: '未通过检查',
            valueText: collectionHealthCtripCatalogCodeListText(catalog.failed_check_ids),
            rawText: Array.isArray(catalog.failed_check_ids) ? catalog.failed_check_ids.join('、') : '',
            wide: true,
        },
        {
            key: 'gap-status',
            label: '采集缺口状态',
            valueText: collectionHealthCtripCatalogStatusText(catalog.capture_gap_status),
            rawText: String(catalog.capture_gap_status || 'missing'),
        },
        {
            key: 'pending-rules',
            label: '待补采集规则',
            valueText: `${Number(catalog.capture_gap_missing_formal_endpoint_count || 0)} 项`,
            rawText: String(catalog.capture_gap_missing_formal_endpoint_count || 0),
        },
        {
            key: 'pending-fields',
            label: '待补数值项',
            valueText: `${Number(catalog.capture_gap_missing_field_count || 0)} 项`,
            rawText: String(catalog.capture_gap_missing_field_count || 0),
        },
        {
            key: 'evidence-directions',
            label: '候选证据方向',
            valueText: `${Number(catalog.capture_gap_p3_evidence_section_count || 0)} 个`,
            rawText: String(catalog.capture_gap_p3_evidence_section_count || 0),
        },
        {
            key: 'blockers',
            label: '阻塞原因',
            valueText: collectionHealthCtripCatalogCodeListText(catalog.capture_gap_blockers),
            rawText: Array.isArray(catalog.capture_gap_blockers) ? catalog.capture_gap_blockers.join('、') : '',
            wide: true,
        },
    ];

    const buildCollectionHealthCtripCatalogActionRows = (catalog = {}) => {
        const actions = Array.isArray(catalog?.capture_gap_next_actions)
            ? catalog.capture_gap_next_actions
            : [];
        return actions.map(action => {
            const sectionText = collectionHealthCtripCatalogCodeText(action?.section || action?.candidate_section || '');
            const endpointText = action?.endpoint_id ? '对应采集规则' : '';
            return {
                ...action,
                actionText: String(action?.action || '').trim() || '补齐携程采集证据',
                reasonText: collectionHealthCtripCatalogActionReasonText(action?.reason),
                scopeText: [sectionText, endpointText].filter(Boolean).join(' / '),
                rawText: [
                    action?.action,
                    action?.reason,
                    action?.section,
                    action?.candidate_section,
                    action?.endpoint_id,
                ].filter(Boolean).join(' / '),
            };
        });
    };

    const buildCollectionHealthCtripPersistedRows = (rows = []) => (
        (Array.isArray(rows) ? rows : [])
            .filter(row => String(row?.source || '').toLowerCase() === 'ctrip')
            .sort((a, b) => (
                String(b?.data_date || '').localeCompare(String(a?.data_date || ''))
                || String(b?.updated_at || b?.update_time || '').localeCompare(String(a?.updated_at || a?.update_time || ''))
                || Number(b?.id || 0) - Number(a?.id || 0)
            ))
    );

    const collectionHealthCtripIdentityBlocked = (report = {}) => (
        Number(report.filtered_count || 0) > 0 && ['blocked', 'warning'].includes(String(report.status || '').toLowerCase())
    );

    const collectionHealthCtripIdentityMessage = (report = {}) => {
        const message = String(report.message || '').trim();
        const nextAction = String(report.next_action || '').trim();
        return [message, nextAction].filter(Boolean).join(' ');
    };

    const buildCollectionHealthCtripLatestCards = (latest = {}) => {
        const freshness = latest.freshness || {};
        const effectiveness = latest.effectiveness || {};
        const freshnessValue = freshness.age_hours === null || freshness.age_hours === undefined
            ? (freshness.label || '暂无有效采集')
            : `${freshness.age_hours} 小时`;
        return [
            { key: 'module_count', label: '覆盖模块', value: `${latest.module_count || 0} 个`, sub: '不含订单明细、点评列表' },
            { key: 'response_count', label: '接口响应', value: `${latest.response_count || 0}`, sub: latest.response_count ? '本轮已返回' : '本轮未返回' },
            { key: 'standard_row_count', label: '入库快照', value: `${latest.standard_row_count || 0}`, sub: latest.standard_row_count ? '可用于门店分析' : '未形成标准数据' },
            { key: 'catalog_fact_count', label: '已识别指标', value: `${latest.catalog_fact_count || 0}`, sub: '已提取的字段和值' },
            { key: 'coverage_rate', label: '覆盖率', value: latest.coverage_rate === null || latest.coverage_rate === undefined ? '-' : `${latest.coverage_rate}%`, sub: '按已抓/待补口径统计' },
            { key: 'freshness', label: '实效', value: freshnessValue, sub: effectiveness.label || freshness.label || '需要重新采集' },
        ];
    };

    const buildCollectionHealthCtripOverviewAuthState = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        if (!safeRows.length) return { value: '未配置', status: 'waiting_config', className: 'text-amber-700' };
        if (safeRows.some(row => row?.is_usable)) return { value: '登录态已验证', status: 'ok', className: 'text-emerald-700' };
        const status = String(safeRows[0]?.status || '').toLowerCase();
        if (['expired', 'failed', 'auth_failed'].includes(status) || safeRows.every(row => !row?.is_usable)) {
            return { value: '需重新登录', status: 'expired', className: 'text-red-700' };
        }
        return { value: '待验证', status: status || 'unknown', className: 'text-amber-700' };
    };

    const buildCollectionHealthCtripOverviewStatusCards = ({
        latest = {},
        persistedCount = 0,
        authState = {},
        latestRow = {},
        identityReport = {},
        identityBlocked = false,
        dataDate = '-',
        capturedAt = '暂无有效采集',
        sourceRowCount = 0,
        moduleCount = 5,
        catalogAuthText = '',
    } = {}) => {
        const effect = latest.effectiveness || {};
        const safePersistedCount = Number(persistedCount || 0);
        const rowCount = identityBlocked ? safePersistedCount : Number(sourceRowCount || 0);
        const statusText = identityBlocked ? '门店身份冲突' : (effect.label || (rowCount > 0 ? '已形成入库快照' : '未形成入库快照'));
        const statusClass = identityBlocked ? 'text-red-700' : (['effective', 'fresh'].includes(String(effect.status || '')) || rowCount > 0 ? 'text-emerald-700' : 'text-amber-700');
        return [
            { key: 'auth', label: '当前授权', value: authState.value, sub: catalogAuthText, className: authState.className },
            { key: 'date', label: '数据日期', value: dataDate || latestRow.data_date || '-', sub: '当前展示口径', className: 'text-gray-900' },
            { key: 'latest', label: '最近采集', value: capturedAt || latestRow.updated_at || '暂无有效采集', sub: latest.freshness?.label || '-', className: 'text-gray-900' },
            { key: 'rows', label: '本轮入库', value: identityBlocked ? `安全 ${safePersistedCount} 条` : (rowCount > 0 ? `${rowCount} 条` : '未形成入库快照'), sub: identityBlocked ? `已过滤 ${identityReport.filtered_count || 0} 条错店风险数据` : 'online_daily_data', className: identityBlocked ? 'text-red-700' : (rowCount > 0 ? 'text-emerald-700' : 'text-amber-700') },
            { key: 'modules', label: '可抓模块', value: '经营 / 流量 / 竞争 / PSI / 广告', sub: `${moduleCount || 5} 个模块`, className: 'text-gray-900' },
            { key: 'status', label: '采集状态', value: statusText, sub: identityBlocked ? '已阻止错店数据展示' : `缺失 ${latest.missing_field_count || 0} 项`, className: statusClass },
        ];
    };

    const buildCtripOverviewFetchModuleCards = (authState = {}) => {
        const disabledLabel = authState.status === 'expired' ? '重新登录后抓取' : '';
        const actionLabel = (label) => disabledLabel || label;
        return [
            { key: 'business', title: '收益经营', subtitle: '订单、间夜、成交率、均价', tab: 'ctrip-flow-overview', icon: 'fas fa-yen-sign', actionLabel: actionLabel('抓取经营') },
            { key: 'traffic', title: '流量漏斗', subtitle: '曝光、访客、下单转化', tab: 'ctrip-traffic', icon: 'fas fa-filter', actionLabel: actionLabel('抓取流量') },
            { key: 'competitor', title: '竞争表现', subtitle: '竞争圈排名、价格排名', tab: 'ctrip-ranking', icon: 'fas fa-trophy', actionLabel: actionLabel('抓取竞争') },
            { key: 'quality', title: '服务质量', subtitle: 'PSI、评分、回复率、收藏数', tab: 'ctrip-quality', icon: 'fas fa-shield-alt', actionLabel: actionLabel('抓取 PSI') },
            { key: 'ads', title: '广告投放', subtitle: '花费、曝光、点击、ROAS', tab: 'ctrip-ads', icon: 'fas fa-bullhorn', actionLabel: actionLabel('抓取广告') },
        ];
    };

    const collectionHealthCtripMetricPreviewValue = (preview, key, options = {}) => {
        if (!preview || !key) return undefined;
        const normalizedKey = String(key || '').trim();
        for (const mapKey of ['metrics', 'raw_metrics', 'rank_metrics']) {
            const map = preview?.[mapKey];
            if (map && typeof map === 'object' && Object.prototype.hasOwnProperty.call(map, normalizedKey) && map[normalizedKey] !== null && map[normalizedKey] !== '') {
                return map[normalizedKey];
            }
        }
        if (options.direct !== false && Object.prototype.hasOwnProperty.call(preview, key) && preview[key] !== null && preview[key] !== '') {
            return preview[key];
        }
        return undefined;
    };

    const collectionHealthCtripCalculatedValue = (preview, key) => {
        const amount = collectionHealthCtripNumberValue(preview?.amount);
        const quantity = collectionHealthCtripNumberValue(preview?.quantity);
        const listExposure = collectionHealthCtripNumberValue(preview?.list_exposure);
        const detailExposure = collectionHealthCtripNumberValue(preview?.detail_exposure);
        const orderFilling = collectionHealthCtripNumberValue(preview?.order_filling_num);
        const orderSubmit = collectionHealthCtripNumberValue(preview?.order_submit_num);
        const adOrderAmount = collectionHealthCtripNumberValue(preview?.ad_order_amount ?? preview?.order_amount);
        if (key === 'avg_price' && amount !== null && quantity && quantity > 0) return amount / quantity;
        if (key === 'exposure_conversion_rate' && listExposure && listExposure > 0 && detailExposure !== null) return detailExposure / listExposure * 100;
        if (key === 'order_fill_rate' && detailExposure && detailExposure > 0 && orderFilling !== null) return orderFilling / detailExposure * 100;
        if (key === 'deal_rate' && orderFilling && orderFilling > 0 && orderSubmit !== null) return orderSubmit / orderFilling * 100;
        if (key === 'roas' && amount && amount > 0 && adOrderAmount !== null) return adOrderAmount / amount;
        return undefined;
    };

    const collectionHealthCtripKeysForLabels = (labels) => {
        const joined = (Array.isArray(labels) ? labels : [labels]).join(' ');
        const rules = [
            [/订单|预订/, ['book_order_num', 'order_count', 'orderCount', 'bookOrderNum']],
            [/间夜/, ['quantity', 'room_nights', 'roomNights']],
            [/销售额|成交收入|花费|费用|成本/, ['amount', 'order_amount', 'cost_amount']],
            [/平均卖价|均价|价格/, ['avg_price', 'average_price', 'avgPrice']],
            [/访客|详情页/, ['detail_exposure', 'detail_visitor', 'visitor_count', 'detailVisitors']],
            [/曝光|列表页/, ['list_exposure', 'listExposure', 'exposure']],
            [/提交/, ['order_submit_num', 'submitUsers', 'orderSubmitNum']],
            [/点击/, ['detail_exposure', 'clicks', 'click_count']],
            [/转化率|成交率/, ['flow_rate', 'conversion_rate', 'exposure_conversion_rate']],
            [/点评分|评分/, ['comment_score', 'commentScore', 'qunar_comment_score']],
            [/PSI|服务质量/, ['psi', 'psi_score', 'psiScore', 'service_score']],
            [/回复率/, ['five_min_reply_rate', 'reply_rate', 'response_rate']],
            [/收藏/, ['hotel_collect', 'collect_count', 'favorite_count']],
            [/排名/, ['amount_rank', 'rank', 'quantity_rank', 'book_order_num_rank']],
            [/ROAS/, ['roas']],
        ];
        const keys = [];
        rules.forEach(([pattern, values]) => {
            if (pattern.test(joined)) keys.push(...values);
        });
        return [...new Set(keys)];
    };

    const collectionHealthCtripDataTypesForSections = (sections) => {
        const set = new Set();
        (Array.isArray(sections) ? sections : [sections]).forEach(section => {
            const value = String(section || '');
            if (value.includes('ads')) set.add('advertising');
            if (value.includes('traffic')) set.add('traffic');
            if (value.includes('business') || value.includes('sales') || value.includes('competitor') || value.includes('quality')) set.add('business');
        });
        return Array.from(set);
    };

    const collectionHealthCtripActionForSections = (sections) => {
        const list = (Array.isArray(sections) ? sections : [sections]).map(item => String(item || ''));
        if (list.some(section => section.includes('ads'))) return { module: '广告投放', actionLabel: '补抓广告', actionTab: 'ctrip-ads' };
        if (list.some(section => section.includes('traffic'))) return { module: '流量漏斗', actionLabel: '补抓流量', actionTab: 'ctrip-traffic' };
        if (list.some(section => section.includes('competitor'))) return { module: '竞争表现', actionLabel: '补抓竞争', actionTab: 'ctrip-ranking' };
        if (list.some(section => section.includes('quality'))) return { module: '服务质量', actionLabel: '补抓 PSI', actionTab: 'ctrip-quality' };
        return { module: '收益经营', actionLabel: '补抓经营', actionTab: 'ctrip-flow-overview' };
    };

    const collectionHealthCtripModuleStats = (sections, modules = []) => {
        const sectionSet = new Set((Array.isArray(sections) ? sections : [sections]).map(item => String(item || '').trim()).filter(Boolean));
        const matchedModules = (Array.isArray(modules) ? modules : []).filter(module => sectionSet.has(String(module?.section || '').trim()));
        return matchedModules.reduce((stats, module) => {
            stats.moduleCount += 1;
            stats.fileFound = stats.fileFound || !!module.file_found;
            stats.failed = stats.failed || ['failed', 'error'].includes(String(module.status || '').toLowerCase()) || String(module.gate_status || '').toLowerCase() === 'fail';
            stats.responseCount += Number(module.response_count || 0);
            stats.standardRowCount += Number(module.standard_row_count || 0);
            stats.catalogFactCount += Number(module.catalog_fact_count || 0);
            stats.missingEndpointCount += Number(module.missing_endpoint_count || 0);
            return stats;
        }, {
            moduleCount: 0,
            fileFound: false,
            failed: false,
            responseCount: 0,
            standardRowCount: 0,
            catalogFactCount: 0,
            missingEndpointCount: 0,
        });
    };

    const collectionHealthCtripRowsForContext = (rows = [], options = {}) => {
        const dataTypes = Array.isArray(options.dataTypes) ? options.dataTypes.map(item => String(item).toLowerCase()) : [];
        const dimensionIncludes = Array.isArray(options.dimensionIncludes) ? options.dimensionIncludes.map(item => String(item).toLowerCase()) : [];
        return (Array.isArray(rows) ? rows : []).filter(row => {
            const dataType = String(row?.data_type || '').toLowerCase();
            if (dataTypes.length && !dataTypes.includes(dataType)) return false;
            const preview = row?.metric_preview || {};
            const dimension = String(preview.dimension || row?.dimension || '').toLowerCase();
            if (dimensionIncludes.length && !dimensionIncludes.some(item => dimension.includes(item))) return false;
            return true;
        });
    };

    const collectionHealthCtripPreviewMetricKey = (preview = {}) => {
        const direct = String(preview.metric_key || preview.metricKey || '').trim().toLowerCase();
        if (direct) return direct;
        const dimension = String(preview.dimension || '').trim().toLowerCase();
        const match = dimension.match(/^catalog:[^:]+:[^:]+:([^:]+)/);
        return match ? match[1] : '';
    };

    const collectionHealthCtripMetricKeyAliases = (key) => {
        const aliasMap = {
            amount: ['amount', 'order_amount', 'book_amount', 'sale_amount', 'ad_cost', 'cost_amount', 'business_amount'],
            order_amount: ['amount', 'order_amount', 'book_amount', 'sale_amount', 'ad_order_amount', 'booking_amount', 'gmv'],
            cost_amount: ['amount', 'ad_cost', 'cost_amount'],
            quantity: ['quantity', 'room_nights', 'roomnight', 'room_night', 'check_out_quantity', 'ad_room_nights'],
            room_nights: ['quantity', 'room_nights', 'roomnight', 'room_night'],
            book_order_num: ['book_order_num', 'order_count', 'orders', 'book_order_count', 'bookordernum', 'ad_orders'],
            order_count: ['book_order_num', 'order_count', 'orders', 'book_order_count'],
            list_exposure: ['list_exposure', 'listexposure', 'ad_impressions', 'impressions', 'exposure'],
            detail_exposure: ['detail_exposure', 'detail_visitor', 'visitor_count', 'detailvisitors', 'ad_clicks', 'clicks'],
            order_filling_num: ['order_filling_num', 'order_page_visitor', 'orderfillingnum'],
            order_submit_num: ['order_submit_num', 'order_submit_user', 'ordersubmitnum', 'submit_users', 'ad_orders'],
            flow_rate: ['flow_rate', 'conversion_rate', 'exposure_conversion_rate', 'order_conversion_rate', 'deal_rate'],
            conversion_rate: ['flow_rate', 'conversion_rate', 'exposure_conversion_rate', 'order_conversion_rate', 'deal_rate'],
            deal_rate: ['deal_rate', 'transaction_rate', 'conversion_rate'],
            avg_price: ['avg_price', 'average_price', 'adr', 'price'],
            average_price: ['avg_price', 'average_price', 'adr', 'price'],
            rank: ['rank', 'amount_rank', 'quantity_rank', 'book_order_num_rank', 'avg_price_rank', 'price_rank', 'visitor_rank', 'flow_rank', 'traffic_rank'],
            data_value: ['data_value', 'value'],
            ad_order_amount: ['ad_order_amount', 'order_amount', 'booking_amount', 'gmv'],
            roas: ['roas', 'roi'],
            psi: ['psi', 'psi_score', 'service_score'],
            psi_score: ['psi', 'psi_score', 'service_score'],
            comment_score: ['comment_score', 'score', 'hotel_score'],
            qunar_comment_score: ['qunar_comment_score', 'comment_score', 'score'],
            five_min_reply_rate: ['five_min_reply_rate', 'reply_rate', 'response_rate'],
            reply_rate: ['five_min_reply_rate', 'reply_rate', 'response_rate'],
            hotel_collect: ['hotel_collect', 'collect_count', 'favorite_count'],
        };
        const normalized = String(key || '').trim().toLowerCase();
        return new Set([normalized, ...(aliasMap[normalized] || [])]);
    };

    const collectionHealthCtripMetricKeyMatches = (preview, key) => {
        const metricKey = collectionHealthCtripPreviewMetricKey(preview);
        if (!metricKey) return true;
        const aliases = collectionHealthCtripMetricKeyAliases(key);
        if (aliases.has(metricKey)) return true;
        const metricKeyParts = metricKey.split(/[\+,\|\s]+/).map(part => part.trim()).filter(Boolean);
        return metricKeyParts.some(part => collectionHealthCtripMetricKeyAliases(key).has(part));
    };

    const collectionHealthCtripMissingDiagnosis = (sections, labels, options = {}) => {
        const sectionList = (Array.isArray(sections) ? sections : [sections]).map(item => String(item || '').trim()).filter(Boolean);
        const targetLabels = (Array.isArray(labels) ? labels : [labels]).map(item => String(item || '').trim()).filter(Boolean);
        const action = collectionHealthCtripActionForSections(sectionList);
        const authState = options.authState || {};
        if (authState.status === 'waiting_config') {
            return { diagnosisType: 'config', module: action.module, reasonText: '配置问题：缺少携程 Cookie，先补充 Cookie 后再抓。', actionLabel: '检查配置', actionTab: '' };
        }
        if (authState.status === 'expired') {
            return { diagnosisType: 'config', module: action.module, reasonText: '配置问题：携程 Cookie 不可用或过期，先重新登录或更新 Cookie/API 辅助内容。', actionLabel: '更新 Cookie/API 辅助', actionTab: '' };
        }
        if (options.identityBlocked) {
            return {
                diagnosisType: 'hotel_identity_conflict',
                module: action.module,
                reasonText: options.identityMessage || '已抓到携程数据，但门店身份存在冲突，系统已阻止展示错店风险数据。',
                actionLabel: '检查配置',
                actionTab: '',
            };
        }

        const dataTypes = options.dataTypes || collectionHealthCtripDataTypesForSections(sectionList);
        const rows = collectionHealthCtripRowsForContext(options.persistedRows || [], { dataTypes, dimensionIncludes: options.dimensionIncludes || [] });
        const stats = collectionHealthCtripModuleStats(sectionList, options.latestModules || []);
        if (!stats.fileFound && rows.length === 0) {
            return { diagnosisType: 'not_captured', module: action.module, reasonText: `未补抓对应模块：尚无${action.module}抓取结果，先执行${action.actionLabel}。`, actionLabel: action.actionLabel, actionTab: action.actionTab };
        }
        if (stats.failed) {
            return { diagnosisType: 'request_failed', module: action.module, reasonText: `接口失败：${action.module}已发起但请求失败，需检查 Cookie 或平台返回。`, actionLabel: action.actionLabel, actionTab: action.actionTab };
        }
        if (stats.responseCount <= 0 && stats.fileFound) {
            return { diagnosisType: 'wrong_endpoint', module: action.module, reasonText: `抓取位置不对：${action.module}接口未命中，需补抓对应模块或校验平台页面位置。`, actionLabel: action.actionLabel, actionTab: action.actionTab };
        }
        if (stats.missingEndpointCount > 0 && rows.length === 0) {
            return { diagnosisType: 'wrong_endpoint', module: action.module, reasonText: `抓取位置不对：${action.module}关键接口未命中，需补抓对应模块。`, actionLabel: action.actionLabel, actionTab: action.actionTab };
        }
        if (stats.responseCount > 0 && stats.standardRowCount <= 0 && rows.length === 0) {
            return { diagnosisType: 'mapping', module: action.module, reasonText: `字段映射/入库问题：${action.module}接口有响应但未形成入库字段。`, actionLabel: action.actionLabel, actionTab: action.actionTab };
        }
        if (rows.length > 0 || stats.standardRowCount > 0 || stats.catalogFactCount > 0) {
            return { diagnosisType: 'mapping', module: action.module, reasonText: `字段映射/入库问题：${action.module}已抓到数据，但${targetLabels[0] || '该字段'}未保存或维度不匹配。`, actionLabel: action.actionLabel, actionTab: action.actionTab };
        }
        return { diagnosisType: 'unknown', module: action.module, reasonText: `需补抓${action.module}后复核配置和字段映射。`, actionLabel: action.actionLabel, actionTab: action.actionTab };
    };

    const collectionHealthCtripMissingMetric = (sections, labels, options = {}) => {
        const diagnosis = collectionHealthCtripMissingDiagnosis(sections, labels, options);
        return { value: '未抓到', status: 'missing', source: diagnosis.reasonText, ...diagnosis };
    };

    const collectionHealthCtripMetricFromRows = (keys, options = {}) => {
        const targetKeys = (keys || []).filter(Boolean);
        if (!targetKeys.length) return null;
        const dataTypes = Array.isArray(options.dataTypes) ? options.dataTypes.map(item => String(item).toLowerCase()) : [];
        const dimensionIncludes = Array.isArray(options.dimensionIncludes) ? options.dimensionIncludes.map(item => String(item).toLowerCase()) : [];
        const unit = options.unit || '';
        const rows = Array.isArray(options.persistedRows) ? options.persistedRows : [];
        for (const row of rows) {
            const dataType = String(row?.data_type || '').toLowerCase();
            if (dataTypes.length && !dataTypes.includes(dataType)) continue;
            const preview = row?.metric_preview || {};
            const dimension = String(preview.dimension || row?.dimension || '').toLowerCase();
            if (dimensionIncludes.length && !dimensionIncludes.some(item => dimension.includes(item))) continue;
            for (const key of targetKeys) {
                const previewMetricKey = collectionHealthCtripPreviewMetricKey(preview);
                const metricKeyMatched = collectionHealthCtripMetricKeyMatches(preview, key);
                const canUseDirectPreviewValue = metricKeyMatched && (previewMetricKey || dimensionIncludes.length);
                let value = collectionHealthCtripMetricPreviewValue(preview, key, { direct: canUseDirectPreviewValue });
                if (canUseDirectPreviewValue && value === undefined) {
                    value = collectionHealthCtripMetricPreviewValue(preview, 'data_value');
                }
                if (value === undefined) {
                    const calculatedValue = collectionHealthCtripCalculatedValue(preview, key);
                    if (calculatedValue !== undefined) {
                        value = calculatedValue;
                    }
                }
                if (value === undefined || value === null || value === '') continue;
                return {
                    value: collectionHealthCtripMetricDisplay(value, unit),
                    status: 'ok',
                    source: `最近入库 ${row.data_date || '-'}`,
                    reasonText: '已入库',
                    diagnosisType: 'ok',
                };
            }
        }
        return null;
    };

    const collectionHealthCtripMetricValue = (sections, labels, options = {}) => {
        const modules = Array.isArray(options.latestModules) ? options.latestModules : [];
        const targetLabels = (Array.isArray(labels) ? labels : [labels]).map(label => String(label || '').trim()).filter(Boolean);
        const sectionList = (Array.isArray(sections) ? sections : [sections]).map(section => String(section || '').trim()).filter(Boolean);
        const persisted = collectionHealthCtripMetricFromRows(
            options.keys || collectionHealthCtripKeysForLabels(targetLabels),
            {
                dataTypes: options.dataTypes || collectionHealthCtripDataTypesForSections(sectionList),
                dimensionIncludes: options.dimensionIncludes || [],
                unit: options.unit || '',
                persistedRows: options.persistedRows || [],
            }
        );
        if (persisted) return persisted;
        const canUseModuleSnapshot = !String(options.targetHotelId || '').trim();
        if (!canUseModuleSnapshot) {
            return collectionHealthCtripMissingMetric(sectionList, targetLabels, options);
        }
        for (const section of sectionList) {
            const module = modules.find(item => item.section === section);
            if (!module) continue;
            const snapshotValues = Array.isArray(module.snapshot_values) ? module.snapshot_values : [];
            const snapshotHit = snapshotValues.find(item => targetLabels.includes(String(item?.label || '').trim()));
            if (snapshotHit) {
                return {
                    value: collectionHealthCtripValueText(snapshotHit),
                    status: 'ok',
                    source: `${module.label || section} / ${snapshotHit.label}`,
                    reasonText: '已抓到',
                    diagnosisType: 'ok',
                };
            }
            const metrics = Array.isArray(module.metrics) ? module.metrics : [];
            const metricHit = metrics.find(item => targetLabels.includes(String(item?.label || item?.key || '').trim()));
            const examples = Array.isArray(metricHit?.examples) ? metricHit.examples.filter(item => item !== null && item !== '') : [];
            if (metricHit && examples.length) {
                return {
                    value: collectionHealthCtripValueText({ value: examples[0], unit: metricHit.unit || '' }),
                    status: 'ok',
                    source: `${module.label || section} / ${metricHit.label || metricHit.key || '-'}`,
                    reasonText: '已抓到',
                    diagnosisType: 'ok',
                };
            }
        }
        return collectionHealthCtripMissingMetric(sectionList, targetLabels, options);
    };

    const collectionHealthCtripOverviewMetric = (label, sections, labels, options = {}, context = {}) => ({
        label,
        ...collectionHealthCtripMetricValue(sections, labels, { ...context, ...options }),
    });

    const buildCollectionHealthCtripCoreSnapshotGroups = (context = {}) => {
        const metric = (sections, label, labels) => collectionHealthCtripOverviewMetric(label, sections, labels, {}, context);
        const groupStatus = (metrics) => metrics.some(item => item.status === 'ok') ? 'captured' : 'missing_file';
        const buildGroup = (key, label, sections, metrics) => ({
            key,
            label,
            sourceText: sections.join(' / '),
            status: groupStatus(metrics),
            metrics,
        });
        const businessMetrics = [
            metric(['business_overview', 'sales_report'], '预订销售额', ['预订销售额', '成交收入']),
            metric(['business_overview', 'sales_report'], '间夜量', ['间夜量', '成交间夜']),
            metric(['business_overview', 'sales_report'], '预订订单数', ['预订订单数', '昨日订单数']),
            metric(['business_overview', 'sales_report'], '平均卖价', ['平均卖价', '平均房价']),
            metric(['business_overview', 'sales_report'], '出租率（OTA渠道）', ['出租率']),
        ];
        const trafficMetrics = [
            metric(['traffic_report', 'business_overview'], '列表页曝光', ['列表页曝光量', '昨日浏览量']),
            metric(['traffic_report', 'business_overview'], '详情页访客', ['详情页访客量']),
            metric(['traffic_report', 'business_overview'], '访客量', ['访客量', '昨日访客数']),
            metric(['traffic_report', 'business_overview'], '订单提交人数', ['订单提交人数']),
            metric(['traffic_report', 'business_overview'], '转化率', ['流量转化率', '成交/下单转化率', '下单转化率', '昨日转化率']),
        ];
        const competitorMetrics = [
            metric(['business_overview', 'competitor_overview', 'competitor_rank'], '竞争圈排名', ['竞争圈排名', '竞争圈成交排名', '预订销售额排名', '访客排名']),
            metric(['business_overview', 'competitor_overview'], '竞争圈平均值', ['竞争圈平均值', '竞争圈平均访客', '竞品访客']),
            metric(['business_overview', 'competitor_overview', 'competitor_rank'], '竞品价格/排名', ['平均卖价', '平均房价', '出租率排名', '销售额排名']),
        ];
        const qualityMetrics = [
            metric(['quality_psi', 'business_overview'], 'PSI服务质量分', ['PSI服务质量分', '服务质量分']),
            metric(['quality_psi', 'business_overview'], '基础分', ['基础分']),
            metric(['quality_psi', 'business_overview'], '奖励分', ['奖励分']),
            metric(['quality_psi', 'business_overview'], '减分项', ['减分项']),
            metric(['quality_psi'], '提分任务', ['提分任务']),
        ];
        const adsMetrics = [
            metric(['ads_pyramid'], '广告花费', ['广告花费']),
            metric(['ads_pyramid'], '广告曝光', ['广告曝光']),
            metric(['ads_pyramid'], '广告点击', ['广告点击']),
            metric(['ads_pyramid'], '广告订单', ['广告订单', '广告预订订单']),
            metric(['ads_pyramid'], '广告金额', ['广告金额', '广告订单金额']),
            metric(['ads_pyramid'], 'ROAS', ['ROAS', '广告投产比ROAS']),
        ];
        return [
            buildGroup('business', '经营成交', ['business_overview', 'sales_report'], businessMetrics),
            buildGroup('traffic', '流量转化', ['traffic_report', 'business_overview'], trafficMetrics),
            buildGroup('competitor', '竞争表现', ['business_overview', 'competitor_overview'], competitorMetrics),
            buildGroup('quality', '服务质量', ['quality_psi', 'business_overview'], qualityMetrics),
            buildGroup('ads', '广告投放', ['ads_pyramid'], adsMetrics),
        ];
    };

    const buildCollectionHealthCtripOverviewRevenueMetrics = (context = {}) => [
        collectionHealthCtripOverviewMetric('实时预订订单', ['business_overview', 'sales_report'], ['预订订单数', '昨日订单数'], { dataTypes: ['business'], keys: ['book_order_num', 'order_count', 'orderCount', 'bookOrderNum'] }, context),
        collectionHealthCtripOverviewMetric('实时在店间夜', ['business_overview', 'sales_report'], ['间夜量', '成交间夜'], { dataTypes: ['business'], keys: ['quantity', 'room_nights', 'roomNights'] }, context),
        collectionHealthCtripOverviewMetric('离店销售额', ['business_overview', 'sales_report'], ['预订销售额', '成交收入'], { dataTypes: ['business'], keys: ['amount', 'order_amount'] }, context),
        collectionHealthCtripOverviewMetric('离店间夜', ['business_overview', 'sales_report'], ['间夜量', '成交间夜'], { dataTypes: ['business'], keys: ['quantity', 'room_nights', 'roomNights'] }, context),
        collectionHealthCtripOverviewMetric('成交率', ['business_overview', 'sales_report'], ['成交/下单转化率', '下单转化率', '流量转化率'], { dataTypes: ['business', 'traffic'], keys: ['flow_rate', 'conversion_rate', 'deal_rate'], unit: '%' }, context),
        collectionHealthCtripOverviewMetric('平均卖价', ['business_overview', 'sales_report'], ['平均卖价', '平均房价'], { dataTypes: ['business'], keys: ['avg_price', 'average_price', 'avgPrice'] }, context),
    ];

    const buildCollectionHealthCtripOverviewTrafficMetrics = (context = {}) => [
        collectionHealthCtripOverviewMetric('实时访客量', ['traffic_report', 'business_overview'], ['访客量', '详情页访客量'], { dataTypes: ['traffic', 'business'], keys: ['detail_exposure', 'detail_visitor', 'visitor_count', 'detailVisitors'] }, context),
        collectionHealthCtripOverviewMetric('实时排名', ['traffic_report', 'business_overview'], ['流量排名', '访客排名'], { dataTypes: ['traffic', 'business'], keys: ['flow_rank', 'rank', 'amount_rank'] }, context),
        collectionHealthCtripOverviewMetric('竞争圈排名', ['business_overview', 'competitor_overview', 'competitor_rank'], ['竞争圈排名', '竞争圈成交排名'], { dataTypes: ['business'], keys: ['amount_rank', 'quantity_rank', 'book_order_num_rank', 'rank'] }, context),
        collectionHealthCtripOverviewMetric('曝光转化率', ['traffic_report'], ['曝光转化率', '流量转化率'], { dataTypes: ['traffic'], keys: ['flow_rate', 'exposure_conversion_rate'], dimensionIncludes: ['self', 'myhotel'], unit: '%' }, context),
        collectionHealthCtripOverviewMetric('下单转化率', ['traffic_report'], ['下单转化率'], { dataTypes: ['traffic'], keys: ['order_fill_rate'], dimensionIncludes: ['self', 'myhotel'], unit: '%' }, context),
        collectionHealthCtripOverviewMetric('成交转化率', ['traffic_report'], ['成交转化率'], { dataTypes: ['traffic'], keys: ['deal_rate'], dimensionIncludes: ['self', 'myhotel'], unit: '%' }, context),
    ];

    const buildCollectionHealthCtripOverviewFunnelRows = (context = {}) => {
        const funnelMetric = (label, keys, dimensionIncludes = []) => ({
            label,
            myHotel: collectionHealthCtripMetricFromRows(keys, { ...context, dataTypes: ['traffic'], dimensionIncludes }) || collectionHealthCtripMissingMetric(['traffic_report'], [label], { ...context, dataTypes: ['traffic'], keys, dimensionIncludes }),
            competitorAverage: collectionHealthCtripMetricFromRows(keys, { ...context, dataTypes: ['traffic'], dimensionIncludes: ['competitor', 'avg'] }) || collectionHealthCtripMissingMetric(['traffic_report'], [label, '竞争圈平均'], { ...context, dataTypes: ['traffic'], keys, dimensionIncludes: ['competitor', 'avg'] }),
        });
        return [
            funnelMetric('列表页曝光量', ['list_exposure', 'listExposure'], ['self', 'myhotel']),
            funnelMetric('详情页访客量', ['detail_exposure', 'detailVisitors'], ['self', 'myhotel']),
            funnelMetric('订单页访客量', ['order_filling_num', 'orderFillingNum'], ['self', 'myhotel']),
            funnelMetric('订单提交人数', ['order_submit_num', 'orderSubmitNum'], ['self', 'myhotel']),
        ];
    };

    const buildCollectionHealthCtripOverviewPanels = (context = {}) => [
        {
            key: 'competitor',
            title: '竞争表现',
            scope: '竞争圈',
            metrics: [
                collectionHealthCtripOverviewMetric('竞争圈排名', ['business_overview', 'competitor_overview', 'competitor_rank'], ['竞争圈排名', '竞争圈成交排名'], { dataTypes: ['business'], keys: ['amount_rank', 'quantity_rank', 'book_order_num_rank', 'rank'] }, context),
                collectionHealthCtripOverviewMetric('竞争圈平均值', ['business_overview', 'competitor_overview'], ['竞争圈平均值', '竞争圈平均访客'], { dataTypes: ['traffic', 'business'], keys: ['competitor_average', 'competitor_amount', 'competitor_orders'], dimensionIncludes: ['competitor', 'avg'] }, context),
                collectionHealthCtripOverviewMetric('价格排名', ['business_overview', 'competitor_rank'], ['价格排名', '平均卖价排名'], { dataTypes: ['business'], keys: ['avg_price_rank', 'price_rank', 'amount_rank'] }, context),
                collectionHealthCtripOverviewMetric('竞品均价', ['business_overview', 'competitor_overview'], ['竞品均价', '竞争圈平均房价'], { dataTypes: ['business'], keys: ['competitor_avg_price', 'avg_price'], dimensionIncludes: ['competitor', 'avg'] }, context),
            ],
        },
        {
            key: 'service',
            title: '服务质量',
            scope: 'PSI',
            metrics: [
                collectionHealthCtripOverviewMetric('PSI服务质量分', ['quality_psi', 'business_overview'], ['PSI服务质量分', '服务质量分'], { dataTypes: ['business'], keys: ['psi', 'psi_score', 'psiScore', 'service_score'] }, context),
                collectionHealthCtripOverviewMetric('酒店点评分', ['quality_psi', 'business_overview'], ['酒店点评分', '点评分'], { dataTypes: ['business'], keys: ['comment_score', 'commentScore', 'qunar_comment_score'] }, context),
                collectionHealthCtripOverviewMetric('5分钟回复率', ['quality_psi'], ['5分钟回复率', '回复率'], { dataTypes: ['business'], keys: ['five_min_reply_rate', 'reply_rate', 'response_rate'], unit: '%' }, context),
                collectionHealthCtripOverviewMetric('酒店收藏数', ['quality_psi', 'business_overview'], ['酒店收藏数', '收藏数'], { dataTypes: ['business'], keys: ['hotel_collect', 'collect_count', 'favorite_count'] }, context),
            ],
        },
        {
            key: 'ads',
            title: '广告投放',
            scope: '效果页',
            metrics: [
                collectionHealthCtripOverviewMetric('广告花费', ['ads_pyramid'], ['广告花费'], { dataTypes: ['advertising'], keys: ['amount', 'ad_cost', 'cost_amount'] }, context),
                collectionHealthCtripOverviewMetric('广告曝光', ['ads_pyramid'], ['广告曝光'], { dataTypes: ['advertising'], keys: ['list_exposure', 'ad_impressions'] }, context),
                collectionHealthCtripOverviewMetric('广告点击', ['ads_pyramid'], ['广告点击'], { dataTypes: ['advertising'], keys: ['detail_exposure', 'ad_clicks'] }, context),
                collectionHealthCtripOverviewMetric('广告订单', ['ads_pyramid'], ['广告订单', '广告预订订单'], { dataTypes: ['advertising'], keys: ['book_order_num', 'order_submit_num', 'ad_orders'] }, context),
                collectionHealthCtripOverviewMetric('广告金额', ['ads_pyramid'], ['广告金额', '广告订单金额'], { dataTypes: ['advertising'], keys: ['ad_order_amount', 'order_amount'] }, context),
                collectionHealthCtripOverviewMetric('ROAS', ['ads_pyramid'], ['ROAS', '广告投产比ROAS'], { dataTypes: ['advertising'], keys: ['roas'] }, context),
            ],
        },
    ];

    const buildCollectionHealthCtripMissingActionRows = ({
        revenueMetrics = [],
        trafficMetrics = [],
        funnelRows = [],
        panels = [],
    } = {}) => {
        const allMetrics = [
            ...(Array.isArray(revenueMetrics) ? revenueMetrics : []),
            ...(Array.isArray(trafficMetrics) ? trafficMetrics : []),
            ...(Array.isArray(funnelRows) ? funnelRows : []).flatMap(row => [
                { ...(row?.myHotel || {}), label: row?.label || '' },
                { ...(row?.competitorAverage || {}), label: `${row?.label || ''} 竞争圈平均` },
            ]),
            ...(Array.isArray(panels) ? panels : []).flatMap(panel => (Array.isArray(panel?.metrics) ? panel.metrics : []).map(metric => ({
                ...metric,
                label: `${panel?.title || ''}-${metric?.label || ''}`,
            }))),
        ];
        const groups = new Map();
        allMetrics.forEach(metric => {
            if (!metric || metric.status === 'ok') return;
            const key = `${metric.diagnosisType || 'unknown'}|${metric.actionTab || ''}|${metric.reasonText || metric.source || ''}`;
            if (!groups.has(key)) {
                groups.set(key, {
                    diagnosisType: metric.diagnosisType || 'unknown',
                    module: metric.module || collectionHealthCtripActionForSections([]).module,
                    reasonText: metric.reasonText || metric.source || '未抓到',
                    actionLabel: metric.actionLabel || '',
                    actionTab: metric.actionTab || '',
                    count: 0,
                    fields: [],
                });
            }
            const group = groups.get(key);
            group.count += 1;
            group.fields.push(metric.label || '未命名指标');
        });
        return Array.from(groups.values()).slice(0, 6);
    };

    const normalizePhase1MetricDataType = (value) => String(value || '').toLowerCase().trim();
    const phase1TargetDateDataTypes = (row) => Array.isArray(row?.target_date_data_types)
        ? row.target_date_data_types.map(normalizePhase1MetricDataType).filter(Boolean)
        : [];
    const phase1HasAnyDataType = (types, needles) => types.some(type => needles.some(needle => type.includes(needle)));
    const buildPhase1MetricDomainReadiness = ({
        sourceDatePlatformRows = [],
        metricTrustKeys = [],
        hasCompleteTargetDateCoverage = false,
    } = {}) => {
        const platformRows = Array.isArray(sourceDatePlatformRows) ? sourceDatePlatformRows : [];
        const trustKeys = Array.isArray(metricTrustKeys) ? metricTrustKeys.map(item => String(item || '').trim()).filter(Boolean) : [];
        const metricDomainReadiness = platformRows.map(row => {
            const platform = String(row?.platform || '').toLowerCase();
            const targetRows = Math.max(0, Number(row?.target_date_rows || 0));
            const targetTypes = phase1TargetDateDataTypes(row);
            const revenueReady = targetRows > 0 && phase1HasAnyDataType(targetTypes, ['business', 'order', 'orders', 'revenue']);
            const trafficReady = targetRows > 0 && phase1HasAnyDataType(targetTypes, ['traffic', 'flow', 'flow_data']);
            const conversionReady = trafficReady;
            const missingDomains = [];
            if (!revenueReady) missingDomains.push('revenue');
            if (!trafficReady) missingDomains.push('traffic');
            if (!conversionReady) missingDomains.push('conversion');
            return {
                platform,
                target_date_rows: targetRows,
                target_date_data_types: targetTypes,
                revenue_status: revenueReady ? 'ready' : 'missing',
                traffic_status: trafficReady ? 'ready' : 'missing',
                conversion_status: conversionReady ? 'ready' : 'missing',
                missing_domains: missingDomains,
            };
        });
        const revenueReadyPlatforms = metricDomainReadiness.filter(row => row.revenue_status === 'ready').map(row => row.platform).filter(Boolean);
        const trafficReadyPlatforms = metricDomainReadiness.filter(row => row.traffic_status === 'ready').map(row => row.platform).filter(Boolean);
        const conversionReadyPlatforms = metricDomainReadiness.filter(row => row.conversion_status === 'ready').map(row => row.platform).filter(Boolean);
        const revenueMissingPlatforms = metricDomainReadiness.filter(row => row.revenue_status !== 'ready').map(row => row.platform).filter(Boolean);
        const trafficMissingPlatforms = metricDomainReadiness.filter(row => row.traffic_status !== 'ready').map(row => row.platform).filter(Boolean);
        const conversionMissingPlatforms = metricDomainReadiness.filter(row => row.conversion_status !== 'ready').map(row => row.platform).filter(Boolean);
        const metricDomainGapCodes = metricDomainReadiness.flatMap(row => {
            const platform = String(row?.platform || '').toLowerCase();
            if (!platform) return [];
            const codes = [];
            if (row.revenue_status !== 'ready') codes.push(`${platform}_revenue_metric_inputs_missing`);
            if (trustKeys.length === 0 && Number(row?.target_date_rows || 0) > 0) codes.push(`${platform}_metric_trust_missing`);
            if (row.traffic_status !== 'ready') codes.push(`${platform}_traffic_conversion_facts_missing`);
            return codes;
        });
        const platformFieldTrust = metricDomainReadiness.map(row => {
            const platform = String(row?.platform || '').toLowerCase();
            const targetRows = Math.max(0, Number(row?.target_date_rows || 0));
            const revenueReady = row?.revenue_status === 'ready';
            const reasonCodes = [];
            if (targetRows <= 0 && platform) reasonCodes.push(`${platform}_target_date_source_rows_missing`);
            if (!revenueReady && platform) reasonCodes.push(`${platform}_revenue_metric_inputs_missing`);
            if (targetRows > 0 && trustKeys.length === 0 && platform) reasonCodes.push(`${platform}_metric_trust_missing`);
            return {
                platform,
                target_date_rows: targetRows,
                target_date_data_types: Array.isArray(row?.target_date_data_types) ? row.target_date_data_types : [],
                field_trust_status: targetRows <= 0
                    ? 'target_date_source_missing'
                    : (revenueReady ? 'target_date_revenue_sample_present' : 'target_date_metric_inputs_missing'),
                reason_codes: reasonCodes,
                metric_trust_required: true,
                source_policy: 'target_date_rows_field_definitions_metric_trust_required',
            };
        });
        return {
            metricDomainReadiness,
            revenueReadyPlatforms,
            trafficReadyPlatforms,
            conversionReadyPlatforms,
            revenueMissingPlatforms,
            trafficMissingPlatforms,
            conversionMissingPlatforms,
            metricDomainGapCodes,
            platformFieldTrust,
            allMetricDomainsReady: hasCompleteTargetDateCoverage
                && metricDomainReadiness.length > 0
                && trustKeys.length > 0
                && revenueReadyPlatforms.length === metricDomainReadiness.length
                && trafficReadyPlatforms.length === metricDomainReadiness.length
                && conversionReadyPlatforms.length === metricDomainReadiness.length,
        };
    };

    const phase1TrafficActionModeLabel = (mode) => ({
        manual_cookie_api: '手动 Cookie/API',
        browser_profile: '浏览器 Profile',
        status_check: '状态复核',
    }[String(mode || '')] || '');
    const phase1TrafficP0GateLabel = (status) => ({
        ready: 'P0流量已就绪',
        requires_p0_verifier: 'P0待字段复验',
        missing_target_date_traffic_rows: 'P0缺目标日流量',
    }[String(status || '')] || '');
    const phase1TrafficPayloadCandidateLabel = (status) => ({
        missing_expected_payload: '预期Payload缺失',
        expected_payload_present_unverified: 'Payload待dry-run',
        system_hotel_id_missing: '本地酒店范围缺失',
    }[String(status || '')] || '');
    const phase1TrafficPreImportEvidenceLabel = (status) => ({
        not_provided: '预导入证据未提供',
        valid_external_evidence_not_ingested: '外部证据未入库',
        valid_external_evidence_with_ingested_rows: '外部证据已入库',
        external_evidence_not_valid: '外部证据无效',
    }[String(status || '')] || '');
    const phase1TrafficFieldFactLabel = (status) => ({
        no_target_date_traffic_rows: '目标日流量字段未加载',
        requires_p0_verifier: '需复验字段事实',
    }[String(status || '')] || '');
    const phase1TrafficLatestSyncTaskCodeLabel = (code) => ({
        login_or_profile_not_ready: '登录/Profile未就绪',
        browser_dependency_missing: '浏览器依赖缺失',
        sync_completed_without_saved_rows: '同步完成但未入库',
        sync_normalized_without_saved_rows: '已标准化但未入库',
        sync_task_target_date_mismatch: '任务日期不匹配',
        sync_reported_saved_rows_requires_target_date_verifier: '已报保存需复验',
        no_rows_parsed: '未解析到业务行',
        sync_running: '同步未完成',
        waiting_config: '等待配置',
        task_status_missing: '任务状态缺失',
        capture_failed: '采集失败',
        unknown: '未知原因',
    }[String(code || '')] || String(code || ''));
    const buildPhase1TrafficLatestSyncTaskText = (row = {}) => {
        const taskCount = Number(row?.traffic_latest_sync_task_count || 0);
        const statusCounts = row?.traffic_latest_sync_task_status_counts && typeof row.traffic_latest_sync_task_status_counts === 'object'
            ? row.traffic_latest_sync_task_status_counts
            : {};
        const codeCounts = row?.traffic_latest_sync_task_message_code_counts && typeof row.traffic_latest_sync_task_message_code_counts === 'object'
            ? row.traffic_latest_sync_task_message_code_counts
            : {};
        const savedCount = Number(row?.traffic_latest_sync_task_saved_count || 0);
        const normalizedCount = Number(row?.traffic_latest_sync_task_normalized_count || 0);
        const statusText = Object.entries(statusCounts)
            .filter(([, value]) => Number(value || 0) > 0)
            .map(([status, value]) => `${status}:${Number(value || 0)}`)
            .join('/');
        const codeText = Object.entries(codeCounts)
            .filter(([, value]) => Number(value || 0) > 0)
            .map(([code, value]) => `${phase1TrafficLatestSyncTaskCodeLabel(code)}:${Number(value || 0)}`)
            .join('/');
        const parts = [];
        if (taskCount > 0) parts.push(`最近同步 ${taskCount} 项`);
        if (statusText) parts.push(statusText);
        if (codeText) parts.push(codeText);
        if (normalizedCount > 0 || savedCount > 0) parts.push(`标准化${normalizedCount}行/入库${savedCount}行`);
        if (row?.traffic_latest_sync_task_sensitive_values_exposed === false) parts.push('同步诊断已脱敏');
        return parts.join('，');
    };
    const phase1P0StandardFactSummary = (row = {}) => {
        const p0_standard_fact_status_counts = row?.p0_standard_fact_status_counts && typeof row.p0_standard_fact_status_counts === 'object'
            ? row.p0_standard_fact_status_counts
            : {};
        const p0_standard_fact_complete_metric_keys = Array.isArray(row?.p0_standard_fact_complete_metric_keys)
            ? row.p0_standard_fact_complete_metric_keys
            : [];
        const p0_standard_fact_missing_metric_keys = Array.isArray(row?.p0_standard_fact_missing_metric_keys)
            ? row.p0_standard_fact_missing_metric_keys
            : [];
        const p0_standard_fact_incomplete_metric_keys = Array.isArray(row?.p0_standard_fact_incomplete_metric_keys)
            ? row.p0_standard_fact_incomplete_metric_keys
            : [];
        const countText = Object.entries(p0_standard_fact_status_counts)
            .filter(([, value]) => Number(value || 0) > 0)
            .map(([status, value]) => `${status}:${Number(value || 0)}`)
            .join('/');
        const parts = [];
        if (countText) parts.push(`standard_fact_counts ${countText}`);
        if (p0_standard_fact_complete_metric_keys.length > 0) parts.push(`standard_fact_complete_keys ${p0_standard_fact_complete_metric_keys.slice(0, 5).join('/')}`);
        if (p0_standard_fact_missing_metric_keys.length > 0) parts.push(`standard_fact_missing_keys ${p0_standard_fact_missing_metric_keys.slice(0, 5).join('/')}`);
        if (p0_standard_fact_incomplete_metric_keys.length > 0) parts.push(`standard_fact_incomplete_keys ${p0_standard_fact_incomplete_metric_keys.slice(0, 5).join('/')}`);
        return parts.join('，');
    };
    const buildPhase1TrafficP0NextText = (row = {}) => {
        const gateLabel = phase1TrafficP0GateLabel(row?.p0_traffic_gate_status || '');
        const modeLabel = phase1TrafficActionModeLabel(row?.p0_next_action_mode || row?.recommended_collection_mode || '');
        const controlledEntry = String(row?.p0_next_action_entry || row?.action_entry || '').startsWith('/api/online-data/');
        const noSensitiveCommand = row?.next_command_policy === 'metadata_only_no_sensitive_commands';
        const stepCount = Number(row?.p0_next_step_count || 0);
        const externalEvidenceStatus = String(row?.p0_external_evidence_status || row?.external_evidence_status || 'not_provided');
        const preImportStatus = String(row?.p0_pre_import_evidence_status || row?.pre_import_evidence_status || 'not_provided');
        const preImportPolicy = String(row?.p0_pre_import_evidence_policy || '');
        const trafficFieldFactStatus = String(row?.p0_traffic_field_fact_status || '');
        const profileLoginTriggerPolicy = String(row?.p0_profile_login_trigger_policy || '');
        const profileLoginTriggerAvailableCount = Number(row?.p0_profile_login_trigger_available_count || 0);
        const profileLoginTriggerUnavailableCount = Number(row?.p0_profile_login_trigger_unavailable_count || 0);
        const afterLoginSyncAvailableCount = Number(row?.p0_after_login_sync_available_count || 0);
        const manualLoginVerifiedCount = Number(row?.p0_manual_login_state_verified_count || 0);
        const sourceChainScope = String(row?.p0_source_chain_scope || '');
        const sourceChainPolicy = String(row?.p0_source_chain_policy || '');
        const targetTrafficDataTypeCount = Array.isArray(row?.p0_target_traffic_data_types) ? row.p0_target_traffic_data_types.length : 0;
        const sourceChainNoTargetRows = sourceChainScope === 'no_target_date_source_rows';
        const sourceChainReferenceOnly = targetTrafficDataTypeCount <= 0
            && (row?.p0_source_chain_reference_only === true
                || sourceChainScope === 'reference_only_non_traffic_source_rows'
                || sourceChainPolicy.includes('reference only'));
        const payloadCandidateCounts = row?.p0_payload_candidate_status_counts && typeof row.p0_payload_candidate_status_counts === 'object'
            ? row.p0_payload_candidate_status_counts
            : {};
        const payloadCandidatePolicy = String(row?.p0_payload_candidate_policy || '');
        const payloadCandidatePayloadPolicy = String(row?.p0_payload_candidate_payload_policy || '');
        const payloadCandidateStoragePolicy = String(row?.p0_payload_candidate_storage_policy || '');
        const payloadCandidateMissingCount = Number(row?.p0_payload_candidate_missing_count || payloadCandidateCounts.missing_expected_payload || 0);
        const payloadCandidateUnverifiedCount = Number(row?.p0_payload_candidate_unverified_count || payloadCandidateCounts.expected_payload_present_unverified || 0);
        const payloadCandidateReadyCount = Number(row?.p0_payload_candidate_ready_count || 0);
        const payloadCandidateBlockedCount = Number(payloadCandidateCounts.blocked || 0);
        const payloadCandidatePathCount = Array.isArray(row?.p0_payload_candidate_paths) ? row.p0_payload_candidate_paths.length : 0;
        const payloadCandidateIssueCount = Array.isArray(row?.p0_payload_candidate_issue_codes) ? row.p0_payload_candidate_issue_codes.length : 0;
        const payloadCandidateTargetDateRows = Number(row?.p0_payload_candidate_target_date_rows || 0);
        const payloadCandidateTrafficEvidenceRows = Number(row?.p0_payload_candidate_traffic_evidence_rows || 0);
        const payloadCandidateSourcePathRows = Number(row?.p0_payload_candidate_evidence_source_path_rows || 0);
        const payloadCandidateStructuredSourcePathRows = Number(row?.p0_payload_candidate_evidence_structured_source_path_rows || 0);
        const payloadCandidateRawDataFieldFactsRows = Number(row?.p0_payload_candidate_evidence_raw_data_field_facts_rows || 0);
        const payloadCandidateRawDataExposedRows = Number(row?.p0_payload_candidate_evidence_raw_data_exposed_rows || 0);
        const payloadCandidateSensitiveValueRows = Number(row?.p0_payload_candidate_evidence_sensitive_value_rows || 0);
        const payloadCandidateMissingMetricCount = Array.isArray(row?.p0_payload_candidate_evidence_missing_metric_keys)
            ? row.p0_payload_candidate_evidence_missing_metric_keys.length
            : 0;
        const payloadCandidateMetricCount = Array.isArray(row?.p0_payload_candidate_evidence_metric_keys)
            ? row.p0_payload_candidate_evidence_metric_keys.length
            : 0;
        const requiredMetricCount = Array.isArray(row?.p0_required_metric_keys) ? row.p0_required_metric_keys.length : 0;
        const requiredStorageFieldCount = Array.isArray(row?.p0_required_storage_fields) ? row.p0_required_storage_fields.length : 0;
        const requiredFieldFactCount = Array.isArray(row?.p0_required_field_fact_keys) ? row.p0_required_field_fact_keys.length : 0;
        const missingMetricCount = Array.isArray(row?.p0_missing_metric_keys) ? row.p0_missing_metric_keys.length : 0;
        const fieldLoopMatrix = Array.isArray(row?.p0_field_loop_matrix) ? row.p0_field_loop_matrix : [];
        const standardFactStatus = String(row?.p0_standard_fact_status || '');
        const standardFactPolicy = String(row?.p0_standard_fact_policy || '');
        const standardFactRawDataPolicy = String(row?.p0_standard_fact_raw_data_policy || '');
        const standardFactRequiredMetricCount = Number(row?.p0_standard_fact_required_metric_count ?? requiredMetricCount);
        const standardFactCompleteMetricCount = Number(row?.p0_standard_fact_complete_metric_count ?? 0);
        const standardFactMissingMetricCount = Number(row?.p0_standard_fact_missing_metric_count ?? missingMetricCount);
        const standardFactIncompleteMetricCount = Number(row?.p0_standard_fact_incomplete_metric_count ?? 0);
        const standardFactStorageFieldCount = Number(row?.p0_standard_fact_storage_field_count ?? requiredStorageFieldCount);
        const unloadedFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'no_target_date_traffic_rows').length;
        const verifierFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'requires_p0_verifier').length;
        const completeFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'complete').length;
        const incompleteFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'incomplete').length;
        const missingFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'missing').length;
        const closureChain = row?.p0_traffic_closure_chain && typeof row.p0_traffic_closure_chain === 'object'
            ? Object.values(row.p0_traffic_closure_chain)
            : [];
        const closureChainPolicy = String(row?.p0_traffic_closure_chain_policy || '');
        const closureChainNoTargetCount = closureChain.filter(item => String(item?.status || '') === 'no_target_date_traffic_rows').length;
        const closureChainVerifierCount = closureChain.filter(item => String(item?.status || '') === 'requires_p0_verifier').length;
        const closureChainReadyCount = closureChain.filter(item => String(item?.status || '') === 'ready').length;
        const closureChainIncompleteCount = closureChain.filter(item => String(item?.status || '') === 'incomplete').length;
        const platformHotelIdentifierStatus = String(row?.p0_platform_hotel_identifier_status || '');
        const platformHotelIdentifierSource = String(row?.p0_platform_hotel_identifier_source || '');
        const platformHotelIdentifierPolicy = String(row?.p0_platform_hotel_identifier_policy || '');
        const latestSyncTaskText = buildPhase1TrafficLatestSyncTaskText(row);
        const standardFactSummaryText = phase1P0StandardFactSummary(row);
        const preImportLabel = phase1TrafficPreImportEvidenceLabel(preImportStatus);
        const parts = [];
        if (gateLabel) parts.push(gateLabel);
        if (sourceChainNoTargetRows) parts.push('目标日源数据未入库');
        if (sourceChainReferenceOnly) parts.push('源证据仅参考');
        if (preImportLabel && (preImportStatus !== 'not_provided' || row?.p0_traffic_gate_status !== 'ready')) parts.push(preImportLabel);
        if (externalEvidenceStatus !== 'not_provided' && preImportPolicy.includes('source proof only')) parts.push('证据不等于闭环');
        if (profileLoginTriggerAvailableCount > 0) parts.push(`本机授权入口 ${profileLoginTriggerAvailableCount} 项`);
        if (afterLoginSyncAvailableCount > 0) parts.push(`登录后同步 ${afterLoginSyncAvailableCount} 项`);
        if (profileLoginTriggerUnavailableCount > 0) parts.push(`本机授权待执行 ${profileLoginTriggerUnavailableCount} 项`);
        if (manualLoginVerifiedCount > 0) parts.push(`登录态已确认 ${manualLoginVerifiedCount} 项`);
        if (profileLoginTriggerPolicy === 'metadata_only_backend_resolves_platform_identity') parts.push('入口不展示平台原始ID');
        if (profileLoginTriggerPolicy === 'client_local_authorization_only_no_server_browser_launch') parts.push('禁用服务端登录窗口');
        if (payloadCandidateMissingCount > 0) parts.push(`${phase1TrafficPayloadCandidateLabel('missing_expected_payload')} ${payloadCandidateMissingCount} 项`);
        if (payloadCandidateUnverifiedCount > 0) parts.push(`${phase1TrafficPayloadCandidateLabel('expected_payload_present_unverified')} ${payloadCandidateUnverifiedCount} 项`);
        if (payloadCandidateReadyCount > 0) parts.push(`Payload可执行 ${payloadCandidateReadyCount} 项`);
        if (payloadCandidateBlockedCount > 0) parts.push(`Payload阻断 ${payloadCandidateBlockedCount} 项`);
        if (payloadCandidatePathCount > 0) parts.push(`预期路径 ${payloadCandidatePathCount} 项`);
        if (payloadCandidateIssueCount > 0) parts.push(`Payload缺口 ${payloadCandidateIssueCount} 类`);
        if (payloadCandidateTargetDateRows > 0) parts.push(`Payload目标日 ${payloadCandidateTargetDateRows} 行`);
        if (payloadCandidateTrafficEvidenceRows > 0) parts.push(`Payload证据 ${payloadCandidateTrafficEvidenceRows} 行`);
        if (payloadCandidateSourcePathRows > 0) parts.push(`Payload source_path ${payloadCandidateStructuredSourcePathRows}/${payloadCandidateSourcePathRows} 行`);
        if (payloadCandidateRawDataFieldFactsRows > 0) parts.push(`Payload字段事实 ${payloadCandidateRawDataFieldFactsRows} 行`);
        if (payloadCandidateMetricCount > 0) parts.push(`Payload指标 ${payloadCandidateMetricCount} 项`);
        if (payloadCandidateMissingMetricCount > 0) parts.push(`Payload缺指标 ${payloadCandidateMissingMetricCount} 项`);
        if (payloadCandidateRawDataExposedRows > 0) parts.push(`Payload raw_data暴露 ${payloadCandidateRawDataExposedRows} 行`);
        if (payloadCandidateSensitiveValueRows > 0) parts.push(`Payload敏感值暴露 ${payloadCandidateSensitiveValueRows} 行`);
        if (payloadCandidatePolicy === 'ui_metadata_only_no_import') parts.push('UI不导入Payload');
        if (payloadCandidatePayloadPolicy === 'path_metadata_only_no_payload_content') parts.push('不展示Payload内容');
        if (payloadCandidateStoragePolicy === 'does_not_write_online_daily_data') parts.push('不写入库表');
        if (latestSyncTaskText) parts.push(latestSyncTaskText);
        if (standardFactSummaryText) parts.push(standardFactSummaryText);
        if (requiredMetricCount > 0) parts.push(`需闭环指标 ${requiredMetricCount} 项`);
        if (requiredStorageFieldCount > 0) parts.push(`入库字段 ${requiredStorageFieldCount} 项`);
        if (requiredFieldFactCount > 0) parts.push(`字段事实 ${requiredFieldFactCount} 项`);
        if (standardFactStatus) parts.push(`standard_fact:${standardFactStatus}`);
        if (standardFactRequiredMetricCount > 0) parts.push(`standard_fact_metrics ${standardFactCompleteMetricCount}/${standardFactRequiredMetricCount}`);
        if (standardFactMissingMetricCount > 0) parts.push(`standard_fact_missing ${standardFactMissingMetricCount}`);
        if (standardFactIncompleteMetricCount > 0) parts.push(`standard_fact_incomplete ${standardFactIncompleteMetricCount}`);
        if (standardFactStorageFieldCount > 0) parts.push(`standard_fact_storage ${standardFactStorageFieldCount}`);
        if (standardFactPolicy === 'derived_from_p0_field_loop_matrix_ota_channel_only') parts.push('standard_fact_ota_channel_only');
        if (standardFactRawDataPolicy === 'raw_data_field_facts_only_raw_payload_not_returned') parts.push('raw_data_payload_not_returned');
        if (fieldLoopMatrix.length > 0) parts.push(`字段矩阵 ${fieldLoopMatrix.length} 项`);
        if (closureChain.length > 0) parts.push(`闭环链 ${closureChain.length} 项`);
        if (closureChainPolicy.includes('OTA-channel evidence only')) parts.push('仅OTA渠道证据');
        if (platformHotelIdentifierStatus === 'no_target_date_traffic_rows') parts.push('平台酒店身份未加载');
        if (platformHotelIdentifierStatus === 'requires_p0_verifier') parts.push('平台酒店身份待复验');
        if (platformHotelIdentifierStatus === 'ready') parts.push('平台酒店身份已证明');
        if (platformHotelIdentifierSource) parts.push(`身份来源 ${platformHotelIdentifierSource}`);
        if (platformHotelIdentifierPolicy.includes('not raw IDs')) parts.push('不展示平台原始ID');
        if (unloadedFieldLoopCount > 0) parts.push(`未加载 ${unloadedFieldLoopCount} 项`);
        if (verifierFieldLoopCount > 0) parts.push(`待复验 ${verifierFieldLoopCount} 项`);
        if (completeFieldLoopCount > 0) parts.push(`完成 ${completeFieldLoopCount} 项`);
        if (incompleteFieldLoopCount > 0) parts.push(`待补 ${incompleteFieldLoopCount} 项`);
        if (missingFieldLoopCount > 0) parts.push(`缺事实 ${missingFieldLoopCount} 项`);
        if (closureChainNoTargetCount > 0) parts.push(`链路未加载 ${closureChainNoTargetCount} 项`);
        if (closureChainVerifierCount > 0) parts.push(`链路待复验 ${closureChainVerifierCount} 项`);
        if (closureChainReadyCount > 0) parts.push(`链路完成 ${closureChainReadyCount} 项`);
        if (closureChainIncompleteCount > 0) parts.push(`链路待补 ${closureChainIncompleteCount} 项`);
        if (missingMetricCount > 0) parts.push(`缺指标 ${missingMetricCount} 项`);
        const fieldFactLabel = phase1TrafficFieldFactLabel(trafficFieldFactStatus);
        if (fieldFactLabel) parts.push(fieldFactLabel);
        if (modeLabel) parts.push(`建议${modeLabel}`);
        if (stepCount > 0) parts.push(`酒店级步骤 ${stepCount} 项`);
        if (controlledEntry && noSensitiveCommand) parts.push('不展示敏感命令');
        return parts.length ? `，${parts.join('，')}` : '';
    };

    const phase1EmployeeQuestionStatusText = (status) => ({
        proved: '已证明',
        warning: '需复核',
        missing: '缺失',
        not_proved: '待证明',
    }[String(status || '')] || '待证明');

    const phase1EmployeeQuestionStatusClass = (status) => ({
        proved: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        warning: 'bg-amber-50 text-amber-700 border-amber-100',
        missing: 'bg-red-50 text-red-700 border-red-100',
        not_proved: 'bg-gray-50 text-gray-600 border-gray-200',
        missing_question: 'bg-red-50 text-red-700 border-red-100',
        request_failed: 'bg-red-50 text-red-700 border-red-100',
    }[String(status || '')] || 'bg-gray-50 text-gray-600 border-gray-200');

    const dailyWorkbenchStatusText = (status) => ({
        complete: '已闭合',
        incomplete: '未闭合',
        empty: '无数据',
        ready: '已加载',
        proved: '已证明',
        warning: '需复核',
        missing: '缺失',
        not_proved: '待证明',
        missing_question: '问题缺失',
        request_failed: '请求失败',
        not_loaded: '未加载',
        unknown: '未知',
    }[String(status || '').toLowerCase()] || phase1EmployeeQuestionStatusText(status));

    const dailyWorkbenchStatusClass = (status) => ({
        complete: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        ready: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        proved: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        incomplete: 'bg-amber-50 text-amber-700 border-amber-100',
        warning: 'bg-amber-50 text-amber-700 border-amber-100',
        not_proved: 'bg-gray-50 text-gray-600 border-gray-200',
        not_loaded: 'bg-gray-50 text-gray-600 border-gray-200',
        empty: 'bg-gray-50 text-gray-600 border-gray-200',
        missing: 'bg-red-50 text-red-700 border-red-100',
        missing_question: 'bg-red-50 text-red-700 border-red-100',
        request_failed: 'bg-red-50 text-red-700 border-red-100',
    }[String(status || '').toLowerCase()] || 'bg-gray-50 text-gray-600 border-gray-200');

    const buildDailyWorkbenchWriteBoundary = () => ({
        summaryText: '只读查看不会写业务数据；运行巡检会写入运行时快照和操作日志，导出会写入导出审计日志；两类操作均需二次确认。',
        run: {
            requiresConfirmation: true,
            runtimeSnapshotWritten: true,
            operationLogWritten: true,
            otaCollectionTriggered: false,
            businessTableWritten: false,
            confirmText: '运行巡检会写入 runtime/phase2_daily_workbench_patrol 巡检快照、latest 索引和一条操作日志；不会触发 OTA 采集，也不会改写业务表。确认继续？',
        },
        export: {
            requiresConfirmation: true,
            runtimeSnapshotWritten: false,
            operationLogWritten: true,
            otaCollectionTriggered: false,
            businessTableWritten: false,
            confirmText: '导出只读取已有巡检快照，但会写入一条导出审计日志；不会触发 OTA 采集，也不会改写业务表。确认继续？',
        },
    });

    const phase3OperationEffectLoopStatusText = (status) => ({
        patrol_anomaly_confirmed: '异常已确认',
        source_row_missing: '源行缺失',
        action_required: '待执行',
        execution_missing: '缺执行',
        execution_in_progress: '执行中',
        done_without_execution_task: '缺任务证据',
        executed_evidence_recorded: '已举证',
        skipped: '已跳过',
        execution_incomplete: '执行未完',
        review_missing: '待复盘',
        observing: '观察中',
        reviewed: '已复盘',
        candidate: '候选',
        not_ready: '未就绪',
        ready: '已就绪',
        metric_window_missing: '指标不足',
    }[String(status || '').toLowerCase()] || status || '未知');

    const phase3OperationEffectLoopStatusClass = (status) => {
        const normalized = String(status || '').toLowerCase();
        if (['executed_evidence_recorded', 'reviewed', 'candidate', 'ready'].includes(normalized)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['action_required', 'execution_in_progress', 'observing'].includes(normalized)) return 'bg-blue-50 text-blue-700 border-blue-100';
        if (['review_missing', 'execution_incomplete', 'not_ready', 'metric_window_missing'].includes(normalized)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (['execution_missing', 'source_row_missing', 'done_without_execution_task'].includes(normalized)) return 'bg-red-50 text-red-700 border-red-100';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const phase1EmployeeActionFamilyText = (family) => ({
        target_date_source_rows: '采集补证',
        standard_facts: '标准事实',
        revenue_metric_inputs: '收益指标',
        traffic_conversion_facts: '流量/转化',
        ai_diagnosis_evidence: 'AI 证据',
        operation_execution_evidence: '执行闭环',
        evidence_scope: '证据范围',
    }[String(family || '').trim()] || String(family || '').trim() || '证据缺口');

    const phase1EmployeeReadinessStatusText = (status) => ({
        ready: '可核对现有状态',
        requires_user_context: '需要先提供 Cookie/Payload 上下文',
        profile_missing: '未找到本机 Profile',
        profile_found_login_unverified: '发现 Profile，但登录态未验证',
    }[String(status || '').trim()] || String(status || '').trim());

    const phase1EmployeeReadinessEvidenceText = (value) => ({
        user_supplied_cookie_or_payload_required: '需要用户提供 Cookie/Payload/导出上下文',
        storage_profile_directory_count: '只读取本机 Profile 目录数量',
        read_local_profile_directory_names_only: '只读取本机 Profile 目录名',
        read_existing_collection_reliability_only: '只读现有采集可靠性状态',
    }[String(value || '').trim()] || String(value || '').trim());

    const phase1EmployeeQuestionKeyText = (key) => ({
        today_ota_collected: '今天 OTA 数据有没有采到',
        trusted_fields: '哪些字段可信',
        missing_fields: '哪些字段缺失',
        revenue_traffic_conversion: '收入/流量/转化问题',
        ai_evidence: 'AI 建议依据',
        next_operation_action: '下一步执行动作',
    }[String(key || '').trim()] || String(key || '').trim());

    const phase1EmployeePlatformText = (platform) => ({
        ctrip: '携程',
        meituan: '美团',
    }[String(platform || '').toLowerCase()] || String(platform || '').toUpperCase());

    const phase1EmployeeDateRelationText = (relation) => ({
        target_date: '目标日',
        stale_before_target: '早于目标日',
        future_dated_for_target: '晚于目标日',
        none: '未匹配目标日',
    }[String(relation || '').trim()] || String(relation || '').trim());

    const phase1EmployeeActionStatusText = (status) => ({
        missing: '待补证据',
        blocked: '被上游缺口阻断',
        warning: '需复核',
        ready: '可复核',
        proved: '已证明',
    }[String(status || '').trim()] || String(status || '').trim());

    const phase1MetricDomainPlatformText = (platform) => ({
        ctrip: '携程',
        meituan: '美团',
    }[String(platform || '').toLowerCase()] || (platform ? 'OTA 平台' : 'OTA'));

    const phase1MetricDomainDataTypeText = (type) => {
        const raw = String(type || '').toLowerCase();
        if (['business', 'business_overview', 'revenue', 'order', 'orders'].includes(raw)) return '经营/收益';
        if (['traffic', 'flow', 'flow_data'].includes(raw)) return '流量/转化';
        if (['advertising', 'ads'].includes(raw)) return '广告';
        if (['quality', 'quality_psi'].includes(raw)) return '服务质量';
        if (['review', 'comment'].includes(raw)) return '点评';
        return raw ? '未识别数据类型' : '未标注数据类型';
    };

    const phase1MissingFieldDetailText = (code) => ({
        available_room_nights_missing: '缺可售房晚，暂不能可靠计算 OCC、RevPAR 或可售基准。',
        commission_fields_missing: '缺佣金金额或佣金率，暂不能核算净收入和渠道成本。',
        net_revenue_fields_missing: '缺净收入输入，暂不能输出净 RevPAR 或真实到手收入。',
        lead_time_fields_missing: '缺提前预订天数，暂不能判断提前期结构和临近入住风险。',
        cancellation_fields_missing: '缺取消订单或取消金额，暂不能判断取消对收入的影响。',
        cancel_room_nights_missing: '缺取消房晚，暂不能计算房晚取消率。',
        competitor_price_fields_missing: '缺竞品价格，暂不能做竞品价差和调价判断。',
    }[String(code || '').trim()] || '该缺口需要补齐字段定义或目标日样本后再判断。');

    const phase1MissingFieldLabel = (code) => ({
        available_room_nights_missing: '可售房晚缺失',
        commission_fields_missing: '佣金字段缺失',
        net_revenue_fields_missing: '净收入字段缺失',
        lead_time_fields_missing: '提前预订字段缺失',
        cancellation_fields_missing: '取消字段缺失',
        cancel_room_nights_missing: '取消房晚缺失',
        competitor_price_fields_missing: '竞品价格字段缺失',
    }[String(code || '').trim()] || String(code || '').trim() || '未命名缺口');

    const phase1MissingFieldNextActionText = (code, source = 'data_gaps') => {
        const raw = String(code || '').trim();
        if (/available_room_nights|net_revenue|commission|lead_time|cancellation|cancel_room_nights|competitor_price/i.test(raw)) {
            return '按字段资产核对平台返回和入库字段，再重跑收益指标核验。';
        }
        return source === 'missing_field_codes'
            ? '按字段缺口清单补齐字段定义或样本证据。'
            : '按数据缺口清单补齐目标日证据后复跑诊断。';
    };

    const phase1MissingFieldSourceText = (source) => source === 'missing_field_codes' ? '字段缺口' : '数据缺口';

    const phase1MetricDomainStatusText = (status) => String(status || '').toLowerCase() === 'ready' ? '可复核' : '缺失';
    const phase1MetricDomainStatusClass = (status) => String(status || '').toLowerCase() === 'ready'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : 'border-amber-200 bg-amber-50 text-amber-700';
    const phase1MetricDomainMissingLabel = (domain) => ({
        revenue: '收益',
        traffic: '流量',
        conversion: '转化',
    }[String(domain || '').toLowerCase()] || String(domain || ''));
    const phase1MetricDomainProblemText = ({ revenueReady, trafficReady, conversionReady, sourceRows, trafficRows } = {}) => {
        if (revenueReady && trafficReady && conversionReady) {
            return '收益、流量、转化均可复核。';
        }
        if (sourceRows <= 0) {
            return '目标日源数据缺失，收益、流量、转化都不能证明。';
        }
        if (revenueReady && (!trafficReady || !conversionReady || trafficRows <= 0)) {
            return '收益可先复核；流量/转化缺失，不能判断曝光到下单漏斗。';
        }
        if (!trafficReady || !conversionReady || trafficRows <= 0) {
            return '流量/转化缺失，不能判断曝光、访问或下单转化问题。';
        }
        return '收益指标缺失，不能输出收入问题结论。';
    };
    const phase1MetricDomainNextActionText = ({ revenueReady, trafficReady, conversionReady, sourceRows, trafficRows } = {}) => {
        if (revenueReady && trafficReady && conversionReady) {
            return '可进入 OTA 经营诊断。';
        }
        if (sourceRows <= 0) {
            return '先补目标日 OTA 源数据，再复跑收益指标核验。';
        }
        if (!revenueReady) {
            return '复核标准事实层和收益指标输入。';
        }
        if (!trafficReady || !conversionReady || trafficRows <= 0) {
            return '补齐流量/转化事实，再复核漏斗诊断。';
        }
        return '按缺口补齐目标日证据后复跑诊断。';
    };

    const phase1EmployeeCountItem = (key, label, value, ok = false) => ({
        key,
        label,
        value: String(value ?? 0),
        className: ok ? 'text-emerald-700' : 'text-slate-800',
    });
    const phase1EmployeeQuestionBlockingGapCodes = (row = {}) => {
        const evidence = row?.evidence && typeof row.evidence === 'object' ? row.evidence : {};
        const status = String(row?.status || '').trim();
        if (['proved', 'no_gap_reported'].includes(status)) {
            return [];
        }
        const codes = [];
        [
            row?.blocking_gap_codes,
            evidence.blocking_gap_codes,
            evidence.blocking_missing_codes,
            evidence.operation_blocking_missing_codes,
            evidence.metric_domain_gap_codes,
            evidence.direct_next_action_resolves_missing_codes,
            evidence.primary_next_action_resolves_missing_codes,
            evidence.data_gap_codes,
        ].forEach((items) => {
            (Array.isArray(items) ? items : []).forEach((item) => {
                const code = String(item || '').trim();
                if (code && !codes.includes(code)) {
                    codes.push(code);
                }
            });
        });
        return codes;
    };
    const mergePhase1EmployeeQuestionRow = (backendRow = {}, localRow = {}) => {
        const merged = { ...(localRow || {}), ...(backendRow || {}) };
        return {
            ...merged,
            detail: backendRow?.detail || localRow?.detail || '',
            detailRawText: backendRow?.detailRawText || localRow?.detailRawText || '',
            nextActionText: backendRow?.nextActionText || localRow?.nextActionText || '',
            nextActionRawText: backendRow?.nextActionRawText || localRow?.nextActionRawText || '',
            employeeNextActionText: backendRow?.employeeNextActionText || localRow?.employeeNextActionText || '',
            actionCodes: backendRow?.actionCodes || localRow?.actionCodes || '',
            primaryNextActionCode: backendRow?.primaryNextActionCode || localRow?.primaryNextActionCode || '',
            directNextActionCode: backendRow?.directNextActionCode || localRow?.directNextActionCode || '',
            actionCodesText: backendRow?.actionCodesText || localRow?.actionCodesText || '',
            primaryNextActionText: backendRow?.primaryNextActionText || localRow?.primaryNextActionText || '',
            directNextActionText: backendRow?.directNextActionText || localRow?.directNextActionText || '',
            primaryNextActionEntry: backendRow?.primaryNextActionEntry || localRow?.primaryNextActionEntry || '',
            directNextActionEntry: backendRow?.directNextActionEntry || localRow?.directNextActionEntry || '',
            primaryNextActionEntryText: backendRow?.primaryNextActionEntryText || localRow?.primaryNextActionEntryText || '',
            directNextActionEntryText: backendRow?.directNextActionEntryText || localRow?.directNextActionEntryText || '',
            primaryNextActionSuccessCriteria: backendRow?.primaryNextActionSuccessCriteria || localRow?.primaryNextActionSuccessCriteria || '',
            directNextActionSuccessCriteria: backendRow?.directNextActionSuccessCriteria || localRow?.directNextActionSuccessCriteria || '',
            primaryNextActionSuccessCriteriaText: backendRow?.primaryNextActionSuccessCriteriaText || localRow?.primaryNextActionSuccessCriteriaText || '',
            directNextActionSuccessCriteriaText: backendRow?.directNextActionSuccessCriteriaText || localRow?.directNextActionSuccessCriteriaText || '',
            blockedActionCodes: backendRow?.blockedActionCodes || localRow?.blockedActionCodes || '',
            blockedActionCodesText: backendRow?.blockedActionCodesText || localRow?.blockedActionCodesText || '',
            blockingGapCodes: backendRow?.blockingGapCodes?.length ? backendRow.blockingGapCodes : (localRow?.blockingGapCodes || []),
            blockingReasonText: backendRow?.blockingReasonText || localRow?.blockingReasonText || '',
            linkedActionCount: Number(backendRow?.linkedActionCount || localRow?.linkedActionCount || 0),
        };
    };
    const phase1EmployeeQuestionPresentationRow = (backendRow = {}, localRow = {}) => ({
        ...(backendRow || {}),
        question: backendRow?.question || localRow?.question || '',
        detail: backendRow?.detail || localRow?.detail || '',
        detailRawText: backendRow?.detailRawText || localRow?.detailRawText || '',
        nextActionText: backendRow?.nextActionText || localRow?.nextActionText || '',
        nextActionRawText: backendRow?.nextActionRawText || localRow?.nextActionRawText || '',
        employeeNextActionText: backendRow?.employeeNextActionText || localRow?.employeeNextActionText || '',
        blockingReasonText: backendRow?.blockingReasonText || localRow?.blockingReasonText || '',
    });
    const phase1EmployeeActionRawCode = (item) => {
        if (typeof item === 'string') return item.trim();
        if (!item || typeof item !== 'object') return '';
        return String(item.action_code || item.actionCode || item.code || '').trim();
    };
    const phase1EmployeeActionPlatformText = (item, rawCode) => {
        const source = item && typeof item === 'object' ? item : {};
        const explicitPlatform = String(source.platform || '').trim();
        if (explicitPlatform) return phase1EmployeePlatformText(explicitPlatform);
        const match = String(rawCode || '').match(/(?:^|_)(ctrip|meituan)(?:_|$)/);
        return match ? phase1EmployeePlatformText(match[1]) : 'OTA 平台';
    };
    const phase1EmployeeActionEntryText = (entry, item = {}) => {
        const rawEntry = String(entry || '').trim();
        if (!rawEntry) return '';
        const pathOnly = rawEntry.split('?')[0].replace(/^https?:\/\/[^/]+/i, '');
        const rawCode = phase1EmployeeActionRawCode(item);
        const platformText = phase1EmployeeActionPlatformText(item, rawCode);
        const entryMap = {
            '/api/online-data/collection-reliability': 'OTA 采集可靠性状态核对',
            '/api/online-data/fetch-ctrip': '携程手动 Cookie/API 获取入口',
            '/api/online-data/fetch-meituan': '美团手动 Cookie/API 获取入口',
            '/api/online-data/capture-ctrip-browser': '携程浏览器 Profile 采集入口',
            '/api/online-data/capture-meituan-browser': '美团浏览器 Profile 采集入口',
            '/api/online-data/fetch-ctrip-traffic': '携程流量/转化采集入口',
            '/api/online-data/fetch-meituan-traffic': '美团流量/转化采集入口',
            '/api/online-data/data-analysis': 'OTA 经营数据分析入口',
            '/api/ota-standard/revenue-metrics': 'OTA 收益指标与标准事实核对',
            '/api/agent/ota-diagnosis': 'AI 诊断证据核对入口',
            '/api/operation/execution-intents': '运营执行意图入口',
            '/api/operation/execution-flow': '运营执行流程核对入口',
        };
        if (entryMap[pathOnly]) return entryMap[pathOnly];
        if (rawCode === 'phase1_confirm_source_date_evidence' || String(item?.action_family || item?.actionFamily || '') === 'target_date_source_rows') {
            return `${platformText}目标日源数据补证入口`;
        }
        if (String(item?.action_family || item?.actionFamily || '') === 'traffic_conversion_facts') {
            return `${platformText}流量/转化证据核对入口`;
        }
        if (String(item?.action_family || item?.actionFamily || '') === 'revenue_metric_inputs' || String(item?.action_family || item?.actionFamily || '') === 'standard_facts') {
            return `${platformText}收益指标与标准事实核对入口`;
        }
        const family = String(item?.action_family || item?.actionFamily || '').trim();
        if (family === 'operation_execution_evidence') return '现有运营执行入口';
        if (family === 'ai_diagnosis_evidence') return '现有 AI 诊断入口';
        if (family === 'target_date_source_rows') return '现有目标日源数据补证入口';
        if (family === 'traffic_conversion_facts') return '现有流量/转化核对入口';
        if (family === 'revenue_metric_inputs' || family === 'standard_facts') return '现有收益指标核对入口';
        return '现有核验入口';
    };
    const phase1EmployeeActionEntryOptionModeText = (option) => {
        if (!option || typeof option !== 'object') {
            return String(option || '').trim();
        }
        const rawMode = String(option.mode || option.type || '').trim();
        return ({
            manual_cookie_api: '手动 Cookie/API',
            browser_profile: '浏览器 Profile',
            status_check: '状态核对',
        }[rawMode] || String(option.label || '').trim() || rawMode);
    };
    const phase1EmployeeActionEntryOptionRawText = (option) => {
        if (!option || typeof option !== 'object') {
            return String(option || '').trim();
        }
        const label = String(option.label || option.mode || option.type || '').trim();
        const entry = String(option.entry || '').trim();
        return [label, entry].filter(Boolean).join('：');
    };
    const phase1EmployeeActionEntryOptionText = (option) => {
        if (!option || typeof option !== 'object') {
            return String(option || '').trim();
        }
        const modeText = phase1EmployeeActionEntryOptionModeText(option);
        const entry = phase1EmployeeActionEntryText(option.entry || '', option);
        return [modeText, entry].filter(Boolean).join('：');
    };
    const phase1EmployeeActionEntryOptionPlatformText = (option) => {
        if (!option || typeof option !== 'object') {
            return 'OTA';
        }
        const raw = [
            option.platform,
            option.source,
            option.entry,
            option.label,
        ].map(value => String(value || '').toLowerCase()).join(' ');
        if (raw.includes('ctrip') || raw.includes('携程')) {
            return '携程';
        }
        if (raw.includes('meituan') || raw.includes('美团')) {
            return '美团';
        }
        return 'OTA';
    };
    const phase1EmployeeActionEntryOptionInputText = (value) => ({
        target_date: '目标日期',
        system_hotel_id: '系统酒店 ID',
        ctrip_hotel_id_or_node_id: '携程酒店/节点 ID',
        meituan_poi_id_or_partner_id: '美团 POI/合作方 ID',
        authorized_cookie_or_headers: '授权 Cookie/请求头',
        traffic_request_url_or_cdp_endpoint_evidence: '流量接口或 CDP 端点证据',
        traffic_payload_or_query_params: '流量 Payload/查询参数',
        desensitized_traffic_response_sample_or_source_trace_id: '脱敏响应样例或 source_trace_id',
        authorized_ctrip_profile_dir: '授权携程 Profile',
        authorized_meituan_profile_dir: '授权美团 Profile',
        manual_login_state_verified: '人工确认登录态',
        traffic_response_listener: '流量响应监听',
    }[String(value || '').trim()] || String(value || '').trim());
    const phase1EmployeeActionEntryOptionContractText = (option) => {
        const contract = option && typeof option === 'object' && option.input_contract && typeof option.input_contract === 'object'
            ? option.input_contract
            : null;
        if (!contract || String(contract.target_data_type || '').trim() !== 'traffic') {
            return '';
        }
        const metricKeys = Array.isArray(contract.required_metric_keys)
            ? contract.required_metric_keys.map(value => String(value || '').trim()).filter(Boolean)
            : [];
        const storageFields = Array.isArray(contract.required_storage_fields)
            ? contract.required_storage_fields.map(value => String(value || '').trim()).filter(Boolean)
            : [];
        const inputs = Array.isArray(contract.required_inputs)
            ? contract.required_inputs.map(phase1EmployeeActionEntryOptionInputText).filter(Boolean)
            : [];
        const fieldFactKeys = Array.isArray(contract.required_field_fact_keys)
            ? contract.required_field_fact_keys.map(value => String(value || '').trim()).filter(Boolean)
            : [];
        const metricText = metricKeys.length ? `需闭环指标 ${metricKeys.join('、')}` : '';
        const storageText = storageFields.length ? `需入库字段 ${storageFields.join('、')}` : '';
        const inputText = inputs.length ? `需补输入 ${inputs.join('、')}` : '';
        const factText = fieldFactKeys.length ? '需证明采集证据、source path、metric key、入库字段和已入库值' : '';
        const sensitiveText = contract.sensitive_values_allowed === false ? '不展示 Cookie、token 或 Profile 原值' : '';
        return [metricText, storageText, inputText, factText, sensitiveText].filter(Boolean).join('；');
    };
    const phase1EmployeeActionEntryOptionGuidanceText = (option) => {
        if (!option || typeof option !== 'object') {
            return '';
        }
        const modeText = phase1EmployeeActionEntryOptionModeText(option);
        const rawMode = String(option.mode || option.type || '').trim();
        const platformText = phase1EmployeeActionEntryOptionPlatformText(option);
        const platformPrefix = platformText === 'OTA' ? '' : platformText;
        const platformBackendText = platformText === 'OTA' ? 'OTA 后台' : `${platformText}后台`;
        const stableDetails = ({
            manual_cookie_api: [
                `用于已取得${platformText} Cookie/Payload/导出上下文时补齐目标日数据`,
                '需用户提供 Cookie/Payload 上下文、门店标识和目标日期',
                `不代登录${platformBackendText}，不启动浏览器 Profile，不改变采集字段`,
            ],
            browser_profile: [
                `用于${platformPrefix}已授权本机浏览器 Profile 走现有自动采集路径`,
                '需 Profile 存在，登录态仍由现有流程核验',
                '不绕过验证码、短信或人机验证，不改变自动采集逻辑',
            ],
            status_check: [
                '用于只读核对目标日入库、最近可用日期和失败原因',
                '只读取现有采集可靠性和 online_daily_data 状态',
                '不写 OTA 数据，不改变字段映射',
            ],
        }[rawMode] || [
            '按现有入口要求选择',
            '按现有入口要求补齐上下文',
            '不改变采集逻辑和字段',
        ]);
        const contractText = phase1EmployeeActionEntryOptionContractText(option);
        const details = [...stableDetails, contractText].filter(Boolean).join('；');
        return [modeText, details].filter(Boolean).join('：');
    };
    const phase1EmployeeActionEntryOptionGuidanceRawText = (option) => {
        if (!option || typeof option !== 'object') {
            return '';
        }
        return [
            String(option.use_when || '').trim(),
            String(option.requires || '').trim(),
            String(option.boundary || '').trim(),
        ].filter(Boolean).join('；');
    };
    const phase1EmployeeActionEntryOptionReadinessText = (option) => {
        if (!option || typeof option !== 'object') {
            return '';
        }
        const readiness = option.readiness && typeof option.readiness === 'object' ? option.readiness : null;
        if (!readiness) {
            return '';
        }
        const modeText = phase1EmployeeActionEntryOptionModeText(option);
        const label = phase1EmployeeReadinessStatusText(readiness.status) || String(readiness.label || '').trim();
        const canRunNowText = readiness.can_run_now === true ? '可直接执行' : (readiness.can_run_now === false ? '需先准备' : '');
        const reason = String(readiness.reason || '').trim();
        const evidenceValues = [
            phase1EmployeeReadinessEvidenceText(readiness.evidence),
            phase1EmployeeReadinessEvidenceText(readiness.source_policy),
        ].filter(Boolean);
        const evidence = Array.from(new Set(evidenceValues)).join(' / ');
        const profileCount = Number.isFinite(Number(readiness.profile_count)) ? Number(readiness.profile_count) : null;
        const profileText = profileCount === null ? '' : `Profile ${profileCount} 个`;
        return [modeText, [canRunNowText, label, profileText, reason, evidence].filter(Boolean).join(' / ')].filter(Boolean).join('：');
    };
    const phase1EmployeeKnownQuestionText = (key) => {
        const raw = String(key || '').trim();
        const text = phase1EmployeeQuestionKeyText(raw);
        return text && text !== raw ? text : '';
    };
    const phase1EmployeeKnownQuestionListText = (values) => {
        const list = Array.isArray(values) ? values : (values ? [values] : []);
        const mapped = list.map(phase1EmployeeKnownQuestionText).filter(Boolean);
        return mapped.length ? mapped.join('、') : (list.length ? '未识别员工问题' : '');
    };
    const phase1EmployeeActionSuccessCriteriaText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const employeeCriteria = String(source.employee_success_criteria || source.employeeSuccessCriteria || '').trim();
        if (employeeCriteria) return employeeCriteria;
        const rawCode = phase1EmployeeActionRawCode(item);
        const family = String(source.action_family || source.actionFamily || '').trim();
        const platformText = phase1EmployeeActionPlatformText(source, rawCode);
        if (rawCode === 'phase1_confirm_source_date_evidence' || family === 'target_date_source_rows') {
            return `${platformText}目标日入库行数 > 0；最近可用/历史数据只作参考`;
        }
        if (/^(?:phase1_collect_(ctrip|meituan)_target_date_source_rows|(ctrip|meituan)_source_rows_missing_collect_existing_path)$/.test(rawCode)) {
            return `${platformText}目标日入库行数 > 0；最近可用/历史数据只作参考`;
        }
        if (/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/.test(rawCode) || family === 'standard_facts') {
            return `${platformText}标准事实层已就绪，目标日数据能进入收益/流量/转化指标计算`;
        }
        if (/^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/.test(rawCode) || family === 'revenue_metric_inputs') {
            return `${platformText}收益指标输入可复核，指标可信状态和数据缺口均有明确状态`;
        }
        if (/^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/.test(rawCode) || family === 'traffic_conversion_facts') {
            return `${platformText}流量/转化事实已入库并可复核，缺口不再阻断 AI 依据`;
        }
        if (rawCode === 'resolve_ai_diagnosis_blocked_action_items') {
            return 'AI 动作项不再被上游 OTA 缺口阻断，并带有证据来源、数据缺口和可执行动作项';
        }
        if (rawCode === 'phase1_collect_ai_diagnosis_evidence' || rawCode === 'collect_ai_diagnosis_evidence' || family === 'ai_diagnosis_evidence') {
            return 'AI 诊断带有证据来源、数据缺口和至少一个非阻断动作项';
        }
        if (rawCode === 'phase1_create_operation_execution_evidence' || rawCode === 'collect_operation_execution_evidence' || family === 'operation_execution_evidence') {
            return '执行意图可追溯到 OTA 诊断动作，并出现审批、执行证据、复盘或 ROI 信号';
        }
        const localMatch = rawCode.match(/^local_(.+)_required_action$/);
        const questionKey = localMatch?.[1] || String(source.question_key || source.questionKey || '').trim();
        if (questionKey) {
            const questionText = phase1EmployeeKnownQuestionText(questionKey) || '当前员工问题';
            return ({
                today_ota_collected: '目标日携程/美团入库状态可复核，缺失平台明确标出',
                trusted_fields: '字段可信状态有字段资产、指标可信状态和目标日样例支撑',
                missing_fields: '缺失字段和数据缺口被显式列出，无缺口时也保留可复核证据',
                revenue_traffic_conversion: '收益、流量、转化分别有 ready/missing 状态，缺失时不输出确定结论',
                ai_evidence: 'AI 建议有证据来源、数据缺口和非阻断动作项',
                next_operation_action: '下一步动作能追溯到 OTA 诊断，并出现执行闭环信号',
            }[questionKey] || `${questionText}从未证明变为可复核`);
        }
        return rawCode || family || String(source.success_criteria || source.successCriteria || '').trim()
            ? '该动作对应缺口从待证明变为可复核；原始完成条件仅保留追溯。'
            : '';
    };
    const phase1EmployeeActionEvidenceNeededText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const employeeEvidence = Array.isArray(source.employee_evidence_needed || source.employeeEvidenceNeeded)
            ? (source.employee_evidence_needed || source.employeeEvidenceNeeded).filter(Boolean).join('、')
            : String(source.employee_evidence_needed || source.employeeEvidenceNeeded || '').trim();
        if (employeeEvidence) return employeeEvidence;
        const rawCode = phase1EmployeeActionRawCode(item);
        const family = String(source.action_family || source.actionFamily || '').trim();
        const platformText = phase1EmployeeActionPlatformText(source, rawCode);
        if (rawCode === 'phase1_confirm_source_date_evidence' || family === 'target_date_source_rows') {
            return `${platformText}目标日入库行、平台源数据快照、采集日志或回放记录`;
        }
        if (/^(?:phase1_collect_(ctrip|meituan)_target_date_source_rows|(ctrip|meituan)_source_rows_missing_collect_existing_path)$/.test(rawCode)) {
            return `${platformText}目标日入库行、平台源数据快照、采集日志或回放记录`;
        }
        if (/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/.test(rawCode) || family === 'standard_facts') {
            return `${platformText}标准事实层状态、验收行数、校验标记和数据类型`;
        }
        if (/^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/.test(rawCode) || family === 'revenue_metric_inputs') {
            return `${platformText}目标日收益样本、指标可信状态、数据缺口清单`;
        }
        if (/^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/.test(rawCode) || family === 'traffic_conversion_facts') {
            return `${platformText}流量/转化事实、数据缺口、目标日数据类型`;
        }
        if (rawCode === 'resolve_ai_diagnosis_blocked_action_items' || rawCode === 'phase1_collect_ai_diagnosis_evidence' || rawCode === 'collect_ai_diagnosis_evidence' || family === 'ai_diagnosis_evidence') {
            return 'AI 诊断证据来源、数据缺口、非阻断动作项';
        }
        if (rawCode === 'phase1_create_operation_execution_evidence' || rawCode === 'collect_operation_execution_evidence' || family === 'operation_execution_evidence') {
            return '运营执行意图或执行流程、审批、执行证据、复盘或 ROI';
        }
        const localMatch = rawCode.match(/^local_(.+)_required_action$/);
        const questionKey = localMatch?.[1] || String(source.question_key || source.questionKey || '').trim();
        if (questionKey) {
            return ({
                today_ota_collected: '目标日平台源数据快照、入库行数、缺失平台',
                trusted_fields: '字段资产、指标可信状态、数据质量、目标日样例',
                missing_fields: '缺失字段清单、数据缺口、字段资产',
                revenue_traffic_conversion: '目标日收益事实、流量事实、转化事实、指标可信状态、数据缺口',
                ai_evidence: 'AI 诊断证据来源、数据缺口、动作项',
                next_operation_action: '运营执行意图或执行流程、审批、执行证据、复盘/ROI',
            }[questionKey] || '当前问题对应的可复核证据');
        }
        return rawCode || family || source.evidence_needed || source.evidenceNeeded
            ? '当前动作对应的目标日 OTA 证据、状态快照和缺口清单'
            : '';
    };
    const phase1EmployeeActionVerificationStepsText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        return Array.isArray(source.employee_verification_steps || source.employeeVerificationSteps)
            ? (source.employee_verification_steps || source.employeeVerificationSteps).filter(Boolean).join('；')
            : String(source.employee_verification_steps || source.employeeVerificationSteps || '').trim();
    };
    const phase1EmployeeActionBlockedActionText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const blockedCodes = Array.isArray(source.blocked_by_action_codes || source.blockedByActionCodes)
            ? (source.blocked_by_action_codes || source.blockedByActionCodes)
            : String(source.blocked_by_action_codes || source.blockedByActionCodes || '').split(/[、,，\s]+/);
        return blockedCodes.map(code => phase1EmployeeActionCodeText(code, {
            knownQuestionText: phase1EmployeeKnownQuestionText,
            platformText: phase1EmployeePlatformText,
        })).filter(Boolean).join('、');
    };
    const phase1EmployeeActionEmployeeExplanationText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const rawCode = phase1EmployeeActionRawCode(item);
        const family = String(source.action_family || source.actionFamily || '').trim();
        const platformText = phase1EmployeeActionPlatformText(source, rawCode);
        const fallback = String(source.employee_explanation || source.employeeExplanation || '').trim();
        if (rawCode === 'resolve_ai_diagnosis_blocked_action_items') return 'AI 动作项仍被上游 OTA 证据缺口阻断，当前只能定位缺口，不能直接进入执行。';
        if (rawCode === 'phase1_create_operation_execution_evidence' || rawCode === 'collect_operation_execution_evidence' || family === 'operation_execution_evidence') return '运营动作还没有形成可追溯的审批、执行证据、复盘或 ROI 信号，不能证明闭环完成。';
        if (rawCode === 'phase1_collect_ai_diagnosis_evidence' || rawCode === 'collect_ai_diagnosis_evidence' || family === 'ai_diagnosis_evidence') return 'AI 诊断需要同时引用证据来源、数据缺口和动作项，缺少任一环节都只能作为待补证据。';
        if (/^(?:phase1_collect_(ctrip|meituan)_target_date_source_rows|(ctrip|meituan)_source_rows_missing_collect_existing_path)$/.test(rawCode) || rawCode === 'phase1_confirm_source_date_evidence' || family === 'target_date_source_rows') return `${platformText}目标日源数据还没有形成入库证明，当前不能判定该平台今日 OTA 数据已采到。`;
        if (/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/.test(rawCode) || family === 'standard_facts') return `${platformText}源数据尚未证明进入标准事实层，收益、流量或转化分析不能直接使用该平台事实。`;
        if (/^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/.test(rawCode) || family === 'revenue_metric_inputs') return `${platformText}收益指标输入或指标可信证据未就绪，不能给出确定的收入、ADR、订单或间夜结论。`;
        if (/^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/.test(rawCode) || family === 'traffic_conversion_facts') return `${platformText}流量/转化事实缺失，不能判断曝光、访问、下单或转化问题。`;
        const localMatch = rawCode.match(/^local_(.+)_required_action$/);
        const questionKey = localMatch?.[1] || String(source.question_key || source.questionKey || '').trim();
        if (questionKey) {
            const questionText = phase1EmployeeKnownQuestionText(questionKey) || '当前员工问题';
            return `${questionText}还没有形成完整证据，只能作为待补证据项处理。`;
        }
        return fallback;
    };
    const phase1EmployeeActionLimitedConclusionsText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const rawCode = phase1EmployeeActionRawCode(item);
        const family = String(source.action_family || source.actionFamily || '').trim();
        const platformText = phase1EmployeeActionPlatformText(source, rawCode);
        const fallback = Array.isArray(source.limited_conclusions || source.limitedConclusions)
            ? (source.limited_conclusions || source.limitedConclusions).filter(Boolean).join('、')
            : String(source.limited_conclusions || source.limitedConclusions || '').trim();
        if (rawCode === 'resolve_ai_diagnosis_blocked_action_items') return '不能声明 AI 建议可执行，不能创建确定运营动作。';
        if (rawCode === 'phase1_create_operation_execution_evidence' || rawCode === 'collect_operation_execution_evidence' || family === 'operation_execution_evidence') return '不能声明运营动作已完成，不能声明已有 ROI 或复盘结论。';
        if (rawCode === 'phase1_collect_ai_diagnosis_evidence' || rawCode === 'collect_ai_diagnosis_evidence' || family === 'ai_diagnosis_evidence') return '不能声明 AI 依据完整，不能把缺证据建议当作确定决策。';
        if (/^(?:phase1_collect_(ctrip|meituan)_target_date_source_rows|(ctrip|meituan)_source_rows_missing_collect_existing_path)$/.test(rawCode) || rawCode === 'phase1_confirm_source_date_evidence' || family === 'target_date_source_rows') return `不能判定${platformText}目标日已采到，不能据此下收益或 AI 结论。`;
        if (/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/.test(rawCode) || family === 'standard_facts') return `不能确认${platformText}标准事实层已就绪，不能进入确定指标计算。`;
        if (/^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/.test(rawCode) || family === 'revenue_metric_inputs') return `不能确认${platformText}收入、ADR、间夜、订单趋势，不能作为 AI 定价依据。`;
        if (/^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/.test(rawCode) || family === 'traffic_conversion_facts') return `不能确认${platformText}曝光、访问、转化率或漏斗问题。`;
        return fallback;
    };
    const phase1EmployeeActionStillUsableMetricsText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const rawCode = phase1EmployeeActionRawCode(item);
        const family = String(source.action_family || source.actionFamily || '').trim();
        const platformText = phase1EmployeeActionPlatformText(source, rawCode);
        const fallback = Array.isArray(source.still_usable_metrics || source.stillUsableMetrics)
            ? (source.still_usable_metrics || source.stillUsableMetrics).filter(Boolean).join('、')
            : String(source.still_usable_metrics || source.stillUsableMetrics || '').trim();
        if (rawCode === 'resolve_ai_diagnosis_blocked_action_items') return '已有证据来源和数据缺口可用于定位缺口，但不能当作可执行建议。';
        if (rawCode === 'phase1_create_operation_execution_evidence' || rawCode === 'collect_operation_execution_evidence' || family === 'operation_execution_evidence') return '已有执行流计数可用于排查进度，但缺少可追溯 OTA 诊断链时不算闭环。';
        if (rawCode === 'phase1_collect_ai_diagnosis_evidence' || rawCode === 'collect_ai_diagnosis_evidence' || family === 'ai_diagnosis_evidence') return '已有 OTA 数据缺口和字段状态可用于定位诊断阻断点。';
        if (/^(?:phase1_collect_(ctrip|meituan)_target_date_source_rows|(ctrip|meituan)_source_rows_missing_collect_existing_path)$/.test(rawCode) || rawCode === 'phase1_confirm_source_date_evidence' || family === 'target_date_source_rows') return '已采到的其他平台、最近可用日期和历史样本可作参考，但不能替代目标日缺失平台。';
        if (/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/.test(rawCode) || family === 'standard_facts') return `${platformText}源数据行、原始快照、验收或拒绝原因可用于排查。`;
        if (/^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/.test(rawCode) || family === 'revenue_metric_inputs') return '已就绪平台的指标和数据缺口可用于定位缺口，不能补齐缺失平台结论。';
        if (/^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/.test(rawCode) || family === 'traffic_conversion_facts') return '已有收益事实和已入库业务事实可参考，流量/转化结论需等事实补齐。';
        return fallback;
    };
    const phase1EmployeeActionExplanationNextActionText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const employeeNextAction = String(source.employee_explanation_next_action || source.employeeExplanationNextAction || '').trim();
        if (employeeNextAction) return employeeNextAction;
        const rawCode = phase1EmployeeActionRawCode(item);
        const family = String(source.action_family || source.actionFamily || '').trim();
        const platformText = phase1EmployeeActionPlatformText(source, rawCode);
        const blockedText = phase1EmployeeActionBlockedActionText(source);
        const fallback = String(source.explanation_next_action || source.explanationNextAction || '').trim();
        if (rawCode === 'resolve_ai_diagnosis_blocked_action_items') return blockedText ? `先处理：${blockedText}；再重新生成 AI 诊断。` : '先补齐 OTA 证据，再重新生成 AI 诊断。';
        if (rawCode === 'phase1_create_operation_execution_evidence' || rawCode === 'collect_operation_execution_evidence' || family === 'operation_execution_evidence') return blockedText ? `先完成上游动作：${blockedText}；再创建或复核执行意图。` : '从真实 AI 动作项创建执行意图，并保留审批、执行证据和复盘。';
        if (rawCode === 'phase1_collect_ai_diagnosis_evidence' || rawCode === 'collect_ai_diagnosis_evidence' || family === 'ai_diagnosis_evidence') return '补齐 OTA 证据后重新生成诊断，确认返回证据来源、数据缺口和动作项。';
        if (/^(?:phase1_collect_(ctrip|meituan)_target_date_source_rows|(ctrip|meituan)_source_rows_missing_collect_existing_path)$/.test(rawCode) || rawCode === 'phase1_confirm_source_date_evidence' || family === 'target_date_source_rows') return `默认通过现有${platformText}浏览器 Profile 采集入口补齐目标日源数据；手动 Cookie/API 仅用于临时补数或排障。`;
        if (/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/.test(rawCode) || family === 'standard_facts') return `复核${platformText}标准事实层验收行、校验标记和 data_type。`;
        if (/^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/.test(rawCode) || family === 'revenue_metric_inputs') return `复核${platformText}收益指标输入、指标可信证据和数据缺口。`;
        if (/^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/.test(rawCode) || family === 'traffic_conversion_facts') return `补齐${platformText}流量/转化事实，再复跑收益指标和 AI 诊断。`;
        const localMatch = rawCode.match(/^local_(.+)_required_action$/);
        const questionKey = localMatch?.[1] || String(source.question_key || source.questionKey || '').trim();
        if (questionKey) {
            const questionText = phase1EmployeeKnownQuestionText(questionKey) || '当前员工问题';
            return `按“${questionText}”对应证据清单补齐后复跑员工六问。`;
        }
        return fallback;
    };
    const phase1EmployeeActionDisplayText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const employeeAction = String(source.employee_action || source.employeeAction || '').trim();
        if (employeeAction) return employeeAction;
        const rawCode = phase1EmployeeActionRawCode(source);
        const codeText = phase1EmployeeActionCodeText(rawCode, {
            knownQuestionText: phase1EmployeeKnownQuestionText,
            platformText: phase1EmployeePlatformText,
        });
        if (codeText && codeText !== rawCode) return codeText;
        const questionKey = String(source.question_key || source.questionKey || '').trim();
        const family = String(source.action_family || source.actionFamily || '').trim();
        const questionText = phase1EmployeeKnownQuestionText(questionKey);
        const familyText = phase1EmployeeActionFamilyText(family);
        if (questionText && family) return `${questionText}：${familyText}`;
        if (questionText) return `补齐${questionText}证据`;
        if (family) return `现有${familyText}补证动作`;
        return '现有首要补证动作';
    };
    const phase1EmployeeActionOwnerText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const family = String(source.action_family || source.actionFamily || '').trim();
        const questionKey = String(source.question_key || source.questionKey || '').trim();
        if (family === 'ai_diagnosis_evidence' || questionKey === 'ai_evidence') return 'AI 诊断复核';
        if (family === 'operation_execution_evidence' || questionKey === 'next_operation_action') return '运营执行负责人';
        if (['standard_facts', 'revenue_metric_inputs', 'traffic_conversion_facts'].includes(family) || questionKey === 'revenue_traffic_conversion') return '收益/数据复核';
        if (family === 'target_date_source_rows' || questionKey === 'today_ota_collected') return '酒店运营人员';
        return String(source.owner || '').trim() || '酒店运营人员';
    };
    const phase1EmployeeActionMetaText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const rawCode = phase1EmployeeActionRawCode(source);
        const platformText = phase1EmployeeActionPlatformText(source, rawCode);
        const questionText = phase1EmployeeKnownQuestionText(source.question_key || source.questionKey || '') || '当前员工问题';
        return [
            phase1EmployeeActionOwnerText(source),
            platformText || 'OTA 渠道',
            questionText,
            phase1EmployeeActionStatusText(source.status),
        ].filter(Boolean).join(' / ');
    };
    const phase1EmployeeActionProtectedBoundaryText = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const rawCode = phase1EmployeeActionRawCode(source);
        const family = String(source.action_family || source.actionFamily || '').trim();
        if (/^(?:phase1_collect_(ctrip|meituan)_target_date_source_rows|(ctrip|meituan)_source_rows_missing_collect_existing_path)$/.test(rawCode) || rawCode === 'phase1_confirm_source_date_evidence' || family === 'target_date_source_rows') {
            return '默认只使用现有浏览器 Profile 或状态核对入口补证；手动 Cookie/API 仅作临时补数或排障；不改变采集逻辑和字段。';
        }
        if (family === 'standard_facts' || /^(ctrip|meituan)_etl_not_ready_check_standard_facts$/.test(rawCode)) {
            return '只复核现有入库数据和标准事实状态；不新增采集字段或表结构。';
        }
        if (family === 'revenue_metric_inputs' || /^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/.test(rawCode)) {
            return '只复核现有收益指标输入、指标可信证据和数据缺口；不把缺失指标补成成功。';
        }
        if (family === 'traffic_conversion_facts' || /^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/.test(rawCode)) {
            return '只复核现有流量/转化事实和缺口；不推断未采集平台数据。';
        }
        if (family === 'ai_diagnosis_evidence' || rawCode === 'resolve_ai_diagnosis_blocked_action_items') {
            return 'AI 只能引用已有 OTA 证据、数据缺口和动作项；不能替代缺失采集。';
        }
        if (family === 'operation_execution_evidence' || rawCode === 'collect_operation_execution_evidence') {
            return '执行闭环必须追溯到 OTA 诊断动作和执行证据；不把普通建议当作已执行。';
        }
        const rawBoundary = String(source.protected_boundary || source.protectedBoundary || '').trim();
        return rawBoundary ? '按现有证据边界处理；不改变采集逻辑和字段，不把缺失证据写成完成。' : '';
    };
    const normalizePhase1EmployeeRequiredAction = (item) => {
        const gapCodeText = (code) => phase1EmployeeGapCodeText(code, phase1EmployeeKnownQuestionText);
        const actionCodeText = (code) => phase1EmployeeActionCodeText(code, {
            knownQuestionText: phase1EmployeeKnownQuestionText,
            platformText: phase1EmployeePlatformText,
        });
        const entryOptions = Array.isArray(item?.entry_options)
            ? item.entry_options.map(phase1EmployeeActionEntryOptionText).filter(Boolean)
            : [];
        const entryOptionRaw = Array.isArray(item?.entry_options)
            ? item.entry_options.map(phase1EmployeeActionEntryOptionRawText).filter(Boolean)
            : [];
        const entryOptionGuidance = Array.isArray(item?.entry_options)
            ? item.entry_options.map(phase1EmployeeActionEntryOptionGuidanceText).filter(Boolean)
            : [];
        const entryOptionGuidanceRaw = Array.isArray(item?.entry_options)
            ? item.entry_options.map(phase1EmployeeActionEntryOptionGuidanceRawText).filter(Boolean)
            : [];
        const entryReadiness = Array.isArray(item?.entry_options)
            ? item.entry_options.map(phase1EmployeeActionEntryOptionReadinessText).filter(Boolean)
            : [];
        return {
            actionCode: String(item?.action_code || item?.code || item?.action || ''),
            actionFamily: String(item?.action_family || ''),
            actionFamilyText: phase1EmployeeActionFamilyText(item?.action_family || item?.type || ''),
            type: String(item?.type || ''),
            priority: String(item?.priority || 'medium'),
            status: String(item?.status || 'missing'),
            platform: String(item?.platform || '').toUpperCase(),
            questionKey: String(item?.question_key || ''),
            reason: String(item?.reason || ''),
            employeeAction: String(item?.employee_action || ''),
            action: String(item?.next_action || item?.action || ''),
            actionText: phase1EmployeeActionDisplayText(item),
            employeeExplanation: String(item?.employee_explanation || ''),
            employeeExplanationText: phase1EmployeeActionEmployeeExplanationText(item),
            limitedConclusions: Array.isArray(item?.limited_conclusions) ? item.limited_conclusions.filter(Boolean).join('、') : String(item?.limited_conclusions || ''),
            limitedConclusionsText: phase1EmployeeActionLimitedConclusionsText(item),
            stillUsableMetrics: Array.isArray(item?.still_usable_metrics) ? item.still_usable_metrics.filter(Boolean).join('、') : String(item?.still_usable_metrics || ''),
            stillUsableMetricsText: phase1EmployeeActionStillUsableMetricsText(item),
            employeeExplanationNextAction: String(item?.employee_explanation_next_action || ''),
            explanationNextAction: String(item?.explanation_next_action || ''),
            explanationNextActionText: phase1EmployeeActionExplanationNextActionText(item),
            entry: String(item?.entry || ''),
            entryText: phase1EmployeeActionEntryText(item?.entry || '', item),
            entryOptions,
            entryOptionsText: entryOptions.join('、'),
            entryOptionsRawText: entryOptionRaw.join('、'),
            entryOptionGuidanceText: entryOptionGuidance.join('；'),
            entryOptionGuidanceRawText: entryOptionGuidanceRaw.join('；'),
            entryReadinessText: entryReadiness.join('；'),
            relatedQuestionKeys: Array.isArray(item?.related_question_keys) ? item.related_question_keys.map(value => String(value)).filter(Boolean) : [],
            relatedQuestionKeysText: phase1EmployeeKnownQuestionListText(item?.related_question_keys),
            relatedQuestionKeysRawText: Array.isArray(item?.related_question_keys) ? item.related_question_keys.map(value => String(value)).filter(Boolean).join('、') : '',
            owner: String(item?.owner || '未指定'),
            ownerText: phase1EmployeeActionOwnerText(item),
            actionMetaText: phase1EmployeeActionMetaText(item),
            actionMetaRawText: [String(item?.owner || ''), String(item?.platform || ''), String(item?.reason || ''), String(item?.status || '')].filter(Boolean).join(' / '),
            employeeEvidenceNeeded: Array.isArray(item?.employee_evidence_needed) ? item.employee_evidence_needed.filter(Boolean).join('、') : String(item?.employee_evidence_needed || ''),
            evidenceNeeded: Array.isArray(item?.evidence_needed) ? item.evidence_needed.filter(Boolean).join('、') : String(item?.evidence_needed || ''),
            evidenceNeededText: phase1EmployeeActionEvidenceNeededText(item),
            employeeSuccessCriteria: String(item?.employee_success_criteria || ''),
            successCriteria: String(item?.success_criteria || ''),
            successCriteriaText: phase1EmployeeActionSuccessCriteriaText(item),
            employeeVerificationSteps: Array.isArray(item?.employee_verification_steps) ? item.employee_verification_steps.filter(Boolean).join('、') : String(item?.employee_verification_steps || ''),
            verificationStepsText: phase1EmployeeActionVerificationStepsText(item),
            blockedBy: Array.isArray(item?.blocked_by) ? item.blocked_by.map(gapCodeText).filter(Boolean).join('、') : gapCodeText(item?.blocked_by || ''),
            blockedByActions: Array.isArray(item?.blocked_by_action_codes) ? item.blocked_by_action_codes.map(actionCodeText).filter(Boolean).join('、') : String(item?.blocked_by_action_codes || '').split('、').map(actionCodeText).filter(Boolean).join('、'),
            liveClosureGapCodes: Array.isArray(item?.live_closure_gap_codes) ? item.live_closure_gap_codes.map(gapCodeText).filter(Boolean).join('、') : gapCodeText(item?.live_closure_gap_codes || ''),
            resolvesMissingCodes: Array.isArray(item?.resolves_missing_codes) ? item.resolves_missing_codes.map(gapCodeText).filter(Boolean).join('、') : gapCodeText(item?.resolves_missing_codes || ''),
            protectedBoundary: String(item?.protected_boundary || ''),
            protectedBoundaryText: phase1EmployeeActionProtectedBoundaryText(item),
            sourcePolicy: String(item?.source_policy || ''),
        };
    };
    const phase1LocalActionMeta = (key) => ({
        today_ota_collected: {
            actionFamily: 'target_date_source_rows',
            priority: 'high',
            entry: '/api/online-data/collection-reliability',
            owner: '酒店运营人员',
            evidenceNeeded: '目标日来源证据、OTA 入库表同日期源数据行、采集日志或回放记录',
            successCriteria: '目标日携程/美团均有入库行，且缺失平台为空。',
        },
        trusted_fields: {
            actionFamily: 'standard_facts',
            priority: 'medium',
            entry: '/api/online-data/collection-reliability',
            owner: '收益运营人员',
            evidenceNeeded: '字段资产、指标可信证据、数据质量状态、目标日样例',
            successCriteria: '字段资产、指标可信证据和数据质量状态均能支撑字段可信判断。',
        },
        missing_fields: {
            actionFamily: 'standard_facts',
            priority: 'medium',
            entry: '/api/online-data/collection-reliability',
            owner: '产品/技术',
            evidenceNeeded: '数据质量缺失字段、OTA 诊断数据缺口、字段资产',
            successCriteria: '缺失字段和数据缺口被显式列出；无缺口时也保留可复核证据。',
        },
        revenue_traffic_conversion: {
            actionFamily: 'traffic_conversion_facts',
            priority: 'high',
            entry: '/api/ota-standard/revenue-metrics',
            owner: 'OTA 运营人员',
            evidenceNeeded: '目标日收益事实、流量事实、转化事实、指标可信证据、数据缺口',
            successCriteria: '收益、流量、转化指标域分别有 ready/missing 状态，缺失时不输出确定结论。',
        },
        ai_evidence: {
            actionFamily: 'ai_diagnosis_evidence',
            priority: 'high',
            entry: '/api/agent/ota-diagnosis',
            owner: 'AI 运营人员',
            evidenceNeeded: '证据来源、数据缺口、动作项',
            successCriteria: 'OTA 诊断响应包含证据来源、数据缺口和至少一个非阻断动作项。',
        },
        next_operation_action: {
            actionFamily: 'operation_execution_evidence',
            priority: 'medium',
            entry: '/api/operation/execution-intents',
            owner: '运营负责人',
            evidenceNeeded: '执行意图或执行流、审批状态、执行证据、复盘或 ROI',
            successCriteria: '执行意图或执行流程可追溯到 OTA 诊断动作项，并出现审批、执行证据、复盘或 ROI 信号。',
        },
    }[String(key || '')] || {
        actionFamily: 'evidence_scope',
        priority: 'medium',
        entry: '/api/online-data/collection-reliability',
        owner: '运营人员',
        evidenceNeeded: '当前问题对应的可复核证据',
        successCriteria: '证据状态从待证明变为可复核。',
    });
    const buildPhase1LocalRequiredAction = (row, index = 0) => {
        const key = String(row?.key || row?.question || `question_${index + 1}`).trim();
        const meta = phase1LocalActionMeta(key);
        const statusText = phase1EmployeeQuestionStatusText(row?.status);
        const detail = String(row?.detail || row?.evidence || '').trim();
        return normalizePhase1EmployeeRequiredAction({
            action_code: `local_${key}_required_action`,
            action_family: meta.actionFamily,
            type: 'local_employee_question_action',
            priority: meta.priority,
            status: 'missing',
            platform: 'ota',
            question_key: key,
            reason: ['本地六问推导', statusText, detail].filter(Boolean).join(' / '),
            next_action: String(row?.nextActionText || row?.employeeNextActionText || row?.nextAction || '复核当前问题对应证据。'),
            entry: meta.entry,
            owner: meta.owner,
            evidence_needed: meta.evidenceNeeded,
            success_criteria: meta.successCriteria,
            blocked_by: row?.primaryNextActionCode ? [row.primaryNextActionCode] : [],
            blocked_by_action_codes: row?.primaryNextActionCode ? [row.primaryNextActionCode] : [],
            resolves_missing_codes: [key],
            source_policy: 'local_ui_derived_from_employee_questions',
            protected_boundary: '仅为前端根据已加载员工六问推导的待办；不代表后端采集成功，不改变携程/美团手动或自动获取逻辑，不改变获取字段。',
        });
    };
    const phase1DiagnosisActionItemStatus = (item) => String(item?.status || '').trim();
    const phase1DiagnosisActionItemText = (item) => String(item?.action || item?.title || item?.name || '').trim();
    const phase1DiagnosisActionItemBlocked = (item) => {
        const status = phase1DiagnosisActionItemStatus(item);
        return status === 'blocked' || status.startsWith('blocked_');
    };
    const buildPhase1AiDiagnosisEvidence = ({ diagnosisResult = {}, gaps = [], actions = [] } = {}) => {
        const source = diagnosisResult && typeof diagnosisResult === 'object' ? diagnosisResult : {};
        const evidenceSources = Array.isArray(source?.evidence_sources) ? source.evidence_sources : [];
        const safeGaps = Array.isArray(gaps) ? gaps : [];
        const safeActions = Array.isArray(actions) ? actions : [];
        const dataGapEvidencePresent = Object.prototype.hasOwnProperty.call(source, 'data_gaps');
        const actionableCount = safeActions.filter(item => !phase1DiagnosisActionItemBlocked(item) && phase1DiagnosisActionItemText(item)).length;
        const blockedCount = safeActions.filter(phase1DiagnosisActionItemBlocked).length;
        const hasEvidence = evidenceSources.length > 0 && dataGapEvidencePresent && safeActions.length > 0;
        const status = hasEvidence && actionableCount > 0
            ? 'proved'
            : ((evidenceSources.length > 0 || dataGapEvidencePresent || safeGaps.length > 0 || safeActions.length > 0) ? 'warning' : 'missing');
        const blockingMissingCodes = [];
        if (evidenceSources.length === 0) blockingMissingCodes.push('ai_evidence_sources_missing');
        if (!dataGapEvidencePresent) blockingMissingCodes.push('ai_data_gaps_missing');
        if (safeActions.length === 0) {
            blockingMissingCodes.push('ai_action_items_missing');
        } else if (blockedCount >= safeActions.length) {
            blockingMissingCodes.push('ai_action_items_blocked');
        }
        return {
            status,
            evidence_source_count: evidenceSources.length,
            data_gap_evidence_present: dataGapEvidencePresent,
            data_gap_count: safeGaps.length,
            action_item_count: safeActions.length,
            actionable_action_item_count: actionableCount,
            blocked_action_item_count: blockedCount,
            action_item_statuses: safeActions.map(phase1DiagnosisActionItemStatus).filter(Boolean),
            blocking_missing_codes: status === 'proved' ? [] : blockingMissingCodes,
        };
    };
    const phase1EmployeeAiJudgementText = ({ status, blockingCount, actionableCount } = {}) => {
        if (String(status || '').toLowerCase() === 'proved' && actionableCount > 0 && blockingCount === 0) {
            return 'AI 建议已有可追溯证据和可执行动作项。';
        }
        if (blockingCount > 0) {
            return 'AI 建议依据已暴露上游缺口，动作项仍被阻断。';
        }
        return 'AI 建议依据未证明，不能作为确定经营结论。';
    };
    const phase1EmployeeAiLimitText = ({ blockingCount, actionableCount } = {}) => {
        if (blockingCount > 0) {
            return '不能把 blocked 动作项当成可执行经营建议。';
        }
        if (actionableCount <= 0) {
            return '没有可执行动作项时，不能创建运营执行闭环。';
        }
        return '';
    };
    const phase1EmployeeOperationJudgementText = ({ status, executionIntentCount, executionFlowItemCount, completionSignalCount } = {}) => {
        if (String(status || '').toLowerCase() === 'proved' && completionSignalCount > 0) {
            return '运营动作已有审批、执行、证据、复盘或 ROI 信号。';
        }
        if (executionIntentCount <= 0 && executionFlowItemCount <= 0) {
            return '还没有可追溯执行意图或执行流。';
        }
        return '已有执行记录但缺少闭环完成信号。';
    };
    const phase1EmployeeOperationLimitText = ({ completionSignalCount, linkedIntentCount, linkedFlowCount } = {}) => {
        if (linkedIntentCount <= 0 && linkedFlowCount <= 0) {
            return '不能把未关联 OTA 诊断的普通执行记录算作闭环。';
        }
        if (completionSignalCount <= 0) {
            return '没有审批、执行、证据、复盘或 ROI 信号时，不能证明动作已落地。';
        }
        return '';
    };
    const buildPhase1EmployeeAiEvidenceSummary = ({ row = {}, evidence = {} } = {}) => {
        const source = evidence && typeof evidence === 'object' ? evidence : {};
        if (!source || typeof source !== 'object') return null;
        const blocking = Array.isArray(source.blocking_missing_codes) ? source.blocking_missing_codes.filter(Boolean).map(item => String(item)) : [];
        const rowBlocking = Array.isArray(row?.blocking_gap_codes) ? row.blocking_gap_codes.filter(Boolean).map(item => String(item)) : [];
        const allBlocking = Array.from(new Set([...blocking, ...rowBlocking]));
        const status = String(row?.status || source.status || (source.proved ? 'proved' : 'warning'));
        const evidenceSourceCount = Number(source.evidence_source_count || 0);
        const actionableCount = Number(source.actionable_action_item_count || 0);
        const dataGapPresent = source.data_gap_evidence_present === true || allBlocking.length > 0;
        const directEntry = String(row?.direct_next_action_entry || source.direct_next_action_entry || '');
        const primaryEntry = String(row?.primary_next_action_entry || source.primary_next_action_entry || '');
        const directCode = String(row?.direct_next_action_code || source.direct_next_action_code || '');
        const primaryCode = String(row?.primary_next_action_code || source.primary_next_action_code || '');
        const linkedCodes = Array.isArray(row?.next_action_codes) ? row.next_action_codes : [];
        const mappedNextAction = (directCode || primaryCode || linkedCodes.length) ? phase1EmployeeQuestionNextActionText(row) : '';
        const entryRaw = directEntry || primaryEntry || '/api/agent/ota-diagnosis';
        const entryText = phase1EmployeeActionEntryText(entryRaw, {
            action_code: directCode || primaryCode || 'resolve_ai_diagnosis_blocked_action_items',
            action_family: row?.direct_next_action_family || row?.evidence?.direct_next_action_family || 'ai_diagnosis_evidence',
            question_key: 'ai_evidence',
        });
        return {
            items: [
                phase1EmployeeCountItem('status', '状态', phase1EmployeeQuestionStatusText(status), status === 'proved'),
                phase1EmployeeCountItem('evidence_sources', '证据来源', evidenceSourceCount, evidenceSourceCount > 0),
                phase1EmployeeCountItem('data_gaps', '数据缺口', dataGapPresent ? '已返回' : '缺失', dataGapPresent),
                phase1EmployeeCountItem('action_items', '可执行动作', actionableCount, actionableCount > 0),
            ],
            blockingText: allBlocking.map(phase1EmployeeGapCodeText).filter(Boolean).join('、'),
            blockingRawText: allBlocking.join('、'),
            judgementText: phase1EmployeeAiJudgementText({ status, blockingCount: allBlocking.length, actionableCount }),
            judgementRawText: `status=${status || 'missing'} / blocking_gap_count=${allBlocking.length} / actionable_action_item_count=${actionableCount}`,
            limitText: phase1EmployeeAiLimitText({ blockingCount: allBlocking.length, actionableCount }),
            limitRawText: `blocked_action_codes=${Array.isArray(row?.blocked_action_codes) ? row.blocked_action_codes.join(',') : 'empty'} / blocking_gap_codes=${allBlocking.join(',') || 'empty'}`,
            nextActionText: mappedNextAction || '先补齐上游 OTA 证据，再生成 AI 诊断。',
            policyText: `入口 ${entryText || entryRaw}；AI 建议必须引用证据来源、数据缺口和动作项，不用缺失数据生成确定结论`,
            policyRawText: entryRaw,
        };
    };
    const buildPhase1EmployeeOperationSummary = ({ row = {}, evidence = {} } = {}) => {
        const source = evidence && typeof evidence === 'object' ? evidence : {};
        if (!source || typeof source !== 'object') return null;
        const blocking = Array.isArray(source.blocking_missing_codes) ? source.blocking_missing_codes.filter(Boolean).map(item => String(item)) : [];
        const status = String(row?.status || source.operation_evidence_status || 'missing');
        const directEntry = String(row?.direct_next_action_entry || source.direct_next_action_entry || '');
        const directCode = String(row?.direct_next_action_code || source.direct_next_action_code || '');
        const primaryCode = String(row?.primary_next_action_code || source.primary_next_action_code || '');
        const linkedCodes = Array.isArray(row?.next_action_codes) ? row.next_action_codes : [];
        const mappedNextAction = (directCode || primaryCode || linkedCodes.length) ? phase1EmployeeQuestionNextActionText(row) : '';
        const entryRaw = directEntry || '/api/operation/execution-intents';
        const entryText = phase1EmployeeActionEntryText(entryRaw, {
            action_code: directCode || primaryCode || 'collect_operation_execution_evidence',
            action_family: row?.direct_next_action_family || row?.evidence?.direct_next_action_family || 'operation_execution_evidence',
            question_key: 'next_operation_action',
        });
        const evidenceReadyCount = Number(source.evidence_ready_count || source.execution_evidence_count || 0);
        const linkedIntentCount = Number(source.ota_diagnosis_linked_intent_count || 0);
        const linkedFlowCount = Number(source.ota_diagnosis_linked_flow_item_count || 0);
        const completionSignalCount = Number(source.completion_signal_count || 0)
            || (
                Number(source.approved_count || 0)
                + Number(source.executed_count || 0)
                + evidenceReadyCount
                + Number(source.reviewed_count || 0)
                + Number(source.roi_ready_count || 0)
            );
        const executionIntentCount = Number(source.execution_intent_count || 0);
        const executionFlowItemCount = Number(source.execution_flow_item_count || 0);
        return {
            items: [
                phase1EmployeeCountItem('status', '状态', phase1EmployeeQuestionStatusText(status), status === 'proved'),
                phase1EmployeeCountItem('intents', '执行意图', executionIntentCount, executionIntentCount > 0),
                phase1EmployeeCountItem('flow', '执行流', executionFlowItemCount, executionFlowItemCount > 0),
                phase1EmployeeCountItem('evidence', '执行证据', evidenceReadyCount, evidenceReadyCount > 0),
                phase1EmployeeCountItem('approved', '已审批', Number(source.approved_count || 0), Number(source.approved_count || 0) > 0),
                phase1EmployeeCountItem('executed', '已执行', Number(source.executed_count || 0), Number(source.executed_count || 0) > 0),
                phase1EmployeeCountItem('reviewed', '已复盘', Number(source.reviewed_count || 0), Number(source.reviewed_count || 0) > 0),
                phase1EmployeeCountItem('roi', 'ROI', Number(source.roi_ready_count || 0), Number(source.roi_ready_count || 0) > 0),
            ],
            blockingText: blocking.map(phase1EmployeeGapCodeText).filter(Boolean).join('、'),
            blockingRawText: blocking.join('、'),
            judgementText: phase1EmployeeOperationJudgementText({ status, executionIntentCount, executionFlowItemCount, completionSignalCount }),
            judgementRawText: `operation_evidence_status=${status || 'missing'} / execution_intent_count=${executionIntentCount} / execution_flow_item_count=${executionFlowItemCount} / completion_signal_count=${completionSignalCount}`,
            limitText: phase1EmployeeOperationLimitText({ completionSignalCount, linkedIntentCount, linkedFlowCount }),
            limitRawText: `ota_diagnosis_linked_intent_count=${linkedIntentCount} / ota_diagnosis_linked_flow_item_count=${linkedFlowCount} / completion_signal_count=${completionSignalCount}`,
            nextActionText: mappedNextAction || '先取得真实 OTA 诊断动作项，再创建执行意图并保留审批、执行证据和复盘。',
            policyText: `入口 ${entryText || entryRaw}；只有可追溯到 OTA 诊断动作项且有审批、执行、复盘或 ROI 信号时才算闭环`,
            policyRawText: entryRaw,
        };
    };
    const buildPhase1EmployeeClosureSummary = ({ rows = [], actions = [], backendSummary = {}, protectedBoundary = '' } = {}) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        if (!safeRows.length) return null;
        const safeActions = Array.isArray(actions) ? actions : [];
        const summary = backendSummary && typeof backendSummary === 'object' ? backendSummary : {};
        const provedRows = safeRows.filter(row => ['proved', 'no_gap_reported'].includes(String(row?.status || '')));
        const unresolvedRows = safeRows.filter(row => !['proved', 'no_gap_reported'].includes(String(row?.status || '')));
        const topAction = safeActions.find(item => String(item?.status || '') !== 'blocked') || safeActions[0] || null;
        const fallbackActionRow = unresolvedRows.find(row => String(row?.nextActionText || row?.employeeNextActionText || row?.nextAction || row?.next_action || '').trim())
            || safeRows.find(row => String(row?.nextActionText || row?.employeeNextActionText || row?.nextAction || row?.next_action || '').trim())
            || null;
        const backendStatus = String(summary?.status || '').trim();
        const provedCount = Number.isFinite(Number(summary?.proved_count)) ? Number(summary.proved_count) : provedRows.length;
        const questionCount = Number.isFinite(Number(summary?.employee_question_count)) ? Number(summary.employee_question_count) : safeRows.length;
        const unresolvedCount = Number.isFinite(Number(summary?.missing_count)) ? Number(summary.missing_count) : unresolvedRows.length;
        const backendMissingQuestions = Array.isArray(summary?.missing_questions) ? summary.missing_questions.map(item => String(item)).filter(Boolean) : [];
        const backendMissingQuestionKeys = Array.isArray(summary?.missing_question_keys) ? summary.missing_question_keys.map(item => String(item)).filter(Boolean) : [];
        const isClosed = backendStatus === 'complete' || (unresolvedCount === 0 && safeRows.length > 0);
        const isPartial = provedCount > 0;
        const topActionCode = String(summary?.top_action_code || topAction?.actionCode || fallbackActionRow?.directNextActionCode || fallbackActionRow?.primaryNextActionCode || '').trim();
        const topActionTextRaw = String(summary?.top_action || topAction?.action || fallbackActionRow?.nextAction || fallbackActionRow?.next_action || '').trim();
        const topActionText = topActionCode
            ? phase1EmployeeActionDisplayText({
                action_code: topActionCode,
                action: topActionTextRaw,
                question_key: summary?.top_question_key || summary?.top_action_question_key || topAction?.questionKey || fallbackActionRow?.key || '',
                action_family: summary?.top_action_family || topAction?.actionFamily || '',
                platform: summary?.top_action_platform || topAction?.platform || '',
            })
            : (isClosed ? '继续复核 OTA 证据和执行结果' : '先补齐 OTA 渠道证据');
        const backendEntryOptions = Array.isArray(summary?.top_action_entry_options)
            ? summary.top_action_entry_options.map(phase1EmployeeActionEntryOptionText).filter(Boolean)
            : [];
        const backendEntryOptionRaw = Array.isArray(summary?.top_action_entry_options)
            ? summary.top_action_entry_options.map(phase1EmployeeActionEntryOptionRawText).filter(Boolean)
            : [];
        const backendEntryOptionGuidance = Array.isArray(summary?.top_action_entry_options)
            ? summary.top_action_entry_options.map(phase1EmployeeActionEntryOptionGuidanceText).filter(Boolean)
            : [];
        const backendEntryOptionGuidanceRaw = Array.isArray(summary?.top_action_entry_options)
            ? summary.top_action_entry_options.map(phase1EmployeeActionEntryOptionGuidanceRawText).filter(Boolean)
            : [];
        const backendEntryOptionReadiness = Array.isArray(summary?.top_action_entry_options)
            ? summary.top_action_entry_options.map(phase1EmployeeActionEntryOptionReadinessText).filter(Boolean)
            : [];
        const localEntryOptions = Array.isArray(topAction?.entryOptions)
            ? topAction.entryOptions
            : (Array.isArray(topAction?.entry_options) ? topAction.entry_options.map(phase1EmployeeActionEntryOptionText).filter(Boolean) : []);
        const localEntryOptionRaw = topAction?.entryOptionsRawText
            ? [topAction.entryOptionsRawText]
            : (Array.isArray(topAction?.entry_options) ? topAction.entry_options.map(phase1EmployeeActionEntryOptionRawText).filter(Boolean) : []);
        const localEntryOptionGuidance = topAction?.entryOptionGuidanceText
            ? [topAction.entryOptionGuidanceText]
            : (Array.isArray(topAction?.entry_options) ? topAction.entry_options.map(phase1EmployeeActionEntryOptionGuidanceText).filter(Boolean) : []);
        const localEntryOptionGuidanceRaw = topAction?.entryOptionGuidanceRawText
            ? [topAction.entryOptionGuidanceRawText]
            : (Array.isArray(topAction?.entry_options) ? topAction.entry_options.map(phase1EmployeeActionEntryOptionGuidanceRawText).filter(Boolean) : []);
        const localEntryOptionReadiness = topAction?.entryReadinessText
            ? [topAction.entryReadinessText]
            : (Array.isArray(topAction?.entry_options) ? topAction.entry_options.map(phase1EmployeeActionEntryOptionReadinessText).filter(Boolean) : []);
        const topActionEntryOptionsText = (backendEntryOptions.length ? backendEntryOptions : localEntryOptions).join('、');
        const topActionEntryOptionsRawText = (backendEntryOptionRaw.length ? backendEntryOptionRaw : localEntryOptionRaw).join('、');
        const topActionEntryOptionGuidanceText = (backendEntryOptionGuidance.length ? backendEntryOptionGuidance : localEntryOptionGuidance).join('；');
        const topActionEntryOptionGuidanceRawText = (backendEntryOptionGuidanceRaw.length ? backendEntryOptionGuidanceRaw : localEntryOptionGuidanceRaw).join('；');
        const topActionEntryReadinessText = (backendEntryOptionReadiness.length ? backendEntryOptionReadiness : localEntryOptionReadiness).join('；');
        const topActionVerificationText = String(topAction?.verificationStepsText || topAction?.employeeVerificationSteps || '').trim();
        const backendTopActionImpactRaw = Array.isArray(summary?.top_action_related_question_keys) ? summary.top_action_related_question_keys.map(value => String(value)).filter(Boolean) : [];
        const backendTopActionImpactText = phase1EmployeeKnownQuestionListText(backendTopActionImpactRaw);
        const backendTopActionResolves = Array.isArray(summary?.top_action_resolves_missing_codes) ? summary.top_action_resolves_missing_codes.map(phase1EmployeeGapCodeText).filter(Boolean) : [];
        const backendTopActionLiveGaps = Array.isArray(summary?.top_action_live_closure_gap_codes) ? summary.top_action_live_closure_gap_codes.map(phase1EmployeeGapCodeText).filter(Boolean) : [];
        const localTopActionImpactRaw = Array.isArray(topAction?.relatedQuestionKeys)
            ? topAction.relatedQuestionKeys.map(value => String(value)).filter(Boolean)
            : (Array.isArray(topAction?.related_question_keys) ? topAction.related_question_keys.map(value => String(value)).filter(Boolean) : []);
        const localTopActionImpactText = phase1EmployeeKnownQuestionListText(localTopActionImpactRaw);
        const localTopActionResolves = topAction?.resolvesMissingCodes ? String(topAction.resolvesMissingCodes).split('、').map(phase1EmployeeGapCodeText).filter(Boolean) : (Array.isArray(topAction?.resolves_missing_codes) ? topAction.resolves_missing_codes.map(phase1EmployeeGapCodeText).filter(Boolean) : []);
        const localTopActionLiveGaps = topAction?.liveClosureGapCodes ? String(topAction.liveClosureGapCodes).split('、').map(phase1EmployeeGapCodeText).filter(Boolean) : (Array.isArray(topAction?.live_closure_gap_codes) ? topAction.live_closure_gap_codes.map(phase1EmployeeGapCodeText).filter(Boolean) : []);
        const topActionImpactText = backendTopActionImpactText || localTopActionImpactText;
        const topActionImpactRawText = (backendTopActionImpactRaw.length ? backendTopActionImpactRaw : localTopActionImpactRaw).join('、');
        const topActionResolvesText = (backendTopActionResolves.length ? backendTopActionResolves : localTopActionResolves).join('、');
        const topActionLiveGapText = (backendTopActionLiveGaps.length ? backendTopActionLiveGaps : localTopActionLiveGaps).join('、');
        const topActionSuccessCriteriaRaw = String(summary?.top_action_success_criteria || topAction?.successCriteria || fallbackActionRow?.directNextActionSuccessCriteria || fallbackActionRow?.primaryNextActionSuccessCriteria || '').trim();
        const topActionSuccessCriteria = phase1EmployeeActionSuccessCriteriaText({
            action_code: topActionCode,
            platform: summary?.top_action_platform || topAction?.platform || '',
            question_key: summary?.top_action_question_key || topAction?.questionKey || fallbackActionRow?.key || '',
            action_family: topAction?.actionFamily || '',
            success_criteria: topActionSuccessCriteriaRaw,
        });
        const topActionEntryRaw = String(summary?.top_action_entry || topAction?.entry || topAction?.directNextActionEntry || fallbackActionRow?.directNextActionEntry || fallbackActionRow?.primaryNextActionEntry || '').trim();
        const topActionEntryText = phase1EmployeeActionEntryText(topActionEntryRaw, {
            action_code: topActionCode,
            platform: summary?.top_action_platform || topAction?.platform || '',
            action_family: summary?.top_action_family || topAction?.actionFamily || '',
        });
        const sourceSnapshot = summary?.top_action_source_snapshot && typeof summary.top_action_source_snapshot === 'object'
            ? summary.top_action_source_snapshot
            : {};
        const topActionSourceSnapshotText = phase1EmployeeSourceSnapshotText(sourceSnapshot);
        const unresolvedQuestionTextRaw = backendMissingQuestions.join('、');
        const unresolvedQuestionText = (
            backendMissingQuestionKeys.length
                ? backendMissingQuestionKeys.map(phase1EmployeeKnownQuestionText).filter(Boolean)
                : unresolvedRows.map(row => phase1EmployeeKnownQuestionText(row?.key || '')).filter(Boolean)
        ).join('、') || (unresolvedCount > 0 ? '未识别员工问题' : '');
        return {
            status: isClosed ? 'complete' : (isPartial ? 'warning' : 'not_proved'),
            statusText: isClosed ? '可进入经营诊断' : '未闭环',
            panelClass: isClosed ? 'border-emerald-100 bg-emerald-50' : (isPartial ? 'border-amber-100 bg-amber-50' : 'border-slate-200 bg-slate-50'),
            badgeClass: isClosed ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : (isPartial ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-slate-200 bg-white text-slate-600'),
            provedText: `${provedCount} / ${questionCount}`,
            unresolvedText: `${unresolvedCount} 项`,
            unresolvedQuestionText,
            unresolvedQuestionTextRaw,
            summaryText: isClosed
                ? '六个员工问题均有可复核证据，可继续进入 AI 诊断和运营执行复盘。'
                : `仍有 ${unresolvedCount} 个问题未证明：${unresolvedQuestionText || '待加载'}。未证明前不输出确定经营结论。`,
            topActionText,
            topActionTextRaw,
            topActionEntry: topActionEntryRaw,
            topActionEntryText,
            topActionEntryOptionsText,
            topActionEntryOptionsRawText,
            topActionEntryOptionGuidanceText,
            topActionEntryOptionGuidanceRawText,
            topActionEntryReadinessText,
            topActionVerificationText,
            topActionImpactText,
            topActionImpactRawText,
            topActionResolvesText,
            topActionLiveGapText,
            topActionSourceSnapshotText,
            topActionSuccessCriteria,
            topActionSuccessCriteriaRaw,
            boundaryText: String(summary?.protected_boundary || protectedBoundary || '只按 OTA 渠道证据判断；latest_available/历史样本只作参考；不改变携程/美团手动或自动获取逻辑，不改变获取字段。'),
        };
    };

    const phase1EmployeeEvidenceStatusText = (value) => ({
        proved: '已证明',
        ready: '已就绪',
        warning: '需复核',
        missing: '缺失',
        blocked: '被阻断',
        pending: '待处理',
        incomplete: '未完成',
        unknown: '未知',
        ok: '正常',
        normal: '正常',
        empty: '空数据',
        partial: '部分就绪',
        blocked_by_verified_ota_gaps: '已验证 OTA 缺口阻断',
        blocked_by_missing_ota_data: '缺少 OTA 数据阻断',
        ai_action_items_blocked: 'AI 动作项被上游证据阻断',
        ai_action_items_missing: 'AI 动作项缺失',
        operation_execution_sample_missing: '运营执行样例缺失',
        operation_execution_ai_action_link_missing: '运营执行未关联 OTA 诊断动作',
        operation_execution_evidence_incomplete: '运营执行证据不完整',
        missing_real_api_response: '缺少真实接口响应',
        missing_real_ota_diagnosis_response: '缺少真实 OTA 诊断响应',
        read_existing_ota_gap_evidence_only: '只读 OTA 缺口证据',
        read_existing_collection_reliability_only: '只读采集可靠性状态',
        read_existing_online_daily_data_only: '只读 online_daily_data 入库状态',
        read_existing_ota_standard_revenue_metrics_only: '只读 OTA 标准收益指标',
        read_existing_operation_execution_state_only: '只读运营执行状态',
        local_ui_derived_from_employee_questions: '前端根据员工六问派生',
        target_date_rows_field_definitions_metric_trust_required: '目标日源数据 + 字段定义 + 指标可信证据',
        target_date_rows_plus_metric_trust_required: '目标日源数据 + 指标可信证据',
        generated_blocked_from_verified_missing_requirements: '由已验证缺口生成的阻断状态',
        user_supplied_cookie_or_payload_required: '需要用户提供 Cookie/Payload 上下文',
        storage_profile_directory_count: '只读本机 Profile 目录数量',
    }[String(value || '').trim()] || String(value || '').trim());

    const phase1FieldTrustStatusText = (status) => ({
        metric_trust_ready: '可复核',
        target_date_revenue_sample_present: '待指标可信证据',
        target_date_metric_inputs_missing: '指标缺失',
        target_date_source_missing: '源数据缺失',
    }[String(status || '').toLowerCase()] || '未证明');

    const phase1EmployeeEvidencePolicyText = (value) => ({
        read_existing_ota_gap_evidence_only: '只读现有 OTA 缺口证据',
        read_existing_collection_reliability_only: '只读现有采集可靠性状态',
        read_existing_online_daily_data_only: '只读 OTA 入库状态',
        read_existing_ota_standard_revenue_metrics_only: '只读 OTA 标准收益指标',
        read_existing_operation_execution_state_only: '只读运营执行状态',
        local_ui_derived_from_employee_questions: '前端根据员工六问派生',
        target_date_rows_field_definitions_metric_trust_required: '目标日源数据 + 字段定义 + 指标可信证据',
        target_date_rows_plus_metric_trust_required: '目标日源数据 + 指标可信证据',
        read_target_date_online_daily_data_types_only: '只读目标日 OTA 指标域',
        read_platform_data_sources_metadata_only: '只读平台采集源元数据',
        requires_target_date_rows_field_definitions_metric_trust_and_data_quality: '需要目标日源数据、字段定义、指标可信和数据质量证据',
        generated_blocked_from_verified_missing_requirements: '由已验证缺口生成的阻断状态',
        user_supplied_cookie_or_payload_required: '需要用户提供 Cookie/Payload 上下文',
        storage_profile_directory_count: '只读本机 Profile 目录数量',
        read_local_profile_directory_names_only: '只读本机 Profile 目录名',
    }[String(value || '').trim()] || String(value || '').trim());

    const phase1EmployeeStorageTableText = (value) => ({
        online_daily_data: 'OTA 入库表',
    }[String(value || '').trim()] || String(value || '').trim());

    const phase1EmployeeGapCodeText = (code, knownQuestionText = phase1EmployeeKnownQuestionText) => {
        const raw = String(code || '').trim();
        if (!raw) return '';
        const questionText = typeof knownQuestionText === 'function' ? knownQuestionText(raw) : '';
        if (questionText) return questionText;
        return ({
            source_date_evidence_missing: '目标日来源证据缺失',
            target_date_source_rows_missing: '目标日 OTA 源数据缺失',
            ctrip_source_rows_missing: '携程目标日源数据缺失',
            meituan_source_rows_missing: '美团目标日源数据缺失',
            ctrip_target_date_source_rows_missing: '携程目标日源数据缺失',
            meituan_target_date_source_rows_missing: '美团目标日源数据缺失',
            ctrip_etl_not_ready: '携程标准事实层未就绪',
            meituan_etl_not_ready: '美团标准事实层未就绪',
            ctrip_revenue_metrics_not_ready: '携程收益指标未就绪',
            meituan_revenue_metrics_not_ready: '美团收益指标未就绪',
            ctrip_traffic_facts_missing: '携程流量事实缺失',
            meituan_traffic_facts_missing: '美团流量事实缺失',
            ctrip_conversion_facts_missing: '携程转化事实缺失',
            meituan_conversion_facts_missing: '美团转化事实缺失',
            ctrip_metric_trust_missing: '携程指标可信证据缺失',
            meituan_metric_trust_missing: '美团指标可信证据缺失',
            ctrip_revenue_metric_inputs_missing: '携程收益指标输入缺失',
            meituan_revenue_metric_inputs_missing: '美团收益指标输入缺失',
            metric_trust_not_loaded: '指标可信证据未加载',
            target_date_metric_inputs_missing: '目标日指标输入缺失',
            target_date_revenue_sample_missing: '目标日收益样本缺失',
            field_definitions_missing: '字段资产定义缺失',
            field_definition_keys_missing: '字段定义键缺失',
            missing_field_codes_missing: '缺失字段码未返回',
            data_gap_codes_missing: '数据缺口码未返回',
            ai_evidence_sources_missing: 'AI 证据来源缺失',
            ai_data_gaps_missing: 'AI 数据缺口字段缺失',
            ai_action_items_missing: 'AI 动作项缺失',
            ai_action_items_blocked: 'AI 动作项被上游证据阻断',
            blocked_by_verified_ota_gaps: '已验证 OTA 缺口阻断',
            operation_execution_sample_missing: '运营执行样例缺失',
            operation_execution_ai_action_link_missing: '运营执行未关联 OTA 诊断动作',
            operation_execution_evidence_incomplete: '运营执行证据不完整',
            evidence_scope_date_mismatch: '证据日期范围不一致',
            latest_available_reference_only: '只有历史或其他日期参考数据',
            online_daily_data_target_date_rows_missing: 'online_daily_data 目标日入库行缺失',
            read_existing_ota_gap_evidence_only: '只读现有 OTA 缺口证据',
        }[raw] || '未识别证据缺口');
    };

    const phase1EmployeeActionCodeText = (code, helpers = {}) => {
        const raw = String(code || '').trim();
        if (!raw) return '';
        const knownQuestionText = typeof helpers.knownQuestionText === 'function' ? helpers.knownQuestionText : phase1EmployeeKnownQuestionText;
        const platformText = typeof helpers.platformText === 'function' ? helpers.platformText : phase1EmployeePlatformText;
        if (raw === 'phase1_confirm_source_date_evidence') return '核对目标日 OTA 入库证据';
        if (raw === 'phase1_collect_ai_diagnosis_evidence' || raw === 'collect_ai_diagnosis_evidence') return '补齐 AI 诊断证据';
        if (raw === 'resolve_ai_diagnosis_blocked_action_items') return '先解除 AI 动作项阻断';
        if (raw === 'phase1_create_operation_execution_evidence' || raw === 'collect_operation_execution_evidence') return '补齐运营执行证据';
        const localMatch = raw.match(/^local_(.+)_required_action$/);
        if (localMatch) return `补齐${knownQuestionText(localMatch[1]) || '未识别员工问题'}证据`;
        const targetRowsMatch = raw.match(/^phase1_collect_(ctrip|meituan)_target_date_source_rows$/);
        if (targetRowsMatch) return `补齐${platformText(targetRowsMatch[1])}目标日源数据`;
        const sourceRowsMatch = raw.match(/^(ctrip|meituan)_source_rows_missing_collect_existing_path$/);
        if (sourceRowsMatch) return `使用现有${platformText(sourceRowsMatch[1])}入口补齐目标日源数据`;
        const standardFactsMatch = raw.match(/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/);
        if (standardFactsMatch) return `复核${platformText(standardFactsMatch[1])}标准事实层`;
        const revenueMetricMatch = raw.match(/^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/);
        if (revenueMetricMatch) return `复核${platformText(revenueMetricMatch[1] || revenueMetricMatch[2])}收益指标输入`;
        const trafficMatch = raw.match(/^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/);
        if (trafficMatch) return `核对${platformText(trafficMatch[1] || trafficMatch[2])}流量/转化采集证据`;
        return '未识别补证动作';
    };

    const phase1EmployeeSourceSnapshotText = (sourceSnapshot) => {
        if (!sourceSnapshot || typeof sourceSnapshot !== 'object') return '';
        const latest = sourceSnapshot.latest_available && typeof sourceSnapshot.latest_available === 'object'
            ? sourceSnapshot.latest_available
            : {};
        const platformText = phase1EmployeePlatformText(sourceSnapshot.platform);
        const targetRows = Number.isFinite(Number(sourceSnapshot.target_date_rows)) ? Number(sourceSnapshot.target_date_rows) : 0;
        const targetDate = String(sourceSnapshot.target_date || '').trim();
        const parts = [];
        if (platformText) {
            parts.push(`${platformText}${targetDate ? ` ${targetDate}` : ''} 目标日入库 ${targetRows} 行`);
        }
        if (latest.date) {
            const relationText = phase1EmployeeDateRelationText(latest.date_relation);
            const latestRows = Number.isFinite(Number(latest.rows)) ? Number(latest.rows) : null;
            parts.push(`最近可用 ${latest.date}${latestRows === null ? '' : ` / ${latestRows} 行`}${relationText ? ` / ${relationText}` : ''}`);
        }
        if (sourceSnapshot.latest_available_reference_only === true) {
            parts.push('最近可用只作参考，不能替代目标日入库证明');
        }
        if (sourceSnapshot.platform || sourceSnapshot.proof_requirement) {
            parts.push(targetRows > 0 ? '已满足：目标日该平台入库行 > 0' : '证明要求：目标日该平台入库行 > 0');
        }
        return parts.join('；');
    };
    const phase1EmployeeQuestionNextActionText = (row) => {
        const directCode = String(row?.direct_next_action_code || row?.evidence?.direct_next_action_code || '').trim();
        const primaryCode = String(row?.primary_next_action_code || row?.evidence?.primary_next_action_code || '').trim();
        const directText = phase1EmployeeActionCodeText(directCode);
        const primaryText = phase1EmployeeActionCodeText(primaryCode);
        if (primaryText && primaryCode && primaryCode !== directCode) {
            const fallbackQuestionText = phase1EmployeeKnownQuestionText(row?.key || row?.question || '') || '当前员工问题';
            return `先处理${primaryText}，再执行${directText || `补齐${fallbackQuestionText}证据`}`;
        }
        if (directText) return directText;
        const linkedCodes = Array.isArray(row?.next_action_codes)
            ? row.next_action_codes
            : String(row?.next_action_codes || '').split(/[、,，\s]+/);
        const linkedText = linkedCodes.map(phase1EmployeeActionCodeText).find(Boolean);
        if (linkedText) return linkedText;
        const questionText = phase1EmployeeKnownQuestionText(row?.key || row?.question || '');
        return questionText ? `补齐${questionText}证据` : '按动作队列补齐证据';
    };
    const phase1EmployeeQuestionEvidenceText = (evidence) => {
        if (!evidence) return '';
        if (typeof evidence === 'string') return evidence;
        if (Array.isArray(evidence)) return evidence.filter(Boolean).join('、');
        if (typeof evidence !== 'object') return String(evidence || '');
        const parts = [];
        if (Number(evidence.source_rows || 0) > 0) parts.push(`源数据 ${evidence.source_rows} 行`);
        if (Object.prototype.hasOwnProperty.call(evidence, 'target_date_source_rows')) {
            parts.push(`目标日 ${Number(evidence.target_date_source_rows || 0)} 行`);
        }
        const platformCoverage = evidence.target_date_platform_coverage && typeof evidence.target_date_platform_coverage === 'object'
            ? evidence.target_date_platform_coverage
            : null;
        if (platformCoverage) {
            const missingPlatforms = Array.isArray(platformCoverage.missing_platforms)
                ? platformCoverage.missing_platforms.filter(Boolean).map(phase1EmployeePlatformText)
                : [];
            if (missingPlatforms.length) parts.push(`缺失平台 ${missingPlatforms.join('、')}`);
            else if (Number(platformCoverage.platform_count || 0) > 0) parts.push(`平台覆盖 ${platformCoverage.covered_platform_count || 0}/${platformCoverage.platform_count}`);
            if (platformCoverage.source_date_evidence_missing) parts.push('缺少目标日来源证据');
        } else if (evidence.coverage_status) {
            parts.push(`覆盖 ${evidence.coverage_status}`);
        }
        if (Array.isArray(evidence.platforms) && evidence.platforms.length) {
            const platformRowsText = evidence.platforms
                .map(row => {
                    const platform = phase1EmployeePlatformText(row?.platform || '');
                    const rows = Number(row?.source_rows?.count ?? row?.source_rows ?? row?.target_date_rows ?? 0);
                    const latest = row?.latest_available && typeof row.latest_available === 'object' ? row.latest_available : {};
                    const latestDate = String(latest?.date || row?.latest_available_date || '').trim();
                    const latestRelation = String(latest?.date_relation || row?.latest_available_date_relation || '').trim();
                    const latestRelationText = phase1EmployeeDateRelationText(latestRelation);
                    const latestText = latestDate ? `最近可用参考 ${latestDate}${latestRelationText ? `(${latestRelationText})` : ''}` : '';
                    return [platform ? `${platform} 目标日${rows}行` : `目标日${rows}行`, latestText].filter(Boolean).join(' ');
                })
                .filter(Boolean)
                .slice(0, 4)
                .join('、');
            if (platformRowsText) parts.push(`平台明细 ${platformRowsText}`);
        }
        if (Number(evidence.reference_saved_count || 0) > 0) parts.push(`入库参考 ${evidence.reference_saved_count} 条`);
        if (Number(evidence.reference_replay_count || 0) > 0) parts.push(`回放参考 ${evidence.reference_replay_count} 条`);
        if (Number(evidence.analysis_rows_reference_only || 0) > 0) parts.push(`分析参考 ${evidence.analysis_rows_reference_only} 条`);
        const formatPlatformList = (items) => Array.isArray(items)
            ? items.filter(Boolean).map(phase1EmployeePlatformText).join('、')
            : '';
        const revenueReadyPlatforms = formatPlatformList(evidence.revenue_ready_platforms);
        const trafficReadyPlatforms = formatPlatformList(evidence.traffic_ready_platforms);
        const conversionReadyPlatforms = formatPlatformList(evidence.conversion_ready_platforms);
        const revenueMissingPlatforms = formatPlatformList(evidence.revenue_missing_platforms);
        const trafficMissingPlatforms = formatPlatformList(evidence.traffic_missing_platforms);
        const conversionMissingPlatforms = formatPlatformList(evidence.conversion_missing_platforms);
        if (revenueReadyPlatforms) parts.push(`收益可复核 ${revenueReadyPlatforms}`);
        if (trafficReadyPlatforms) parts.push(`流量可复核 ${trafficReadyPlatforms}`);
        if (conversionReadyPlatforms) parts.push(`转化可复核 ${conversionReadyPlatforms}`);
        if (revenueMissingPlatforms) parts.push(`收益缺失 ${revenueMissingPlatforms}`);
        if (trafficMissingPlatforms) parts.push(`流量缺失 ${trafficMissingPlatforms}`);
        if (conversionMissingPlatforms) parts.push(`转化缺失 ${conversionMissingPlatforms}`);
        if (Array.isArray(evidence.metric_domain_readiness) && evidence.metric_domain_readiness.length) {
            const missingDomainText = evidence.metric_domain_readiness
                .map(row => {
                    const domains = Array.isArray(row?.missing_domains)
                        ? row.missing_domains.filter(Boolean).map(phase1MetricDomainMissingLabel).join('/')
                        : '';
                    return domains ? `${phase1EmployeePlatformText(row?.platform || '')}:${domains}` : '';
                })
                .filter(Boolean)
                .slice(0, 3)
                .join('、');
            if (missingDomainText) parts.push(`指标域缺失 ${missingDomainText}`);
        }
        if (Array.isArray(evidence.traffic_source_readiness) && evidence.traffic_source_readiness.length) {
            const trafficInputLabel = (key) => ({
                registered_traffic_data_source: '登记采集源',
                traffic_request_url_or_cdp_endpoint_evidence: '请求证据',
                traffic_payload_or_query_params: 'Payload/参数',
                manual_login_state_verified: '人工确认登录态',
                authorized_ctrip_profile_dir: '携程Profile',
                authorized_meituan_profile_dir: '美团Profile',
                traffic_collection_run_and_target_date_rows: '目标日入库',
                traffic_data_source_ready_state: '采集源就绪',
                platform_data_sources_table: '采集源表',
                platform_data_sources_schema: '采集源字段',
                platform_data_sources_readable: '采集源可读',
            }[String(key || '')] || String(key || ''));
            const trafficSourceText = evidence.traffic_source_readiness
                .map(row => {
                    const platform = phase1EmployeePlatformText(row?.platform || '');
                    const sourceCount = Number(row?.traffic_source_count || 0);
                    const readyCount = Number(row?.traffic_ready_count || 0);
                    const waitingCount = Number(row?.traffic_waiting_config_count || 0);
                    const trafficRows = Number(row?.target_date_traffic_rows || 0);
                    const p0NextText = buildPhase1TrafficP0NextText(row);
                    const requiredInputs = Array.isArray(row?.required_next_inputs)
                        ? row.required_next_inputs.map(trafficInputLabel).filter(Boolean).slice(0, 3).join('/')
                        : '';
                    const suffix = requiredInputs ? `（需${requiredInputs}）` : '';
                    if (trafficRows > 0) return `${platform}流量已入库${p0NextText}`;
                    if (sourceCount <= 0) return `${platform}流量采集源未登记${suffix}${p0NextText}`;
                    if (waitingCount > 0) return `${platform}流量采集源待授权${suffix}${p0NextText}`;
                    if (readyCount > 0) return `${platform}流量采集源已就绪${suffix}${p0NextText}`;
                    return `${platform}流量采集源未就绪${suffix}${p0NextText}`;
                })
                .filter(Boolean)
                .slice(0, 3)
                .join('、');
            if (trafficSourceText) parts.push(`采集源 ${trafficSourceText}`);
        }
        if (evidence.metric_domain_policy) parts.push(`指标域口径 ${phase1EmployeeEvidencePolicyText(evidence.metric_domain_policy)}`);
        if (evidence.traffic_source_policy) parts.push(`采集源口径 ${phase1EmployeeEvidencePolicyText(evidence.traffic_source_policy)}`);
        if (Number(evidence.field_definition_count || 0) > 0) parts.push(`字段定义 ${evidence.field_definition_count} 项`);
        const evidenceKeyText = (items, limit = 4, mapper = (item) => String(item || '').trim()) => Array.isArray(items)
            ? items.filter(Boolean).map(item => mapper(item)).filter(Boolean).slice(0, limit).join('、')
            : '';
        const phase1EmployeeReadableGapText = (code) => {
            const raw = String(code || '').trim();
            if (!raw) return '';
            const fieldText = phase1MissingFieldLabel(raw);
            if (fieldText && fieldText !== raw) return fieldText;
            const gapText = phase1EmployeeGapCodeText(raw);
            if (gapText && gapText !== raw) return gapText;
            return '未识别缺口';
        };
        const phase1EmployeeReadableActionOrGapText = (code) => {
            const raw = String(code || '').trim();
            if (!raw) return '';
            const actionText = phase1EmployeeActionCodeText(raw);
            if (actionText && actionText !== raw) return actionText;
            return phase1EmployeeReadableGapText(raw);
        };
        const fieldDefinitionKeys = evidenceKeyText(evidence.field_definition_keys);
        const metricTrustKeys = evidenceKeyText(evidence.metric_trust_keys);
        const metricDomainGapCodes = evidenceKeyText(evidence.metric_domain_gap_codes, 4, phase1EmployeeReadableGapText);
        const dataGapCodes = evidenceKeyText(evidence.data_gap_codes, 4, phase1EmployeeReadableGapText);
        const missingFieldCodes = evidenceKeyText(evidence.missing_field_codes, 4, phase1EmployeeReadableGapText);
        const fieldPendingActionCodes = evidenceKeyText(evidence.field_pending_action_codes, 4, phase1EmployeeReadableActionOrGapText);
        const blockedActionCodes = evidenceKeyText(evidence.blocked_action_codes, 3, phase1EmployeeReadableActionOrGapText);
        const blockingMissingCodes = evidenceKeyText(evidence.blocking_missing_codes, 5, phase1EmployeeReadableGapText);
        const diagnosisStatus = phase1EmployeeEvidenceStatusText(evidence.diagnosis_status);
        const actionItemStatus = phase1EmployeeEvidenceStatusText(evidence.action_item_status);
        const sourcePolicyText = phase1EmployeeEvidenceStatusText(evidence.source_policy);
        const platformFieldTrustText = Array.isArray(evidence.platform_field_trust)
            ? evidence.platform_field_trust
                .map(row => {
                    const platform = phase1EmployeePlatformText(row?.platform || '');
                    const statusText = phase1FieldTrustStatusText(row?.field_trust_status);
                    const rows = Number(row?.target_date_rows || 0);
                    const keyCount = Number(row?.metric_trust_key_count || 0);
                    const trustKeys = Array.isArray(row?.metric_trust_keys) && row.metric_trust_keys.length ? ` 指标可信证据${keyCount}项` : '';
                    const reasonCodesText = Array.isArray(row?.reason_codes)
                        ? row.reason_codes.slice(0, 2).map(phase1EmployeeGapCodeText).filter(Boolean).join('/')
                        : '';
                    const reasonText = reasonCodesText ? ` / ${reasonCodesText}` : '';
                    return platform ? `${platform}:${statusText} ${rows}行${trustKeys}${reasonText}` : '';
                })
                .filter(Boolean)
                .slice(0, 4)
                .join('、')
            : '';
        if (fieldDefinitionKeys) parts.push(`字段资产 ${fieldDefinitionKeys}`);
        if (metricTrustKeys) parts.push(`指标可信键 ${metricTrustKeys}`);
        if (platformFieldTrustText) parts.push(`字段可信平台 ${platformFieldTrustText}`);
        if (metricDomainGapCodes) parts.push(`指标域缺口 ${metricDomainGapCodes}`);
        if (dataGapCodes) parts.push(`数据缺口 ${dataGapCodes}`);
        if (missingFieldCodes && missingFieldCodes !== dataGapCodes) parts.push(`缺口字段 ${missingFieldCodes}`);
        if (fieldPendingActionCodes) parts.push(`字段动作 ${fieldPendingActionCodes}`);
        const directNextActionCode = String(evidence.direct_next_action_code || '').trim();
        const primaryNextActionCode = String(evidence.primary_next_action_code || '').trim();
        const nextActionContext = {
            action_code: directNextActionCode || primaryNextActionCode,
            action_family: evidence.direct_next_action_family || evidence.primary_next_action_family || '',
            question_key: evidence.question_key || evidence.key || '',
        };
        if (directNextActionCode) parts.push(`直接动作 ${phase1EmployeeActionCodeText(directNextActionCode)}`);
        if (primaryNextActionCode && primaryNextActionCode !== directNextActionCode) parts.push(`先处理动作 ${phase1EmployeeActionCodeText(primaryNextActionCode)}`);
        if (Number(evidence.linked_action_count || 0) > 0) parts.push(`关联动作 ${evidence.linked_action_count} 项`);
        if (evidence.direct_next_action_entry) parts.push(`入口 ${phase1EmployeeActionEntryText(evidence.direct_next_action_entry, nextActionContext)}`);
        if (evidence.direct_next_action_success_criteria) parts.push(`完成判定 ${phase1EmployeeActionSuccessCriteriaText({
            ...nextActionContext,
            success_criteria: evidence.direct_next_action_success_criteria,
        })}`);
        if (diagnosisStatus) parts.push(`AI状态 ${diagnosisStatus}`);
        if (actionItemStatus) parts.push(`动作状态 ${actionItemStatus}`);
        if (sourcePolicyText) parts.push(`证据口径 ${sourcePolicyText}`);
        if (blockedActionCodes) parts.push(`阻断动作 ${blockedActionCodes}`);
        if (blockingMissingCodes) parts.push(`阻断缺口 ${blockingMissingCodes}`);
        if (evidence.metric_trust_required) parts.push('需复核指标可信证据');
        if (evidence.data_quality_status) parts.push(`质量 ${phase1EmployeeEvidenceStatusText(evidence.data_quality_status) || '需复核'}`);
        if (Number(evidence.missing_field_count || 0) > 0) parts.push(`缺失字段 ${evidence.missing_field_count} 项`);
        if (Number(evidence.field_pending_action_count || 0) > 0) parts.push(`字段待办 ${evidence.field_pending_action_count} 项`);
        if (Number(evidence.evidence_source_count || 0) > 0) parts.push(`AI证据 ${evidence.evidence_source_count} 条`);
        if (evidence.data_gap_evidence_present) parts.push(`AI缺口 ${Number(evidence.data_gap_count || 0)} 项`);
        else if (Object.prototype.hasOwnProperty.call(evidence, 'data_gap_evidence_present')) parts.push('缺少数据缺口证据');
        if (Number(evidence.actionable_action_item_count || 0) > 0) parts.push(`可执行建议 ${evidence.actionable_action_item_count} 条`);
        if (Number(evidence.blocked_action_item_count || 0) > 0) parts.push(`阻断建议 ${evidence.blocked_action_item_count} 条`);
        if (Number(evidence.pending_action_count || 0) > 0) parts.push(`待办 ${evidence.pending_action_count} 项`);
        if (evidence.operation_evidence_status) {
            const statusText = phase1EmployeeEvidenceStatusText(evidence.operation_evidence_status) || phase1EmployeeQuestionStatusText(evidence.operation_evidence_status);
            parts.push(`运营证据 ${statusText}`);
        }
        if (Number(evidence.execution_intent_count || 0) > 0) parts.push(`执行意图 ${evidence.execution_intent_count} 条`);
        if (Number(evidence.execution_flow_item_count || 0) > 0) parts.push(`执行流 ${evidence.execution_flow_item_count} 条`);
        if (Number(evidence.ota_diagnosis_linked_intent_count || 0) > 0) parts.push(`OTA诊断执行 ${evidence.ota_diagnosis_linked_intent_count} 条`);
        if (Number(evidence.ota_diagnosis_linked_flow_item_count || 0) > 0) parts.push(`OTA诊断执行流 ${evidence.ota_diagnosis_linked_flow_item_count} 条`);
        if (evidence.operation_ai_action_link_required && !evidence.ai_action_items_ready) parts.push('缺少可执行 AI 动作项');
        if (Number(evidence.approved_count || 0) > 0) parts.push(`已审批 ${evidence.approved_count}`);
        if (Number(evidence.executed_count || 0) > 0) parts.push(`已执行 ${evidence.executed_count}`);
        if (Number(evidence.evidence_ready_count || 0) > 0) parts.push(`执行证据 ${evidence.evidence_ready_count}`);
        if (Number(evidence.reviewed_count || 0) > 0) parts.push(`已复盘 ${evidence.reviewed_count}`);
        if (Number(evidence.blocked_execution_count || 0) > 0) parts.push(`执行阻断 ${evidence.blocked_execution_count}`);
        if (Array.isArray(evidence.upstream_blockers) && evidence.upstream_blockers.length) {
            parts.push(`阻断 ${evidence.upstream_blockers.slice(0, 3).join('、')}`);
        }
        if (Array.isArray(evidence.blocking_missing_codes) && evidence.blocking_missing_codes.length) {
            parts.push(`阻断 ${evidence.blocking_missing_codes.slice(0, 3).map(phase1EmployeeGapCodeText).join('、')}`);
        }
        if (Array.isArray(evidence.evidence_refs) && evidence.evidence_refs.length) {
            parts.push(evidence.evidence_refs.slice(0, 2).join(' / '));
        }
        return parts.join('；');
    };
    const normalizePhase1EmployeeQuestionRow = (row) => ({
        key: String(row?.key || row?.question || ''),
        question: String(row?.question || ''),
        status: String(row?.status || 'not_proved'),
        detail: String(row?.employee_detail || row?.detail || row?.message || ''),
        detailRawText: String(row?.detail || row?.message || ''),
        evidence: phase1EmployeeQuestionEvidenceText(row?.evidence),
        blockingGapCodes: phase1EmployeeQuestionBlockingGapCodes(row),
        blockingReasonText: phase1EmployeeQuestionBlockingGapCodes(row).slice(0, 6).map(phase1EmployeeGapCodeText).join('、'),
        nextAction: String(row?.next_action || row?.nextAction || ''),
        nextActionRawText: String(row?.next_action || row?.nextAction || ''),
        employeeNextActionText: String(row?.employee_next_action || ''),
        nextActionText: phase1EmployeeQuestionNextActionText(row),
        actionCodes: Array.isArray(row?.next_action_codes) ? row.next_action_codes.filter(Boolean).join('、') : String(row?.next_action_codes || ''),
        primaryNextActionCode: String(row?.primary_next_action_code || row?.evidence?.primary_next_action_code || ''),
        directNextActionCode: String(row?.direct_next_action_code || row?.evidence?.direct_next_action_code || ''),
        actionCodesText: Array.isArray(row?.next_action_codes) ? row.next_action_codes.map(phase1EmployeeActionCodeText).filter(Boolean).join('、') : String(row?.next_action_codes || '').split('、').map(phase1EmployeeActionCodeText).filter(Boolean).join('、'),
        primaryNextActionText: phase1EmployeeActionCodeText(row?.primary_next_action_code || row?.evidence?.primary_next_action_code || ''),
        directNextActionText: phase1EmployeeActionCodeText(row?.direct_next_action_code || row?.evidence?.direct_next_action_code || ''),
        primaryNextActionEntry: String(row?.primary_next_action_entry || row?.evidence?.primary_next_action_entry || ''),
        directNextActionEntry: String(row?.direct_next_action_entry || row?.evidence?.direct_next_action_entry || ''),
        primaryNextActionEntryText: phase1EmployeeActionEntryText(row?.primary_next_action_entry || row?.evidence?.primary_next_action_entry || '', {
            action_code: row?.primary_next_action_code || row?.evidence?.primary_next_action_code || '',
        }),
        directNextActionEntryText: phase1EmployeeActionEntryText(row?.direct_next_action_entry || row?.evidence?.direct_next_action_entry || '', {
            action_code: row?.direct_next_action_code || row?.evidence?.direct_next_action_code || '',
        }),
        primaryNextActionSuccessCriteria: String(row?.primary_next_action_success_criteria || row?.evidence?.primary_next_action_success_criteria || ''),
        directNextActionSuccessCriteria: String(row?.direct_next_action_success_criteria || row?.evidence?.direct_next_action_success_criteria || ''),
        primaryNextActionSuccessCriteriaText: phase1EmployeeActionSuccessCriteriaText({
            action_code: row?.primary_next_action_code || row?.evidence?.primary_next_action_code || '',
            success_criteria: row?.primary_next_action_success_criteria || row?.evidence?.primary_next_action_success_criteria || '',
            question_key: row?.key || '',
        }),
        directNextActionSuccessCriteriaText: phase1EmployeeActionSuccessCriteriaText({
            action_code: row?.direct_next_action_code || row?.evidence?.direct_next_action_code || '',
            success_criteria: row?.direct_next_action_success_criteria || row?.evidence?.direct_next_action_success_criteria || '',
            question_key: row?.key || '',
        }),
        blockedActionCodes: Array.isArray(row?.blocked_action_codes || row?.evidence?.blocked_action_codes)
            ? (row?.blocked_action_codes || row?.evidence?.blocked_action_codes).filter(Boolean).join('、')
            : String(row?.blocked_action_codes || row?.evidence?.blocked_action_codes || ''),
        blockedActionCodesText: Array.isArray(row?.blocked_action_codes || row?.evidence?.blocked_action_codes)
            ? (row?.blocked_action_codes || row?.evidence?.blocked_action_codes).map(phase1EmployeeActionCodeText).filter(Boolean).join('、')
            : String(row?.blocked_action_codes || row?.evidence?.blocked_action_codes || '').split('、').map(phase1EmployeeActionCodeText).filter(Boolean).join('、'),
        linkedActionCount: Number(row?.evidence?.linked_action_count || 0),
    });

    const phase1EmployeeCollectionDataTypeText = (type) => {
        const raw = String(type || '').toLowerCase();
        if (['business', 'business_overview', 'revenue', 'order', 'orders'].includes(raw)) return '经营/收益';
        if (['traffic', 'flow', 'flow_data'].includes(raw)) return '流量/转化';
        if (['advertising', 'ads'].includes(raw)) return '广告';
        if (['quality', 'quality_psi'].includes(raw)) return '服务质量';
        if (['review', 'comment'].includes(raw)) return '点评';
        return raw ? '未识别数据类型' : '';
    };
    const normalizePhase1CollectionSourceSummaryRow = (row) => {
        const latest = row?.latest_available && typeof row.latest_available === 'object' ? row.latest_available : {};
        const latestDate = String(latest?.date || '').trim();
        const latestRelation = String(latest?.date_relation || '').trim();
        const latestRelationText = phase1EmployeeDateRelationText(latestRelation);
        const latestRows = Number(latest?.rows ?? latest?.count ?? 0);
        const targetRows = Number(row?.target_date_rows || 0);
        const targetTypes = Array.isArray(row?.target_date_data_types) ? row.target_date_data_types.filter(Boolean).map(item => String(item)) : [];
        const targetTypeText = Array.from(new Set(targetTypes.map(phase1EmployeeCollectionDataTypeText).filter(Boolean))).join('、');
        const platform = String(row?.platform || '').toLowerCase();
        const referenceOnly = row?.latest_available_reference_only !== false && latestRelation !== 'target_date';
        const statusText = targetRows > 0 ? '目标日已入库' : (latestDate ? '仅有参考' : '目标日缺失');
        const latestText = latestDate
            ? `最近可用 ${latestDate}${latestRows ? ` / ${latestRows} 行` : ''}${latestRelationText ? ` / ${latestRelationText}` : ''}${referenceOnly ? ' / 不能替代目标日' : ''}`
            : '最近可用：未查询到';
        return {
            platform,
            platformLabel: phase1EmployeePlatformText(platform),
            targetDateRows: targetRows,
            targetText: `目标日 ${targetRows} 行${targetTypeText ? ` / ${targetTypeText}` : ''}`,
            targetRawText: `target_date_data_types=${targetTypes.join(',') || 'empty'}`,
            latestText,
            latestRawText: `latest_available.date=${latestDate || 'empty'} / date_relation=${latestRelation || 'empty'} / latest_available_reference_only=${referenceOnly ? 'true' : 'false'}`,
            statusText,
            boundaryText: `${phase1EmployeeStorageTableText(row?.storage_table || 'online_daily_data')} / ${phase1EmployeeEvidencePolicyText(row?.source_policy || 'read_existing_online_daily_data_only')} / 不改变采集逻辑`,
            boundaryRawText: `${row?.storage_table || 'online_daily_data'} / ${row?.source_policy || 'read_existing_online_daily_data_only'} / collection_logic_changed=${row?.collection_logic_changed === true ? 'true' : 'false'}`,
        };
    };
    const phase1FieldTrustStatusClass = (status) => String(status || '').toLowerCase() === 'metric_trust_ready'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : 'border-amber-200 bg-amber-50 text-amber-700';
    const normalizePhase1EmployeeFieldTrustRow = (row) => {
        const platform = String(row?.platform || '').toLowerCase();
        const targetRows = Number(row?.target_date_rows || 0);
        const trustKeyCount = Number(row?.metric_trust_key_count || 0);
        const trustKeys = Array.isArray(row?.metric_trust_keys)
            ? row.metric_trust_keys.map(item => String(item || '')).filter(Boolean)
            : [];
        const reasonCodes = Array.isArray(row?.reason_codes)
            ? row.reason_codes.map(item => String(item || '')).filter(Boolean)
            : [];
        const status = String(row?.field_trust_status || '').toLowerCase();
        const metricStatusRaw = String(row?.metric_status || 'unknown').toLowerCase();
        const metricStatusText = ({
            ready: '指标已就绪',
            missing: '指标缺失',
            empty: '指标为空',
            unknown: '指标状态未知',
        }[metricStatusRaw] || '指标需复核');
        return {
            platform,
            platformLabel: phase1EmployeePlatformText(platform),
            statusText: phase1FieldTrustStatusText(status),
            statusClass: phase1FieldTrustStatusClass(status),
            metricText: `目标日 ${targetRows} 行 / 指标可信证据 ${trustKeyCount} 项 / ${metricStatusText}`,
            metricRawText: `target_date_rows=${targetRows} / metric_trust_key_count=${trustKeyCount} / metric_status=${metricStatusRaw}`,
            trustKeyText: trustKeys.slice(0, 5).join('、') + (trustKeys.length > 5 ? ` 等 ${trustKeys.length} 项` : ''),
            reasonText: reasonCodes.map(code => phase1EmployeeGapCodeText(code, phase1EmployeeKnownQuestionText)).filter(Boolean).join('、'),
            reasonRawText: reasonCodes.join('、'),
            policyText: `${phase1EmployeeEvidencePolicyText(row?.source_policy || 'target_date_rows_plus_metric_trust_required')}；未证明时不把字段写成可信`,
            policyRawText: String(row?.source_policy || 'target_date_rows_plus_metric_trust_required'),
        };
    };
    const normalizePhase1EmployeeMissingFieldRow = (code, source = 'data_gaps') => {
        const normalizedCode = String(code || '').trim();
        return {
            code: normalizedCode,
            label: phase1MissingFieldLabel(normalizedCode),
            sourceText: phase1MissingFieldSourceText(source),
            detailText: phase1MissingFieldDetailText(normalizedCode),
            nextActionText: phase1MissingFieldNextActionText(normalizedCode, source),
            nextActionRawText: `${source || 'data_gaps'} / ${normalizedCode || 'missing_code'}`,
            policyText: '显式保留缺口；不使用 0、空值或成功状态替代',
            policyRawText: `${source || 'data_gaps'} / ${normalizedCode || 'missing_code'}`,
        };
    };
    const normalizePhase1EmployeeMissingFieldSummaryRow = (item) => {
        const source = item && typeof item === 'object' ? item : {};
        const normalizedCode = String(source.code || '').trim();
        const sourceKeys = Array.isArray(source.source_keys || source.sourceKeys)
            ? (source.source_keys || source.sourceKeys).map(value => String(value)).filter(Boolean)
            : [];
        const fallbackSource = sourceKeys.includes('missing_field_codes') ? 'missing_field_codes' : 'data_gaps';
        return {
            code: normalizedCode,
            label: String(source.label || '').trim() || phase1MissingFieldLabel(normalizedCode),
            sourceText: String(source.source_text || source.sourceText || '').trim() || phase1MissingFieldSourceText(fallbackSource),
            detailText: String(source.business_impact || source.businessImpact || '').trim() || phase1MissingFieldDetailText(normalizedCode),
            nextActionText: String(source.next_action || source.nextAction || '').trim() || phase1MissingFieldNextActionText(normalizedCode, fallbackSource),
            nextActionRawText: `${sourceKeys.join('、') || fallbackSource} / ${normalizedCode || 'missing_code'}`,
            policyText: String(source.policy || '').trim() || '显式保留缺口；不使用 0、空值或成功状态替代',
            policyRawText: `${sourceKeys.join('、') || fallbackSource} / ${normalizedCode || 'missing_code'}`,
        };
    };
    const normalizePhase1EmployeeMetricDomainRow = (row) => {
        const platform = String(row?.platform || '').toLowerCase();
        const missingDomains = Array.isArray(row?.missing_domains)
            ? Array.from(new Set(row.missing_domains.map(phase1MetricDomainMissingLabel).filter(Boolean)))
            : [];
        const sourceRows = Number(row?.source_rows ?? row?.target_date_rows ?? 0);
        const trafficRows = Number(row?.traffic_rows ?? 0);
        const dataTypes = Array.isArray(row?.target_date_data_types)
            ? row.target_date_data_types.map(item => String(item || '')).filter(Boolean)
            : [];
        const dataTypeText = Array.from(new Set(dataTypes.map(phase1MetricDomainDataTypeText).filter(Boolean))).join('、');
        const revenueReady = String(row?.revenue_status || '').toLowerCase() === 'ready';
        const trafficReady = String(row?.traffic_status || '').toLowerCase() === 'ready';
        const conversionReady = String(row?.conversion_status || '').toLowerCase() === 'ready';
        const problemText = phase1MetricDomainProblemText({ revenueReady, trafficReady, conversionReady, sourceRows, trafficRows });
        const nextActionText = phase1MetricDomainNextActionText({ revenueReady, trafficReady, conversionReady, sourceRows, trafficRows });
        return {
            platform,
            platformLabel: phase1MetricDomainPlatformText(platform),
            revenueText: phase1MetricDomainStatusText(row?.revenue_status),
            trafficText: phase1MetricDomainStatusText(row?.traffic_status),
            conversionText: phase1MetricDomainStatusText(row?.conversion_status),
            revenueClass: phase1MetricDomainStatusClass(row?.revenue_status),
            trafficClass: phase1MetricDomainStatusClass(row?.traffic_status),
            conversionClass: phase1MetricDomainStatusClass(row?.conversion_status),
            missingText: missingDomains.join('、'),
            sourceText: `目标日源数据 ${sourceRows} 行 / 流量事实 ${trafficRows} 行`,
            sourceRawText: `platform=${platform || 'platform_missing'} / source_rows=${sourceRows} / traffic_rows=${trafficRows}`,
            trafficSourceText: '',
            trafficSourceRawText: '',
            problemText,
            problemRawText: `revenue_status=${row?.revenue_status || 'missing'} / traffic_status=${row?.traffic_status || 'missing'} / conversion_status=${row?.conversion_status || 'missing'} / missing_domains=${Array.isArray(row?.missing_domains) ? row.missing_domains.join(',') : 'empty'}`,
            nextActionText,
            nextActionRawText: `source_rows=${sourceRows} / traffic_rows=${trafficRows} / revenue_ready=${revenueReady ? 'true' : 'false'} / traffic_ready=${trafficReady ? 'true' : 'false'} / conversion_ready=${conversionReady ? 'true' : 'false'}`,
            policyText: `只读目标日指标域${dataTypeText ? ` / ${dataTypeText}` : ''}；缺失时不输出确定结论`,
            policyRawText: `target_date_data_types=${dataTypes.join(',') || 'empty'}`,
        };
    };
    const normalizePhase1EmployeeMetricDomainSummaryRow = (row) => {
        const source = row && typeof row === 'object' ? row : {};
        const platform = String(source.platform || '').toLowerCase();
        const revenueReady = String(source.revenue_text || source.revenueText || '').includes('可复核');
        const trafficReady = String(source.traffic_text || source.trafficText || '').includes('可复核');
        const conversionReady = String(source.conversion_text || source.conversionText || '').includes('可复核');
        return {
            platform,
            platformLabel: String(source.platform_label || source.platformLabel || '').trim() || phase1MetricDomainPlatformText(platform),
            revenueText: String(source.revenue_text || source.revenueText || '').trim() || '缺失',
            trafficText: String(source.traffic_text || source.trafficText || '').trim() || '缺失',
            conversionText: String(source.conversion_text || source.conversionText || '').trim() || '缺失',
            revenueClass: phase1MetricDomainStatusClass(revenueReady ? 'ready' : 'missing'),
            trafficClass: phase1MetricDomainStatusClass(trafficReady ? 'ready' : 'missing'),
            conversionClass: phase1MetricDomainStatusClass(conversionReady ? 'ready' : 'missing'),
            missingText: String(source.missing_text || source.missingText || '').trim(),
            sourceText: String(source.source_text || source.sourceText || '').trim(),
            sourceRawText: `platform=${platform || 'platform_missing'}`,
            trafficSourceText: String(source.traffic_source_text || source.trafficSourceText || '').trim(),
            trafficSourceRawText: String(source.traffic_source_status || source.trafficSourceStatus || source.traffic_source_next_action || source.trafficSourceNextAction || '').trim(),
            problemText: String(source.problem || source.problemText || '').trim(),
            problemRawText: `platform=${platform || 'platform_missing'}`,
            nextActionText: String(source.next_action || source.nextAction || '').trim(),
            nextActionRawText: `platform=${platform || 'platform_missing'}`,
            policyText: String(source.policy || '').trim(),
            policyRawText: `platform=${platform || 'platform_missing'}`,
        };
    };
    const phase1EmployeeBackendRows = (backendQuestionSource = {}) => {
        if (Array.isArray(backendQuestionSource?.rows)) return backendQuestionSource.rows;
        if (Array.isArray(backendQuestionSource?.questions)) return backendQuestionSource.questions;
        return [];
    };
    const phase1EmployeeBackendQuestion = (backendQuestionSource = {}, key = '') => (
        phase1EmployeeBackendRows(backendQuestionSource).find(row => String(row?.key || '') === key) || {}
    );
    const buildPhase1EmployeeCollectionSourceRows = ({ backendQuestionSource = {}, collectionReliability = {}, dashboardDataSources = {} } = {}) => {
        const summaryRows = Array.isArray(backendQuestionSource?.collection_source_summary)
            ? backendQuestionSource.collection_source_summary
            : (Array.isArray(collectionReliability?.collection_source_summary)
                ? collectionReliability.collection_source_summary
                : (Array.isArray(dashboardDataSources?.collection_source_summary) ? dashboardDataSources.collection_source_summary : []));
        if (summaryRows.length) {
            return summaryRows.map(normalizePhase1CollectionSourceSummaryRow).filter(row => row.platform);
        }
        const sourceDateEvidence = collectionReliability?.source_date_evidence || dashboardDataSources?.source_date_evidence || {};
        return (Array.isArray(sourceDateEvidence?.platforms) ? sourceDateEvidence.platforms : [])
            .map(row => {
                const latest = row?.latest_available && typeof row.latest_available === 'object' ? row.latest_available : null;
                const relation = String(row?.date_relation || latest?.date_relation || 'none');
                return normalizePhase1CollectionSourceSummaryRow({
                    platform: row?.platform,
                    storage_table: 'online_daily_data',
                    source_policy: 'read_existing_online_daily_data_only',
                    target_date_rows: row?.target_date_rows,
                    target_date_data_types: row?.target_date_data_types,
                    latest_available: latest ? { ...latest, date_relation: relation } : null,
                    latest_available_reference_only: relation !== 'target_date',
                });
            })
            .filter(row => row.platform);
    };
    const buildOtaTodayCollectionReminderRows = ({
        backendQuestionSource = {},
        collectionReliability = {},
        dashboardDataSources = {},
        collectionSourceRows = null,
        closureSummary = null,
    } = {}) => {
        const sourceRows = Array.isArray(collectionSourceRows)
            ? collectionSourceRows
            : buildPhase1EmployeeCollectionSourceRows({ backendQuestionSource, collectionReliability, dashboardDataSources });
        const backendSummary = closureSummary && typeof closureSummary === 'object'
            ? closureSummary
            : (backendQuestionSource?.closure_summary && typeof backendQuestionSource.closure_summary === 'object'
                ? backendQuestionSource.closure_summary
                : (collectionReliability?.phase1_employee_questions?.closure_summary || dashboardDataSources?.phase1_employee_questions?.closure_summary || {}));
        const topActionPlatform = String(backendSummary?.top_action_platform || backendSummary?.top_action_source_snapshot?.platform || '').toLowerCase();
        const topActionEntryOptions = Array.isArray(backendSummary?.top_action_entry_options) ? backendSummary.top_action_entry_options : [];
        const fallbackSnapshot = backendSummary?.top_action_source_snapshot && typeof backendSummary.top_action_source_snapshot === 'object'
            ? normalizePhase1CollectionSourceSummaryRow({
                platform: backendSummary.top_action_source_snapshot.platform,
                storage_table: 'online_daily_data',
                source_policy: 'read_existing_online_daily_data_only',
                target_date_rows: backendSummary.top_action_source_snapshot.target_date_rows,
                latest_available: backendSummary.top_action_source_snapshot.latest_available,
                latest_available_reference_only: backendSummary.top_action_source_snapshot.latest_available_reference_only,
            })
            : null;
        const rows = sourceRows.length ? sourceRows : (fallbackSnapshot?.platform ? [fallbackSnapshot] : []);

        return rows
            .filter(row => String(row?.platform || '').trim())
            .map((row) => {
                const platform = String(row.platform || '').toLowerCase();
                const platformLabel = row.platformLabel || phase1EmployeePlatformText(platform);
                const targetRows = Math.max(0, Number(row.targetDateRows ?? row.target_date_rows ?? row.source_rows ?? 0));
                const isReady = targetRows > 0;
                const matchingOptions = topActionEntryOptions
                    .filter(option => {
                        const optionPlatform = String(option?.platform || option?.target_platform || '').toLowerCase();
                        return !optionPlatform || !platform || optionPlatform === platform || topActionPlatform === platform;
                    });
                const entryText = matchingOptions.map(phase1EmployeeActionEntryOptionText).filter(Boolean).join('、');
                const entryRawText = matchingOptions.map(phase1EmployeeActionEntryOptionRawText).filter(Boolean).join('、');
                const readinessText = matchingOptions.map(phase1EmployeeActionEntryOptionReadinessText).filter(Boolean).join('；');
                const targetText = row.targetText || `目标日 ${targetRows} 行`;
                const latestText = row.latestText || '最近可用：未查询到';
                const status = isReady ? 'ready' : 'missing';
                const nextActionText = isReady
                    ? '继续复核字段可信、指标可信和 AI/运营后续门禁。'
                    : (entryText
                        ? `按现有入口补采：${entryText}`
                        : `优先核对${platformLabel}浏览器 Profile、登录态和采集配置，再补齐目标日源数据。`);
                const detail = isReady
                    ? `${targetText}；当日 OTA 源数据已有入库行，但仍需继续校验字段和指标可信。`
                    : `${targetText}；${latestText}。latest_available/历史样本只能作参考，不能替代目标日采集证明。`;
                return {
                    key: `ota-today-${platform || 'platform'}`,
                    platform,
                    platformLabel,
                    status,
                    statusText: isReady ? '当日已采到' : '当日未采到',
                    className: isReady
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                        : 'border-amber-200 bg-amber-50 text-amber-700',
                    priority: isReady ? 'ok' : 'high',
                    sourceLabel: '当日采集',
                    title: isReady ? `${platformLabel} 当日采集已入库` : `${platformLabel} 当日采集缺失`,
                    detail,
                    targetText,
                    latestText,
                    nextActionText,
                    proofText: isReady
                        ? '证明口径：online_daily_data 目标日源数据行数大于 0。'
                        : '完成判定：online_daily_data 目标日源数据行数大于 0，并保留 data_source_id/sync_task_id/source_trace/raw_data 证据。',
                    boundaryText: row.boundaryText || '只按 OTA 渠道目标日证据判断；不改变携程/美团采集逻辑。',
                    entryText,
                    entryRawText,
                    readinessText,
                    actionTab: '',
                    buttonText: entryText ? '查看入口' : '核对采集配置',
                };
            });
    };
    const buildOtaTodayCollectionReminderSummary = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        const missingRows = safeRows.filter(row => row?.status !== 'ready');
        const readyRows = safeRows.filter(row => row?.status === 'ready');
        if (!safeRows.length) {
            return {
                status: 'unknown',
                statusText: '未返回证据',
                className: 'border-gray-200 bg-gray-50 text-gray-500',
                detailText: '尚未拿到携程/美团目标日源数据摘要，不能判断当日采集健康。',
                pendingCount: 0,
                readyCount: 0,
            };
        }
        if (missingRows.length) {
            return {
                status: 'missing',
                statusText: `${missingRows.length} 个平台缺当日证据`,
                className: 'border-amber-200 bg-amber-50 text-amber-700',
                detailText: `${missingRows.map(row => row.platformLabel).join('、')} 目标日 OTA 入库证据不足；latest_available 仅作参考。`,
                pendingCount: missingRows.length,
                readyCount: readyRows.length,
            };
        }
        return {
            status: 'ready',
            statusText: '当日采集已具备',
            className: 'border-emerald-200 bg-emerald-50 text-emerald-700',
            detailText: `${readyRows.length} 个平台已有目标日入库行；继续复核字段可信、收益分析和 AI/运营门禁。`,
            pendingCount: 0,
            readyCount: readyRows.length,
        };
    };
    const buildPhase1EmployeeFieldTrustRows = ({ backendQuestionSource = {}, collectionReliability = {}, dashboardDataSources = {} } = {}) => {
        const trustedQuestion = phase1EmployeeBackendQuestion(backendQuestionSource, 'trusted_fields');
        const trustRows = Array.isArray(trustedQuestion?.evidence?.platform_field_trust)
            ? trustedQuestion.evidence.platform_field_trust
            : [];
        if (trustRows.length) {
            return trustRows.map(normalizePhase1EmployeeFieldTrustRow).filter(row => row.platform);
        }
        const sourceDateEvidence = collectionReliability?.source_date_evidence || dashboardDataSources?.source_date_evidence || {};
        return (Array.isArray(sourceDateEvidence?.platforms) ? sourceDateEvidence.platforms : [])
            .map(row => normalizePhase1EmployeeFieldTrustRow({
                platform: row?.platform,
                target_date_rows: row?.target_date_rows,
                metric_status: 'unknown',
                field_trust_status: Number(row?.target_date_rows || 0) > 0 ? 'target_date_metric_inputs_missing' : 'target_date_source_missing',
                metric_trust_key_count: 0,
                metric_trust_keys: [],
                reason_codes: Number(row?.target_date_rows || 0) > 0 ? ['metric_trust_not_loaded'] : ['target_date_source_rows_missing'],
                source_policy: 'target_date_rows_plus_metric_trust_required',
            }))
            .filter(row => row.platform);
    };
    const buildPhase1EmployeeMissingFieldRows = ({ backendQuestionSource = {}, collectionHealthQuality = {}, otaDiagnosisDataGaps = [] } = {}) => {
        const missingQuestion = phase1EmployeeBackendQuestion(backendQuestionSource, 'missing_fields');
        const evidence = missingQuestion?.evidence && typeof missingQuestion.evidence === 'object' ? missingQuestion.evidence : {};
        const summaryRows = Array.isArray(evidence.missing_field_summary)
            ? evidence.missing_field_summary.map(normalizePhase1EmployeeMissingFieldSummaryRow).filter(row => row.code || row.label)
            : [];
        if (summaryRows.length) return summaryRows;
        const rows = [];
        const seen = new Set();
        const appendCodes = (codes, source) => {
            (Array.isArray(codes) ? codes : []).forEach((code) => {
                const normalizedCode = String(code || '').trim();
                if (!normalizedCode || seen.has(normalizedCode)) return;
                seen.add(normalizedCode);
                rows.push(normalizePhase1EmployeeMissingFieldRow(normalizedCode, source));
            });
        };
        appendCodes(evidence.data_gap_codes, 'data_gaps');
        appendCodes(evidence.missing_field_codes, 'missing_field_codes');
        if (rows.length) return rows;
        const quality = collectionHealthQuality && typeof collectionHealthQuality === 'object' ? collectionHealthQuality : {};
        appendCodes(quality.missing_field_codes, 'missing_field_codes');
        appendCodes(quality.missing_fields, 'missing_field_codes');
        appendCodes(quality.field_missing_codes, 'missing_field_codes');
        appendCodes((Array.isArray(otaDiagnosisDataGaps) ? otaDiagnosisDataGaps : []).map(item => item?.code || item?.field || item?.metric_key), 'data_gaps');
        return rows;
    };
    const buildPhase1EmployeeMetricDomainRows = ({ backendQuestionSource = {}, collectionReliability = {}, dashboardDataSources = {} } = {}) => {
        const revenueQuestion = phase1EmployeeBackendQuestion(backendQuestionSource, 'revenue_traffic_conversion');
        const summaryRows = Array.isArray(revenueQuestion?.evidence?.metric_domain_summary)
            ? revenueQuestion.evidence.metric_domain_summary
            : [];
        if (summaryRows.length) {
            return summaryRows.map(normalizePhase1EmployeeMetricDomainSummaryRow).filter(row => row.platform);
        }
        const readinessRows = Array.isArray(revenueQuestion?.evidence?.metric_domain_readiness)
            ? revenueQuestion.evidence.metric_domain_readiness
            : [];
        if (readinessRows.length) {
            return readinessRows.map(normalizePhase1EmployeeMetricDomainRow).filter(row => row.platform);
        }
        const sourceDateEvidence = collectionReliability?.source_date_evidence || dashboardDataSources?.source_date_evidence || {};
        return (Array.isArray(sourceDateEvidence?.platforms) ? sourceDateEvidence.platforms : [])
            .map(row => {
                const platform = String(row?.platform || '').toLowerCase();
                const targetRows = Math.max(0, Number(row?.target_date_rows || 0));
                const targetTypes = Array.isArray(row?.target_date_data_types)
                    ? row.target_date_data_types.map(item => String(item || '').toLowerCase()).filter(Boolean)
                    : [];
                const hasType = (needles) => targetTypes.some(type => needles.some(needle => type.includes(needle)));
                const revenueReady = targetRows > 0 && hasType(['business', 'order', 'orders', 'revenue']);
                const trafficReady = targetRows > 0 && hasType(['traffic', 'flow', 'flow_data']);
                const missingDomains = [];
                if (!revenueReady) missingDomains.push('revenue');
                if (!trafficReady) missingDomains.push('traffic');
                if (!trafficReady) missingDomains.push('conversion');
                return normalizePhase1EmployeeMetricDomainRow({
                    platform,
                    target_date_rows: targetRows,
                    target_date_data_types: targetTypes,
                    revenue_status: revenueReady ? 'ready' : 'missing',
                    traffic_status: trafficReady ? 'ready' : 'missing',
                    conversion_status: trafficReady ? 'ready' : 'missing',
                    missing_domains: missingDomains,
                });
            })
            .filter(row => row.platform);
    };
    const buildPhase1EmployeeQuestionRows = ({
        latestLog = {},
        historyReplay = [],
        onlineAnalysisPagination = {},
        analysisData = {},
        collectionReliability = {},
        dashboardDataSources = {},
        collectionHealthQuality = {},
        collectionHealthFieldRows = [],
        collectionHealthPendingActions = [],
        otaDiagnosisDataGaps = [],
        aiDiagnosisEvidence = {},
        operationEvidence = {},
    } = {}) => {
        const currentLatestLog = latestLog && typeof latestLog === 'object' ? latestLog : {};
        const latestLogStatus = String(currentLatestLog.status || '').toLowerCase();
        const latestSavedCount = Number(currentLatestLog.saved_count || currentLatestLog.total_saved_count || 0);
        const replaySavedCount = (Array.isArray(historyReplay) ? historyReplay : []).reduce((sum, row) => sum + Number(row?.saved_count || row?.row_count || 0), 0);
        const analysisRows = Number(onlineAnalysisPagination?.total || analysisData?.summary?.total_record_count || 0);
        const hasStoredOtaRows = latestSavedCount > 0 || replaySavedCount > 0 || analysisRows > 0;
        const sourceDateEvidence = collectionReliability?.source_date_evidence || dashboardDataSources?.source_date_evidence || {};
        const sourceDatePlatformRows = Array.isArray(sourceDateEvidence?.platforms) ? sourceDateEvidence.platforms : [];
        const sourceDateEvidenceAvailable = sourceDatePlatformRows.length > 0;
        const sourceDateMissingPlatforms = sourceDatePlatformRows
            .filter(row => Number(row?.target_date_rows || 0) <= 0)
            .map(row => String(row?.platform || '').toUpperCase())
            .filter(Boolean);
        const sourceDateMissingPlatformText = sourceDateMissingPlatforms.map(phase1EmployeePlatformText).join('、');
        const targetDateSourceRows = sourceDatePlatformRows.reduce((sum, row) => sum + Number(row?.target_date_rows || 0), 0);
        const targetDateCoverageStatus = sourceDateEvidenceAvailable
            ? (sourceDateMissingPlatforms.length === 0 ? 'complete' : (targetDateSourceRows > 0 ? 'partial' : 'missing'))
            : (hasStoredOtaRows ? 'unknown' : 'missing');
        const sourceDateEvidenceStatus = targetDateCoverageStatus === 'complete'
            ? 'target_date_complete'
            : (targetDateCoverageStatus === 'partial' ? 'target_date_partial' : 'target_date_missing');
        const sourceDateEvidenceLegacyStatus = targetDateSourceRows > 0 ? 'target_date_present' : 'target_date_missing';
        const hasCompleteTargetDateCoverage = sourceDateEvidenceAvailable && targetDateCoverageStatus === 'complete';
        const hasPartialTargetDateCoverage = sourceDateEvidenceAvailable && targetDateCoverageStatus === 'partial';
        const referenceRowCount = latestSavedCount || replaySavedCount || analysisRows;
        const targetDateCoverageEvidence = sourceDateEvidenceAvailable
            ? `目标日 ${targetDateSourceRows} 行${sourceDateMissingPlatformText ? `；缺失平台 ${sourceDateMissingPlatformText}` : ''}`
            : (hasStoredOtaRows ? `入库/回放/分析参考 ${referenceRowCount} 条；缺少目标日来源证据` : '');
        const targetDatePlatformCoverageEvidence = sourceDateEvidenceAvailable
            ? {
                status: targetDateCoverageStatus,
                source_date_evidence_status: sourceDateEvidenceStatus,
                legacy_status: sourceDateEvidenceLegacyStatus,
                coverage_status: sourceDateEvidenceStatus,
                missing_platforms: sourceDateMissingPlatforms,
                platform_count: sourceDatePlatformRows.length,
                covered_platform_count: sourceDatePlatformRows.length - sourceDateMissingPlatforms.length,
                source_date_evidence_available: true,
            }
            : {
                status: targetDateCoverageStatus,
                source_date_evidence_status: sourceDateEvidenceStatus,
                legacy_status: sourceDateEvidenceLegacyStatus,
                coverage_status: sourceDateEvidenceStatus,
                source_date_evidence_available: false,
                source_date_evidence_missing: true,
                reference_saved_count: latestSavedCount,
                reference_replay_count: replaySavedCount,
                analysis_rows_reference_only: analysisRows,
                reference_rows: referenceRowCount,
            };
        const targetDateBlockingMissingCodes = hasCompleteTargetDateCoverage
            ? []
            : (sourceDateEvidenceAvailable
                ? (sourceDateMissingPlatforms.length
                    ? sourceDateMissingPlatforms.map(platform => `${String(platform || '').toLowerCase()}_target_date_rows_missing`).filter(Boolean)
                    : ['target_date_rows_missing'])
                : ['source_date_evidence_missing']);
        const quality = collectionHealthQuality && typeof collectionHealthQuality === 'object' ? collectionHealthQuality : {};
        const qualityStatus = String(quality.status || '').toLowerCase();
        const missingFieldCount = Number(quality.missing_count || 0);
        const fieldRows = Array.isArray(collectionHealthFieldRows) ? collectionHealthFieldRows : [];
        const pendingActions = Array.isArray(collectionHealthPendingActions) ? collectionHealthPendingActions : [];
        const dataGaps = Array.isArray(otaDiagnosisDataGaps) ? otaDiagnosisDataGaps : [];
        const fieldDefinitionCount = fieldRows.length;
        const pendingFieldActions = pendingActions.filter(item => String(item?.type || '').includes('field') || String(item?.action_code || '').includes('field'));
        const normalizePhase1EvidenceKey = (value) => String(value || '').trim();
        const fieldDefinitionKeys = fieldRows
            .map(row => normalizePhase1EvidenceKey(row?.key || [row?.source || row?.platform, row?.module || row?.section || row?.data_type, row?.field || row?.id || row?.name].filter(Boolean).join('.')))
            .filter(Boolean)
            .slice(0, 40);
        const fieldPendingActionCodes = pendingFieldActions
            .map(item => normalizePhase1EvidenceKey(item?.action_code || item?.code))
            .filter(Boolean);
        const missingFieldCodes = [
            ...(Array.isArray(quality.missing_field_codes) ? quality.missing_field_codes : []),
            ...(Array.isArray(quality.missing_fields) ? quality.missing_fields : []),
            ...(Array.isArray(quality.field_missing_codes) ? quality.field_missing_codes : []),
        ].map(normalizePhase1EvidenceKey).filter(Boolean);
        const diagnosisDataGapCodes = dataGaps
            .map(item => normalizePhase1EvidenceKey(item?.code || item?.field || item?.metric_key))
            .filter(Boolean);
        const phase1RevenueMetricEvidence = collectionReliability?.phase1_employee_questions?.revenue_metric_evidence
            || collectionReliability?.revenue_metric_evidence
            || dashboardDataSources?.phase1_employee_questions?.revenue_metric_evidence
            || dashboardDataSources?.revenue_metric_evidence
            || {};
        const metricTrustKeys = Array.isArray(phase1RevenueMetricEvidence.metric_trust_keys)
            ? phase1RevenueMetricEvidence.metric_trust_keys.map(normalizePhase1EvidenceKey).filter(Boolean)
            : [];
        const revenueMetricDataGapCodes = Array.isArray(phase1RevenueMetricEvidence.data_gap_codes)
            ? phase1RevenueMetricEvidence.data_gap_codes.map(normalizePhase1EvidenceKey).filter(Boolean)
            : [];
        const fieldTrustProved = hasCompleteTargetDateCoverage
            && fieldDefinitionCount > 0
            && qualityStatus === 'ok'
            && metricTrustKeys.length > 0
            && missingFieldCount === 0
            && pendingFieldActions.length === 0;
        const fieldTrustPartial = fieldDefinitionCount > 0 || targetDateSourceRows > 0;
        const safeAiDiagnosisEvidence = aiDiagnosisEvidence && typeof aiDiagnosisEvidence === 'object' ? aiDiagnosisEvidence : {};
        const safeOperationEvidence = operationEvidence && typeof operationEvidence === 'object' ? operationEvidence : {};
        const aiActionItemsReady = safeAiDiagnosisEvidence.status === 'proved' && Number(safeAiDiagnosisEvidence.actionable_action_item_count || 0) > 0;
        const operationProofReady = aiActionItemsReady && safeOperationEvidence.operation_evidence_status === 'proved';
        const operationHasPendingInput = Number(safeAiDiagnosisEvidence.actionable_action_item_count || 0) > 0 || pendingActions.length > 0;
        const operationQuestionStatus = operationProofReady
            ? 'proved'
            : (safeOperationEvidence.operation_evidence_status !== 'missing' || operationHasPendingInput ? 'warning' : 'missing');
        const operationBlockingCodes = operationQuestionStatus === 'proved'
            ? []
            : Array.from(new Set([
                ...(Array.isArray(safeOperationEvidence.blocking_missing_codes) ? safeOperationEvidence.blocking_missing_codes : []),
                ...(aiActionItemsReady ? [] : (Array.isArray(safeAiDiagnosisEvidence.blocking_missing_codes) ? safeAiDiagnosisEvidence.blocking_missing_codes : [])),
            ]));
        const {
            metricDomainReadiness,
            revenueReadyPlatforms,
            trafficReadyPlatforms,
            conversionReadyPlatforms,
            revenueMissingPlatforms,
            trafficMissingPlatforms,
            conversionMissingPlatforms,
            metricDomainGapCodes,
            platformFieldTrust,
            allMetricDomainsReady,
        } = buildPhase1MetricDomainReadiness({
            sourceDatePlatformRows,
            metricTrustKeys,
            hasCompleteTargetDateCoverage,
        });
        const metricProblemStatus = allMetricDomainsReady ? 'proved' : (targetDateSourceRows > 0 ? 'warning' : 'not_proved');
        const revenueReadyText = revenueReadyPlatforms.map(item => String(item).toUpperCase()).join('、');

        const localRows = [
            {
                key: 'today_ota_collected',
                question: '今天 OTA 数据有没有采到',
                status: hasCompleteTargetDateCoverage ? 'proved' : (hasPartialTargetDateCoverage || (!sourceDateEvidenceAvailable && hasStoredOtaRows) ? 'warning' : (latestLogStatus === 'failed' ? 'missing' : 'not_proved')),
                detail: hasCompleteTargetDateCoverage
                    ? '携程和美团目标日数据均有入库证据。'
                    : (hasPartialTargetDateCoverage
                        ? '目标日数据只覆盖部分平台，不能视为携程/美团均已完成。'
                        : (!sourceDateEvidenceAvailable && hasStoredOtaRows
                            ? '已有入库/回放/分析参考，但缺少目标日来源证据，不能证明目标日携程/美团均已采到。'
                            : '未看到可证明目标日携程/美团均已入库的 OTA 数据证据。')),
                evidence: {
                    target_date_source_rows: targetDateSourceRows,
                    target_date_platform_coverage: targetDatePlatformCoverageEvidence,
                    source_date_evidence_status: sourceDateEvidenceStatus,
                    legacy_status: sourceDateEvidenceLegacyStatus,
                    coverage_status: sourceDateEvidenceStatus,
                    source_date_evidence_available: sourceDateEvidenceAvailable,
                    reference_saved_count: latestSavedCount,
                    reference_replay_count: replaySavedCount,
                    analysis_rows_reference_only: analysisRows,
                    latest_log_message: currentLatestLog.message || '',
                    period_end_date: collectionReliability?.period?.end_date || '',
                    evidence_summary: targetDateCoverageEvidence,
                    blocking_missing_codes: targetDateBlockingMissingCodes,
                },
                nextAction: hasCompleteTargetDateCoverage ? '继续检查字段可信度、收益指标和 AI 依据。' : '默认使用现有携程/美团浏览器 Profile 采集入口补齐缺失平台同日数据；手动 Cookie/API 仅作临时补数或排障。',
            },
            {
                key: 'trusted_fields',
                question: '哪些字段可信',
                status: fieldTrustProved ? 'proved' : (fieldTrustPartial ? 'warning' : 'not_proved'),
                detail: fieldTrustProved
                    ? '字段资产、目标日样例和数据质量状态均可用于判断字段可信度。'
                    : (fieldDefinitionCount > 0
                        ? '字段资产和口径已可查看，仍需结合目标日样例、指标可信证据和数据质量状态复核。'
                        : '轻量模式或未加载字段资产时，不能判定字段可信。'),
                evidence: {
                    field_definition_count: fieldDefinitionCount,
                    field_definition_keys: fieldDefinitionKeys,
                    target_date_source_rows: targetDateSourceRows,
                    target_date_platform_coverage: targetDatePlatformCoverageEvidence,
                    platform_field_trust: platformFieldTrust,
                    data_quality_status: qualityStatus || 'unknown',
                    missing_field_count: missingFieldCount,
                    field_pending_action_count: pendingFieldActions.length,
                    field_pending_action_codes: fieldPendingActionCodes,
                    revenue_metric_status: String(phase1RevenueMetricEvidence.status || 'unknown'),
                    metric_trust_key_count: metricTrustKeys.length,
                    metric_trust_keys: metricTrustKeys,
                    data_gap_codes: revenueMetricDataGapCodes,
                    revenue_metric_evidence_policy: String(phase1RevenueMetricEvidence.source_policy || 'read_existing_ota_standard_revenue_metrics_only'),
                    metric_trust_required: true,
                    field_trust_policy: 'requires_target_date_rows_field_definitions_metric_trust_and_data_quality',
                    evidence_refs: [
                        '/api/online-data/collection-reliability.field_definitions',
                        '/api/online-data/collection-reliability.data_quality',
                        '/api/ota-standard/revenue-metrics.metric_trust',
                    ],
                },
                nextAction: fieldTrustProved
                    ? '按字段资产、来源路径、指标可信证据和入库样例逐项复核。'
                    : (hasCompleteTargetDateCoverage
                        ? '打开收益指标的指标可信证据和数据质量缺口，逐项确认字段可信度。'
                        : '先补齐携程/美团同日源数据，再按字段资产、指标可信证据和数据质量状态判断可信度。'),
            },
            {
                key: 'missing_fields',
                question: '哪些字段缺失',
                status: missingFieldCount > 0 || pendingFieldActions.length > 0 || dataGaps.length > 0 ? 'proved' : (qualityStatus === 'ok' ? 'warning' : 'not_proved'),
                detail: missingFieldCount > 0 || pendingFieldActions.length > 0 || dataGaps.length > 0 ? '字段缺口已显式暴露，不能用 0 或空值代替。' : '当前没有缺口样例；未加载完整诊断时不能等同于无缺口。',
                evidence: {
                    missing_field_count: missingFieldCount,
                    missing_field_codes: missingFieldCodes,
                    data_gap_codes: diagnosisDataGapCodes,
                    field_pending_action_count: pendingFieldActions.length,
                    field_pending_action_codes: fieldPendingActionCodes,
                    data_quality_status: qualityStatus || 'unknown',
                    evidence_refs: [
                        '/api/online-data/collection-reliability.data_quality',
                        '/api/agent/ota-diagnosis.data_gaps',
                    ],
                },
                nextAction: '按数据缺口、字段资产和质量任务处理缺口。',
            },
            {
                key: 'revenue_traffic_conversion',
                question: '收入/流量/转化出了什么问题',
                status: metricProblemStatus,
                detail: allMetricDomainsReady
                    ? '收益、流量、转化均有目标日事实，可进入经营诊断。'
                    : (metricTrustKeys.length === 0 && targetDateSourceRows > 0
                        ? '已有目标日 OTA 数据样本，但指标可信证据未输出时不能证明收益、流量或转化指标可信。'
                        : (revenueReadyPlatforms.length
                        ? `收益指标可先复核：${revenueReadyText || '部分平台'}；流量/转化事实不足时，不输出流量或转化确定结论。`
                        : (targetDateSourceRows > 0
                            ? '已有目标日 OTA 数据样本，但收益、流量、转化指标域尚未全部证明。'
                            : '没有目标日入库样本时，不生成收入、流量或转化结论。'))),
                evidence: {
                    target_date_source_rows: targetDateSourceRows,
                    target_date_platform_coverage: targetDatePlatformCoverageEvidence,
                    metric_domain_readiness: metricDomainReadiness,
                    revenue_ready_platforms: revenueReadyPlatforms,
                    traffic_ready_platforms: trafficReadyPlatforms,
                    conversion_ready_platforms: conversionReadyPlatforms,
                    revenue_missing_platforms: revenueMissingPlatforms,
                    traffic_missing_platforms: trafficMissingPlatforms,
                    conversion_missing_platforms: conversionMissingPlatforms,
                    metric_domain_gap_codes: metricDomainGapCodes,
                    metric_trust_key_count: metricTrustKeys.length,
                    metric_trust_keys: metricTrustKeys,
                    data_gap_codes: revenueMetricDataGapCodes,
                    revenue_metric_status: String(phase1RevenueMetricEvidence.status || 'unknown'),
                    metric_domain_policy: 'read_target_date_online_daily_data_types_only',
                    analysis_rows_reference_only: analysisRows,
                },
                nextAction: allMetricDomainsReady
                    ? '进入经营诊断，逐项引用指标可信证据、数据缺口和目标日指标域证据。'
                    : (metricTrustKeys.length === 0 && targetDateSourceRows > 0
                        ? '打开收益指标，复核指标可信证据和数据缺口；未输出前不生成确定指标结论。'
                        : (revenueReadyPlatforms.length
                        ? '先复核收益指标；流量/转化结论必须等待目标日流量事实补齐。'
                        : (targetDateSourceRows > 0
                            ? '打开收益指标，复核收入汇总、流量事实、指标可信证据和数据缺口。'
                            : '先补齐同日 OTA 源数据和标准事实层。'))),
            },
            {
                key: 'ai_evidence',
                question: 'AI 建议依据是什么',
                status: safeAiDiagnosisEvidence.status,
                detail: safeAiDiagnosisEvidence.status === 'proved'
                    ? 'AI 诊断已带证据来源和可执行动作项。'
                    : (safeAiDiagnosisEvidence.status === 'warning'
                        ? 'AI 诊断已有部分证据，但动作项缺失或仍处于阻断状态。'
                        : '尚未看到本范围的 AI 诊断证据。'),
                evidence: safeAiDiagnosisEvidence,
                nextAction: safeAiDiagnosisEvidence.status === 'proved'
                    ? ''
                    : (Number(safeAiDiagnosisEvidence.blocked_action_item_count || 0) > 0
                        ? '先处理 AI 动作项阻断项，再重新生成可进入执行意图的动作。'
                        : '生成 OTA 诊断，并确认返回证据来源、数据缺口和动作项。'),
            },
            {
                key: 'next_operation_action',
                question: '下一步该执行什么动作',
                status: operationQuestionStatus,
                detail: operationProofReady
                    ? '可执行 AI 动作项与 OTA 诊断执行意图均已有审批、执行证据或复盘信号。'
                    : (safeOperationEvidence.operation_evidence_status === 'warning'
                        ? '已有执行意图或执行流记录，但缺少可执行 AI 动作项关联、审批通过、执行证据或复盘信号。'
                        : '当前只有建议/待办时，不能视为运营执行闭环完成。'),
                evidence: {
                    ...safeOperationEvidence,
                    ai_action_items_ready: aiActionItemsReady,
                    operation_ai_action_link_required: true,
                    blocking_missing_codes: operationBlockingCodes,
                },
                nextAction: operationProofReady
                    ? '继续跟进执行结果和复盘状态。'
                    : (safeOperationEvidence.operation_evidence_status === 'warning'
                        ? '补齐可执行 AI 动作项关联、审批通过、执行证据或复盘状态；未补齐前不标记运营闭环完成。'
                        : '从真实诊断动作创建执行意图，保留审批、执行证据和复盘。'),
            },
        ];
        const normalizedLocalRows = localRows.map(normalizePhase1EmployeeQuestionRow);
        const backendQuestionSource = collectionReliability?.phase1_employee_questions || dashboardDataSources?.phase1_employee_questions || {};
        const backendRows = Array.isArray(backendQuestionSource?.rows)
            ? backendQuestionSource.rows.map(normalizePhase1EmployeeQuestionRow)
            : [];
        if (!backendRows.length) return normalizedLocalRows;
        const localByKey = new Map(normalizedLocalRows.map(row => [row.key, row]));
        return backendRows.map(row => {
            const local = localByKey.get(row.key);
            if (!local) return row;
            if (['today_ota_collected', 'trusted_fields', 'missing_fields'].includes(row.key)) return phase1EmployeeQuestionPresentationRow(row, local);
            if (local.status === 'proved' && row.status !== 'proved') return mergePhase1EmployeeQuestionRow(row, local);
            if (['ai_evidence', 'next_operation_action'].includes(row.key) && ['proved', 'warning'].includes(local.status)) {
                return mergePhase1EmployeeQuestionRow(row, local);
            }
            return row;
        });
    };
    const buildPhase1EmployeeRequiredActions = ({ backendQuestionSource = {}, rows = [] } = {}) => {
        const actions = Array.isArray(backendQuestionSource?.next_required_actions)
            ? backendQuestionSource.next_required_actions.map(normalizePhase1EmployeeRequiredAction)
            : [];
        const backendActions = actions.filter(item => item.actionCode && (item.action || item.actionText));
        if (backendActions.length) return backendActions;
        return (Array.isArray(rows) ? rows : [])
            .filter(row => !['proved', 'no_gap_reported'].includes(String(row?.status || '')) && String(row?.nextActionText || row?.employeeNextActionText || row?.nextAction || '').trim())
            .map(buildPhase1LocalRequiredAction)
            .filter(item => item.actionCode && (item.action || item.actionText));
    };

    const onlineAnalysisSourceText = (source) => {
        if (source === 'ctrip') return '携程';
        if (source === 'meituan') return '美团';
        return source || '-';
    };

    const onlineAnalysisDataTypeText = (type) => ({
        business: '经营',
        traffic: '流量',
        rank: '排名',
        ranking: '排名',
        peer_rank: '竞对榜单',
        search_keyword: '搜索词',
        traffic_analysis: '流量分析',
        traffic_forecast: '未来预测',
        advertising: '广告',
        review: '点评',
        quality: '服务质量',
        service: '服务',
        service_quality: '服务质量',
        psi: 'PSI',
    }[type] || type || '-');

    const buildOnlineAnalysisSummaryCards = (summary = {}, dimension = 'day', formatNumber = value => String(value ?? '')) => [
        {
            key: 'amount',
            label: 'OTA销售额',
            value: `¥${formatNumber(summary.total_amount || 0)}`,
            sub: `${dimension === 'day' ? '日' : dimension === 'week' ? '周' : '月'}维度汇总`,
            className: 'text-emerald-700',
        },
        {
            key: 'quantity',
            label: 'OTA间夜',
            value: formatNumber(summary.total_quantity || 0),
            sub: `均值 ${formatNumber(summary.avg_quantity || 0)}`,
            className: 'text-blue-700',
        },
        {
            key: 'orders',
            label: 'OTA订单',
            value: formatNumber(summary.total_orders || 0),
            sub: `评分 ${formatNumber(summary.avg_score || 0)}`,
            className: 'text-amber-700',
        },
        {
            key: 'metric_value',
            label: '指标值',
            value: formatNumber(summary.total_data_value || 0),
            sub: '流量/排名/服务等扩展指标',
            className: 'text-indigo-700',
        },
        {
            key: 'records',
            label: '入库事实行',
            value: formatNumber(summary.total_record_count || 0),
            sub: 'online_daily_data',
            className: 'text-slate-900',
        },
        {
            key: 'hotels',
            label: '覆盖酒店',
            value: formatNumber(summary.hotel_count || 0),
            sub: summary.latest_data_date ? `最新 ${summary.latest_data_date}` : '暂无日期',
            className: 'text-gray-700',
        },
    ];

    const buildOnlineAnalysisMetricDefinitionRows = (hasSamples = false) => [
        {
            key: 'ota_revenue',
            label: 'OTA销售额',
            formula: '来自 online_daily_data.amount 汇总，仅表示已入库 OTA 渠道销售额。',
            source: '来源：携程/美团已授权采集结果；不等同于全酒店总营收。',
            status: hasSamples ? '有样本' : '待样本',
            className: hasSamples ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-gray-50 text-gray-500 border-gray-200',
        },
        {
            key: 'room_nights',
            label: 'OTA间夜',
            formula: '来自 quantity / room_nights 类字段汇总；缺字段时保留缺失状态。',
            source: '来源：经营、订单、销售报告中已映射字段。',
            status: '需字段命中',
            className: 'bg-amber-50 text-amber-700 border-amber-100',
        },
        {
            key: 'adr',
            label: 'ADR',
            formula: '优先展示采集字段；无稳定字段时不使用销售额/间夜倒推替代。',
            source: '来源：房价、平均卖价、实时起价等 OTA 字段。',
            status: '口径复核',
            className: 'bg-blue-50 text-blue-700 border-blue-100',
        },
        {
            key: 'conversion',
            label: '流量转化',
            formula: '曝光、访客、下单、成交分层展示；不同漏斗层不混算。',
            source: '来源：流量报告、竞争圈、广告模块的独立数据域。',
            status: '分层展示',
            className: 'bg-indigo-50 text-indigo-700 border-indigo-100',
        },
    ];

    const onlineAnalysisFieldFactStatus = (item) => (
        item?.field_fact_status && typeof item.field_fact_status === 'object'
            ? item.field_fact_status
            : { status: 'not_loaded', label: '字段事实未写入', detail: '未返回 field_fact_status' }
    );

    const onlineAnalysisP0CaptureEvidenceStatus = (item) => {
        const status = onlineAnalysisFieldFactStatus(item);
        const fieldFactStatus = String(status.status || 'not_loaded');
        const captured = Number(status.captured_count || 0);
        const looseEvidence = Number(status.capture_evidence_count || 0);
        const desensitizedEvidence = Number(status.desensitized_capture_evidence_count || 0);
        if (fieldFactStatus === 'not_loaded') {
            return {
                status: 'not_loaded',
                label: 'P0证据未写入',
                captured,
                looseEvidence,
                desensitizedEvidence,
            };
        }
        if (captured <= 0) {
            return {
                status: 'missing',
                label: 'P0证据缺失',
                captured,
                looseEvidence,
                desensitizedEvidence,
            };
        }
        if (desensitizedEvidence >= captured) {
            return {
                status: 'ready',
                label: 'P0证据就绪',
                captured,
                looseEvidence,
                desensitizedEvidence,
            };
        }
        return {
            status: looseEvidence > 0 ? 'partial' : 'missing',
            label: looseEvidence > 0 ? 'P0证据待补' : 'P0证据缺失',
            captured,
            looseEvidence,
            desensitizedEvidence,
        };
    };

    const onlineAnalysisP0CaptureEvidenceStatusText = (item) => {
        const status = onlineAnalysisP0CaptureEvidenceStatus(item);
        if (status.captured <= 0) return status.label;
        return `${status.label} ${status.desensitizedEvidence}/${status.captured}`;
    };

    const onlineAnalysisP0CaptureEvidenceStatusClass = (item) => {
        const status = String(onlineAnalysisP0CaptureEvidenceStatus(item).status || 'not_loaded');
        const base = 'inline-flex max-w-[9rem] items-center justify-center rounded-full border px-2 py-0.5 text-[11px] leading-4';
        if (status === 'ready') return `${base} border-emerald-100 bg-emerald-50 text-emerald-700`;
        if (status === 'partial') return `${base} border-amber-100 bg-amber-50 text-amber-700`;
        if (status === 'missing') return `${base} border-red-100 bg-red-50 text-red-700`;
        return `${base} border-slate-200 bg-slate-50 text-slate-500`;
    };

    const onlineAnalysisP0CaptureEvidenceDetailText = (item) => {
        const status = onlineAnalysisP0CaptureEvidenceStatus(item);
        const parts = [
            `脱敏采集证据 source_trace_id + source_url_hash ${status.desensitizedEvidence}/${status.captured}`,
            `普通采集证据 ${status.looseEvidence}`,
        ];
        if (status.status !== 'ready') {
            parts.push('P0闭环需每个 metric 具备脱敏 trace 与 source URL hash');
        }
        return parts.join('；');
    };

    const onlineAnalysisFieldFactStatusText = (item) => {
        const status = onlineAnalysisFieldFactStatus(item);
        const label = String(status.label || '').trim() || '字段事实';
        const captured = Number(status.captured_count || 0);
        const missing = Number(status.missing_count || 0);
        if (status.status === 'not_loaded') return label;
        return missing > 0 ? `${label} ${captured}/${captured + missing}` : `${label} ${captured}`;
    };

    const onlineAnalysisFieldFactStatusClass = (item) => {
        const status = String(onlineAnalysisFieldFactStatus(item).status || 'not_loaded');
        const base = 'inline-flex max-w-[9rem] items-center justify-center rounded-full border px-2 py-0.5 text-[11px] leading-4';
        if (status === 'ready') return `${base} border-emerald-100 bg-emerald-50 text-emerald-700`;
        if (status === 'partial') return `${base} border-amber-100 bg-amber-50 text-amber-700`;
        if (status === 'missing') return `${base} border-red-100 bg-red-50 text-red-700`;
        return `${base} border-slate-200 bg-slate-50 text-slate-500`;
    };

    const onlineAnalysisFieldFactDetailText = (item) => {
        const status = onlineAnalysisFieldFactStatus(item);
        const detail = String(status.detail || '').trim();
        const capturedKeys = Array.isArray(status.captured_metric_keys) ? status.captured_metric_keys : [];
        const missingKeys = Array.isArray(status.missing_metric_keys) ? status.missing_metric_keys : [];
        const storedPresent = Number(status.stored_value_present_count || 0);
        const storedMissing = Number(status.stored_value_missing_count || 0);
        const parts = [];
        if (detail) parts.push(detail);
        if (storedPresent > 0 || storedMissing > 0) parts.push(`入库值 ${storedPresent}/${storedPresent + storedMissing}`);
        if (capturedKeys.length) parts.push(`已闭环 ${capturedKeys.slice(0, 6).join('、')}`);
        if (missingKeys.length) parts.push(`缺失 ${missingKeys.slice(0, 6).join('、')}`);
        return parts.join('；') || '字段事实未写入';
    };

    const buildOnlineAnalysisChartConfig = (chartData) => ({
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index', intersect: false },
            },
            scales: {
                y: { type: 'linear', display: true, position: 'left', title: { display: true, text: '销售额(¥)' } },
                y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: '房晚/订单' }, grid: { drawOnChartArea: false } },
            },
        },
    });

    return {
        onlineDataQualityStatusText,
        onlineDataQualityStatusClass,
        onlineDataQualityPromptList,
        onlineDataQualityScopeText,
        autoFetchRecordStatusClass,
        manualOneClickFetchPlatformText,
        manualOneClickFetchNowText,
        normalizeManualOneClickFetchStoredRows,
        summarizeManualOneClickFetchRows,
        buildManualOneClickFetchCards,
        buildManualOneClickFetchEmptyText,
        manualOneClickFetchStatusClass,
        manualOneClickFetchActionableStatus,
        manualOneClickFetchRowHasHotel,
        manualOneClickFetchCanEditRow,
        manualOneClickFetchCanRetryRow,
        manualOneClickFetchCanDeleteRow,
        manualOneClickFetchCanSupplementRow,
        sortManualOneClickFetchRows,
        manualOneClickFetchMessageIsQunarVisitorZero,
        manualOneClickFetchHasQunarVisitorZeroFailureInRows,
        findManualOneClickFetchExistingStoredRow,
        buildManualOneClickFetchTasks,
        buildManualOneClickFetchBaseRow,
        buildManualOneClickFetchRunningRow,
        buildManualOneClickFetchResultRow,
        buildManualOneClickFetchFailureRow,
        manualOneClickFetchQunarVisitorNumber,
        summarizeManualOneClickFetchQunarVisitorQuality,
        manualOneClickFetchQunarVisitorNeedsRetry,
        manualOneClickFetchSavedCount,
        manualOneClickFetchResultMessage,
        summarizeManualOneClickFetchResult,
        buildOnlineHistoryQueryParams,
        isDirtyQuestionMarkText,
        formatOnlineHistoryHotelOption,
        formatOnlineHistoryRaw,
        buildHotelDataDashboardRequests,
        collectionHealthCookieLightClass,
        collectionHealthCookieLightText,
        dataHealthNormalizeStatus,
        dataHealthPriorityClass,
        dataHealthPriorityText,
        dataHealthPlatformText,
        collectionHealthAuthorizationPlatformText,
        collectionHealthAuthorizationMessageText,
        collectionHealthAuthorizationActionHintText,
        collectionHealthFailureTypeText,
        collectionHealthFailureReasonText,
        collectionHealthFailureNextActionText,
        platformBatchHealthBadgeClass,
        buildPlatformBatchHealthRows,
        buildPlatformBatchHealthSummaryCards,
        buildCollectionHealthFailureReasonRanking,
        buildDataHealthTodayWorkOrders,
        buildOtaTodayCollectionReminderRows,
        buildOtaTodayCollectionReminderSummary,
        buildDataHealthDiagnosticBoundary,
        dataHealthRefreshModeText,
        buildDataHealthDiagnosticStatusRows,
        normalizeDataHealthRefreshRequest,
        createDataHealthRefreshRequestState,
        buildDataHealthPanelRefreshJobs,
        scheduleDataHealthLightDiagnosticsRefresh,
        buildDataHealthFieldGapActionRows,
        summarizeDataHealthFieldGapActions,
        employeeOtaChecklistPriorityRank,
        employeeOtaChecklistCategoryClass,
        employeeOtaChecklistCategoryText,
        buildEmployeeOtaChecklistHeadline,
        otaFieldGapQueueStatusText,
        buildOtaFieldGapQueueRows,
        summarizeOtaFieldGapQueue,
        buildDataHealthCookieAlertRows,
        summarizeDataHealthCookieAlerts,
        buildDataHealthQualityTaskRows,
        buildDataHealthHighRiskActionRows,
        summarizeDataHealthHighRiskActions,
        summarizePublicEndpointSecurity,
        publicEndpointTokenText,
        publicEndpointDisplayName,
        publicEndpointSecurityBoundaryText,
        publicEndpointSecurityEvidenceText,
        publicEndpointPathText,
        releaseEvidenceInputLabel,
        releaseEvidenceStatusText,
        releaseEvidencePriority,
        releaseEvidenceNoClosureText,
        buildReleaseEvidencePanelRows,
        summarizeReleaseEvidencePanel,
        dashboardStateText,
        dashboardStateClass,
        dashboardMetricText,
        dashboardEvidenceText,
        collectionHealthStatusText,
        collectionHealthStatusClass,
        platformCollectionResourceLabel,
        platformCollectionResourceStatusText,
        platformCollectionResourceStatusClass,
        platformCollectionEtlStatusText,
        platformCollectionFreshnessText,
        collectionHealthPendingActionPlatformText,
        collectionHealthPendingActionTypeText,
        collectionHealthPendingActionText,
        collectionHealthPendingActionReasonText,
        collectionHealthPendingActionEvidenceText,
        collectionHealthPendingActionProtectedBoundaryText,
        collectionHealthPendingActionOwnerText,
        collectionHealthCtripCatalogStatusText,
        collectionHealthCtripCatalogAuthStatusText,
        collectionHealthCtripCatalogCodeText,
        collectionHealthCtripCatalogCodeListText,
        collectionHealthCtripSectionText,
        collectionHealthCtripCatalogActionReasonText,
        collectionHealthCtripCatalogStatus,
        collectionHealthCtripCatalogMessage,
        collectionHealthCtripCatalogGateText,
        collectionHealthCtripModuleStatusText,
        collectionHealthCtripModuleStatusClass,
        collectionHealthCtripShortList,
        collectionHealthCtripMetricText,
        collectionHealthCtripValueText,
        collectionHealthCtripMetricDisplay,
        collectionHealthCtripNumberValue,
        collectionHealthCtripEffectivenessClass,
        collectionHealthFieldSourceText,
        collectionHealthFieldModuleText,
        collectionHealthFieldStorageTableText,
        collectionHealthFieldAssetStatusText,
        collectionHealthFieldAssetStatusClass,
        collectionHealthFieldAssetListText,
        buildCollectionHealthAuthorizationRowsReadable,
        buildCollectionHealthFailureReasonRows,
        buildCollectionHealthPendingActionRows,
        buildCollectionHealthFieldAssetCards,
        collectionHealthLifecycleStageStatus,
        collectionHealthLifecycleReadyCount,
        buildCollectionHealthCtripCatalogCards,
        collectionHealthCtripCatalogDiagnosticScopeText,
        collectionHealthCtripCatalogAuthText,
        collectionHealthCtripCatalogPendingFetchText,
        collectionHealthCtripCatalogPendingFieldText,
        buildCollectionHealthCtripCatalogVisibleNotes,
        collectionHealthCtripCatalogActionText,
        buildCollectionHealthCtripCatalogDetailRows,
        buildCollectionHealthCtripCatalogActionRows,
        buildCollectionHealthCtripPersistedRows,
        collectionHealthCtripIdentityBlocked,
        collectionHealthCtripIdentityMessage,
        buildCollectionHealthCtripLatestCards,
        buildCollectionHealthCtripOverviewAuthState,
        buildCollectionHealthCtripOverviewStatusCards,
        buildCtripOverviewFetchModuleCards,
        collectionHealthCtripMetricPreviewValue,
        collectionHealthCtripCalculatedValue,
        collectionHealthCtripKeysForLabels,
        collectionHealthCtripDataTypesForSections,
        collectionHealthCtripActionForSections,
        collectionHealthCtripModuleStats,
        collectionHealthCtripRowsForContext,
        collectionHealthCtripPreviewMetricKey,
        collectionHealthCtripMetricKeyAliases,
        collectionHealthCtripMetricKeyMatches,
        collectionHealthCtripMissingDiagnosis,
        collectionHealthCtripMissingMetric,
        collectionHealthCtripMetricFromRows,
        collectionHealthCtripMetricValue,
        collectionHealthCtripOverviewMetric,
        buildCollectionHealthCtripCoreSnapshotGroups,
        buildCollectionHealthCtripOverviewRevenueMetrics,
        buildCollectionHealthCtripOverviewTrafficMetrics,
        buildCollectionHealthCtripOverviewFunnelRows,
        buildCollectionHealthCtripOverviewPanels,
        buildCollectionHealthCtripMissingActionRows,
        buildPhase1MetricDomainReadiness,
        buildPhase1TrafficLatestSyncTaskText,
        phase1P0StandardFactSummary,
        buildPhase1TrafficP0NextText,
        phase1EmployeeQuestionStatusText,
        phase1EmployeeQuestionStatusClass,
        dailyWorkbenchStatusText,
        dailyWorkbenchStatusClass,
        buildDailyWorkbenchWriteBoundary,
        phase3OperationEffectLoopStatusText,
        phase3OperationEffectLoopStatusClass,
        phase1EmployeeActionFamilyText,
        phase1EmployeeReadinessStatusText,
        phase1EmployeeReadinessEvidenceText,
        phase1EmployeeQuestionKeyText,
        phase1EmployeeKnownQuestionText,
        phase1EmployeeKnownQuestionListText,
        phase1EmployeePlatformText,
        phase1EmployeeDateRelationText,
        phase1EmployeeActionStatusText,
        phase1MetricDomainPlatformText,
        phase1MetricDomainDataTypeText,
        phase1MissingFieldDetailText,
        phase1MissingFieldLabel,
        phase1MissingFieldNextActionText,
        phase1MissingFieldSourceText,
        phase1MetricDomainStatusText,
        phase1MetricDomainStatusClass,
        phase1MetricDomainMissingLabel,
        phase1MetricDomainProblemText,
        phase1MetricDomainNextActionText,
        phase1EmployeeCountItem,
        phase1EmployeeQuestionBlockingGapCodes,
        mergePhase1EmployeeQuestionRow,
        phase1EmployeeQuestionPresentationRow,
        phase1EmployeeActionRawCode,
        phase1EmployeeActionPlatformText,
        phase1EmployeeActionEntryText,
        phase1EmployeeActionEntryOptionModeText,
        phase1EmployeeActionEntryOptionRawText,
        phase1EmployeeActionEntryOptionText,
        phase1EmployeeActionEntryOptionPlatformText,
        phase1EmployeeActionEntryOptionInputText,
        phase1EmployeeActionEntryOptionContractText,
        phase1EmployeeActionEntryOptionGuidanceText,
        phase1EmployeeActionEntryOptionGuidanceRawText,
        phase1EmployeeActionEntryOptionReadinessText,
        phase1EmployeeActionSuccessCriteriaText,
        phase1EmployeeActionEvidenceNeededText,
        phase1EmployeeActionVerificationStepsText,
        phase1EmployeeActionBlockedActionText,
        phase1EmployeeActionEmployeeExplanationText,
        phase1EmployeeActionLimitedConclusionsText,
        phase1EmployeeActionStillUsableMetricsText,
        phase1EmployeeActionExplanationNextActionText,
        phase1EmployeeActionDisplayText,
        phase1EmployeeActionOwnerText,
        phase1EmployeeActionMetaText,
        phase1EmployeeActionProtectedBoundaryText,
        normalizePhase1EmployeeRequiredAction,
        phase1LocalActionMeta,
        buildPhase1LocalRequiredAction,
        phase1DiagnosisActionItemStatus,
        phase1DiagnosisActionItemText,
        phase1DiagnosisActionItemBlocked,
        buildPhase1AiDiagnosisEvidence,
        phase1EmployeeAiJudgementText,
        phase1EmployeeAiLimitText,
        phase1EmployeeOperationJudgementText,
        phase1EmployeeOperationLimitText,
        buildPhase1EmployeeAiEvidenceSummary,
        buildPhase1EmployeeOperationSummary,
        buildPhase1EmployeeClosureSummary,
        phase1EmployeeEvidenceStatusText,
        phase1FieldTrustStatusText,
        phase1EmployeeEvidencePolicyText,
        phase1EmployeeStorageTableText,
        phase1EmployeeGapCodeText,
        phase1EmployeeActionCodeText,
        phase1EmployeeSourceSnapshotText,
        phase1EmployeeQuestionNextActionText,
        phase1EmployeeQuestionEvidenceText,
        normalizePhase1EmployeeQuestionRow,
        buildPhase1EmployeeQuestionRows,
        buildPhase1EmployeeRequiredActions,
        phase1EmployeeCollectionDataTypeText,
        normalizePhase1CollectionSourceSummaryRow,
        buildPhase1EmployeeCollectionSourceRows,
        phase1FieldTrustStatusClass,
        normalizePhase1EmployeeFieldTrustRow,
        buildPhase1EmployeeFieldTrustRows,
        normalizePhase1EmployeeMissingFieldRow,
        normalizePhase1EmployeeMissingFieldSummaryRow,
        buildPhase1EmployeeMissingFieldRows,
        normalizePhase1EmployeeMetricDomainRow,
        normalizePhase1EmployeeMetricDomainSummaryRow,
        buildPhase1EmployeeMetricDomainRows,
        onlineAnalysisFieldFactStatus,
        onlineAnalysisP0CaptureEvidenceStatus,
        onlineAnalysisP0CaptureEvidenceStatusText,
        onlineAnalysisP0CaptureEvidenceStatusClass,
        onlineAnalysisP0CaptureEvidenceDetailText,
        onlineAnalysisFieldFactStatusText,
        onlineAnalysisFieldFactStatusClass,
        onlineAnalysisFieldFactDetailText,
        onlineAnalysisSourceText,
        onlineAnalysisDataTypeText,
        buildOnlineAnalysisSummaryCards,
        buildOnlineAnalysisMetricDefinitionRows,
        buildOnlineAnalysisChartConfig,
    };
})();
