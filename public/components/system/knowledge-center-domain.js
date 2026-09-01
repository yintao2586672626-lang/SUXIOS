(() => {
    'use strict';

    const create = (context = {}) => {
        const {
            API_BASE,
            buildKnowledgeImportRequestBody,
            captureAuthSession,
            clearAuthSessionIfCurrent,
            computed,
            currentPage,
            defaultKnowledgeCenterHotelId,
            defaultKnowledgeExperienceChunk,
            formatDate,
            formatKnowledgeJson,
            isAuthSessionCurrent,
            ensureOperationStaticReady,
            knowledgeCenterBatchDeleting,
            knowledgeCenterChunkForm,
            knowledgeCenterChunks,
            knowledgeCenterDisplayLabel,
            knowledgeCenterFilter,
            knowledgeCenterForm,
            knowledgeCenterImportDocumentError,
            knowledgeCenterImportDocumentNotice,
            knowledgeCenterImportForm,
            knowledgeCenterImportPreviewRaw,
            knowledgeCenterImportReading,
            knowledgeCenterImportSelectedFile,
            knowledgeCenterImportSourceDocument,
            knowledgeCenterImporting,
            knowledgeCenterLoading,
            knowledgeCenterPagination,
            knowledgeCenterSelectedUnit,
            knowledgeCenterUnits,
            knowledgeDistillationFullTrain,
            knowledgeDistillationMaxBatches,
            knowledgeDistillationResult,
            knowledgeDistillationRunning,
            knowledgeDocumentFileInput,
            knowledgeDocumentTextarea,
            knowledgeImportErrorMessage,
            knowledgeImportSuccessMessage,
            knowledgePromotionAction,
            knowledgePromotionApprovalGate,
            knowledgePromotionCandidates,
            knowledgePromotionError,
            knowledgePromotionEvents,
            knowledgePromotionForm,
            knowledgePromotionHotelId,
            knowledgePromotionLoading,
            knowledgePromotionMemories,
            knowledgePromotionSelectedCandidate,
            knowledgePromotionSourceCandidates,
            knowledgePromotionSourceVersions,
            knowledgePromotionWorkflowStatus,
            knowledgeSopCandidateAction,
            knowledgeSopCandidateEligibleMemories,
            knowledgeSopCandidateError,
            knowledgeSopCandidateForm,
            knowledgeSopCandidateReadback,
            knowledgeSopTaskCreatingChunkId,
            loadOperationActions,
            nextTick,
            openWorkflowFormDialog,
            operatingNetworkAction,
            operatingNetworkData,
            operatingNetworkError,
            operatingNetworkExecutionIntent,
            operatingNetworkHotelId,
            operatingNetworkLastReplication,
            operatingNetworkLoading,
            operatingNetworkProfileForm,
            operatingNetworkProfilePreview,
            operatingNetworkReplicationForm,
            operatingNetworkReviewForm,
            operatingNetworkReviews,
            operationFilters,
            operationStatic,
            parseKnowledgeTags,
            readOperationExecutionIntent,
            request,
            requireOperationStatic,
            requireSystemStatic,
            revenueAiExecutionFocus,
            sameAiGovernanceJson,
            selectedKnowledgeCenterUnitIds,
            showKnowledgeCenterChunksModal,
            showKnowledgeCenterImportModal,
            showKnowledgeCenterUnitModal,
            showToast,
        } = context;

        let knowledgeCenterImportActionEpoch = 0;
        let knowledgePromotionLoadEpoch = 0;
        let knowledgePromotionActionEpoch = 0;

            const normalizeKnowledgeChunkContent = (value) => {
                if (value && typeof value === 'object') return value;
                if (typeof value === 'string') {
                    try {
                        const parsed = JSON.parse(value);
                        return parsed && typeof parsed === 'object' ? parsed : { text: value };
                    } catch (error) {
                        return { text: value };
                    }
                }
                return {};
            };

            const knowledgeChunkTextList = (value, limit = 4) => {
                if (Array.isArray(value)) {
                    return value.flatMap(item => knowledgeChunkTextList(item, limit)).filter(Boolean).slice(0, limit);
                }
                if (value && typeof value === 'object') {
                    return Object.values(value).flatMap(item => knowledgeChunkTextList(item, limit)).filter(Boolean).slice(0, limit);
                }
                const text = String(value || '').trim();
                return text ? [text] : [];
            };

            const knowledgeCenterDisplayList = (value, limit = 12) => knowledgeChunkTextList(value, limit)
                .map(item => knowledgeCenterDisplayLabel(item, '其他分类'))
                .filter((item, index, rows) => item && rows.indexOf(item) === index)
                .join('、');

            const knowledgeChunkProfileRows = (profile) => {
                if (!profile || typeof profile !== 'object' || Array.isArray(profile)) return [];
                return Object.entries(profile)
                    .map(([label, value]) => ({
                        label,
                        value: Array.isArray(value) ? value.join('、') : String(value ?? '').trim(),
                    }))
                    .filter(row => row.value)
                    .slice(0, 6);
            };

            const knowledgeChunkView = (chunk) => {
                const content = normalizeKnowledgeChunkContent(chunk?.content);
                const distilled = normalizeKnowledgeChunkContent(content.ai_distilled || {});
                const sourceData = Object.keys(distilled).length > 0 ? distilled : content;
                const rawText = String(content.raw_text || distilled.raw_text || content.text || '').trim();
                const taskTemplate = content.task_template && typeof content.task_template === 'object'
                    ? content.task_template
                    : null;
                const sopFields = (Array.isArray(content.fields) ? content.fields : [])
                    .map(field => ({
                        label: String(field?.label || '').trim(),
                        value: knowledgeChunkTextList(field?.content, 20).join('；'),
                    }))
                    .filter(field => field.label || field.value);
                const worksheetHeaders = knowledgeChunkTextList(content.headers, 30);
                const worksheetRows = (Array.isArray(content.rows) ? content.rows : [])
                    .slice(0, 30)
                    .map(row => (Array.isArray(row) ? row : [row]).map(cell => knowledgeChunkTextList(cell, 10).join('；')));
                const governanceBoundaries = knowledgeChunkTextList(content.governance, 12);
                const metaRows = [
                    { label: '模块', value: content.module_name || '' },
                    { label: '章节', value: content.section_title || '' },
                    { label: '岗位', value: knowledgeChunkTextList(content.roles, 12).join('、') },
                    { label: '场景', value: knowledgeChunkTextList(content.scenes, 12).join('、') },
                    { label: '平台', value: knowledgeCenterDisplayList(content.platforms, 12) },
                    { label: '来源版本', value: content.seed_version || '' },
                    { label: '证据级别', value: knowledgeCenterDisplayLabel(content.evidence_level, '其他证据级别') },
                    { label: '适用边界', value: knowledgeCenterDisplayLabel(content.scope, '其他适用边界') },
                    { label: '来源', value: knowledgeChunkTextList(content.source_refs, 6).join('；') || content.source || sourceData.source || '' },
                    { label: '类型', value: knowledgeCenterDisplayLabel(content.material_type || sourceData.material_type || content.content_type || chunk?.type || '', '其他类型') },
                    { label: '模型', value: content.model_key || sourceData.model_key || '' },
                    { label: '导入时间', value: content.imported_at || sourceData.distilled_at || '' },
                ].filter(row => row.value);

                return {
                    title: String(content.title || content.module_name || '').trim(),
                    summary: String(sourceData.summary || content.summary || content.module_summary || content.note || content.extra || content.text || '').trim(),
                    profileRows: knowledgeChunkProfileRows(sourceData.hotel_profile || content.hotel_profile),
                    facts: knowledgeChunkTextList(sourceData.facts || content.facts),
                    actions: knowledgeChunkTextList(sourceData.actions || content.actions),
                    boundaries: [
                        ...knowledgeChunkTextList(sourceData.boundaries || content.boundaries, 12),
                        ...governanceBoundaries,
                    ].filter((item, index, rows) => rows.indexOf(item) === index).slice(0, 12),
                    metaRows,
                    sopFields,
                    steps: knowledgeChunkTextList(taskTemplate?.steps, 20),
                    acceptanceCriteria: knowledgeChunkTextList(taskTemplate?.acceptance_criteria, 20),
                    worksheetHeaders,
                    worksheetRows,
                    rawText,
                    fullJson: formatKnowledgeJson(content),
                    taskTemplate,
                    platforms: knowledgeChunkTextList(content.platforms, 20)
                        .map(item => String(item || '').trim().toLowerCase())
                        .filter(Boolean),
                    contentKey: String(content.content_key || '').trim(),
                };
            };

            const knowledgeChunkMatchesCurrentFilter = (chunk) => {
                const content = normalizeKnowledgeChunkContent(chunk?.content);
                const exact = (field, value) => String(field || '').trim().toLowerCase() === String(value || '').trim().toLowerCase();
                const listHas = (field, value) => knowledgeChunkTextList(field, 100)
                    .some(item => exact(item, value));
                const filter = knowledgeCenterFilter.value || {};
                if (filter.module && !exact(content.module_id, filter.module)) return false;
                if (filter.role && !listHas(content.roles, filter.role)) return false;
                if (filter.scene && !listHas(content.scenes, filter.scene)) return false;
                if (filter.platform && !listHas(content.platforms, filter.platform)) return false;
                if (filter.evidence_level && !exact(content.evidence_level, filter.evidence_level)) return false;
                if (filter.version && !exact(content.seed_version, filter.version)) return false;
                const keyword = String(filter.keyword || '').trim().toLowerCase();
                if (keyword && !JSON.stringify(content).toLowerCase().includes(keyword)) return false;
                return true;
            };

            const knowledgeCenterVisibleChunks = computed(() => knowledgeCenterChunks.value.filter(knowledgeChunkMatchesCurrentFilter));

            const parseKnowledgeContent = (raw) => {
                const text = String(raw || '').trim();
                if (!text) return { text: '' };
                try {
                    return JSON.parse(text);
                } catch (error) {
                    return { text };
                }
            };

            const loadKnowledgeCenter = async ({ hotelId = '' } = {}) => {
                knowledgeCenterLoading.value = true;
                try {
                    const params = new URLSearchParams({
                        page: String(knowledgeCenterPagination.value.page || 1),
                        page_size: String(knowledgeCenterPagination.value.page_size || 10),
                    });
                    if (knowledgeCenterFilter.value.keyword) params.append('keyword', knowledgeCenterFilter.value.keyword.trim());
                    if (knowledgeCenterFilter.value.status) params.append('status', knowledgeCenterFilter.value.status);
                    if (knowledgeCenterFilter.value.tag) params.append('tag', knowledgeCenterFilter.value.tag.trim());
                    if (knowledgeCenterFilter.value.source) params.append('source', knowledgeCenterFilter.value.source.trim());
                    for (const key of ['module', 'role', 'scene', 'platform', 'evidence_level', 'version']) {
                        const value = String(knowledgeCenterFilter.value[key] || '').trim();
                        if (value) params.append(key, value);
                    }
                    const requestedHotelId = String(hotelId || '').trim();
                    if (requestedHotelId) params.append('hotel_id', requestedHotelId);

                    const res = await request(`/knowledge/list?${params.toString()}`);
                    if (res.code === 0) {
                        knowledgeCenterUnits.value = res.data?.list || [];
                        const visibleIds = new Set(knowledgeCenterUnits.value.map(unit => String(unit.unit_id)));
                        selectedKnowledgeCenterUnitIds.value = selectedKnowledgeCenterUnitIds.value.filter(id => visibleIds.has(String(id)));
                        knowledgeCenterPagination.value = {
                            total: res.data?.pagination?.total || 0,
                            page: res.data?.pagination?.page || 1,
                            page_size: res.data?.pagination?.page_size || 10,
                            total_page: res.data?.pagination?.total_page || 1,
                        };
                    } else {
                        showToast(res.msg || '知识单元加载失败', 'error');
                    }
                } catch (error) {
                    showToast(error.message || '知识单元加载失败', 'error');
                } finally {
                    knowledgeCenterLoading.value = false;
                }
            };

            const knowledgePromotionRequestId = () => {
                if (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function') {
                    return globalThis.crypto.randomUUID();
                }
                return `knowledge-promotion-${Date.now()}-${Math.random().toString(16).slice(2)}`;
            };

            const knowledgePromotionCanonicalize = (value) => {
                if (Array.isArray(value)) return value.map(knowledgePromotionCanonicalize);
                if (value && typeof value === 'object') {
                    return Object.keys(value).sort().reduce((result, key) => {
                        result[key] = knowledgePromotionCanonicalize(value[key]);
                        return result;
                    }, {});
                }
                return value ?? null;
            };

            const knowledgePromotionCanonicalJson = (value) => JSON.stringify(knowledgePromotionCanonicalize(value));
            const knowledgePromotionLines = (value) => String(value || '')
                .split(/\r?\n/)
                .map((line) => line.trim())
                .filter(Boolean);
            const operatingNetworkItems = (value) => String(value || '')
                .split(/[\r\n,，;；]+/)
                .map((item) => item.trim())
                .filter(Boolean);
            const knowledgePromotionStatusLabel = (status) => ({
                draft: '草稿',
                in_review: '审核中',
                changes_requested: '退回修改',
                rejected: '已拒绝',
                approved: '正式生效',
                withdrawn: '已停用',
            }[String(status || '')] || '未知状态');
            const knowledgePromotionStatusClass = (status) => ({
                draft: 'bg-gray-100 text-gray-700',
                in_review: 'bg-amber-100 text-amber-800',
                changes_requested: 'bg-orange-100 text-orange-800',
                rejected: 'bg-red-100 text-red-700',
                approved: 'bg-emerald-100 text-emerald-800',
                withdrawn: 'bg-slate-200 text-slate-700',
            }[String(status || '')] || 'bg-gray-100 text-gray-600');

            const captureKnowledgePromotionContext = (kind = 'load') => {
                const isAction = kind === 'action';
                const epoch = isAction ? ++knowledgePromotionActionEpoch : ++knowledgePromotionLoadEpoch;
                return {
                    kind: isAction ? 'action' : 'load',
                    epoch,
                    session: captureAuthSession(),
                    page: String(currentPage.value || ''),
                    hotelId: Number(knowledgePromotionHotelId.value || 0),
                };
            };

            const isKnowledgePromotionContextCurrent = (context = {}) => {
                const currentEpoch = context.kind === 'action'
                    ? knowledgePromotionActionEpoch
                    : knowledgePromotionLoadEpoch;
                return Number(context.epoch) === Number(currentEpoch)
                    && isAuthSessionCurrent(context.session)
                    && context.page === 'knowledge-center'
                    && currentPage.value === context.page
                    && Number(knowledgePromotionHotelId.value || 0) === Number(context.hotelId || 0);
            };

            const resetKnowledgePromotionSelection = () => {
                knowledgePromotionSelectedCandidate.value = null;
                knowledgePromotionEvents.value = [];
                knowledgePromotionForm.value = {
                    source_version_id: '',
                    title: '',
                    objective: '',
                    steps_text: '',
                    stop_conditions_text: '',
                    hotel_type_and_scale_text: '',
                    city_district_demand_text: '',
                    price_band_text: '',
                    room_type_structure_text: '',
                    platform_channel_structure_text: '',
                    seasonality_text: '',
                    data_quality_text: '',
                    pre_action_state_text: '',
                    action_parameters_text: '',
                    success_conditions_text: '',
                    failure_samples_text: '',
                    evidence_valid_until: '',
                    note: '',
                    review_due_at: '',
                    evidence_memory_ids: [],
                };
            };

            const syncKnowledgePromotionForm = (candidate) => {
                const revision = candidate?.current_revision || {};
                const applicability = revision.applicability && typeof revision.applicability === 'object'
                    ? revision.applicability
                    : {};
                const profile = applicability.applicability_profile && typeof applicability.applicability_profile === 'object'
                    ? applicability.applicability_profile
                    : {};
                const dueAt = String(candidate?.review_due_at || '').trim();
                knowledgePromotionForm.value = {
                    ...knowledgePromotionForm.value,
                    title: String(revision.title || ''),
                    objective: String(revision.objective || ''),
                    steps_text: Array.isArray(revision.steps) ? revision.steps.join('\n') : '',
                    stop_conditions_text: Array.isArray(revision.stop_conditions) ? revision.stop_conditions.join('\n') : '',
                    hotel_type_and_scale_text: Array.isArray(profile.hotel_type_and_scale) ? profile.hotel_type_and_scale.join('\n') : '',
                    city_district_demand_text: Array.isArray(profile.city_district_demand) ? profile.city_district_demand.join('\n') : '',
                    price_band_text: Array.isArray(profile.price_band) ? profile.price_band.join('\n') : '',
                    room_type_structure_text: Array.isArray(profile.room_type_structure) ? profile.room_type_structure.join('\n') : '',
                    platform_channel_structure_text: Array.isArray(profile.platform_channel_structure) ? profile.platform_channel_structure.join('\n') : '',
                    seasonality_text: Array.isArray(profile.seasonality) ? profile.seasonality.join('\n') : '',
                    data_quality_text: Array.isArray(profile.data_quality) ? profile.data_quality.join('\n') : '',
                    pre_action_state_text: Array.isArray(profile.pre_action_state) ? profile.pre_action_state.join('\n') : '',
                    action_parameters_text: Array.isArray(applicability.action_parameters) ? applicability.action_parameters.join('\n') : '',
                    success_conditions_text: Array.isArray(applicability.success_conditions) ? applicability.success_conditions.join('\n') : '',
                    failure_samples_text: Array.isArray(applicability.failure_samples) ? applicability.failure_samples.join('\n') : '',
                    evidence_valid_until: String(applicability.evidence_valid_until || ''),
                    note: '',
                    review_due_at: dueAt ? dueAt.slice(0, 16).replace(' ', 'T') : '',
                    evidence_memory_ids: [],
                };
            };

            const assertKnowledgePromotionBoundaries = (payload) => {
                if (!payload || payload.persistence_status !== 'readback_verified') {
                    throw new Error('正式知识写入没有返回严格保存回读凭证');
                }
                const boundaries = payload.write_boundaries || {};
                for (const field of [
                    'runtime_json_is_formal_source',
                    'causality_verified',
                    'automatic_execution',
                    'ota_write',
                    'external_message',
                    'knowledge_write_before_approval',
                ]) {
                    if (boundaries[field] !== false) {
                        throw new Error(`正式知识写入边界异常：${field}`);
                    }
                }
                if (!String(boundaries.contract_version || '').trim()) {
                    throw new Error('正式知识写入缺少合同版本');
                }
            };

            const assertKnowledgePromotionCandidateSnapshot = (expected, actual, hotelId) => {
                if (!expected || !actual) throw new Error('正式知识候选独立回读缺失');
                const numericFields = [
                    'id', 'tenant_id', 'hotel_id', 'source_record_id', 'current_revision_id',
                    'current_revision_no', 'row_version', 'promoted_sop_version_id',
                    'promoted_knowledge_unit_id', 'promoted_knowledge_chunk_id',
                ];
                for (const field of numericFields) {
                    if (Number(expected[field] || 0) !== Number(actual[field] || 0)) {
                        throw new Error(`正式知识候选独立回读不一致：${field}`);
                    }
                }
                if (Number(actual.hotel_id || 0) !== Number(hotelId || 0)) {
                    throw new Error('正式知识候选独立回读门店不一致');
                }
                for (const field of ['candidate_key', 'candidate_type', 'source_record_type', 'workflow_status']) {
                    if (String(expected[field] || '') !== String(actual[field] || '')) {
                        throw new Error(`正式知识候选独立回读不一致：${field}`);
                    }
                }
                const expectedRevision = expected.current_revision || {};
                const actualRevision = actual.current_revision || {};
                for (const field of [
                    'id', 'candidate_id', 'revision_no', 'source_sop_candidate_version_id', 'submitted_by',
                ]) {
                    if (Number(expectedRevision[field] || 0) !== Number(actualRevision[field] || 0)) {
                        throw new Error(`正式知识修订独立回读不一致：${field}`);
                    }
                }
                for (const field of ['title', 'objective', 'source_digest', 'content_digest', 'submitted_at']) {
                    if (String(expectedRevision[field] || '') !== String(actualRevision[field] || '')) {
                        throw new Error(`正式知识修订独立回读不一致：${field}`);
                    }
                }
                for (const field of [
                    'steps', 'stop_conditions', 'applicability', 'scope',
                    'evidence_refs', 'outcome_refs', 'conflict_refs',
                ]) {
                    if (knowledgePromotionCanonicalJson(expectedRevision[field])
                        !== knowledgePromotionCanonicalJson(actualRevision[field])) {
                        throw new Error(`正式知识修订独立回读不一致：${field}`);
                    }
                }
                if (String(actual.workflow_status || '') === 'approved') {
                    const expectedProjection = expected.promoted_knowledge || {};
                    const actualProjection = actual.promoted_knowledge || {};
                    if (expectedProjection.integrity_status !== 'verified'
                        || actualProjection.integrity_status !== 'verified'
                        || expectedProjection.is_current !== true
                        || actualProjection.is_current !== true
                    ) {
                        throw new Error('正式知识投影没有通过当前版本完整性回读');
                    }
                    const projectionFields = [
                        ['knowledge_unit', 'unit_id'],
                        ['knowledge_unit', 'current_chunk_id'],
                        ['knowledge_chunk', 'chunk_id'],
                        ['knowledge_chunk', 'operating_sop_version_id'],
                        ['operating_sop_version', 'id'],
                    ];
                    for (const [group, field] of projectionFields) {
                        if (Number(expectedProjection?.[group]?.[field] || 0)
                            !== Number(actualProjection?.[group]?.[field] || 0)) {
                            throw new Error(`正式知识投影独立回读不一致：${group}.${field}`);
                        }
                    }
                    for (const [group, field] of [
                        ['knowledge_unit', 'stable_key'],
                        ['knowledge_unit', 'lifecycle_status'],
                        ['knowledge_chunk', 'content_digest'],
                        ['knowledge_chunk', 'lifecycle_status'],
                        ['operating_sop_version', 'content_digest'],
                        ['operating_sop_version', 'validation_status'],
                        ['operating_sop_version', 'lifecycle_status'],
                    ]) {
                        if (String(expectedProjection?.[group]?.[field] || '')
                            !== String(actualProjection?.[group]?.[field] || '')) {
                            throw new Error(`正式知识投影独立回读不一致：${group}.${field}`);
                        }
                    }
                }
            };

            const readKnowledgePromotionActionSnapshot = async (payload, context) => {
                assertKnowledgePromotionBoundaries(payload);
                const expectedCandidate = payload.candidate || null;
                const candidateId = Number(expectedCandidate?.id || 0);
                if (candidateId <= 0) throw new Error('正式知识写入响应缺少候选ID');
                const [candidateResponse, eventResponse] = await Promise.all([
                    request(`/knowledge/promotions/${candidateId}`),
                    request(`/knowledge/promotions/${candidateId}/events`),
                ]);
                if (!isKnowledgePromotionContextCurrent(context)) return null;
                if (candidateResponse.code !== 200 || !candidateResponse.data) {
                    throw new Error(candidateResponse.message || candidateResponse.msg || '正式知识候选独立回读失败');
                }
                if (eventResponse.code !== 200 || eventResponse.data?.data_status !== 'ok') {
                    throw new Error(eventResponse.message || eventResponse.msg || '正式知识事件独立回读失败');
                }
                const actualCandidate = candidateResponse.data;
                assertKnowledgePromotionCandidateSnapshot(expectedCandidate, actualCandidate, context.hotelId);
                const events = Array.isArray(eventResponse.data.list) ? eventResponse.data.list : [];
                if (Number(eventResponse.data.candidate_id || 0) !== candidateId
                    || Number(eventResponse.data.count || 0) !== events.length
                    || Number(actualCandidate.event_count || 0) !== events.length
                    || events.some((event) => Number(event.candidate_id || 0) !== candidateId)
                ) {
                    throw new Error('正式知识事件时间线独立回读不一致');
                }
                if (payload.event) {
                    const expectedEvent = payload.event;
                    const matched = events.find((event) => Number(event.id || 0) === Number(expectedEvent.id || 0));
                    if (!matched
                        || String(matched.event_type || '') !== String(expectedEvent.event_type || '')
                        || String(matched.to_status || '') !== String(expectedEvent.to_status || '')
                        || Number(matched.revision_id || 0) !== Number(expectedEvent.revision_id || 0)
                    ) {
                        throw new Error('正式知识本次晋级事件独立回读不一致');
                    }
                }
                knowledgePromotionSelectedCandidate.value = actualCandidate;
                knowledgePromotionEvents.value = events;
                syncKnowledgePromotionForm(actualCandidate);
                const index = knowledgePromotionCandidates.value.findIndex((candidate) => Number(candidate.id) === candidateId);
                if (index >= 0) {
                    knowledgePromotionCandidates.value.splice(index, 1, actualCandidate);
                } else {
                    knowledgePromotionCandidates.value.unshift(actualCandidate);
                }
                return actualCandidate;
            };

            const loadKnowledgePromotionWorkbench = async () => {
                const hotelId = Number(knowledgePromotionHotelId.value || 0);
                knowledgePromotionLoadEpoch += 1;
                knowledgePromotionError.value = '';
                if (hotelId <= 0) {
                    knowledgePromotionCandidates.value = [];
                    knowledgePromotionSourceVersions.value = [];
                    knowledgePromotionMemories.value = [];
                    resetKnowledgePromotionSelection();
                    knowledgePromotionLoading.value = false;
                    return;
                }
                const context = captureKnowledgePromotionContext('load');
                knowledgePromotionLoading.value = true;
                resetKnowledgePromotionSelection();
                try {
                    const status = String(knowledgePromotionWorkflowStatus.value || '').trim();
                    const promotionQuery = new URLSearchParams({ hotel_id: String(hotelId) });
                    if (status) promotionQuery.set('workflow_status', status);
                    const [promotionResponse, sourceResponse, memoryResponse] = await Promise.all([
                        request(`/knowledge/promotions?${promotionQuery.toString()}`),
                        request(`/operation/operating-sops?hotel_id=${hotelId}`),
                        request(`/operation/operating-memories?hotel_id=${hotelId}`),
                    ]);
                    if (!isKnowledgePromotionContextCurrent(context)) return;
                    for (const [label, response] of [
                        ['正式候选', promotionResponse],
                        ['来源SOP', sourceResponse],
                        ['经营记忆', memoryResponse],
                    ]) {
                        if (response.code !== 200 || response.data?.data_status !== 'ok') {
                            throw new Error(response.message || response.msg || `${label}加载失败`);
                        }
                    }
                    const candidates = Array.isArray(promotionResponse.data.list) ? promotionResponse.data.list : [];
                    const sources = Array.isArray(sourceResponse.data.list) ? sourceResponse.data.list : [];
                    const memories = Array.isArray(memoryResponse.data.list) ? memoryResponse.data.list : [];
                    for (const [label, rows] of [['正式候选', candidates], ['来源SOP', sources], ['经营记忆', memories]]) {
                        if (rows.some((row) => Number(row?.hotel_id || 0) !== hotelId)) {
                            throw new Error(`${label}返回了其他门店的数据`);
                        }
                    }
                    knowledgePromotionCandidates.value = candidates;
                    knowledgePromotionSourceVersions.value = sources;
                    knowledgePromotionMemories.value = memories;
                    const sourceId = Number(knowledgePromotionForm.value.source_version_id || 0);
                    if (!sources.some((source) => Number(source.id) === sourceId)) {
                        knowledgePromotionForm.value.source_version_id = '';
                    }
                } catch (error) {
                    if (isKnowledgePromotionContextCurrent(context)) {
                        knowledgePromotionError.value = error.message || '正式知识晋级审核台加载失败';
                    }
                } finally {
                    if (Number(context.epoch) === knowledgePromotionLoadEpoch) {
                        knowledgePromotionLoading.value = false;
                    }
                }
            };

            const changeKnowledgePromotionHotel = () => {
                knowledgePromotionLoadEpoch += 1;
                knowledgePromotionActionEpoch += 1;
                knowledgePromotionAction.value = '';
                knowledgePromotionError.value = '';
                knowledgePromotionCandidates.value = [];
                knowledgePromotionSourceVersions.value = [];
                knowledgePromotionMemories.value = [];
                knowledgeSopCandidateForm.value.source_memory_ids = [];
                knowledgeSopCandidateError.value = '';
                knowledgeSopCandidateReadback.value = null;
                resetKnowledgePromotionSelection();
                if (Number(knowledgePromotionHotelId.value || 0) > 0) {
                    loadKnowledgePromotionWorkbench();
                }
            };

            const openKnowledgePromotionCandidate = async (candidate) => {
                const candidateId = Number(candidate?.id || 0);
                const hotelId = Number(knowledgePromotionHotelId.value || 0);
                if (candidateId <= 0 || hotelId <= 0) return;
                const context = captureKnowledgePromotionContext('load');
                knowledgePromotionLoading.value = true;
                knowledgePromotionError.value = '';
                try {
                    const [candidateResponse, eventResponse] = await Promise.all([
                        request(`/knowledge/promotions/${candidateId}`),
                        request(`/knowledge/promotions/${candidateId}/events`),
                    ]);
                    if (!isKnowledgePromotionContextCurrent(context)) return;
                    if (candidateResponse.code !== 200 || !candidateResponse.data) {
                        throw new Error(candidateResponse.message || candidateResponse.msg || '正式知识候选详情加载失败');
                    }
                    if (eventResponse.code !== 200 || eventResponse.data?.data_status !== 'ok') {
                        throw new Error(eventResponse.message || eventResponse.msg || '正式知识事件加载失败');
                    }
                    const actual = candidateResponse.data;
                    if (Number(actual.id || 0) !== candidateId || Number(actual.hotel_id || 0) !== hotelId) {
                        throw new Error('正式知识候选详情身份不一致');
                    }
                    const events = Array.isArray(eventResponse.data.list) ? eventResponse.data.list : [];
                    if (Number(eventResponse.data.candidate_id || 0) !== candidateId
                        || Number(eventResponse.data.count || 0) !== events.length
                        || Number(actual.event_count || 0) !== events.length
                        || events.some((event) => Number(event.candidate_id || 0) !== candidateId)
                    ) {
                        throw new Error('正式知识候选事件时间线不一致');
                    }
                    knowledgePromotionSelectedCandidate.value = actual;
                    knowledgePromotionEvents.value = events;
                    syncKnowledgePromotionForm(actual);
                } catch (error) {
                    if (isKnowledgePromotionContextCurrent(context)) {
                        knowledgePromotionError.value = error.message || '正式知识候选详情加载失败';
                    }
                } finally {
                    if (Number(context.epoch) === knowledgePromotionLoadEpoch) {
                        knowledgePromotionLoading.value = false;
                    }
                }
            };

            const runKnowledgePromotionAction = async (action, url, body, successMessage) => {
                const hotelId = Number(knowledgePromotionHotelId.value || 0);
                if (hotelId <= 0 || knowledgePromotionAction.value) return null;
                const context = captureKnowledgePromotionContext('action');
                if (!context.session?.token || !isKnowledgePromotionContextCurrent(context)) return null;
                knowledgePromotionAction.value = action;
                knowledgePromotionError.value = '';
                try {
                    const response = await request(url, {
                        method: 'POST',
                        body: JSON.stringify({ ...body, idempotency_key: knowledgePromotionRequestId() }),
                    });
                    if (!isKnowledgePromotionContextCurrent(context)) return null;
                    if (response.code !== 200 || !response.data) {
                        throw new Error(response.message || response.msg || '正式知识晋级操作失败');
                    }
                    const candidate = await readKnowledgePromotionActionSnapshot(response.data, context);
                    if (!candidate || !isKnowledgePromotionContextCurrent(context)) return null;
                    showToast(successMessage);
                    return candidate;
                } catch (error) {
                    if (isKnowledgePromotionContextCurrent(context)) {
                        knowledgePromotionError.value = error.message || '正式知识晋级操作失败';
                        showToast(knowledgePromotionError.value, 'error');
                    }
                    return null;
                } finally {
                    if (Number(context.epoch) === knowledgePromotionActionEpoch) {
                        knowledgePromotionAction.value = '';
                    }
                }
            };

            const createKnowledgePromotionCandidate = async () => {
                const sourceVersionId = Number(knowledgePromotionForm.value.source_version_id || 0);
                const source = knowledgePromotionSourceCandidates.value.find((row) => Number(row.id) === sourceVersionId);
                if (!source) {
                    showToast('请选择当前门店有效的候选SOP版本', 'error');
                    return;
                }
                await runKnowledgePromotionAction(
                    'create',
                    '/knowledge/promotions/from-sop-candidate',
                    { source_sop_candidate_version_id: sourceVersionId },
                    '正式知识候选已保存并完成独立回读'
                );
            };

            const createKnowledgeSopCandidate = async () => {
                if (knowledgeSopCandidateAction.value || knowledgePromotionLoading.value) return null;
                const hotelId = Number(knowledgePromotionHotelId.value || 0);
                const form = knowledgeSopCandidateForm.value;
                const eligibleById = new Map(knowledgeSopCandidateEligibleMemories.value
                    .map((memory) => [Number(memory.id || 0), memory]));
                const memoryIds = Array.from(new Set((form.source_memory_ids || [])
                    .map((value) => Number(value || 0))
                    .filter((id) => id > 0 && eligibleById.has(id))));
                const selectedMemories = memoryIds.map((id) => eligibleById.get(id));
                const title = String(form.title || '').trim();
                const steps = knowledgePromotionLines(form.steps_text);
                if (hotelId <= 0 || memoryIds.length === 0 || !title || steps.length === 0) {
                    knowledgeSopCandidateError.value = '请选择至少一条合格记忆，并填写标题和至少一个步骤。';
                    return null;
                }
                const platformScopeKeys = new Set(selectedMemories.map((memory) => [
                    String(memory?.platform || '').trim().toLowerCase(),
                    String(memory?.source_scope || '').trim().toLowerCase(),
                ].join('|')));
                if (platformScopeKeys.size !== 1) {
                    knowledgeSopCandidateError.value = '候选 SOP 的来源记忆必须属于同一平台和事实范围。';
                    return null;
                }
                knowledgeSopCandidateAction.value = 'save';
                knowledgeSopCandidateError.value = '';
                knowledgeSopCandidateReadback.value = null;
                try {
                    const payload = {
                        hotel_id: hotelId,
                        source_memory_ids: memoryIds,
                        title,
                        objective: String(form.objective || '').trim(),
                        steps,
                        stop_conditions: knowledgePromotionLines(form.stop_conditions_text),
                    };
                    const saved = await request('/operation/operating-sops', {
                        method: 'POST',
                        body: JSON.stringify(payload),
                    });
                    if (saved.code !== 200) throw new Error(saved.message || '候选 SOP 保存失败');
                    const savedVersion = saved.data?.version || {};
                    const boundaries = saved.data?.write_boundaries || {};
                    const versionId = Number(savedVersion.id || 0);
                    if (!versionId
                        || saved.data?.persistence_status !== 'readback_verified'
                        || boundaries.automatic_publish !== false
                        || boundaries.automatic_execution !== false
                        || boundaries.ota_write !== false
                        || boundaries.external_message !== false
                    ) throw new Error('候选 SOP 没有返回受控保存回读凭证');
                    const readback = await request(`/operation/operating-sops/${versionId}`);
                    if (readback.code !== 200) throw new Error(readback.message || '候选 SOP 回读失败');
                    const exact = readback.data || {};
                    const exactMemoryIds = Array.isArray(exact.source_memory_ids)
                        ? exact.source_memory_ids.map(Number).sort((a, b) => a - b)
                        : [];
                    const expectedMemoryIds = memoryIds.slice().sort((a, b) => a - b);
                    if (Number(exact.id || 0) !== versionId
                        || Number(exact.hotel_id || 0) !== hotelId
                        || String(exact.content_digest || '') !== String(savedVersion.content_digest || '')
                        || String(exact.title || '') !== title
                        || !sameAiGovernanceJson(exact.steps, steps)
                        || !sameAiGovernanceJson(exactMemoryIds, expectedMemoryIds)
                        || String(exact.validation_status || '') !== 'candidate'
                        || String(exact.lifecycle_status || '') !== 'active'
                    ) throw new Error('候选 SOP 保存与精确回读不一致');
                    knowledgeSopCandidateReadback.value = exact;
                    await loadKnowledgePromotionWorkbench();
                    knowledgePromotionForm.value.source_version_id = String(versionId);
                    showToast('候选 SOP 已保存并精确回读；是否进入正式晋级仍由你主动决定。');
                    return exact;
                } catch (error) {
                    knowledgeSopCandidateError.value = error?.message || '候选 SOP 保存失败';
                    showToast(knowledgeSopCandidateError.value, 'error');
                    return null;
                } finally {
                    knowledgeSopCandidateAction.value = '';
                }
            };

            const saveKnowledgePromotionRevision = async () => {
                const candidate = knowledgePromotionSelectedCandidate.value;
                if (!candidate || !['draft', 'changes_requested'].includes(String(candidate.workflow_status || ''))) return;
                const title = String(knowledgePromotionForm.value.title || '').trim();
                const steps = knowledgePromotionLines(knowledgePromotionForm.value.steps_text);
                if (!title || steps.length === 0) {
                    showToast('修订必须填写标题和至少一个步骤', 'error');
                    return;
                }
                await runKnowledgePromotionAction(
                    'revision',
                    `/knowledge/promotions/${candidate.id}/revisions`,
                    {
                        title,
                        objective: String(knowledgePromotionForm.value.objective || '').trim(),
                        steps,
                        stop_conditions: knowledgePromotionLines(knowledgePromotionForm.value.stop_conditions_text),
                        applicability_profile: {
                            hotel_type_and_scale: knowledgePromotionLines(knowledgePromotionForm.value.hotel_type_and_scale_text),
                            city_district_demand: knowledgePromotionLines(knowledgePromotionForm.value.city_district_demand_text),
                            price_band: knowledgePromotionLines(knowledgePromotionForm.value.price_band_text),
                            room_type_structure: knowledgePromotionLines(knowledgePromotionForm.value.room_type_structure_text),
                            platform_channel_structure: knowledgePromotionLines(knowledgePromotionForm.value.platform_channel_structure_text),
                            seasonality: knowledgePromotionLines(knowledgePromotionForm.value.seasonality_text),
                            data_quality: knowledgePromotionLines(knowledgePromotionForm.value.data_quality_text),
                            pre_action_state: knowledgePromotionLines(knowledgePromotionForm.value.pre_action_state_text),
                        },
                        action_parameters: knowledgePromotionLines(knowledgePromotionForm.value.action_parameters_text),
                        success_conditions: knowledgePromotionLines(knowledgePromotionForm.value.success_conditions_text),
                        failure_samples: knowledgePromotionLines(knowledgePromotionForm.value.failure_samples_text),
                        evidence_valid_until: String(knowledgePromotionForm.value.evidence_valid_until || '').trim(),
                        note: String(knowledgePromotionForm.value.note || '').trim(),
                    },
                    '候选修订已保存并完成独立回读'
                );
            };

            const submitKnowledgePromotionCandidate = async () => {
                const candidate = knowledgePromotionSelectedCandidate.value;
                if (!candidate || candidate.workflow_status !== 'draft') return;
                const dueAt = String(knowledgePromotionForm.value.review_due_at || '').trim();
                await runKnowledgePromotionAction(
                    'submit',
                    `/knowledge/promotions/${candidate.id}/submit`,
                    {
                        note: String(knowledgePromotionForm.value.note || '').trim(),
                        review_due_at: dueAt ? dueAt.replace('T', ' ') + (dueAt.length === 16 ? ':00' : '') : '',
                    },
                    '正式知识候选已送审并完成独立回读'
                );
            };

            const reviewKnowledgePromotionCandidate = async (decision) => {
                const candidate = knowledgePromotionSelectedCandidate.value;
                if (!candidate || candidate.workflow_status !== 'in_review') return;
                const note = String(knowledgePromotionForm.value.note || '').trim();
                if (!note) {
                    showToast('审核决定必须填写说明', 'error');
                    return;
                }
                if (decision === 'approve' && !knowledgePromotionApprovalGate.value.ready) {
                    showToast(knowledgePromotionApprovalGate.value.gaps.join('；') || '正式批准证据不足', 'error');
                    return;
                }
                const label = decision === 'approve' ? '批准' : decision === 'request_changes' ? '退回修改' : '拒绝';
                await runKnowledgePromotionAction(
                    `review:${decision}`,
                    `/knowledge/promotions/${candidate.id}/review`,
                    {
                        decision,
                        note,
                        evidence_memory_ids: decision === 'approve'
                            ? knowledgePromotionApprovalGate.value.selected_ids
                            : [],
                    },
                    `正式知识候选已${label}并完成独立回读`
                );
            };

            const withdrawKnowledgePromotionCandidate = async () => {
                const candidate = knowledgePromotionSelectedCandidate.value;
                if (!candidate || !['draft', 'in_review', 'changes_requested', 'approved'].includes(String(candidate.workflow_status || ''))) return;
                const note = String(knowledgePromotionForm.value.note || '').trim();
                if (!note) {
                    showToast(candidate.workflow_status === 'approved' ? '停用正式版本必须填写原因' : '撤回候选必须填写原因', 'error');
                    return;
                }
                if (!confirm(candidate.workflow_status === 'approved'
                    ? '确定停用当前正式SOP与知识版本吗？停用后不能生成新运营任务。'
                    : '确定撤回当前知识晋级候选吗？')) return;
                await runKnowledgePromotionAction(
                    'withdraw',
                    `/knowledge/promotions/${candidate.id}/withdraw`,
                    { note },
                    candidate.workflow_status === 'approved'
                        ? '正式SOP与知识版本已停用并完成独立回读'
                        : '知识晋级候选已撤回并完成独立回读'
                );
            };

            const operatingNetworkStageStatusLabel = (status) => ({
                complete: '已完成',
                partial: '部分完成',
                missing: '待补齐',
                blocked: '被前置条件阻塞',
                review_required: '待人工识别',
            }[String(status || '')] || '状态未知');

            const operatingNetworkStageStatusClass = (status) => ({
                complete: 'border-emerald-100 bg-emerald-50 text-emerald-700',
                partial: 'border-amber-100 bg-amber-50 text-amber-700',
                missing: 'border-orange-100 bg-orange-50 text-orange-700',
                blocked: 'border-red-100 bg-red-50 text-red-700',
                review_required: 'border-blue-100 bg-blue-50 text-blue-700',
            }[String(status || '')] || 'border-gray-200 bg-gray-50 text-gray-600');

            const operatingNetworkDimensionStatusLabel = (status) => ({
                matched: '满足',
                missing: '缺少',
                conflict: '冲突',
                source_undeclared: '来源未声明',
            }[String(status || '')] || '待判断');

            const operatingNetworkDimensionStatusClass = (status) => ({
                matched: 'border-emerald-100 bg-emerald-50 text-emerald-700',
                missing: 'border-amber-100 bg-amber-50 text-amber-700',
                conflict: 'border-red-100 bg-red-50 text-red-700',
                source_undeclared: 'border-gray-200 bg-gray-50 text-gray-600',
            }[String(status || '')] || 'border-gray-200 bg-gray-50 text-gray-600');

            const resetOperatingNetworkProfileForm = (profile = null) => {
                const dimensions = profile?.profile?.dimensions && typeof profile.profile.dimensions === 'object'
                    ? profile.profile.dimensions
                    : {};
                const onboarding = profile?.profile?.onboarding_confirmations && typeof profile.profile.onboarding_confirmations === 'object'
                    ? profile.profile.onboarding_confirmations
                    : {};
                operatingNetworkProfileForm.value = {
                    hotel_type_and_scale_text: Array.isArray(dimensions.hotel_type_and_scale) ? dimensions.hotel_type_and_scale.join('\n') : '',
                    city_district_demand_text: Array.isArray(dimensions.city_district_demand) ? dimensions.city_district_demand.join('\n') : '',
                    price_band_text: Array.isArray(dimensions.price_band) ? dimensions.price_band.join('\n') : '',
                    room_type_structure_text: Array.isArray(dimensions.room_type_structure) ? dimensions.room_type_structure.join('\n') : '',
                    platform_channel_structure_text: Array.isArray(dimensions.platform_channel_structure) ? dimensions.platform_channel_structure.join('\n') : '',
                    seasonality_text: Array.isArray(dimensions.seasonality) ? dimensions.seasonality.join('\n') : '',
                    data_quality_text: Array.isArray(dimensions.data_quality) ? dimensions.data_quality.join('\n') : '',
                    pre_action_state_text: Array.isArray(dimensions.pre_action_state) ? dimensions.pre_action_state.join('\n') : '',
                    quality_status: String(profile?.quality_status || 'unverified'),
                    effective_date: String(profile?.effective_date || ''),
                    evidence_valid_until: String(profile?.evidence_valid_until || ''),
                    evidence_refs_text: Array.isArray(profile?.evidence_refs) ? profile.evidence_refs.join('\n') : '',
                    source_method: String(profile?.source_method || ''),
                    notes: String(profile?.profile?.notes || ''),
                    room_rate_mapping_status: String(onboarding.room_rate_mapping?.status || 'missing'),
                    room_rate_mapping_refs_text: Array.isArray(onboarding.room_rate_mapping?.evidence_refs) ? onboarding.room_rate_mapping.evidence_refs.join('\n') : '',
                    metric_definition_status: String(onboarding.metric_definition?.status || 'missing'),
                    metric_definition_refs_text: Array.isArray(onboarding.metric_definition?.evidence_refs) ? onboarding.metric_definition.evidence_refs.join('\n') : '',
                };
            };

            const assertOperatingNetworkBoundaries = (boundaries) => {
                if (!boundaries || typeof boundaries !== 'object') {
                    throw new Error('经营复制网络缺少写入边界');
                }
                for (const field of ['automatic_execution', 'ota_write', 'external_message']) {
                    if (boundaries[field] !== false) {
                        throw new Error(`经营复制网络边界异常：${field}`);
                    }
                }
            };

            const applyOperatingNetworkOverview = (payload, hotelId) => {
                if (!payload || !['ok', 'migration_required'].includes(String(payload.data_status || ''))) {
                    throw new Error('经营复制网络返回状态无效');
                }
                assertOperatingNetworkBoundaries(payload.boundaries);
                if (payload.profile && Number(payload.profile.hotel_id || 0) !== Number(hotelId || 0)) {
                    throw new Error('经营画像回读酒店不一致');
                }
                operatingNetworkData.value = payload;
                resetOperatingNetworkProfileForm(payload.profile || null);
                const sourceId = Number(operatingNetworkReplicationForm.value.source_sop_version_id || 0);
                const sourceRows = Array.isArray(payload.verified_sops) ? payload.verified_sops : [];
                if (!sourceRows.some((row) => Number(row.id || 0) === sourceId)) {
                    operatingNetworkReplicationForm.value.source_sop_version_id = '';
                }
                return payload;
            };

            const loadOperatingNetwork = async () => {
                const hotelId = Number(operatingNetworkHotelId.value || 0);
                operatingNetworkError.value = '';
                operatingNetworkProfilePreview.value = null;
                if (hotelId <= 0) {
                    operatingNetworkData.value = null;
                    resetOperatingNetworkProfileForm();
                    return null;
                }
                operatingNetworkLoading.value = true;
                try {
                    const response = await request(`/operation/operating-network?hotel_id=${hotelId}`);
                    if (Number(operatingNetworkHotelId.value || 0) !== hotelId) return null;
                    if (response.code !== 200 || !response.data) {
                        throw new Error(response.msg || '经营复制网络加载失败');
                    }
                    return applyOperatingNetworkOverview(response.data, hotelId);
                } catch (error) {
                    if (Number(operatingNetworkHotelId.value || 0) === hotelId) {
                        operatingNetworkError.value = error.message || '经营复制网络加载失败';
                    }
                    return null;
                } finally {
                    if (Number(operatingNetworkHotelId.value || 0) === hotelId) {
                        operatingNetworkLoading.value = false;
                    }
                }
            };

            const changeOperatingNetworkHotel = () => {
                operatingNetworkAction.value = '';
                operatingNetworkError.value = '';
                operatingNetworkData.value = null;
                operatingNetworkProfilePreview.value = null;
                operatingNetworkLastReplication.value = null;
                operatingNetworkExecutionIntent.value = null;
                operatingNetworkReviews.value = [];
                operatingNetworkReplicationForm.value = {
                    source_sop_version_id: '',
                    target_date_start: '',
                    target_date_end: '',
                };
                resetOperatingNetworkProfileForm();
                if (Number(operatingNetworkHotelId.value || 0) > 0) loadOperatingNetwork();
            };

            const generateOperatingNetworkProfilePreview = async () => {
                const hotelId = Number(operatingNetworkHotelId.value || 0);
                if (hotelId <= 0 || operatingNetworkAction.value) return null;
                operatingNetworkAction.value = 'profile_preview';
                operatingNetworkError.value = '';
                operatingNetworkProfilePreview.value = null;
                try {
                    const response = await request(`/operation/operating-profiles/preview?hotel_id=${hotelId}`);
                    if (Number(operatingNetworkHotelId.value || 0) !== hotelId) return null;
                    const preview = response.data;
                    if (response.code !== 200 || !preview) {
                        throw new Error(response.msg || '经营画像待核验草稿预览生成失败');
                    }
                    if (Number(preview.hotel_id || 0) !== hotelId
                        || preview.preview_only !== true
                        || String(preview.persistence_status || '') !== 'not_persisted'
                        || preview.automatic_verification !== false
                        || String(preview.draft?.quality_status || '') !== 'unverified'
                    ) {
                        throw new Error('经营画像草稿预览边界或酒店身份不一致');
                    }
                    assertOperatingNetworkBoundaries(preview.boundaries);
                    operatingNetworkProfilePreview.value = preview;
                    const filled = Number(preview.summary?.filled_dimension_count || 0);
                    showToast(
                        filled > 0
                            ? `已生成 ${filled}/8 个维度的未核验预览，尚未保存`
                            : '当前可信数据不足，已保留全部未知项',
                        filled > 0 ? 'success' : 'warning'
                    );
                    return preview;
                } catch (error) {
                    operatingNetworkError.value = error.message || '经营画像待核验草稿预览生成失败';
                    showToast(operatingNetworkError.value, 'error');
                    return null;
                } finally {
                    if (Number(operatingNetworkHotelId.value || 0) === hotelId
                        && operatingNetworkAction.value === 'profile_preview'
                    ) {
                        operatingNetworkAction.value = '';
                    }
                }
            };

            const applyOperatingNetworkProfilePreview = () => {
                const hotelId = Number(operatingNetworkHotelId.value || 0);
                const preview = operatingNetworkProfilePreview.value;
                if (!preview || Number(preview.hotel_id || 0) !== hotelId) {
                    operatingNetworkProfilePreview.value = null;
                    showToast('草稿预览已失效，请按当前酒店重新生成', 'error');
                    return false;
                }
                if (preview.preview_only !== true
                    || String(preview.persistence_status || '') !== 'not_persisted'
                    || preview.automatic_verification !== false
                    || String(preview.draft?.quality_status || '') !== 'unverified'
                ) {
                    showToast('草稿预览边界异常，已阻止应用', 'error');
                    return false;
                }
                assertOperatingNetworkBoundaries(preview.boundaries);
                resetOperatingNetworkProfileForm(preview.draft);
                operatingNetworkProfileForm.value.quality_status = 'unverified';
                operatingNetworkProfileForm.value.evidence_valid_until = '';
                showToast('未核验预览已应用到编辑器；请确认空白和缺口后再保存');
                return true;
            };

            const saveOperatingNetworkProfile = async () => {
                const hotelId = Number(operatingNetworkHotelId.value || 0);
                if (hotelId <= 0 || operatingNetworkAction.value) return;
                const form = operatingNetworkProfileForm.value;
                if (!String(form.effective_date || '').trim() || !String(form.source_method || '').trim()) {
                    showToast('画像必须填写生效日期和来源方法', 'error');
                    return;
                }
                operatingNetworkAction.value = 'profile';
                operatingNetworkError.value = '';
                try {
                    const response = await request('/operation/operating-profiles', {
                        method: 'POST',
                        body: JSON.stringify({
                            hotel_id: hotelId,
                            profile: {
                                hotel_type_and_scale: operatingNetworkItems(form.hotel_type_and_scale_text),
                                city_district_demand: operatingNetworkItems(form.city_district_demand_text),
                                price_band: operatingNetworkItems(form.price_band_text),
                                room_type_structure: operatingNetworkItems(form.room_type_structure_text),
                                platform_channel_structure: operatingNetworkItems(form.platform_channel_structure_text),
                                seasonality: operatingNetworkItems(form.seasonality_text),
                                data_quality: operatingNetworkItems(form.data_quality_text),
                                pre_action_state: operatingNetworkItems(form.pre_action_state_text),
                            },
                            quality_status: String(form.quality_status || 'unverified'),
                            effective_date: String(form.effective_date || '').trim(),
                            evidence_valid_until: String(form.evidence_valid_until || '').trim(),
                            evidence_refs: operatingNetworkItems(form.evidence_refs_text),
                            source_method: String(form.source_method || '').trim(),
                            notes: String(form.notes || '').trim(),
                            onboarding: {
                                room_rate_mapping: {
                                    status: String(form.room_rate_mapping_status || 'missing'),
                                    evidence_refs: operatingNetworkItems(form.room_rate_mapping_refs_text),
                                },
                                metric_definition: {
                                    status: String(form.metric_definition_status || 'missing'),
                                    evidence_refs: operatingNetworkItems(form.metric_definition_refs_text),
                                },
                            },
                        }),
                    });
                    if (Number(operatingNetworkHotelId.value || 0) !== hotelId) return null;
                    if (response.code !== 200 || response.data?.persistence_status !== 'readback_verified' || !response.data?.profile) {
                        throw new Error(response.msg || '经营画像未返回严格保存回读凭证');
                    }
                    assertOperatingNetworkBoundaries(response.data.write_boundaries);
                    const expected = response.data.profile;
                    const readback = await request(`/operation/operating-network?hotel_id=${hotelId}`);
                    if (Number(operatingNetworkHotelId.value || 0) !== hotelId) return null;
                    if (readback.code !== 200 || !readback.data?.profile) {
                        throw new Error(readback.msg || '经营画像独立回读失败');
                    }
                    const actual = readback.data.profile;
                    if (Number(actual.id || 0) !== Number(expected.id || 0)
                        || Number(actual.hotel_id || 0) !== hotelId
                        || String(actual.content_digest || '') !== String(expected.content_digest || '')
                    ) {
                        throw new Error('经营画像独立回读不一致');
                    }
                    applyOperatingNetworkOverview(readback.data, hotelId);
                    operatingNetworkProfilePreview.value = null;
                    showToast('经营画像已保存并完成独立回读');
                } catch (error) {
                    if (Number(operatingNetworkHotelId.value || 0) === hotelId) {
                        operatingNetworkError.value = error.message || '经营画像保存失败';
                        showToast(operatingNetworkError.value, 'error');
                    }
                } finally {
                    if (Number(operatingNetworkHotelId.value || 0) === hotelId
                        && operatingNetworkAction.value === 'profile'
                    ) {
                        operatingNetworkAction.value = '';
                    }
                }
            };

            const loadOperatingNetworkReviews = async (replicationId) => {
                const id = Number(replicationId || 0);
                if (id <= 0) {
                    operatingNetworkReviews.value = [];
                    return [];
                }
                const response = await request(`/operation/operating-sop-replications/${id}/reviews`);
                if (response.code !== 200 || response.data?.data_status !== 'ok') {
                    throw new Error(response.msg || '复制复盘回读失败');
                }
                const rows = Array.isArray(response.data.list) ? response.data.list : [];
                if (Number(response.data.replication_id || 0) !== id
                    || Number(response.data.count || 0) !== rows.length
                    || rows.some((row) => Number(row.replication_id || 0) !== id)
                ) {
                    throw new Error('复制复盘独立回读不一致');
                }
                assertOperatingNetworkBoundaries(response.data.boundaries);
                operatingNetworkReviews.value = rows;
                return rows;
            };

            const generateOperatingNetworkReplicationDraft = async () => {
                const hotelId = Number(operatingNetworkHotelId.value || 0);
                const sourceVersionId = Number(operatingNetworkReplicationForm.value.source_sop_version_id || 0);
                const dateStart = String(operatingNetworkReplicationForm.value.target_date_start || '').trim();
                const dateEnd = String(operatingNetworkReplicationForm.value.target_date_end || '').trim();
                if (hotelId <= 0 || sourceVersionId <= 0 || operatingNetworkAction.value) return;
                if (!dateStart || !dateEnd) {
                    showToast('生成草稿前必须钉住目标事实日期范围', 'error');
                    return;
                }
                operatingNetworkAction.value = 'replication';
                operatingNetworkError.value = '';
                operatingNetworkExecutionIntent.value = null;
                try {
                    const response = await request(`/operation/operating-sops/${sourceVersionId}/replications`, {
                        method: 'POST',
                        body: JSON.stringify({
                            target_hotel_id: hotelId,
                            target_date_start: dateStart,
                            target_date_end: dateEnd,
                        }),
                    });
                    if (response.code !== 200 || response.data?.persistence_status !== 'readback_verified' || !response.data?.replication) {
                        throw new Error(response.msg || '复制草稿未返回严格保存回读凭证');
                    }
                    assertOperatingNetworkBoundaries(response.data.write_boundaries);
                    const expected = response.data.replication;
                    const replicationId = Number(expected.id || 0);
                    const readback = await request(`/operation/operating-sop-replications/${replicationId}`);
                    if (readback.code !== 200 || !readback.data) {
                        throw new Error(readback.msg || '复制草稿独立回读失败');
                    }
                    const actual = readback.data;
                    if (Number(actual.id || 0) !== replicationId
                        || Number(actual.source_sop_version_id || 0) !== sourceVersionId
                        || Number(actual.target_hotel_id || 0) !== hotelId
                        || String(actual.content_digest || '') !== String(expected.content_digest || '')
                    ) {
                        throw new Error('复制草稿独立回读不一致');
                    }
                    assertOperatingNetworkBoundaries(actual.draft?.boundaries);
                    if (actual.draft?.applicability_assessment?.recommendation !== 'validation_draft_only') {
                        throw new Error('复制草稿没有保持待验证边界');
                    }
                    operatingNetworkLastReplication.value = actual;
                    await loadOperatingNetworkReviews(replicationId);
                    showToast('仅待验证的复制草稿已保存并完成独立回读');
                } catch (error) {
                    operatingNetworkError.value = error.message || '复制草稿生成失败';
                    showToast(operatingNetworkError.value, 'error');
                } finally {
                    operatingNetworkAction.value = '';
                }
            };

            const restoreOperatingNetworkReplication = async (replication) => {
                await ensureOperationStaticReady();
                return requireOperationStatic(
                    operationStatic.value,
                    'runOperatingNetworkReplicationRestoreFlow',
                )({
                    replication,
                    hotelId: Number(operatingNetworkHotelId.value || 0),
                    busy: !!operatingNetworkAction.value,
                    currentHotelId: () => operatingNetworkHotelId.value,
                    request,
                    setAction: value => { operatingNetworkAction.value = value; },
                    setError: value => { operatingNetworkError.value = value; },
                    assertBoundaries: assertOperatingNetworkBoundaries,
                    setReplication: value => { operatingNetworkLastReplication.value = value; },
                    clearIntent: () => { operatingNetworkExecutionIntent.value = null; },
                    loadReviews: loadOperatingNetworkReviews,
                    toast: showToast,
                });
            };
            const operatingNetworkReplicationLabel = (replication) => (
                operationStatic.value
                    ? requireOperationStatic(operationStatic.value, 'operatingNetworkReplicationLabel')(replication)
                    : ''
            );

            const createOperatingNetworkExecutionIntent = async () => {
                const replication = operatingNetworkLastReplication.value;
                const replicationId = Number(replication?.id || 0);
                if (replicationId <= 0 || operatingNetworkAction.value) return;
                if (String(replication?.status || '') !== 'draft_pending_target_validation'
                    || String(replication?.target_validation_status || '') !== 'facts_available_review_required'
                ) {
                    showToast('当前草稿存在画像、目标事实或经营闭环缺口，只能保留待验证草稿', 'warning');
                    return;
                }
                const comparison = replication?.draft?.target_fact_comparison_contract || {};
                const platform = String(comparison.platform || '').trim().toLowerCase();
                const factDateEnd = String(comparison.date_end || '').trim();
                if (!platform || !/^\d{4}-\d{2}-\d{2}$/.test(factDateEnd)) {
                    showToast('草稿缺少已回读的目标事实平台或日期，不能生成验证任务', 'error');
                    return;
                }
                const minimumActionDate = formatDate(new Date(new Date(`${factDateEnd}T12:00:00`).getTime() + 86400000));
                const formValues = await openWorkflowFormDialog({
                    title: '生成待审批复制验证任务',
                    description: `目标店事实已钉住 ${platform} · ${factDateEnd}。这里只保存待人工审批的验证意图；不会自动执行、写 OTA 或外发消息。当前值 JSON 必须用待验证指标作为数值键。`,
                    submitText: '保存待审批任务',
                    fields: [
                        {
                            name: 'object_type',
                            label: '动作对象',
                            type: 'select',
                            required: true,
                            value: 'campaign',
                            options: [
                                { value: 'campaign', label: '活动/内容实验' },
                                { value: 'price', label: '价格' },
                                { value: 'inventory', label: '库存' },
                                { value: 'room_product', label: '房型产品' },
                            ],
                        },
                        { name: 'action_type', label: '验证动作类型', type: 'text', required: true, value: '' },
                        { name: 'date_start', label: '动作开始日', type: 'date', required: true, value: minimumActionDate, min: minimumActionDate },
                        { name: 'date_end', label: '动作结束日', type: 'date', required: true, value: minimumActionDate, min: minimumActionDate },
                        { name: 'expected_metric', label: '待验证指标键', type: 'text', required: true, value: '' },
                        { name: 'expected_delta', label: '预期有利变化量（可选）', type: 'number', value: '' },
                        { name: 'current_value_json', label: '执行前状态 JSON', type: 'textarea', required: true, value: '{}' },
                        { name: 'target_value_json', label: '动作参数 JSON', type: 'textarea', required: true, value: '{}' },
                        {
                            name: 'risk_level',
                            label: '风险等级',
                            type: 'select',
                            required: true,
                            value: 'low',
                            options: [
                                { value: 'low', label: '低' },
                                { value: 'medium', label: '中' },
                                { value: 'high', label: '高' },
                            ],
                        },
                    ],
                });
                if (formValues === null) return;

                const parseObject = (raw, label) => {
                    let value;
                    try {
                        value = JSON.parse(String(raw || ''));
                    } catch {
                        throw new Error(`${label}必须是有效 JSON`);
                    }
                    if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length === 0) {
                        throw new Error(`${label}必须是非空 JSON 对象`);
                    }
                    return value;
                };

                let currentValue;
                let targetValue;
                try {
                    currentValue = parseObject(formValues.current_value_json, '执行前状态');
                    targetValue = parseObject(formValues.target_value_json, '动作参数');
                } catch (error) {
                    showToast(error.message, 'error');
                    return;
                }
                const objectType = String(formValues.object_type || '').trim().toLowerCase();
                const expectedMetric = String(formValues.expected_metric || '').trim().toLowerCase();
                const actionType = String(formValues.action_type || '').trim();
                const dateStart = String(formValues.date_start || '').trim();
                const dateEnd = String(formValues.date_end || '').trim();
                if (dateEnd < dateStart) {
                    showToast('动作结束日不能早于开始日', 'error');
                    return;
                }
                if (!Object.prototype.hasOwnProperty.call(currentValue, expectedMetric)
                    || !Number.isFinite(Number(currentValue[expectedMetric]))
                ) {
                    showToast(`执行前状态必须包含数值指标键 ${expectedMetric}`, 'error');
                    return;
                }
                const requiredTargetKeys = objectType === 'price'
                    ? ['room_type_key', 'rate_plan_key', 'target_price']
                    : (objectType === 'inventory' ? ['room_type_key'] : (objectType === 'room_product' ? ['room_type_key'] : []));
                const missingTargetKeys = requiredTargetKeys.filter(key => String(targetValue[key] ?? '').trim() === '');
                if (objectType === 'inventory'
                    && !Object.prototype.hasOwnProperty.call(targetValue, 'target_inventory')
                    && !String(targetValue.sell_status || '').trim()
                ) {
                    missingTargetKeys.push('target_inventory 或 sell_status');
                }
                if (missingTargetKeys.length) {
                    showToast(`动作参数缺少：${missingTargetKeys.join('、')}`, 'error');
                    return;
                }
                const expectedDeltaText = String(formValues.expected_delta ?? '').replace(/[,，]/g, '').trim();
                const expectedDelta = expectedDeltaText === '' ? null : Number(expectedDeltaText);
                if (expectedDelta !== null && (!Number.isFinite(expectedDelta) || expectedDelta <= 0)) {
                    showToast('预期有利变化量必须是大于 0 的数值', 'error');
                    return;
                }

                operatingNetworkAction.value = 'execution_intent';
                operatingNetworkError.value = '';
                try {
                    const response = await request(`/operation/operating-sop-replications/${replicationId}/execution-intent`, {
                        method: 'POST',
                        body: JSON.stringify({
                            platform,
                            object_type: objectType,
                            action_type: actionType,
                            date_start: dateStart,
                            date_end: dateEnd,
                            current_value: currentValue,
                            target_value: targetValue,
                            expected_metric: expectedMetric,
                            expected_delta: expectedDelta,
                            risk_level: String(formValues.risk_level || 'low').trim().toLowerCase(),
                        }),
                    });
                    const created = response.data || {};
                    const intent = created.execution_intent || {};
                    if (response.code !== 200
                        || created.persistence_status !== 'readback_verified'
                        || String(intent.status || '') !== 'pending_approval'
                        || String(intent.source_module || '') !== 'operating_network_replication'
                        || Number(intent.source_record_id || 0) !== replicationId
                    ) {
                        throw new Error(response.msg || '复制验证任务未返回严格待审批回读');
                    }
                    assertOperatingNetworkBoundaries(created.write_boundaries);
                    const persisted = await readOperationExecutionIntent(Number(intent.id || 0));
                    const lineage = persisted?.evidence?.operating_network_replication || {};
                    if (String(persisted.status || '') !== 'pending_approval'
                        || String(persisted.source_module || '') !== 'operating_network_replication'
                        || Number(persisted.source_record_id || 0) !== replicationId
                        || Number(persisted.hotel_id || 0) !== Number(replication.target_hotel_id || 0)
                        || String(lineage.replication_content_digest || '') !== String(replication.content_digest || '')
                        || lineage.human_approval_required !== true
                        || lineage.automatic_execution !== false
                        || lineage.ota_write !== false
                        || lineage.external_message !== false
                    ) {
                        throw new Error('复制验证待审批任务独立回读不一致');
                    }
                    operatingNetworkExecutionIntent.value = persisted;
                    showToast('复制验证意图已保存为待审批任务；尚未执行，也未写入 OTA');
                } catch (error) {
                    operatingNetworkError.value = error.message || '复制验证待审批任务创建失败';
                    showToast(operatingNetworkError.value, 'error');
                } finally {
                    operatingNetworkAction.value = '';
                }
            };

            const openOperatingNetworkExecutionIntent = async () => {
                const intent = operatingNetworkExecutionIntent.value;
                const intentId = Number(intent?.id || 0);
                const hotelId = Number(intent?.hotel_id || 0);
                if (intentId <= 0 || hotelId <= 0) {
                    showToast('尚无可回读的复制验证待审批任务', 'warning');
                    return;
                }
                operationFilters.value.hotel_id = String(hotelId);
                revenueAiExecutionFocus.value = { intentId };
                currentPage.value = 'ops-track';
                await loadOperationActions({ focusIntentId: intentId });
                await nextTick();
                const row = document.querySelector(`[data-operation-execution-intent-id="${intentId}"]`);
                if (row) {
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    showToast('任务已保存，但执行池未返回对应记录，请刷新后重试', 'warning');
                }
            };

            const saveOperatingNetworkReview = async () => {
                const replicationId = Number(operatingNetworkLastReplication.value?.id || 0);
                if (replicationId <= 0 || operatingNetworkAction.value) return;
                const form = operatingNetworkReviewForm.value;
                const note = String(form.note || '').trim();
                const evidenceRefs = operatingNetworkItems(form.evidence_refs_text);
                const outcome = String(form.outcome || 'inconclusive');
                const reviewedBusinessDate = String(form.reviewed_business_date || '').trim();
                const observedConditions = operatingNetworkItems(form.observed_conditions_text);
                const failureConditions = operatingNetworkItems(form.failure_conditions_text);
                const stopTriggered = operatingNetworkItems(form.stop_triggered_text);
                if (!note) {
                    showToast('复制复盘必须填写人工说明', 'error');
                    return;
                }
                if (outcome !== 'inconclusive' && evidenceRefs.length === 0) {
                    showToast('成功、失败或停止的复盘必须填写证据引用', 'error');
                    return;
                }
                if (outcome !== 'inconclusive' && !reviewedBusinessDate) {
                    showToast('成功、失败或停止的复盘必须填写业务日期', 'error');
                    return;
                }
                if (outcome === 'success' && observedConditions.length === 0) {
                    showToast('成功复盘必须填写达到成功条件的实际观察', 'error');
                    return;
                }
                if (outcome === 'failed' && failureConditions.length === 0) {
                    showToast('失败复盘必须填写实际失败条件', 'error');
                    return;
                }
                if (outcome === 'stopped' && stopTriggered.length === 0) {
                    showToast('停止复盘必须填写已触发的停止条件', 'error');
                    return;
                }
                if (['success', 'failed'].includes(outcome)
                    && !evidenceRefs.some((ref) => /^operation_effect_reviews#[1-9]\d*$/.test(ref))) {
                    showToast('成功或失败复盘必须引用 operation_effect_reviews#ID', 'error');
                    return;
                }
                if (outcome === 'stopped'
                    && !evidenceRefs.some((ref) => /^operation_execution_evidence#[1-9]\d*$/.test(ref))) {
                    showToast('停止复盘必须引用 operation_execution_evidence#ID', 'error');
                    return;
                }
                operatingNetworkAction.value = 'review';
                operatingNetworkError.value = '';
                try {
                    const response = await request(`/operation/operating-sop-replications/${replicationId}/reviews`, {
                        method: 'POST',
                        body: JSON.stringify({
                            outcome,
                            note,
                            observed_conditions: observedConditions,
                            failure_conditions: failureConditions,
                            stop_triggered: stopTriggered,
                            evidence_refs: evidenceRefs,
                            reviewed_business_date: reviewedBusinessDate,
                        }),
                    });
                    if (response.code !== 200 || response.data?.persistence_status !== 'readback_verified' || !response.data?.review) {
                        throw new Error(response.msg || '复制复盘未返回严格保存回读凭证');
                    }
                    assertOperatingNetworkBoundaries(response.data.write_boundaries);
                    const expected = response.data.review;
                    const rows = await loadOperatingNetworkReviews(replicationId);
                    const actual = rows.find((row) => Number(row.id || 0) === Number(expected.id || 0));
                    if (!actual || String(actual.content_digest || '') !== String(expected.content_digest || '')) {
                        throw new Error('复制复盘独立回读不一致');
                    }
                    operatingNetworkReviewForm.value = {
                        outcome: 'inconclusive',
                        note: '',
                        observed_conditions_text: '',
                        failure_conditions_text: '',
                        stop_triggered_text: '',
                        evidence_refs_text: '',
                        reviewed_business_date: '',
                    };
                    showToast('复制复盘已保存并回读；下次生成草稿将纳入成功或失败样本');
                } catch (error) {
                    operatingNetworkError.value = error.message || '复制复盘保存失败';
                    showToast(operatingNetworkError.value, 'error');
                } finally {
                    operatingNetworkAction.value = '';
                }
            };

            const reloadKnowledgeCenter = () => {
                knowledgeCenterPagination.value.page = 1;
                selectedKnowledgeCenterUnitIds.value = [];
                loadKnowledgeCenter();
            };

            const changeKnowledgeCenterPage = (page) => {
                const nextPage = Math.max(1, Math.min(page, knowledgeCenterPagination.value.total_page || 1));
                if (nextPage === knowledgeCenterPagination.value.page) return;
                knowledgeCenterPagination.value.page = nextPage;
                loadKnowledgeCenter();
            };

            const resetKnowledgeCenterFilter = () => {
                knowledgeCenterFilter.value = {
                    keyword: '', status: '', tag: '', source: '',
                    module: '', role: '', scene: '', platform: '', evidence_level: '', version: '',
                };
                reloadKnowledgeCenter();
            };

            const filterByKnowledgeStatus = (status) => {
                knowledgeCenterFilter.value.status = status;
                reloadKnowledgeCenter();
            };

            const updateKnowledgeUnitStatus = async (unit, status) => {
                if (!unit || unit.status === status) return;
                const previousStatus = unit.status;
                unit.status = status;
                try {
                    const res = await request(`/knowledge/${unit.unit_id}/status`, {
                        method: 'POST',
                        body: JSON.stringify({ status }),
                    });
                    if (res.code === 0) {
                        showToast('状态已更新');
                        await loadKnowledgeCenter();
                    } else {
                        unit.status = previousStatus;
                        showToast(res.msg || '状态更新失败', 'error');
                    }
                } catch (error) {
                    unit.status = previousStatus;
                    showToast(error.message || '状态更新失败', 'error');
                }
            };

            const openKnowledgeUnitModal = (unit = null) => {
                knowledgeCenterForm.value = unit ? {
                    unit_id: unit.unit_id,
                    hotel_id: String(unit.hotel_id ?? 0),
                    name: unit.name || '',
                    source: unit.source || '',
                    status: unit.status || 'pending',
                    description: unit.description || '',
                    tags: (unit.tags || []).join(','),
                } : { unit_id: null, hotel_id: '0', name: '', source: 'text', status: 'pending', description: '', tags: '' };
                showKnowledgeCenterUnitModal.value = true;
            };

            const saveKnowledgeUnit = async () => {
                const form = knowledgeCenterForm.value;
                if (!form.name || !form.name.trim()) {
                    showToast('请输入经验单元名称', 'error');
                    return;
                }
                const payload = {
                    hotel_id: Number(form.hotel_id || 0),
                    name: form.name.trim(),
                    source: form.source || '',
                    status: form.status || 'pending',
                    description: form.description || '',
                    tags: parseKnowledgeTags(form.tags),
                };
                const url = form.unit_id ? `/knowledge/${form.unit_id}/update` : '/knowledge/add';
                const res = await request(url, { method: 'POST', body: JSON.stringify(payload) });
                if (res.code === 0) {
                    showKnowledgeCenterUnitModal.value = false;
                    showToast(form.unit_id ? '经验单元已更新' : '经验单元已创建');
                    await loadKnowledgeCenter();
                } else {
                    showToast(res.msg || '保存失败', 'error');
                }
            };

            const deleteKnowledgeUnit = async (unit) => {
                if (!confirm(`确定删除经验单元"${unit.name}"及其全部片段吗？`)) return;
                const res = await request(`/knowledge/${unit.unit_id}`, { method: 'DELETE' });
                if (res.code === 0) {
                    showToast('删除成功');
                    await loadKnowledgeCenter();
                } else {
                    showToast(res.msg || '删除失败', 'error');
                }
            };

            const refreshKnowledgeUnit = async () => {
                await loadKnowledgeCenter();
                showToast('已刷新');
            };

            const runKnowledgeDistillation = async (mode = 'kd') => {
                if (knowledgeDistillationRunning.value) return;
                const maxBatches = knowledgeDistillationFullTrain.value
                    ? 0
                    : Math.max(1, Math.min(1000, Number(knowledgeDistillationMaxBatches.value) || 1));
                knowledgeDistillationRunning.value = mode;
                try {
                    const res = await request('/knowledge/distillation/run', {
                        method: 'POST',
                        body: JSON.stringify({ mode, max_batches: maxBatches }),
                    });
                    knowledgeDistillationResult.value = res.data || null;
                    if (res.code === 0) {
                        showToast(mode === 'baseline' ? 'Baseline训练完成' : '知识蒸馏训练完成');
                        await loadKnowledgeCenter();
                    } else {
                        showToast(res.msg || '知识蒸馏训练失败', 'error');
                    }
                } catch (error) {
                    showToast(error.message || '知识蒸馏训练失败', 'error');
                } finally {
                    knowledgeDistillationRunning.value = '';
                }
            };

            const openKnowledgeChunks = async (unit) => {
                knowledgeCenterSelectedUnit.value = unit;
                knowledgeCenterChunks.value = [];
                knowledgeCenterChunkForm.value = { type: '经验片段', content: defaultKnowledgeExperienceChunk() };
                showKnowledgeCenterChunksModal.value = true;
                const res = await request(`/knowledge/${unit.unit_id}`);
                if (res.code === 0) {
                    knowledgeCenterSelectedUnit.value = res.data?.unit || unit;
                    knowledgeCenterChunks.value = res.data?.chunks || [];
                } else {
                    showToast(res.msg || '片段加载失败', 'error');
                }
            };

            const saveKnowledgeChunk = async () => {
                const unitId = knowledgeCenterSelectedUnit.value?.unit_id;
                if (!unitId) return;
                const payload = {
                    type: knowledgeCenterChunkForm.value.type || 'manual',
                    content: parseKnowledgeContent(knowledgeCenterChunkForm.value.content),
                };
                const res = await request(`/knowledge/${unitId}/add-chunk`, { method: 'POST', body: JSON.stringify(payload) });
                if (res.code === 0) {
                    knowledgeCenterChunkForm.value = { type: '经验片段', content: defaultKnowledgeExperienceChunk() };
                    await openKnowledgeChunks(knowledgeCenterSelectedUnit.value);
                    await loadKnowledgeCenter();
                    showToast('经验片段已写入');
                } else {
                    showToast(res.msg || '片段写入失败', 'error');
                }
            };

            const createKnowledgeSopTask = async (chunk) => {
                const unitId = Number(knowledgeCenterSelectedUnit.value?.unit_id || 0);
                const chunkId = Number(chunk?.chunk_id || 0);
                const hotelId = Number(defaultKnowledgeCenterHotelId() || 0);
                const chunkView = knowledgeChunkView(chunk);
                const declaredPlatforms = [...new Set(chunkView.platforms || [])];
                const specificPlatforms = declaredPlatforms.filter(platform => !['all', 'all_ota', 'ota'].includes(platform));
                const wildcardPlatform = declaredPlatforms.some(platform => ['all', 'all_ota', 'ota'].includes(platform));
                const selectedPlatform = String(knowledgeCenterFilter.value.platform || '').trim().toLowerCase();
                if (!unitId || !chunkId) {
                    showToast('SOP卡片标识缺失，无法生成任务', 'error');
                    return;
                }
                if (!hotelId) {
                    showToast('请先在知识中枢选择任务目标门店', 'warning');
                    return;
                }
                if (!selectedPlatform && specificPlatforms.length > 1 && !wildcardPlatform) {
                    showToast('该SOP适用于多个平台，请先在平台筛选中选择具体平台', 'warning');
                    return;
                }
                const requestedPlatform = selectedPlatform || 'ota';
                const due = new Date();
                due.setDate(due.getDate() + 7);
                knowledgeSopTaskCreatingChunkId.value = chunkId;
                try {
                    const res = await request(`/knowledge/${unitId}/chunks/${chunkId}/execution-intent`, {
                        method: 'POST',
                        body: JSON.stringify({
                            hotel_id: hotelId,
                            platform: requestedPlatform,
                            due_at: due.toISOString().slice(0, 10),
                        }),
                    });
                    if (res.code !== 0) {
                        throw new Error(res.msg || 'SOP任务草稿生成失败');
                    }
                    const responseIntent = res.data?.execution_intent || {};
                    const intentId = Number(responseIntent.id || 0);
                    if (!Number.isInteger(intentId) || intentId <= 0) {
                        throw new Error('任务草稿创建结果缺少有效ID');
                    }
                    const persistedIntent = await readOperationExecutionIntent(intentId);
                    const persistedEvidence = persistedIntent?.evidence && typeof persistedIntent.evidence === 'object'
                        ? persistedIntent.evidence
                        : {};
                    const provenance = persistedEvidence?.knowledge_provenance && typeof persistedEvidence.knowledge_provenance === 'object'
                        ? persistedEvidence.knowledge_provenance
                        : {};
                    const replayed = responseIntent.idempotent_replay === true;
                    const persistedStatus = String(persistedIntent.status || '');
                    if (Number(persistedIntent.hotel_id || 0) !== hotelId
                        || String(persistedIntent.source_module || '') !== 'knowledge_sop'
                        || Number(persistedIntent.source_record_id || 0) !== chunkId
                        || Number(provenance.knowledge_unit_id || 0) !== unitId
                        || Number(provenance.knowledge_chunk_id || 0) !== chunkId
                        || Number(provenance.target_hotel_id || 0) !== hotelId
                        || String(provenance.resolved_platform || '') !== String(persistedIntent.platform || '')
                        || String(persistedIntent.blocked_reason || '').trim() !== ''
                        || !['pending_approval', 'approved', 'rejected'].includes(persistedStatus)
                        || (!replayed && (
                            persistedStatus !== 'pending_approval'
                            || (Array.isArray(persistedIntent.tasks) && persistedIntent.tasks.length > 0)
                        ))
                    ) {
                        throw new Error('任务草稿数据库回读与知识来源、门店或平台不一致');
                    }
                    operationFilters.value.hotel_id = String(hotelId);
                    await loadOperationActions();
                    if (replayed) {
                        const statusText = persistedStatus === 'approved'
                            ? '已审批'
                            : (persistedStatus === 'rejected' ? '已驳回' : '待审批');
                        showToast(`该知识快照已有任务 #${intentId}（${statusText}），未重复创建`);
                    } else {
                        showToast(`任务草稿 #${intentId} 已保存并回读，等待人工审批`);
                    }
                } catch (error) {
                    showToast(error.message || 'SOP任务草稿生成失败', 'error');
                } finally {
                    if (knowledgeSopTaskCreatingChunkId.value === chunkId) {
                        knowledgeSopTaskCreatingChunkId.value = 0;
                    }
                }
            };

            const isAllKnowledgeCenterPageSelected = computed(() => {
                const ids = knowledgeCenterUnits.value.filter(unit => unit?.can_edit !== false).map(unit => String(unit.unit_id)).filter(Boolean);
                return ids.length > 0 && ids.every(id => selectedKnowledgeCenterUnitIds.value.includes(id));
            });

            const toggleSelectAllKnowledgeCenterUnits = (checked) => {
                selectedKnowledgeCenterUnitIds.value = checked
                    ? knowledgeCenterUnits.value.filter(unit => unit?.can_edit !== false).map(unit => String(unit.unit_id)).filter(Boolean)
                    : [];
            };

            const batchDeleteKnowledgeUnits = async () => {
                const ids = [...new Set(selectedKnowledgeCenterUnitIds.value.map(id => String(id)).filter(Boolean))];
                if (ids.length === 0 || knowledgeCenterBatchDeleting.value) return;

                if (!confirm(`确认删除当前选中的 ${ids.length} 条知识？该操作会同时删除对应片段。`)) {
                    return;
                }

                knowledgeCenterBatchDeleting.value = true;
                try {
                    const failures = [];
                    for (const id of ids) {
                        try {
                            const res = await request(`/knowledge/${id}`, { method: 'DELETE' });
                            if (res.code !== 0) {
                                failures.push(`#${id}: ${res.msg || '删除失败'}`);
                            }
                        } catch (error) {
                            failures.push(`#${id}: ${error.message || '删除失败'}`);
                        }
                    }

                    selectedKnowledgeCenterUnitIds.value = [];
                    await loadKnowledgeCenter();
                    if (failures.length > 0) {
                        showToast(`批量删除完成，失败 ${failures.length} 条：${failures.slice(0, 2).join('；')}`, 'error');
                    } else {
                        showToast(`已删除 ${ids.length} 条知识`);
                    }
                } finally {
                    knowledgeCenterBatchDeleting.value = false;
                }
            };

            const knowledgeDocumentTextExtensions = requireSystemStatic('knowledgeDocumentTextExtensions');
            const knowledgeDocumentHtmlExtensions = requireSystemStatic('knowledgeDocumentHtmlExtensions');
            const knowledgeDocumentSupportedExtensions = requireSystemStatic('knowledgeDocumentSupportedExtensions');
            const knowledgeDocumentMaxBytes = 5 * 1024 * 1024;

            const knowledgeDocumentExtension = (file) => {
                const name = String(file?.name || '');
                const index = name.lastIndexOf('.');
                return index >= 0 ? name.slice(index + 1).toLowerCase() : '';
            };

            const normalizeKnowledgeDocumentText = (value) => {
                return String(value || '')
                    .replace(/\r\n/g, '\n')
                    .replace(/\r/g, '\n')
                    .replace(/[ \t]+\n/g, '\n')
                    .replace(/\n{3,}/g, '\n\n')
                    .trim();
            };

            const knowledgeImportContextChangedCode = 'knowledge_import_context_changed';

            const captureKnowledgeImportActionContext = (action, hotelId = knowledgeCenterImportForm.value.hotel_id) => ({
                epoch: ++knowledgeCenterImportActionEpoch,
                action: String(action || ''),
                session: captureAuthSession(),
                page: currentPage.value,
                hotelId: String(hotelId || '').trim(),
            });

            const isKnowledgeImportActionCurrent = (context) => (
                !!context
                && context.epoch === knowledgeCenterImportActionEpoch
                && isAuthSessionCurrent(context.session)
                && currentPage.value === context.page
                && showKnowledgeCenterImportModal.value
                && String(knowledgeCenterImportForm.value.hotel_id || '').trim() === context.hotelId
            );

            const assertKnowledgeImportActionCurrent = (context) => {
                if (isKnowledgeImportActionCurrent(context)) return;
                const error = new Error('知识导入上下文已变化，旧响应已丢弃');
                error.code = knowledgeImportContextChangedCode;
                throw error;
            };

            const isKnowledgeImportContextChangedError = (error) => (
                String(error?.code || '') === knowledgeImportContextChangedCode
            );

            const requireKnowledgeImportField = (source, field, label) => {
                if (!source || typeof source !== 'object' || !Object.prototype.hasOwnProperty.call(source, field)) {
                    throw new Error(`${label} 缺少 ${field}`);
                }
                return source[field];
            };

            const normalizeKnowledgeImportCount = (value, label, { positive = false } = {}) => {
                if (value === null || value === undefined || value === '') {
                    throw new Error(`${label} 未返回`);
                }
                const normalized = Number(value);
                if (!Number.isInteger(normalized) || normalized < (positive ? 1 : 0)) {
                    throw new Error(`${label} 不是有效整数`);
                }
                return normalized;
            };

            const normalizeKnowledgeImportStringArray = (value, label) => {
                if (!Array.isArray(value)) throw new Error(`${label} 不是数组`);
                return value.map((item, index) => {
                    if (typeof item !== 'string' || item === '') {
                        throw new Error(`${label}[${index}] 不是有效字符串`);
                    }
                    return item;
                });
            };

            const normalizeKnowledgeSourceDocumentForReadback = (source, label) => {
                if (!source || typeof source !== 'object') throw new Error(`${label} 未返回 source_document`);
                const filename = String(requireKnowledgeImportField(source, 'filename', label) || '');
                const extension = String(requireKnowledgeImportField(source, 'extension', label) || '').toLowerCase();
                const sha256 = String(requireKnowledgeImportField(source, 'sha256', label) || '').toLowerCase();
                const textSha256 = String(requireKnowledgeImportField(source, 'text_sha256', label) || '').toLowerCase();
                const charCount = normalizeKnowledgeImportCount(
                    requireKnowledgeImportField(source, 'char_count', label),
                    `${label}.char_count`,
                    { positive: true }
                );
                if (!filename || extension !== 'xlsx' || !/^[a-f0-9]{64}$/.test(sha256) || !/^[a-f0-9]{64}$/.test(textSha256)) {
                    throw new Error(`${label} 的文件名、扩展名或 SHA-256 无效`);
                }
                const sheetsSource = requireKnowledgeImportField(source, 'sheets', label);
                if (!Array.isArray(sheetsSource) || sheetsSource.length === 0) {
                    throw new Error(`${label}.sheets 未返回工作表`);
                }
                const sheets = sheetsSource.map((sheet, index) => {
                    const sheetLabel = `${label}.sheets[${index}]`;
                    const name = String(requireKnowledgeImportField(sheet, 'name', sheetLabel) || '');
                    if (!name) throw new Error(`${sheetLabel}.name 未返回`);
                    const cellRefsTruncated = requireKnowledgeImportField(sheet, 'cell_refs_truncated', sheetLabel);
                    if (typeof cellRefsTruncated !== 'boolean') {
                        throw new Error(`${sheetLabel}.cell_refs_truncated 不是布尔值`);
                    }
                    return {
                        name,
                        row_count: normalizeKnowledgeImportCount(
                            requireKnowledgeImportField(sheet, 'row_count', sheetLabel),
                            `${sheetLabel}.row_count`
                        ),
                        cell_count: normalizeKnowledgeImportCount(
                            requireKnowledgeImportField(sheet, 'cell_count', sheetLabel),
                            `${sheetLabel}.cell_count`
                        ),
                        cell_refs: normalizeKnowledgeImportStringArray(
                            requireKnowledgeImportField(sheet, 'cell_refs', sheetLabel),
                            `${sheetLabel}.cell_refs`
                        ),
                        cell_refs_truncated: cellRefsTruncated,
                        merged_ranges: normalizeKnowledgeImportStringArray(
                            requireKnowledgeImportField(sheet, 'merged_ranges', sheetLabel),
                            `${sheetLabel}.merged_ranges`
                        ),
                    };
                });
                return {
                    filename,
                    extension,
                    sha256,
                    text_sha256: textSha256,
                    char_count: charCount,
                    sheets,
                };
            };

            const assertKnowledgeSourceDocumentExact = (expected, actual, label) => {
                const expectedNormalized = normalizeKnowledgeSourceDocumentForReadback(expected, '预览 source_document');
                const actualNormalized = normalizeKnowledgeSourceDocumentForReadback(actual, label);
                if (JSON.stringify(actualNormalized) !== JSON.stringify(expectedNormalized)) {
                    throw new Error(`${label} 与预览的完整 XLSX 来源元数据不一致`);
                }
                return actualNormalized;
            };

            const normalizeKnowledgeExactJson = (value) => {
                if (Array.isArray(value)) return value.map(normalizeKnowledgeExactJson);
                if (value && typeof value === 'object') {
                    return Object.keys(value).sort().reduce((result, key) => {
                        result[key] = normalizeKnowledgeExactJson(value[key]);
                        return result;
                    }, {});
                }
                return value;
            };

            const knowledgeExactJsonMatches = (left, right) => (
                JSON.stringify(normalizeKnowledgeExactJson(left)) === JSON.stringify(normalizeKnowledgeExactJson(right))
            );

            const extractKnowledgeTextFromHtml = (html) => {
                const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
                doc.body.querySelectorAll('br').forEach(node => node.replaceWith('\n'));
                doc.body.querySelectorAll('p,div,li,tr,h1,h2,h3,h4,h5,h6').forEach(node => {
                    node.appendChild(doc.createTextNode('\n'));
                });
                return normalizeKnowledgeDocumentText(doc.body.textContent || '');
            };

            const appendKnowledgeDocumentText = (text, label = '文档') => {
                const normalized = normalizeKnowledgeDocumentText(text);
                if (!normalized) {
                    knowledgeCenterImportDocumentError.value = `${label}没有可导入的文字内容`;
                    return false;
                }

                const current = normalizeKnowledgeDocumentText(knowledgeCenterImportForm.value.raw);
                knowledgeCenterImportForm.value.raw = current ? `${current}\n\n${normalized}` : normalized;
                knowledgeCenterImportDocumentError.value = '';
                knowledgeCenterImportDocumentNotice.value = `已读取 ${label}，${normalized.length} 字`;
                return true;
            };

            const extractKnowledgeDocumentByApi = async (file, actionContext) => {
                const requestSession = actionContext.session;
                const formData = new FormData();
                formData.append('file', file);
                const headers = {};
                if (requestSession.token) headers.Authorization = requestSession.token;

                const response = await fetch(API_BASE + '/knowledge/document-text', {
                    method: 'POST',
                    headers,
                    body: formData,
                });
                assertKnowledgeImportActionCurrent(actionContext);
                const data = await response.json().catch(() => ({}));
                assertKnowledgeImportActionCurrent(actionContext);
                if (response.status === 401 || data.code === 401) {
                    clearAuthSessionIfCurrent(requestSession);
                    throw new Error('登录已过期，请重新登录');
                }
                if (!response.ok || data.code !== 0) {
                    throw new Error(data.msg || data.message || `文档读取失败: ${response.status}`);
                }
                return data.data || {};
            };

            const extractKnowledgeDocumentFileText = async (file, actionContext) => {
                const extension = knowledgeDocumentExtension(file);
                if (!knowledgeDocumentSupportedExtensions.includes(extension)) {
                    throw new Error(`暂不支持 ${extension || '未知'} 类型文档`);
                }
                if (file.size > knowledgeDocumentMaxBytes) {
                    throw new Error(`${file.name} 超过 5MB`);
                }

                if (knowledgeDocumentTextExtensions.includes(extension)) {
                    const text = await file.text();
                    assertKnowledgeImportActionCurrent(actionContext);
                    return { text: normalizeKnowledgeDocumentText(text), source_document: null };
                }
                if (knowledgeDocumentHtmlExtensions.includes(extension)) {
                    const html = await file.text();
                    assertKnowledgeImportActionCurrent(actionContext);
                    return { text: extractKnowledgeTextFromHtml(html), source_document: null };
                }

                const extracted = await extractKnowledgeDocumentByApi(file, actionContext);
                assertKnowledgeImportActionCurrent(actionContext);
                return {
                    text: normalizeKnowledgeDocumentText(extracted?.text || ''),
                    source_document: extracted?.source_document || null,
                };
            };

            const handleKnowledgeDocumentFiles = async (files) => {
                const list = Array.from(files || []).filter(Boolean);
                if (list.length === 0 || knowledgeCenterImportReading.value || knowledgeCenterImporting.value) return;

                const actionContext = captureKnowledgeImportActionContext('preview');
                knowledgeCenterImportReading.value = true;
                knowledgeCenterImportDocumentError.value = '';
                knowledgeCenterImportDocumentNotice.value = '读取中...';
                try {
                    const xlsxFiles = list.filter(file => knowledgeDocumentExtension(file) === 'xlsx');
                    if (xlsxFiles.length > 0 && (xlsxFiles.length !== 1 || list.length !== 1)) {
                        throw new Error('XLSX 必须单独选择；每次导入一个工作簿以保持来源指纹可核验');
                    }
                    const parsed = [];
                    for (const file of list) {
                        const extracted = await extractKnowledgeDocumentFileText(file, actionContext);
                        assertKnowledgeImportActionCurrent(actionContext);
                        if (!extracted.text) {
                            throw new Error(`${file.name} 未解析到文字内容`);
                        }
                        parsed.push({ name: file.name, file, ...extracted });
                    }
                    if (xlsxFiles.length === 1) {
                        const workbook = parsed[0];
                        const sourceDocument = normalizeKnowledgeSourceDocumentForReadback(
                            workbook.source_document,
                            'XLSX 预览 source_document'
                        );
                        assertKnowledgeImportActionCurrent(actionContext);
                        knowledgeCenterImportSelectedFile.value = workbook.file;
                        knowledgeCenterImportSourceDocument.value = sourceDocument;
                        knowledgeCenterImportPreviewRaw.value = normalizeKnowledgeDocumentText(workbook.text);
                        knowledgeCenterImportForm.value = {
                            ...knowledgeCenterImportForm.value,
                            mode: 'xlsx',
                            source: 'manual_template',
                            raw: workbook.text,
                        };
                        const sheetNames = sourceDocument.sheets.map(sheet => sheet.name).filter(Boolean);
                        knowledgeCenterImportDocumentNotice.value = `已由服务端读取 ${workbook.name} · SHA-256 ${String(sourceDocument.sha256).slice(0, 12)}… · ${sheetNames.length} 个工作表`;
                        showToast('XLSX 已预览；提交时服务端会重新解析同一文件');
                        return;
                    }
                    assertKnowledgeImportActionCurrent(actionContext);
                    knowledgeCenterImportSelectedFile.value = null;
                    knowledgeCenterImportSourceDocument.value = null;
                    knowledgeCenterImportPreviewRaw.value = '';
                    const combined = parsed.map(item => parsed.length > 1 ? `【${item.name}】\n${item.text}` : item.text).join('\n\n');
                    appendKnowledgeDocumentText(combined, parsed.length > 1 ? `${parsed.length} 个文档` : parsed[0].name);
                    showToast('文档内容已写入输入框');
                } catch (error) {
                    if (isKnowledgeImportContextChangedError(error) || !isKnowledgeImportActionCurrent(actionContext)) return;
                    knowledgeCenterImportDocumentError.value = error.message || '文档读取失败';
                    showToast(knowledgeCenterImportDocumentError.value, 'error');
                } finally {
                    if (knowledgeCenterImportActionEpoch === actionContext.epoch) {
                        knowledgeCenterImportReading.value = false;
                    }
                }
            };

            const handleKnowledgeDocumentPaste = async (event) => {
                if (knowledgeCenterImportReading.value || knowledgeCenterImporting.value || knowledgeCenterImportSelectedFile.value) {
                    event.preventDefault();
                    return;
                }
                const clipboard = event.clipboardData;
                if (!clipboard) return;

                const files = Array.from(clipboard.files || []);
                if (files.length > 0) {
                    event.preventDefault();
                    await handleKnowledgeDocumentFiles(files);
                    return;
                }

                const targetTag = String(event.target?.tagName || '').toLowerCase();
                const plainText = clipboard.getData('text/plain');
                const htmlText = clipboard.getData('text/html');
                if (targetTag === 'textarea' && plainText) {
                    knowledgeCenterImportDocumentError.value = '';
                    knowledgeCenterImportDocumentNotice.value = '已粘贴正文';
                    return;
                }

                const text = plainText || (htmlText ? extractKnowledgeTextFromHtml(htmlText) : '');
                if (!text) return;

                event.preventDefault();
                appendKnowledgeDocumentText(text, '剪贴板');
            };

            const handleKnowledgeDocumentDrop = async (event) => {
                if (knowledgeCenterImportReading.value || knowledgeCenterImporting.value || knowledgeCenterImportSelectedFile.value) return;
                const files = event.dataTransfer?.files || [];
                if (files.length > 0) {
                    await handleKnowledgeDocumentFiles(files);
                    return;
                }

                const text = event.dataTransfer?.getData('text/plain') || '';
                if (text) {
                    appendKnowledgeDocumentText(text, '拖入内容');
                }
            };

            const handleKnowledgeDocumentFileSelect = async (event) => {
                if (knowledgeCenterImportReading.value || knowledgeCenterImporting.value) {
                    if (event.target) event.target.value = '';
                    return;
                }
                await handleKnowledgeDocumentFiles(event.target?.files || []);
                if (event.target) event.target.value = '';
            };

            const openKnowledgeDocumentFilePicker = () => {
                if (knowledgeCenterImportReading.value || knowledgeCenterImporting.value) return;
                knowledgeDocumentFileInput.value?.click();
            };

            const focusKnowledgeDocumentTextarea = () => {
                if (knowledgeCenterImportReading.value || knowledgeCenterImporting.value || knowledgeCenterImportSelectedFile.value) return;
                knowledgeDocumentTextarea.value?.focus();
            };

            const setKnowledgeImportMode = (mode = 'document') => {
                if (knowledgeCenterImportReading.value || knowledgeCenterImporting.value) return;
                const nextMode = mode || 'document';
                knowledgeCenterImportForm.value = {
                    ...knowledgeCenterImportForm.value,
                    mode: nextMode,
                    source: nextMode,
                };
            };

            const openKnowledgeImportModal = (mode = 'document') => {
                knowledgeCenterImportActionEpoch += 1;
                const nextMode = mode || 'document';
                knowledgeCenterImportForm.value = {
                    mode: nextMode,
                    source: nextMode,
                    hotel_id: knowledgeCenterImportForm.value.hotel_id || defaultKnowledgeCenterHotelId(),
                    model_key: knowledgeCenterImportForm.value.model_key || 'deepseek_chat',
                    tags: '',
                    raw: '',
                };
                knowledgeCenterImportDocumentNotice.value = '';
                knowledgeCenterImportDocumentError.value = '';
                knowledgeCenterImportReading.value = false;
                knowledgeCenterImportSelectedFile.value = null;
                knowledgeCenterImportSourceDocument.value = null;
                knowledgeCenterImportPreviewRaw.value = '';
                showKnowledgeCenterImportModal.value = true;
            };

            const closeKnowledgeImportModal = () => {
                if (knowledgeCenterImportReading.value || knowledgeCenterImporting.value) return;
                knowledgeCenterImportActionEpoch += 1;
                showKnowledgeCenterImportModal.value = false;
            };

            const verifyKnowledgeImportReadback = async (importData, expected = {}) => {
                const actionContext = expected.actionContext;
                assertKnowledgeImportActionCurrent(actionContext);
                const expectedHotelId = normalizeKnowledgeImportCount(expected.hotelId, '提交 hotel_id', { positive: true });
                const expectedModelKey = String(expected.modelKey || '');
                const expectedRawText = normalizeKnowledgeDocumentText(expected.rawText);
                const expectedSourceDocument = expected.sourceDocument
                    ? normalizeKnowledgeSourceDocumentForReadback(expected.sourceDocument, '预览 source_document')
                    : null;
                const singleXlsx = expected.singleXlsx === true;
                const created = Array.isArray(importData?.created) ? importData.created : [];
                if (created.length === 0) {
                    throw new Error('导入响应没有返回可精确回读的知识记录');
                }
                const errors = requireKnowledgeImportField(importData, 'errors', '导入响应');
                if (!Array.isArray(errors)) throw new Error('导入响应 errors 不是数组');
                const successCount = normalizeKnowledgeImportCount(
                    requireKnowledgeImportField(importData, 'success_count', '导入响应'),
                    '导入响应 success_count'
                );
                const errorCount = normalizeKnowledgeImportCount(
                    requireKnowledgeImportField(importData, 'error_count', '导入响应'),
                    '导入响应 error_count'
                );
                if (Number(importData?.hotel_id || 0) !== expectedHotelId
                    || errorCount !== 0
                    || errors.length !== 0
                    || successCount !== created.length
                    || (singleXlsx && (successCount !== 1 || created.length !== 1))
                ) {
                    throw new Error('AI 摘要未全部成功，导入结果不会关闭或标记为完成');
                }
                if (expectedSourceDocument) {
                    const importContext = importData?.import_context;
                    if (!importContext || typeof importContext !== 'object'
                        || importContext.material_classification !== 'manual_template'
                        || importContext.knowledge_scope !== 'industry_general'
                        || importContext.verification_status !== 'unverified'
                        || importContext.container_scope !== 'authorized_hotel_container_only'
                    ) {
                        throw new Error('XLSX 导入响应丢失人工模板、行业通用或未核验边界');
                    }
                    assertKnowledgeSourceDocumentExact(
                        expectedSourceDocument,
                        importContext.source_document,
                        'POST import_context.source_document'
                    );
                }
                const verifiedUnits = [];
                for (const item of created) {
                    assertKnowledgeImportActionCurrent(actionContext);
                    const unitId = Number(item?.unit?.unit_id || item?.readback?.unit_id || 0);
                    const chunkId = Number(item?.chunk?.chunk_id || item?.readback?.chunk_id || 0);
                    const postUnit = item?.unit || {};
                    const postChunk = item?.chunk || {};
                    const postContent = postChunk?.content;
                    const postSummary = String(postContent?.ai_distilled?.summary || '').trim();
                    const expectedDescription = Array.from(postSummary).slice(0, 1000).join('');
                    if (!item?.readback_verified
                        || unitId <= 0
                        || chunkId <= 0
                        || Number(postUnit.hotel_id || 0) !== expectedHotelId
                        || String(postUnit.status || '') !== 'done'
                        || !String(postUnit.name || '').trim()
                        || !postSummary
                        || String(postUnit.description || '') !== expectedDescription
                        || !postContent
                        || typeof postContent !== 'object'
                        || Number(postContent.hotel_id || 0) !== expectedHotelId
                        || String(postContent.model_key || '') !== expectedModelKey
                        || String(postContent.ai_distilled?.model_key || '') !== expectedModelKey
                        || !String(postContent.distilled_at || '').trim()
                    ) {
                        throw new Error('导入已提交，但服务端保存回读未确认');
                    }
                    const responseReadbackUnit = item?.readback?.unit_snapshot || {};
                    const responseReadbackChunk = item?.readback?.chunk_snapshot || {};
                    if (Number(responseReadbackUnit.unit_id || 0) !== unitId
                        || Number(responseReadbackChunk.chunk_id || 0) !== chunkId
                        || String(responseReadbackUnit.description || '') !== expectedDescription
                        || !knowledgeExactJsonMatches(responseReadbackChunk.content, postContent)
                    ) {
                        throw new Error(`知识 #${unitId} 的 POST 保存快照与服务端回查快照不一致`);
                    }
                    if (singleXlsx) {
                        const expectedBlockedUses = [
                            'hotel_fact_claim',
                            'ota_fact_claim',
                            'business_date_fact_claim',
                            'operation_task_creation',
                            'automatic_ota_write',
                        ];
                        const tags = Array.isArray(postUnit.tags) ? postUnit.tags : [];
                        if (normalizeKnowledgeDocumentText(postContent.raw_text) !== expectedRawText
                            || postContent.material_type !== 'xlsx'
                            || postContent.source !== 'manual_template'
                            || postContent.material_classification !== 'manual_template'
                            || postContent.knowledge_scope !== 'industry_general'
                            || postContent.verification_status !== 'unverified'
                            || postContent.facts_scope !== 'document_reference_not_hotel_fact'
                            || postContent.container_scope !== 'authorized_hotel_container_only'
                            || postContent.requires_current_verification !== true
                            || !knowledgeExactJsonMatches(postContent.blocked_uses, expectedBlockedUses)
                            || !['人工模板', '行业通用', '未核验'].every(tag => tags.includes(tag))
                        ) {
                            throw new Error(`知识 #${unitId} 的 XLSX 原文或事实使用边界不一致`);
                        }
                        assertKnowledgeSourceDocumentExact(
                            expectedSourceDocument,
                            postContent.source_document,
                            `POST 知识 #${unitId} source_document`
                        );
                    }
                    const detail = await request(`/knowledge/${unitId}`);
                    assertKnowledgeImportActionCurrent(actionContext);
                    const detailUnit = detail?.data?.unit || {};
                    const detailChunks = Array.isArray(detail?.data?.chunks) ? detail.data.chunks : [];
                    const detailChunk = detailChunks.find(chunk => Number(chunk?.chunk_id || 0) === chunkId);
                    if (detail?.code !== 0
                        || Number(detailUnit?.unit_id || 0) !== unitId
                        || Number(detailUnit?.hotel_id || 0) !== expectedHotelId
                        || String(detailUnit?.name || '') !== String(postUnit.name || '')
                        || String(detailUnit?.source || '') !== String(postUnit.source || '')
                        || String(detailUnit?.status || '') !== 'done'
                        || String(detailUnit?.description || '') !== expectedDescription
                        || !knowledgeExactJsonMatches(detailUnit?.tags, postUnit.tags)
                        || !detailChunk
                        || Number(detailChunk?.unit_id || 0) !== unitId
                        || String(detailChunk?.type || '') !== String(postChunk.type || '')
                        || !knowledgeExactJsonMatches(detailChunk?.content, postContent)
                    ) {
                        throw new Error(`知识 #${unitId} 已保存，但独立 API 回读不一致`);
                    }
                    if (expectedSourceDocument) {
                        const detailContent = detailChunk.content || {};
                        assertKnowledgeSourceDocumentExact(
                            expectedSourceDocument,
                            detailContent.source_document,
                            `独立 GET 知识 #${unitId} source_document`
                        );
                        if (normalizeKnowledgeDocumentText(detailContent.raw_text) !== expectedRawText
                            || String(detailContent.ai_distilled?.summary || '').trim() !== postSummary
                            || String(detailUnit.description || '') !== Array.from(postSummary).slice(0, 1000).join('')
                        ) {
                            throw new Error(`知识 #${unitId} 的 XLSX 原文或 AI 摘要独立回读不一致`);
                        }
                    }
                    verifiedUnits.push({
                        unit_id: unitId,
                        hotel_id: expectedHotelId,
                        status: 'done',
                        name: String(postUnit.name || ''),
                        description: expectedDescription,
                        ai_summary: postSummary,
                    });
                }
                return verifiedUnits;
            };

            const importKnowledgeUnits = async () => {
                if (knowledgeCenterImporting.value || knowledgeCenterImportReading.value) return;
                const form = { ...knowledgeCenterImportForm.value };
                const raw = normalizeKnowledgeDocumentText(form.raw);
                const selectedFile = knowledgeCenterImportSelectedFile.value;
                const lockedPreviewRaw = normalizeKnowledgeDocumentText(knowledgeCenterImportPreviewRaw.value);
                const hotelId = String(form.hotel_id || '').trim();
                if (!raw && !selectedFile) {
                    showToast('请输入导入内容', 'error');
                    return;
                }
                if (!hotelId) {
                    showToast('请选择资料绑定的门店', 'error');
                    return;
                }
                if (selectedFile && (!lockedPreviewRaw || raw !== lockedPreviewRaw)) {
                    knowledgeCenterImportDocumentError.value = 'XLSX 预览原文已变化，请重新选择同一工作簿后再提交';
                    showToast(knowledgeCenterImportDocumentError.value, 'error');
                    return;
                }
                const frozenRaw = selectedFile ? lockedPreviewRaw : raw;
                let sourceDocument = null;
                if (selectedFile) {
                    try {
                        sourceDocument = normalizeKnowledgeSourceDocumentForReadback(
                            knowledgeCenterImportSourceDocument.value,
                            '提交前预览 source_document'
                        );
                    } catch (error) {
                        knowledgeCenterImportDocumentError.value = error?.message || 'XLSX 预览来源未锁定';
                        showToast(knowledgeCenterImportDocumentError.value, 'error');
                        return;
                    }
                }

                const actionContext = captureKnowledgeImportActionContext('import', hotelId);
                knowledgeCenterImporting.value = true;
                const controller = new AbortController();
                const requestSession = captureAuthSession();
                const timeoutMs = 90000;
                const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
                const requestBody = buildKnowledgeImportRequestBody({
                    form,
                    raw: frozenRaw,
                    tags: parseKnowledgeTags(form.tags),
                });
                try {
                    const headers = requestSession.token ? { Authorization: requestSession.token } : {};
                    let body;
                    if (selectedFile) {
                        const multipart = new FormData();
                        multipart.append('file', selectedFile);
                        multipart.append('hotel_id', hotelId);
                        multipart.append('model_key', String(form.model_key || 'deepseek_chat'));
                        multipart.append('tags', JSON.stringify(parseKnowledgeTags(form.tags)));
                        body = multipart;
                    } else {
                        headers['Content-Type'] = 'application/json';
                        body = JSON.stringify(requestBody);
                    }
                    const response = await fetch(API_BASE + '/knowledge/import', {
                        method: 'POST',
                        headers,
                        body,
                        signal: controller.signal,
                    });
                    assertKnowledgeImportActionCurrent(actionContext);
                    const res = await response.json().catch(() => ({}));
                    assertKnowledgeImportActionCurrent(actionContext);
                    if (response.status === 401 || res.code === 401) {
                        clearAuthSessionIfCurrent(requestSession);
                        throw new Error('登录已过期，请重新登录');
                    }
                    if (!response.ok) {
                        throw new Error(res.msg || res.message || `导入失败: ${response.status}`);
                    }
                    if (res.code !== 0) {
                        throw new Error(res.msg || res.message || '导入失败');
                    }
                    const importedUnits = await verifyKnowledgeImportReadback(res.data, {
                        actionContext,
                        hotelId,
                        modelKey: String(form.model_key || 'deepseek_chat'),
                        rawText: frozenRaw,
                        sourceDocument,
                        singleXlsx: !!selectedFile,
                    });
                    assertKnowledgeImportActionCurrent(actionContext);
                    knowledgeCenterPagination.value.page = 1;
                    await loadKnowledgeCenter({ hotelId });
                    assertKnowledgeImportActionCurrent(actionContext);
                    for (const importedUnit of importedUnits) {
                        const visibleUnit = knowledgeCenterUnits.value.find(unit => (
                            Number(unit?.unit_id || 0) === importedUnit.unit_id
                            && Number(unit?.hotel_id || 0) === importedUnit.hotel_id
                        ));
                        if (!visibleUnit
                            || String(visibleUnit.status || '') !== 'done'
                            || String(visibleUnit.name || '') !== importedUnit.name
                            || String(visibleUnit.description || '') !== importedUnit.description
                        ) {
                            throw new Error(`知识 #${importedUnit.unit_id} 已保存并独立回读，但同酒店列表尚未显示相同摘要`);
                        }
                    }
                    showKnowledgeCenterImportModal.value = false;
                    knowledgeCenterImportSelectedFile.value = null;
                    knowledgeCenterImportSourceDocument.value = null;
                    knowledgeCenterImportPreviewRaw.value = '';
                    knowledgeCenterImportDocumentError.value = '';
                    showToast(`${knowledgeImportSuccessMessage(res.data)}；AI 摘要、保存、完整独立回读与同酒店列表显示均已确认`);
                } catch (error) {
                    if (isKnowledgeImportContextChangedError(error) || !isKnowledgeImportActionCurrent(actionContext)) return;
                    const message = knowledgeImportErrorMessage(error);
                    showToast(message, 'error');
                    knowledgeCenterImportDocumentError.value = message;
                } finally {
                    clearTimeout(timeoutId);
                    if (knowledgeCenterImportActionEpoch === actionContext.epoch) {
                        knowledgeCenterImporting.value = false;
                    }
                }
            };



        return Object.freeze({
            knowledgeChunkView,
            knowledgeCenterVisibleChunks,
            loadKnowledgeCenter,
            knowledgePromotionStatusLabel,
            knowledgePromotionStatusClass,
            loadKnowledgePromotionWorkbench,
            changeKnowledgePromotionHotel,
            openKnowledgePromotionCandidate,
            createKnowledgePromotionCandidate,
            createKnowledgeSopCandidate,
            saveKnowledgePromotionRevision,
            submitKnowledgePromotionCandidate,
            reviewKnowledgePromotionCandidate,
            withdrawKnowledgePromotionCandidate,
            operatingNetworkStageStatusLabel,
            operatingNetworkStageStatusClass,
            operatingNetworkDimensionStatusLabel,
            operatingNetworkDimensionStatusClass,
            loadOperatingNetwork,
            changeOperatingNetworkHotel,
            generateOperatingNetworkProfilePreview,
            applyOperatingNetworkProfilePreview,
            saveOperatingNetworkProfile,
            restoreOperatingNetworkReplication,
            operatingNetworkReplicationLabel,
            generateOperatingNetworkReplicationDraft,
            createOperatingNetworkExecutionIntent,
            openOperatingNetworkExecutionIntent,
            saveOperatingNetworkReview,
            reloadKnowledgeCenter,
            changeKnowledgeCenterPage,
            resetKnowledgeCenterFilter,
            filterByKnowledgeStatus,
            updateKnowledgeUnitStatus,
            openKnowledgeUnitModal,
            saveKnowledgeUnit,
            deleteKnowledgeUnit,
            refreshKnowledgeUnit,
            runKnowledgeDistillation,
            openKnowledgeChunks,
            saveKnowledgeChunk,
            createKnowledgeSopTask,
            isAllKnowledgeCenterPageSelected,
            toggleSelectAllKnowledgeCenterUnits,
            batchDeleteKnowledgeUnits,
            handleKnowledgeDocumentPaste,
            handleKnowledgeDocumentDrop,
            handleKnowledgeDocumentFileSelect,
            openKnowledgeDocumentFilePicker,
            focusKnowledgeDocumentTextarea,
            setKnowledgeImportMode,
            openKnowledgeImportModal,
            closeKnowledgeImportModal,
            importKnowledgeUnits,
        });
    };

    window.SUXI_KNOWLEDGE_CENTER_DOMAIN = Object.freeze({ create });
})();
