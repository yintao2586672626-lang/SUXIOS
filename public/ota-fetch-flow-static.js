window.SUXI_OTA_FETCH_FLOW_STATIC = (() => {
    const createMeituanBatchFetchFlow = ({ buildMeituanBatchFetchPendingEntry, buildMeituanBatchFetchResultEntry, buildMeituanBatchFetchTasks, buildMeituanDisplayModelPayload, hasMeituanCompleteAbsoluteRankRows, isMeituanBackgroundAcceptedResponse, isMeituanBackgroundResult, isMeituanConfigBoundToFormHotel, isMeituanExecutionConfigReady, isMeituanHistoricalPercentOnlyStayOrSales, isMeituanNonRetryableFetchError, isMeituanPendingResult, isMeituanRankResponseComplete, isMeituanRankingFormAlignedWithConfig, meituanRankCandidateValueMode, meituanRankMaxAttempts, meituanRetryDelayMs, mergeMeituanSelfMetricStatus, mergeMeituanSelfMetricValues, resolveMeituanExecutionConfigId, runPostFetchRefresh, selectBetterMeituanRankResponse, validateMeituanBatchFetchInput }) => {
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
        let unverifiedSavedCount = 0;
        let acceptedCount = 0;

        try {
            if (fetchTasks.length > 0) {
                notify(fetchTasks.length === 1 ? fetchTasks[0].toastText : `正在获取 ${fetchTasks.length} 个美团榜单任务...`);
            }
            await Promise.all(fetchTasks.map(async (task, index) => {
                const requestBody = { ...task.body, async: background === true, background: background === true };
                const maxAttempts = meituanRankMaxAttempts(task);
                let attemptCount = 0;
                let commitStarted = false;
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
                        commitStarted = true;
                        const commitResponse = await requestCommit({ ...rankCandidate });
                        if (!runIsActive()) return;
                        if (commitResponse?.code !== 200) {
                            const commitError = new Error(commitResponse?.message || 'Meituan rank candidate commit failed');
                            commitError.data = commitResponse;
                            throw commitError;
                        }
                        if (!res.data || typeof res.data !== 'object') {
                            res.data = {};
                        }
                        res.data.saved_count = Number(commitResponse?.data?.saved_count || 0);
                        res.data.persistence_status = commitResponse?.data?.persistence_status || '';
                        res.data.database_readback = commitResponse?.data?.database_readback || null;
                        if (Object.prototype.hasOwnProperty.call(commitResponse?.data || {}, 'readback_verified')) {
                            res.data.readback_verified = commitResponse.data.readback_verified;
                        } else {
                            delete res.data.readback_verified;
                        }
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
                        const reportedCount = Number(res.data?.saved_count);
                        if (Number.isSafeInteger(reportedCount) && reportedCount > 0) {
                            if (bestEntry.readbackVerified === true) {
                                totalSavedCount += reportedCount;
                            } else {
                                unverifiedSavedCount += reportedCount;
                                results[index].unverifiedSavedCount = reportedCount;
                                results[index].savedCount = 0;
                                results[index].status = 'readback_unverified';
                                results[index].terminalPartial = true;
                                results[index].message = `后台报告处理 ${reportedCount} 条，但数据库回读未核验；请到历史记录核查`;
                            }
                        }
                    }
                    if (runIsActive()) {
                        setOnlineDataResult([...results]);
                    }
                } catch (error) {
                    if (!runIsActive()) return;
                    const reportedCount = commitStarted ? Number(error?.data?.data?.saved_count) : 0;
                    const unverifiedCount = Number.isSafeInteger(reportedCount) && reportedCount > 0 ? reportedCount : 0;
                    unverifiedSavedCount += unverifiedCount;
                    results[index] = {
                        ...buildMeituanBatchFetchPendingEntry(task),
                        status: unverifiedCount > 0 ? 'readback_unverified' : 'exception',
                        attemptCount,
                        retryCount: Math.max(0, attemptCount - 1),
                        maxAttempts,
                        unverifiedSavedCount: unverifiedCount,
                        readbackVerified: false,
                        message: unverifiedCount > 0
                            ? `后台报告处理 ${unverifiedCount} 条，但数据库回读未核验；请到历史记录核查`
                            : (error.message || '请求异常'),
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
            const unverifiedTaskCount = results.filter(item => Number(item?.unverifiedSavedCount || 0) > 0).length;
            const otherIssueCount = Math.max(0, failedCount + incompleteCount - unverifiedTaskCount);
            const loginFailed = results.some(item => item?.credentialStatus === 'login_required' || item?.status === 'login_required' || /未登录|登录态|Cookie|授权/.test(String(item?.error || item?.message || '')));
            if (acceptedCount > 0) {
                setFetchSuccess(true);
                setDataFetchTime(getFetchTime());
                notify(
                    (acceptedCount === fetchTasks.length
                        ? `美团手动获取已提交后台执行（${acceptedCount} 个任务），完成后会更新数据列表和通知`
                        : `美团手动获取已提交 ${acceptedCount} 个后台任务，其余任务已返回结果`)
                        + (unverifiedSavedCount > 0 ? `；另有 ${unverifiedSavedCount} 条后台报告处理但回读未核验` : ''),
                    unverifiedSavedCount > 0 ? 'warning' : 'info'
                );
                if (!suppressPostFetchRefresh) {
                    runPostFetchRefresh(refreshOnlineHistory);
                    if (getOnlineDataTab() === 'data') {
                        refreshOnlineData();
                    }
                }
                return { status: 'accepted', results, acceptedCount, totalSavedCount, unverifiedSavedCount };
            }
            if (fetchTasks.length > 0 && failedCount === fetchTasks.length) {
                setFetchSuccess(false);
                setBusinessSummary(getEmptyBusinessSummary());
                if (unverifiedSavedCount > 0) {
                    notify(`美团后台报告处理 ${unverifiedSavedCount} 条，但数据库回读未核验；请到历史记录核查`, 'warning');
                    if (!suppressPostFetchRefresh) runPostFetchRefresh(refreshOnlineHistory);
                    return { status: 'readback_unverified', results, totalSavedCount, unverifiedSavedCount, failedCount };
                }
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
                    unverifiedSavedCount > 0
                        ? `美团榜单已入库 ${verifiedSavedCount} 条并完成数据库回读核验；另有 ${unverifiedSavedCount} 条后台报告处理但回读未核验${otherIssueCount > 0 ? `；${otherIssueCount} 个任务失败或字段不完整` : ''}`
                        : failedCount + incompleteCount > 0
                        ? `已入库 ${verifiedSavedCount} 条完整榜单数据并完成数据库回读核验，但有 ${failedCount + incompleteCount} 个榜单只返回部分字段`
                        : `美团榜单已入库 ${verifiedSavedCount} 条，并完成数据库回读核验`,
                    failedCount + incompleteCount > 0 || unverifiedSavedCount > 0 ? 'warning' : undefined
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
                        ? `批量请求已完成，接口报告处理 ${totalSavedCount} 条；${failedCount + incompleteCount} 个任务失败或字段不完整，且尚未确认数据库回读${unverifiedSavedCount > 0 ? `；另有 ${unverifiedSavedCount} 条后台报告处理但回读未核验` : ''}`
                        : `批量请求已完成，接口报告处理 ${totalSavedCount} 条，尚未确认数据库回读${unverifiedSavedCount > 0 ? `；另有 ${unverifiedSavedCount} 条后台报告处理但回读未核验` : ''}`,
                    'warning'
                );
                if (!suppressPostFetchRefresh) {
                    runPostFetchRefresh(refreshOnlineHistory);
                    if (getOnlineDataTab() === 'data') {
                        refreshOnlineData();
                    }
                }
            } else if (unverifiedSavedCount > 0) {
                notify(`美团后台报告处理 ${unverifiedSavedCount} 条，但数据库回读未核验；请到历史记录核查`, 'warning');
                if (!suppressPostFetchRefresh) runPostFetchRefresh(refreshOnlineHistory);
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
                status: failedCount + incompleteCount > 0 || (unverifiedSavedCount > 0 && verifiedSavedCount > 0)
                    ? 'partial'
                    : (unverifiedSavedCount > 0 ? 'readback_unverified' : 'success'),
                results,
                totalSavedCount,
                verifiedSavedCount,
                unverifiedSavedCount,
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
    return runMeituanBatchFetchFlow;
    };

    const createPlatformBatchHealthRows = ({ platformBatchHealthSourceActive, platformBatchHealthSourceHotelId, platformBatchHealthSourceTime }) => {
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
    return buildPlatformBatchHealthRows;
    };

    return { createMeituanBatchFetchFlow, createPlatformBatchHealthRows };
})();
