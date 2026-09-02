(() => {
    'use strict';

    const createRevenueCockpitStatic = ({
        revenueAiReasonText,
        resolveRevenueOverviewAsOfDate,
        revenueAiIsoDate,
        revenueOverviewAsOfDateContractVersion,
    } = {}) => {
        if (typeof revenueAiReasonText !== 'function'
            || typeof resolveRevenueOverviewAsOfDate !== 'function'
            || typeof revenueAiIsoDate !== 'function'
            || !revenueOverviewAsOfDateContractVersion
        ) {
            throw new Error('经营驾驶舱静态工具缺少 Revenue AI 事实合同依赖');
        }

        const REVENUE_OVERVIEW_AS_OF_DATE_CONTRACT_VERSION = String(
            revenueOverviewAsOfDateContractVersion,
        );

        const revenueCockpitPlatformLabel = (platform) => ({
            ctrip: '携程',
            meituan: '美团',
            all_ota: '携程 + 美团',
        }[String(platform || '').toLowerCase()] || 'OTA');

        const revenueCockpitSourceMeta = (sourceKey) => ({
            dingdandao_pms: {
                label: 'PMS（订单来了）',
                scope: 'whole_hotel_accommodation',
                scopeLabel: 'PMS 全酒店住宿口径',
                expectedTable: 'dingdandao_operating_target_captures',
            },
            ctrip_ota: {
                label: '携程 OTA',
                scope: 'ota_channel',
                scopeLabel: '携程 OTA 渠道口径',
                expectedTable: 'online_daily_data',
            },
            meituan_ota: {
                label: '美团 OTA',
                scope: 'ota_channel',
                scopeLabel: '美团 OTA 渠道口径',
                expectedTable: 'online_daily_data',
            },
            cockpit_rule: {
                label: '经营驾驶舱规则',
                scope: 'advisory_only',
                scopeLabel: '只读建议口径',
                expectedTable: '',
            },
        }[String(sourceKey || '')] || {
            label: String(sourceKey || '来源待确认'),
            scope: 'unknown',
            scopeLabel: '口径待确认',
            expectedTable: '',
        });

        const revenueCockpitIsoDate = (value) => {
            const text = String(value || '').trim().slice(0, 10);
            return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : '';
        };

        const revenueCockpitDateDistance = (businessDate, today) => {
            const left = revenueCockpitIsoDate(businessDate);
            const right = revenueCockpitIsoDate(today);
            if (!left || !right) return null;
            const leftParts = left.split('-').map(Number);
            const rightParts = right.split('-').map(Number);
            const leftTime = Date.UTC(leftParts[0], leftParts[1] - 1, leftParts[2]);
            const rightTime = Date.UTC(rightParts[0], rightParts[1] - 1, rightParts[2]);
            return Math.round((rightTime - leftTime) / 86400000);
        };

        const resolveRevenueCockpitScope = ({
        scopePayload = null,
        requestedPlatform = '',
        requestedDate = '',
        resetDate = false,
        asOfDate = '',
    } = {}) => {
        const payload = scopePayload && typeof scopePayload === 'object' ? scopePayload : {};
        const allowedPlatforms = new Set(['all_ota', 'ctrip', 'meituan']);
        const platformRows = (Array.isArray(payload.platforms) ? payload.platforms : [])
            .filter((row) => row && allowedPlatforms.has(String(row.platform || '').toLowerCase()))
            .map((row) => {
                const platform = String(row.platform || '').toLowerCase();
                const availableDates = Array.from(new Set(
                    (Array.isArray(row.available_dates) ? row.available_dates : [])
                        .map(revenueCockpitIsoDate)
                        .filter(Boolean),
                )).sort((left, right) => right.localeCompare(left));
                const latest = revenueCockpitIsoDate(row.latest_verified_date) || availableDates[0] || '';
                if (latest && !availableDates.includes(latest)) availableDates.unshift(latest);
                return {
                    platform,
                    label: revenueCockpitPlatformLabel(platform),
                    latestVerifiedDate: latest,
                    availableDates,
                    availableDateCount: Number(row.available_date_count || availableDates.length || 0),
                    verifiedFactCount: Number(row.verified_fact_count || 0),
                };
            })
            .filter((row) => row.availableDates.length > 0);
        const recommended = payload.recommended && typeof payload.recommended === 'object'
            ? payload.recommended
            : {};
        const recommendedPlatform = String(recommended.platform || '').toLowerCase();
        const requestedPlatformKey = String(requestedPlatform || '').toLowerCase();
        const selectedRow = platformRows.find((row) => row.platform === requestedPlatformKey)
            || platformRows.find((row) => row.platform === recommendedPlatform)
            || platformRows[0]
            || null;
        if (!selectedRow) {
            return {
                status: String(payload.data_status || 'empty'),
                strictGate: String(payload?.boundary?.strict_gate || 'history_success+validation_verified+readback_verified'),
                selectedPlatform: '',
                selectedPlatformLabel: '无严格可用平台',
                selectedDate: '',
                previousDate: '',
                sameWeekdayDate: '',
                latestVerifiedDate: '',
                dateDistance: null,
                isAsOfDate: false,
                isLatest: false,
                platformOptions: [],
                dateOptions: [],
                notice: '该酒店没有找到已保存、验证通过且完成严格回读的 OTA 经营日期。',
                boundary: payload.boundary || {},
            };
        }
        const requestedDateKey = revenueCockpitIsoDate(requestedDate);
        const selectedDate = !resetDate && selectedRow.availableDates.includes(requestedDateKey)
            ? requestedDateKey
            : selectedRow.latestVerifiedDate;
        const selectedIndex = selectedRow.availableDates.indexOf(selectedDate);
        const previousDate = selectedIndex >= 0 ? (selectedRow.availableDates[selectedIndex + 1] || '') : '';
        const selectedWeekday = (() => {
            const parts = selectedDate.split('-').map(Number);
            return parts.length === 3 ? new Date(Date.UTC(parts[0], parts[1] - 1, parts[2])).getUTCDay() : -1;
        })();
        const sameWeekdayDate = selectedIndex >= 0
            ? (selectedRow.availableDates.slice(selectedIndex + 1).find((candidate) => {
                const parts = candidate.split('-').map(Number);
                return parts.length === 3
                    && new Date(Date.UTC(parts[0], parts[1] - 1, parts[2])).getUTCDay() === selectedWeekday;
            }) || '')
            : '';
        const distance = revenueCockpitDateDistance(selectedDate, asOfDate);
        const isAsOfDate = distance === 0;
        const distanceText = distance === null
            ? '与固定数据基准日的差异待确认'
            : (distance === 0
                ? '就是数据基准日'
                : (distance > 0 ? `比数据基准日早 ${distance} 天` : `比数据基准日晚 ${Math.abs(distance)} 天`));
        return {
            status: 'ready',
            strictGate: String(payload?.boundary?.strict_gate || 'history_success+validation_verified+readback_verified'),
            selectedPlatform: selectedRow.platform,
            selectedPlatformLabel: selectedRow.label,
            selectedDate,
            previousDate,
            sameWeekdayDate,
            latestVerifiedDate: selectedRow.latestVerifiedDate,
            dateDistance: distance,
            isAsOfDate,
            isLatest: selectedDate === selectedRow.latestVerifiedDate,
            platformOptions: platformRows.map((row) => ({
                value: row.platform,
                label: row.label,
                latestVerifiedDate: row.latestVerifiedDate,
                availableDateCount: row.availableDates.length,
            })),
            dateOptions: selectedRow.availableDates.map((date, index) => ({
                value: date,
                label: `${date}${index === 0 ? ' · 最近严格回读' : ''}`,
                isLatest: index === 0,
            })),
            notice: `${selectedRow.label}当前业务日 ${selectedDate}，${distanceText}；${selectedDate === selectedRow.latestVerifiedDate ? '已默认到该平台最近严格可用日期' : '当前为人工选择的历史严格可用日期'}。`,
            boundary: payload.boundary || {},
        };
    };

    const REVENUE_COCKPIT_VIEW_MODEL_CONTRACT_VERSION = 'revenue_daily_cockpit.v2';
    const revenueCockpitBlockedModel = (message = '服务端未签发当前经营驾驶舱模型', status = 'blocked') => ({
        contractVersion: REVENUE_COCKPIT_VIEW_MODEL_CONTRACT_VERSION,
        status,
        statusLabel: status === 'loading' ? '读取中' : '已阻断',
        statusClass: status === 'loading'
            ? 'border-slate-200 bg-slate-50 text-slate-600'
            : 'border-rose-200 bg-rose-50 text-rose-700',
        headline: status === 'loading' ? '正在读取服务端经营驾驶舱' : '经营驾驶舱已阻断',
        summary: String(message || '服务端未签发当前经营驾驶舱模型'),
        dateNotice: '',
        scopeBoundary: 'PMS 与 OTA 口径保持分离。',
        sections: [],
        visibleSections: [],
        opportunities: [],
        canAskQuestion: false,
        canCreatePendingApproval: false,
        canSaveSnapshot: false,
        actionDisabledReason: String(message || '服务端未签发当前经营驾驶舱模型'),
    });

    const resolveRevenueCockpitCanonicalViewModel = ({
        overview = null,
        hotelId = 0,
        businessDate = '',
        platform = '',
    } = {}) => {
        const payload = overview && typeof overview === 'object' ? overview : {};
        const model = payload.canonical_view_model && typeof payload.canonical_view_model === 'object'
            ? payload.canonical_view_model
            : null;
        const digest = String(payload.canonical_view_model_digest || '').trim().toLowerCase();
        const topContractVersion = String(payload.canonical_view_model_contract_version || '').trim();
        const topStatus = String(payload.canonical_view_model_status || '').trim();
        const asOfDate = resolveRevenueOverviewAsOfDate(payload);
        const factLayer = payload.three_source_fact_layer || {};
        const strict = payload.cockpit_strict_evidence || {};
        const expectedHotelId = Number(hotelId || payload.hotel_id || factLayer?.hotel?.system_hotel_id || 0);
        const expectedBusinessDate = String(businessDate || payload.business_date || '');
        const expectedPlatform = String(platform || strict.platform || '').toLowerCase();
        const invalid = !model
            || topStatus !== 'server_issued'
            || topContractVersion !== REVENUE_COCKPIT_VIEW_MODEL_CONTRACT_VERSION
            || !/^[a-f0-9]{64}$/.test(digest)
            || !asOfDate.ok
            || String(model.contractVersion || '') !== REVENUE_COCKPIT_VIEW_MODEL_CONTRACT_VERSION
            || Number(model.hotelId || 0) !== expectedHotelId
            || expectedHotelId <= 0
            || String(model.businessDate || '') !== expectedBusinessDate
            || !expectedBusinessDate
            || String(model.selectedPlatform || '').toLowerCase() !== expectedPlatform
            || !['ctrip', 'meituan', 'all_ota'].includes(expectedPlatform)
            || String(model.asOfDate || '') !== asOfDate.asOfDate
            || String(model.asOfDateContractVersion || '') !== asOfDate.contractVersion
            || Number(model.tenantId || 0) <= 0
            || Number(model.tenantId || 0) !== Number(strict.tenant_id || factLayer?.hotel?.tenant_id || 0)
            || !Array.isArray(model.sections)
            || !Array.isArray(model.visibleSections)
            || !Array.isArray(model.opportunities);
        if (invalid) {
            return {
                ok: false,
                model: null,
                digest: '',
                message: asOfDate.ok
                    ? '服务端签发的经营驾驶舱模型、摘要、酒店、平台、业务日期或数据基准日身份不一致'
                    : asOfDate.message,
            };
        }
        return { ok: true, model, digest, message: '' };
    };

    const buildRevenueCockpitModel = ({
        overview = null,
        selectedPlatform = '',
        businessDate = '',
        loading = false,
        error = '',
    } = {}) => {
        if (loading) return revenueCockpitBlockedModel('正在读取服务端签发的经营驾驶舱模型', 'loading');
        const canonical = resolveRevenueCockpitCanonicalViewModel({
            overview,
            hotelId: Number(overview?.hotel_id || overview?.three_source_fact_layer?.hotel?.system_hotel_id || 0),
            businessDate,
            platform: selectedPlatform,
        });
        if (!canonical.ok) {
            return revenueCockpitBlockedModel(error || canonical.message);
        }
        return canonical.model;
    };
        const buildRevenueCockpitDownloadRows = (model = {}) => {
            const sections = Array.isArray(model.visibleSections) ? model.visibleSections : [];
            let order = 0;
            return sections.flatMap((section) => (
                (Array.isArray(section.cards) ? section.cards : []).map((card) => {
                    order += 1;
                    return {
                        order,
                        section: String(section.title || ''),
                        card: String(card.label || ''),
                        display: String(card.display || '—'),
                        unit: String(card.unitLabel || card.unit || ''),
                        source: String(card.sourceLabel || ''),
                        business_date: String(card.businessDate || model.businessDate || ''),
                        verification_status: String(card.statusLabel || ''),
                        scope: String(card.scopeLabel || ''),
                        missing_state: String(card.missingState || ''),
                        opportunity_key: String(card.opportunityKey || ''),
                        rank: Number(card.rank || 0) || '',
                        evidence_level: String(card.evidenceLevel || ''),
                        relationship_type: String(card.relationshipType || ''),
                        causality_claimed: card.causalityClaimed === true ? 'true' : 'false',
                        explanation: String(card.reasonText || ''),
                        evidence: (Array.isArray(card.evidenceLines) ? card.evidenceLines : []).join('；'),
                    };
                })
            ));
        };

        const revenueCockpitCsvCell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;

        const buildRevenueCockpitCsv = (model = {}) => {
            const headers = [
                ['order', '顺序'],
                ['section', '分区'],
                ['card', '卡片'],
                ['display', '页面显示'],
                ['unit', '单位'],
                ['source', '来源'],
                ['business_date', '业务日期'],
                ['verification_status', '验证状态'],
                ['scope', '口径'],
                ['missing_state', '缺失状态'],
                ['opportunity_key', '机会键'],
                ['rank', '机会顺序'],
                ['evidence_level', '证据等级'],
                ['relationship_type', '关系类型'],
                ['causality_claimed', '是否因果结论'],
                ['explanation', '说明'],
                ['evidence', '证据'],
            ];
            const rows = buildRevenueCockpitDownloadRows(model);
            return `\uFEFF${[
                headers.map(([, label]) => revenueCockpitCsvCell(label)).join(','),
                ...rows.map((row) => headers.map(([key]) => revenueCockpitCsvCell(row[key])).join(',')),
            ].join('\r\n')}`;
        };

        const buildRevenueCockpitDownloadPayload = (model = {}, fallbackHotelName = '') => {
            const rows = buildRevenueCockpitDownloadRows(model);
            if (!rows.length) {
                return { ok: false, rows, csv: '', fileName: '', message: '当前没有可见驾驶舱卡片可下载' };
            }
            const safeHotel = String(model.hotelName || fallbackHotelName || 'hotel')
                .replace(/[\\/:*?"<>|\s]+/g, '_')
                .slice(0, 40);
            return {
                ok: true,
                rows,
                csv: buildRevenueCockpitCsv(model),
                fileName: `经营驾驶舱_${safeHotel}_${model.businessDate || '日期缺失'}_${model.selectedPlatform || '平台缺失'}.csv`,
                message: `已按页面当前 ${rows.length} 张可见卡片顺序下载`,
            };
        };

        const buildRevenueCockpitQuestionDraft = (model = {}, hotelId = 0) => {
            const normalizedHotelId = Number(hotelId || 0);
            if (!model.canAskQuestion || !normalizedHotelId || !model.businessDate || !model.selectedPlatform) {
                return { ok: false, message: '当前酒店、平台或严格业务日期不完整，不能转为经营问题' };
            }
            const incompleteText = model.status === 'ready' ? '数据完整' : '存在数据缺口';
            return {
                ok: true,
                hotelId: String(normalizedHotelId),
                platform: String(model.selectedPlatform),
                businessDate: String(model.businessDate),
                decisionObject: 'channel',
                question: `${model.businessDate} ${model.selectedPlatformLabel}经营状态如何？当前${incompleteText}，最需要复核的异常、原因和人工动作是什么？`,
                notice: `已从经营驾驶舱带入：${model.selectedPlatformLabel} · ${model.businessDate}；尚未提交问题。`,
                message: '经营问题范围和问题草稿已带入，请人工确认后提交',
            };
        };

        const buildRevenueCockpitOverviewEndpoint = (hotelId, businessDate, platform) => {
            const params = new URLSearchParams({
                hotel_id: String(hotelId || ''),
                business_date: String(businessDate || ''),
                cockpit: '1',
            });
            if (String(platform || '') === 'all_ota') {
                params.set('platform', 'all_ota');
                params.set('enabled_channels', 'ctrip,meituan');
            } else if (platform) {
                params.set('platform', String(platform));
            }
            return `/revenue-ai/overview?${params.toString()}`;
        };

        const resolveRevenueCockpitOverviewResponse = (response = {}, expected = {}) => {
        if (Number(response.code || 0) !== 200) {
            return { ok: false, overview: null, message: response.message || '经营驾驶舱事实读取失败' };
        }
        const overview = response.data && typeof response.data === 'object' ? response.data : null;
        const asOfDate = resolveRevenueOverviewAsOfDate(overview);
        const factLayer = overview?.three_source_fact_layer || {};
        const strict = overview?.cockpit_strict_evidence || {};
        const platform = String(expected.platform || '').toLowerCase();
        const requiredPlatforms = platform === 'all_ota'
            ? ['ctrip', 'meituan']
            : (['ctrip', 'meituan'].includes(platform) ? [platform] : []);
        const canonical = resolveRevenueCockpitCanonicalViewModel({
            overview,
            hotelId: expected.hotelId,
            businessDate: expected.businessDate,
            platform,
        });
        if (!overview
            || !asOfDate.ok
            || !canonical.ok
            || (expected.asOfDate && asOfDate.asOfDate !== String(expected.asOfDate))
            || (expected.asOfDateContractVersion
                && asOfDate.contractVersion !== String(expected.asOfDateContractVersion))
            || String(overview.business_date || '') !== String(expected.businessDate || '')
            || Number(overview.hotel_id || factLayer?.hotel?.system_hotel_id || 0) !== Number(expected.hotelId || 0)
            || String(factLayer.business_date || expected.businessDate || '') !== String(expected.businessDate || '')
            || String(strict.contract_version || '') !== 'revenue_cockpit_strict_evidence.v1'
            || Number(strict.hotel_id || 0) !== Number(expected.hotelId || 0)
            || String(strict.business_date || '') !== String(expected.businessDate || '')
            || String(strict.platform || '').toLowerCase() !== platform
            || Number(strict.tenant_id || 0) <= 0
            || requiredPlatforms.length === 0
            || requiredPlatforms.some((item) => {
                const source = factLayer?.sources?.[`${item}_ota`] || {};
                const provenance = source?.source || {};
                const strictPlatform = strict?.platforms?.[item] || {};
                const acceptedIds = Array.isArray(strictPlatform.accepted_row_ids)
                    ? strictPlatform.accepted_row_ids.map(Number).filter((id) => id > 0)
                    : [];
                const metrics = strictPlatform.metrics && typeof strictPlatform.metrics === 'object'
                    ? strictPlatform.metrics
                    : {};
                const hasAcceptedStrictMetric = Object.values(metrics).some((metric) => metric
                    && metric.strict_readback === true
                    && Array.isArray(metric.accepted_row_ids)
                    && metric.accepted_row_ids.some((id) => acceptedIds.includes(Number(id))));
                return String(source.business_date || '') !== String(expected.businessDate || '')
                    || String(source.actual_business_date || '') !== String(expected.businessDate || '')
                    || String(provenance.platform || '') !== item
                    || String(provenance.table || '') !== 'online_daily_data'
                    || String(strictPlatform.business_date || '') !== String(expected.businessDate || '')
                    || acceptedIds.length === 0
                    || !hasAcceptedStrictMetric;
            })
        ) {
            return { ok: false, overview: null, message: asOfDate.ok
                ? (canonical.message || '经营驾驶舱回读的酒店、平台、业务日期、数据基准日或严格事实合同与当前筛选不一致')
                : asOfDate.message };
        }
        return {
            ok: true,
            overview,
            canonicalModel: canonical.model,
            canonicalDigest: canonical.digest,
            message: '',
        };
    };

        const resolveRevenueCockpitScopeResponse = (response = {}, hotelId = 0) => {
            if (Number(response.code || 0) !== 200) {
                return { ok: false, payload: null, message: response.message || '严格可用日期读取失败' };
            }
            const payload = response.data || {};
            if (String(payload.contract_version || '') !== 'operating_question_scope_options.v1'
                || Number(payload.hotel_id || 0) !== Number(hotelId || 0)
                || String(payload?.boundary?.strict_gate || '')
                    !== 'dual_ota_field_closure.v1:revenue_analysis_consumable'
                || String(payload?.boundary?.fact_authority || '')
                    !== 'trusted_ota_daily_fact_consumer.v1'
                || payload?.boundary?.silent_date_fallback !== false
            ) {
                return { ok: false, payload: null, message: '严格可用日期回读身份或日期回退边界不一致' };
            }
            return { ok: true, payload, message: '' };
        };

        const loadRevenueCockpitSnapshot = async ({
        hotelId = '',
        scopePayload = null,
        scopeHotelId = '',
        selectedPlatform = '',
        selectedDate = '',
        reloadScope = false,
        resetContext = false,
        resetDate = false,
        request,
        readScope,
        readOverview,
        isCurrent = () => true,
    } = {}) => {
        const scopeReader = readScope || ((id) => request(`/agent/operating-question-scopes?hotel_id=${encodeURIComponent(id)}`, {
            requestPolicy: { scope: 'action', force: true },
        }));
        const overviewReader = readOverview || (async (id, date, platform) => {
            const response = await request(buildRevenueCockpitOverviewEndpoint(id, date, platform), {
                requestPolicy: { scope: 'action', force: true },
            });
            const result = resolveRevenueCockpitOverviewResponse(response, {
                hotelId: id,
                businessDate: date,
                platform,
            });
            if (!result.ok) throw new Error(result.message);
            return result.overview;
        });
        let nextScope = scopePayload;
        if (reloadScope || String(scopeHotelId || '') !== String(hotelId || '') || !nextScope) {
            const scopeResult = resolveRevenueCockpitScopeResponse(await scopeReader(hotelId), hotelId);
            if (!isCurrent()) return { status: 'superseded' };
            if (!scopeResult.ok) throw new Error(scopeResult.message);
            nextScope = scopeResult.payload;
        }
        const selection = resolveRevenueCockpitScope({
            scopePayload: nextScope,
            requestedPlatform: resetContext ? '' : selectedPlatform,
            requestedDate: resetContext ? '' : selectedDate,
            resetDate,
            asOfDate: '',
        });
        if (!selection.selectedPlatform || !selection.selectedDate) {
            return {
                status: 'empty',
                scopePayload: nextScope,
                selection,
                overview: null,
                comparisonOverview: null,
                sameWeekdayOverview: null,
            };
        }
        const [overview, comparisonOverview, sameWeekdayOverview] = await Promise.all([
            overviewReader(hotelId, selection.selectedDate, selection.selectedPlatform),
            selection.previousDate
                ? overviewReader(hotelId, selection.previousDate, selection.selectedPlatform)
                : Promise.resolve(null),
            selection.sameWeekdayDate && selection.sameWeekdayDate !== selection.previousDate
                ? overviewReader(hotelId, selection.sameWeekdayDate, selection.selectedPlatform)
                : (selection.sameWeekdayDate && selection.sameWeekdayDate === selection.previousDate
                    ? Promise.resolve('__reuse_previous__')
                    : Promise.resolve(null)),
        ]);
        if (!isCurrent()) return { status: 'superseded' };
        const currentAsOfDate = resolveRevenueOverviewAsOfDate(overview);
        const comparisonOverviews = [comparisonOverview, sameWeekdayOverview]
            .filter(item => item && item !== '__reuse_previous__');
        if (!currentAsOfDate.ok || comparisonOverviews.some((item) => {
            const candidate = resolveRevenueOverviewAsOfDate(item);
            return !candidate.ok
                || candidate.asOfDate !== currentAsOfDate.asOfDate
                || candidate.contractVersion !== currentAsOfDate.contractVersion;
        })) {
            throw new Error('经营驾驶舱当前与对比总览的数据基准日合同不一致');
        }
        return {
            status: overview ? 'ready' : 'empty',
            scopePayload: nextScope,
            selection,
            overview,
            comparisonOverview,
            sameWeekdayOverview: sameWeekdayOverview === '__reuse_previous__'
                ? comparisonOverview
                : sameWeekdayOverview,
        };
    };

        const revenueDecisionSnapshotParams = (model = {}, hotelId = 0, snapshotId = 0) => {
        const params = new URLSearchParams({
            hotel_id: String(Number(hotelId || model.hotelId || 0)),
            business_date: String(model.businessDate || ''),
            platform: String(model.selectedPlatform || ''),
            as_of_date: String(model.asOfDate || ''),
            as_of_date_contract_version: String(model.asOfDateContractVersion || ''),
        });
        if (Number(snapshotId || 0) > 0) params.set('snapshot_id', String(Number(snapshotId)));
        return params;
    };

    const resolveRevenueDecisionSnapshot = (payload = {}, expected = {}) => {
        const wrapper = payload && typeof payload === 'object' ? payload : {};
        if (wrapper.found === false) {
            return String(wrapper.persistence_status || '') === 'not_saved'
                ? { ok: true, status: 'not_saved', snapshot: null, message: '当前范围尚未保存收益决策快照' }
                : { ok: false, status: 'invalid', snapshot: null, message: '快照未保存状态缺少完整回读凭证' };
        }
        const snapshot = wrapper.snapshot && typeof wrapper.snapshot === 'object'
            ? wrapper.snapshot
            : wrapper;
        const model = snapshot.visible_model && typeof snapshot.visible_model === 'object'
            ? snapshot.visible_model
            : {};
        const digestsValid = ['visible_model_digest', 'evidence_digest', 'content_digest'].every(
            (key) => /^[a-f0-9]{64}$/.test(String(snapshot[key] || '')),
        );
        const opportunities = Array.isArray(model.opportunities) ? model.opportunities : [];
        if (Number(snapshot.id || 0) <= 0
            || String(snapshot.contract_version || '') !== 'revenue_decision_snapshot.v1'
            || String(snapshot.persistence_status || wrapper.persistence_status || '') !== 'readback_verified'
            || !digestsValid
            || String(model.contractVersion || '') !== 'revenue_daily_cockpit.v2'
            || Number(snapshot.system_hotel_id || 0) !== Number(expected.hotelId || model.hotelId || 0)
            || Number(model.hotelId || 0) !== Number(snapshot.system_hotel_id || 0)
            || String(snapshot.platform || '') !== String(expected.platform || model.selectedPlatform || '')
            || String(model.selectedPlatform || '') !== String(snapshot.platform || '')
            || String(snapshot.business_date || '') !== String(expected.businessDate || model.businessDate || '')
            || String(model.businessDate || '') !== String(snapshot.business_date || '')
            || String(snapshot.as_of_date || '') !== String(expected.asOfDate || model.asOfDate || '')
            || String(model.asOfDate || '') !== String(snapshot.as_of_date || '')
            || String(snapshot.as_of_date_contract_version || '') !== String(expected.asOfDateContractVersion || model.asOfDateContractVersion || '')
            || String(model.asOfDateContractVersion || '') !== String(snapshot.as_of_date_contract_version || '')
            || (expected.visibleModelDigest
                && String(snapshot.visible_model_digest || '') !== String(expected.visibleModelDigest))
            || opportunities.length !== 8
            || new Set(opportunities.map((item) => String(item?.opportunityKey || ''))).size !== 8
        ) {
            return { ok: false, status: 'invalid', snapshot: null, message: '收益决策快照身份、摘要或八类机会合同不一致' };
        }
        if (Number(expected.snapshotId || 0) > 0
            && Number(snapshot.id) !== Number(expected.snapshotId)
        ) {
            return { ok: false, status: 'invalid', snapshot: null, message: '收益决策快照 ID 回读不一致' };
        }
        return {
            ok: true,
            status: String(snapshot.evidence_identity_status || 'not_checked'),
            snapshot,
            message: String(snapshot.evidence_identity_status || '') === 'stale_current_evidence'
                ? `快照 #${snapshot.id} 已精确回读，但当前事实证据身份已变化`
                : `快照 #${snapshot.id} 已按同一内容与证据身份精确回读`,
        };
    };

    const saveRevenueDecisionSnapshotWithReadback = async ({
        request,
        model = {},
        modelDigest = '',
        hotelId = 0,
    } = {}) => {
        const normalizedHotelId = Number(hotelId || model.hotelId || 0);
        const normalizedModelDigest = String(modelDigest || '').trim().toLowerCase();
        if (typeof request !== 'function'
            || !model.canSaveSnapshot
            || !normalizedHotelId
            || String(model.contractVersion || '') !== 'revenue_daily_cockpit.v2'
            || !/^[a-f0-9]{64}$/.test(normalizedModelDigest)
            || !model.businessDate
            || !revenueAiIsoDate(model.asOfDate)
            || String(model.asOfDateContractVersion || '') !== REVENUE_OVERVIEW_AS_OF_DATE_CONTRACT_VERSION
            || !['ctrip', 'meituan', 'all_ota'].includes(String(model.selectedPlatform || ''))
        ) {
            return { ok: false, snapshot: null, message: model.actionDisabledReason || '当前严格事实不足，不能保存收益决策快照' };
        }
        const saved = await request('/revenue-ai/cockpit/decision-snapshots', {
            method: 'POST',
            body: JSON.stringify({
                hotel_id: normalizedHotelId,
                business_date: model.businessDate,
                platform: model.selectedPlatform,
                as_of_date: model.asOfDate,
                as_of_date_contract_version: model.asOfDateContractVersion,
                visible_model: model,
                visible_model_digest: normalizedModelDigest,
            }),
        });
        if (Number(saved?.code || 0) !== 200) throw new Error(saved?.message || '收益决策快照保存失败');
        const savedResult = resolveRevenueDecisionSnapshot(saved.data, {
            hotelId: normalizedHotelId,
            businessDate: model.businessDate,
            platform: model.selectedPlatform,
            asOfDate: model.asOfDate,
            asOfDateContractVersion: model.asOfDateContractVersion,
            visibleModelDigest: normalizedModelDigest,
        });
        if (!savedResult.ok || !savedResult.snapshot) throw new Error(savedResult.message);
        const params = revenueDecisionSnapshotParams(model, normalizedHotelId, savedResult.snapshot.id);
        const readback = await request(`/revenue-ai/cockpit/decision-snapshots?${params.toString()}`, {
            requestPolicy: { scope: 'action', force: true },
        });
        if (Number(readback?.code || 0) !== 200) throw new Error(readback?.message || '收益决策快照精确回读失败');
        const exact = resolveRevenueDecisionSnapshot(readback.data, {
            snapshotId: savedResult.snapshot.id,
            hotelId: normalizedHotelId,
            businessDate: model.businessDate,
            platform: model.selectedPlatform,
            asOfDate: model.asOfDate,
            asOfDateContractVersion: model.asOfDateContractVersion,
            visibleModelDigest: normalizedModelDigest,
        });
        if (!exact.ok || !exact.snapshot
            || exact.snapshot.content_digest !== savedResult.snapshot.content_digest
            || exact.snapshot.evidence_digest !== savedResult.snapshot.evidence_digest
            || exact.snapshot.visible_model_digest !== savedResult.snapshot.visible_model_digest
        ) {
            throw new Error(exact.message || '收益决策快照保存与回读摘要不一致');
        }
        return { ok: true, status: exact.status, snapshot: exact.snapshot, message: exact.message };
    };

    const restoreRevenueDecisionSnapshotWithReadback = async ({ request, model = {}, hotelId = 0 } = {}) => {
        const normalizedHotelId = Number(hotelId || model.hotelId || 0);
        if (typeof request !== 'function'
            || !normalizedHotelId
            || !model.businessDate
            || !revenueAiIsoDate(model.asOfDate)
            || String(model.asOfDateContractVersion || '') !== REVENUE_OVERVIEW_AS_OF_DATE_CONTRACT_VERSION
            || !['ctrip', 'meituan', 'all_ota'].includes(String(model.selectedPlatform || ''))
        ) {
            return { ok: false, status: 'invalid', snapshot: null, message: '当前范围不完整，无法恢复收益决策快照' };
        }
        const params = revenueDecisionSnapshotParams(model, normalizedHotelId);
        const response = await request(`/revenue-ai/cockpit/decision-snapshots?${params.toString()}`, {
            requestPolicy: { scope: 'action', force: true },
        });
        if (Number(response?.code || 0) !== 200) throw new Error(response?.message || '收益决策快照恢复失败');
        return resolveRevenueDecisionSnapshot(response.data, {
            hotelId: normalizedHotelId,
            businessDate: model.businessDate,
            platform: model.selectedPlatform,
            asOfDate: model.asOfDate,
            asOfDateContractVersion: model.asOfDateContractVersion,
        });
    };

        const createRevenueOpportunityPendingApprovalWithReadback = async ({
            request,
            snapshot = null,
            opportunityKey = '',
        } = {}) => {
            const stored = snapshot && typeof snapshot === 'object' ? snapshot : {};
            const model = stored.visible_model && typeof stored.visible_model === 'object'
                ? stored.visible_model
                : {};
            const opportunity = (Array.isArray(model.opportunities) ? model.opportunities : []).find(
                (item) => String(item?.opportunityKey || '') === String(opportunityKey || ''),
            );
            if (typeof request !== 'function'
                || Number(stored.id || 0) <= 0
                || !opportunity
                || opportunity.canCreatePendingApproval !== true
                || String(stored.evidence_identity_status || '') !== 'matched_current'
            ) {
                return { ok: false, intent: null, message: '请先保存并精确回读当前证据身份，再选择一条可送审建议' };
            }
            const response = await request(`/revenue-ai/cockpit/decision-snapshots/${Number(stored.id)}/pending-approval`, {
                method: 'POST',
                body: JSON.stringify({
                    hotel_id: Number(stored.system_hotel_id),
                    business_date: String(stored.business_date),
                    platform: String(stored.platform),
                    opportunity_key: String(opportunity.opportunityKey),
                }),
            });
            if (Number(response?.code || 0) !== 200) throw new Error(response?.message || '经营机会送审失败');
            const payload = response.data || {};
            const savedResult = resolveRevenueCockpitPendingApprovalSave(payload);
            const recommendation = payload.opportunity || {};
            if (!savedResult.ok
                || savedResult.status !== 'pending_approval'
                || savedResult.taskCount !== 0
                || Number(payload?.snapshot?.id || 0) !== Number(stored.id)
                || String(payload?.snapshot?.content_digest || '') !== String(stored.content_digest || '')
                || String(recommendation.opportunity_key || '') !== String(opportunity.opportunityKey)
                || !/^[a-f0-9]{64}$/.test(String(recommendation.recommendation_digest || ''))
            ) {
                throw new Error(savedResult.message || '经营机会与收益决策快照绑定不一致');
            }
            const readback = await request(`/operation/execution-intents/${savedResult.intentId}`);
            if (Number(readback?.code || 0) !== 200) throw new Error(readback?.message || '待审批行动精确回读失败');
            const exact = resolveRevenueCockpitPendingApprovalReadback(readback.data, {
                intentId: savedResult.intentId,
                tenantId: Number(stored.tenant_id),
                hotelId: Number(stored.system_hotel_id),
                platform: String(stored.platform),
                businessDate: String(stored.business_date),
                objectType: 'operation_checklist',
                actionType: 'human_reviewed_operating_check',
                requirePending: true,
                status: 'pending_approval',
                taskCount: 0,
                sourceModule: 'revenue_cockpit_action',
                decisionContext: {
                    snapshotId: Number(stored.id),
                    snapshotDigest: String(stored.content_digest || ''),
                    opportunityKey: String(opportunity.opportunityKey),
                    opportunityDigest: String(recommendation.recommendation_digest || ''),
                },
            });
            const actionCard = exact.intent?.target_value?.action_card || exact.intent?.evidence?.action_card || {};
            if (!exact.ok
                || String(actionCard.contract_version || '') !== 'operation_action_card.v1'
                || !/^[a-f0-9]{64}$/.test(String(actionCard.content_digest || ''))
                || String(actionCard?.metric_contract?.target_type || '') !== 'observation'
                || String(actionCard?.action?.title || '') !== String(recommendation.title || '')
                || String(actionCard?.action?.description || '') !== String(recommendation.action_text || '')
            ) {
                throw new Error(exact.message || '经营机会没有精确回读同一受管行动生命周期');
            }
            return {
                ok: true,
                intent: exact.intent,
                opportunity,
                message: `${opportunity.title}已转为 pending_approval；未审批、未调价、未写 OTA`,
            };
        };

        const resolveRevenueCockpitPendingApprovalSave = (payload = {}) => {
            const savedIntent = payload?.execution_intent || {};
            const intentId = Number(savedIntent.id || 0);
            const tasks = savedIntent.tasks;
            const status = String(payload?.status || savedIntent.status || '');
            const taskCount = Number(payload?.execution_task_count ?? (Array.isArray(tasks) ? tasks.length : -1));
            if (!intentId
                || status !== 'pending_approval'
                || String(savedIntent.status || '') !== 'pending_approval'
                || String(payload?.persistence_status || '') !== 'readback_verified'
                || !Array.isArray(tasks)
                || !Number.isInteger(taskCount)
                || taskCount !== 0
                || tasks.length !== taskCount
                || payload?.execution_task_created !== false
                || payload?.external_action_triggered !== false
            ) {
                return { ok: false, intentId: 0, message: '待审批行动未返回“已回读且未执行”的完整凭证' };
            }
            return {
                ok: true,
                intentId,
                status,
                taskCount,
                reused: payload?.reused_existing_intent === true,
                message: `待审批行动 #${intentId} 已精确回读；未创建执行任务，也未写 OTA`,
            };
        };

        const resolveRevenueCockpitPendingApprovalReadback = (intent = {}, expected = {}) => {
            if (!Array.isArray(intent.tasks)) {
                return { ok: false, intent: null, message: '运营行动回读缺少真实任务数组' };
            }
            const tasks = intent.tasks;
            const allowedSources = ['revenue_cockpit_action', 'operating_question', 'operating_loop_approval'];
            const requirePending = expected.requirePending !== false;
            const expectedStatus = String(expected.status || '');
            const expectedTaskCount = Number(expected.taskCount);
            const actionCard = intent?.target_value?.action_card || intent?.evidence?.action_card || {};
            const trace = actionCard?.trace || {};
            const decisionContext = expected?.decisionContext || null;
            if (Number(intent.id || 0) !== Number(expected.intentId || 0)
                || Number(intent.tenant_id || 0) <= 0
                || (Number(expected.tenantId || 0) > 0
                    && Number(intent.tenant_id || 0) !== Number(expected.tenantId || 0))
                || Number(intent.hotel_id || 0) !== Number(expected.hotelId || 0)
                || !['ctrip', 'meituan', 'all_ota'].includes(String(intent.platform || ''))
                || (expected.platform && String(intent.platform || '') !== String(expected.platform))
                || !allowedSources.includes(String(intent.source_module || ''))
                || (expected.sourceModule && String(intent.source_module || '') !== String(expected.sourceModule))
                || String(intent.object_type || '') !== String(expected.objectType || 'operation_checklist')
                || String(intent.action_type || '') !== String(expected.actionType || 'human_reviewed_operating_check')
                || String(intent.date_start || '') !== String(expected.businessDate || '')
                || String(intent.date_end || '') !== String(expected.businessDate || '')
                || !String(intent.status || '')
                || (requirePending && String(intent.status || '') !== 'pending_approval')
                || (requirePending && tasks.length !== 0)
                || (!requirePending && expectedStatus && String(intent.status || '') !== expectedStatus)
                || (!requirePending && Number.isInteger(expectedTaskCount) && expectedTaskCount >= 0
                    && tasks.length !== expectedTaskCount)
                || !revenueCockpitTaskCardinalityIsValid(String(intent.status || ''), tasks)
                || String(actionCard.contract_version || '') !== 'operation_action_card.v1'
                || !/^[a-f0-9]{64}$/.test(String(actionCard.content_digest || ''))
                || (decisionContext && (
                    Number(trace.decision_snapshot_id || 0) !== Number(decisionContext.snapshotId || 0)
                    || String(trace.decision_snapshot_digest || '') !== String(decisionContext.snapshotDigest || '')
                    || String(trace.opportunity_key || '') !== String(decisionContext.opportunityKey || '')
                    || String(trace.opportunity_digest || '') !== String(decisionContext.opportunityDigest || '')
                ))
                || (Array.isArray(expected.taskSignatures)
                    && JSON.stringify(tasks.map(task => ({
                        id: Number(task?.id || 0),
                        status: String(task?.status || ''),
                        result_status: String(task?.result_status || ''),
                    })).sort((left, right) => left.id - right.id)) !== JSON.stringify(expected.taskSignatures))
            ) {
                return { ok: false, intent: null, message: '待审批行动保存与精确回读身份不一致' };
            }
            return { ok: true, intent, message: '' };
        };

        const revenueCockpitTaskCardinalityIsValid = (intentStatus = '', tasks = []) => {
            if (!Array.isArray(tasks) || tasks.some(task => !Number.isInteger(Number(task?.id || 0)) || Number(task.id) <= 0)) {
                return false;
            }
            const status = String(intentStatus || '').trim().toLowerCase();
            if (['draft', 'pending_approval'].includes(status)) return tasks.length === 0;
            if (status === 'approved') return tasks.length === 1;
            if (['cancelled', 'rejected'].includes(status)) return tasks.length <= 1;
            return false;
        };

        const restoreRevenueCockpitPendingApprovalWithReadback = async ({
            request,
            model = {},
            hotelId = 0,
            snapshot = null,
        } = {}) => {
            const normalizedHotelId = Number(hotelId || 0);
            const tenantId = Number(model.tenantId || 0);
            const businessDate = String(model.businessDate || '').trim();
            const platform = String(model.selectedPlatform || '').trim().toLowerCase();
            if (typeof request !== 'function'
                || !normalizedHotelId
                || !tenantId
                || !businessDate
                || !['ctrip', 'meituan', 'all_ota'].includes(platform)
            ) {
                return { ok: false, intent: null, message: '当前经营驾驶舱范围不完整，无法恢复已保存行动' };
            }
            const params = new URLSearchParams({
                hotel_id: String(normalizedHotelId),
                business_date: businessDate,
                platform,
            });
            const restored = await request(`/revenue-ai/cockpit/pending-approval?${params.toString()}`);
            if (Number(restored?.code || 0) !== 200) {
                throw new Error(restored?.message || '已保存运营行动恢复失败');
            }
            const payload = restored?.data || {};
            const scope = payload?.cockpit_scope || {};
            if (Number(scope.hotel_id || 0) !== normalizedHotelId
                || Number(scope.tenant_id || 0) !== tenantId
                || String(scope.business_date || '') !== businessDate
                || String(scope.platform || '') !== platform
            ) {
                throw new Error('已保存运营行动恢复范围与当前驾驶舱不一致');
            }
            if (payload?.found === false) {
                if (String(payload?.status || '') !== 'not_saved'
                    || String(payload?.persistence_status || '') !== 'not_saved'
                    || payload?.execution_intent !== null
                    || (Array.isArray(payload?.execution_intents) && payload.execution_intents.length !== 0)
                    || Number(payload?.execution_task_count || 0) !== 0
                ) {
                    throw new Error('未保存状态缺少完整的只读回读凭证');
                }
                return {
                    ok: true,
                    status: 'not_saved',
                    intent: null,
                    message: '当前事实范围尚未保存运营行动',
                };
            }

            const scopedIntents = Array.isArray(payload?.execution_intents)
                ? payload.execution_intents
                : [payload?.execution_intent || {}];
            const scopedIntent = payload?.execution_intent || scopedIntents[0] || {};
            const intentId = Number(scopedIntent.id || 0);
            const taskCount = Number(payload?.execution_task_count);
            const intentCount = Number(payload?.execution_intent_count ?? scopedIntents.length);
            const scopedIntentIds = scopedIntents.map(intent => Number(intent?.id || 0));
            if (payload?.found !== true
                || String(payload?.persistence_status || '') !== 'readback_verified'
                || !intentId
                || !Array.isArray(scopedIntents)
                || scopedIntents.length < 1
                || !Number.isInteger(intentCount)
                || intentCount !== scopedIntents.length
                || scopedIntentIds.some(id => id <= 0)
                || new Set(scopedIntentIds).size !== scopedIntentIds.length
                || Number(scopedIntents[0]?.id || 0) !== intentId
                || !Number.isInteger(taskCount)
                || taskCount < 0
                || !Array.isArray(scopedIntent.tasks)
                || scopedIntent.tasks.length !== taskCount
                || String(scopedIntent.status || '') !== String(payload?.status || '')
                || !revenueCockpitTaskCardinalityIsValid(String(scopedIntent.status || ''), scopedIntent.tasks)
            ) {
                throw new Error('已保存运营行动未返回完整的范围回读凭证');
            }

            const exactIntents = await Promise.all(scopedIntents.map(async (candidate, index) => {
                if (!Array.isArray(candidate?.tasks) || !String(candidate?.status || '')) {
                    throw new Error('已保存运营行动列表缺少真实状态或任务数组');
                }
                if (!revenueCockpitTaskCardinalityIsValid(String(candidate.status || ''), candidate.tasks)) {
                    throw new Error('已保存运营行动任务基数与生命周期状态不一致');
                }
                const candidateId = Number(candidate.id || 0);
                const readback = await request(`/operation/execution-intents/${candidateId}`);
                if (Number(readback?.code || 0) !== 200) {
                    throw new Error(readback?.message || '已保存运营行动精确回读失败');
                }
                const exactResult = resolveRevenueCockpitPendingApprovalReadback(readback.data, {
                    intentId: candidateId,
                    tenantId,
                    hotelId: normalizedHotelId,
                    platform,
                    businessDate,
                    objectType: 'operation_checklist',
                    actionType: 'human_reviewed_operating_check',
                    requirePending: false,
                    status: String(candidate.status || ''),
                    taskCount: candidate.tasks.length,
                    taskSignatures: candidate.tasks.map(task => ({
                        id: Number(task?.id || 0),
                        status: String(task?.status || ''),
                        result_status: String(task?.result_status || ''),
                    })).sort((left, right) => left.id - right.id),
                });
                if (!exactResult.ok) throw new Error(exactResult.message);
                const actionCard = exactResult.intent?.target_value?.action_card
                    || exactResult.intent?.evidence?.action_card
                    || {};
                const trace = actionCard?.trace || {};
                const opportunityKey = String(trace.opportunity_key || '').trim();
                if (opportunityKey) {
                    const storedSnapshot = snapshot && typeof snapshot === 'object' ? snapshot : {};
                    const storedModel = storedSnapshot.visible_model && typeof storedSnapshot.visible_model === 'object'
                        ? storedSnapshot.visible_model
                        : {};
                    const opportunity = (Array.isArray(storedModel.opportunities) ? storedModel.opportunities : []).find(
                        item => String(item?.opportunityKey || '') === opportunityKey,
                    );
                    if (!opportunity
                        || String(storedSnapshot.evidence_identity_status || '') !== 'matched_current'
                        || Number(trace.decision_snapshot_id || 0) !== Number(storedSnapshot.id || 0)
                        || String(trace.decision_snapshot_digest || '') !== String(storedSnapshot.content_digest || '')
                        || !/^[a-f0-9]{64}$/.test(String(trace.opportunity_digest || ''))
                        || String(actionCard?.action?.title || '') !== String(opportunity.title || '')
                        || String(actionCard?.action?.description || '') !== String(opportunity.recommendedCheckAction || '')
                    ) {
                        throw new Error('已保存经营机会与当前收益决策快照身份不一致');
                    }
                }
                if (index === 0 && candidate.tasks.length !== taskCount) {
                    throw new Error('主运营行动任务数与范围回读不一致');
                }
                return exactResult.intent;
            }));
            return {
                ok: true,
                status: 'readback_verified',
                intent: exactIntents[0],
                intents: exactIntents,
                message: `已恢复 ${exactIntents.length} 个运营行动及各自真实任务状态`,
            };
        };

        return Object.freeze({
            resolveRevenueCockpitScope,
            resolveRevenueCockpitCanonicalViewModel,
            buildRevenueCockpitModel,
            buildRevenueCockpitDownloadRows,
            buildRevenueCockpitCsv,
            buildRevenueCockpitDownloadPayload,
            buildRevenueCockpitQuestionDraft,
            buildRevenueCockpitOverviewEndpoint,
            resolveRevenueCockpitOverviewResponse,
            resolveRevenueCockpitScopeResponse,
            loadRevenueCockpitSnapshot,
            resolveRevenueDecisionSnapshot,
            saveRevenueDecisionSnapshotWithReadback,
            restoreRevenueDecisionSnapshotWithReadback,
            createRevenueOpportunityPendingApprovalWithReadback,
            resolveRevenueCockpitPendingApprovalSave,
            resolveRevenueCockpitPendingApprovalReadback,
            revenueCockpitTaskCardinalityIsValid,
            restoreRevenueCockpitPendingApprovalWithReadback,
        });
    };

    window.SUXI_REVENUE_COCKPIT_STATIC = Object.freeze({
        create: createRevenueCockpitStatic,
    });
})();
