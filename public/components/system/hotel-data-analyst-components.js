(() => {
    'use strict';

    const create = ({ inject, h, nextTick }) => {
        const HOTEL_DATA_ANALYST_SUGGESTIONS = Object.freeze([
            '当前选择范围最需要复核的经营指标是什么？请列出证据和缺口。',
            '分析当前选择日期的曝光、浏览到下单链路，缺失指标不要补零。',
            '对比当前范围的关键指标，区分事实、异常信号和可能解释。',
            '基于当前可信事实生成一份管理层可读的简短酒店数据分析。',
        ]);

        // HOTEL_DATA_ANALYST_QUALITY_UI_START
        const normalizeHotelDataAnalystQualityReceipt = (receipt) => {
            const fallback = (reason) => ({
                visible: true,
                contractVersion: '',
                status: 'blocked',
                statusLabel: '质量回执缺失',
                qualityStatus: 'failed',
                claimStatus: 'blocked',
                summary: '当前记录没有可核对的分析质量回执，不能把它升级为可信结论。',
                checks: [],
                checkCount: 0,
                passedCount: 0,
                partialCount: 0,
                blockedCount: 1,
                nextActions: ['重新读取当前分析记录；仍缺失时重新生成。'],
                subjectDigest: '',
                scopeDigest: '',
                evidenceDigest: '',
                receiptDigest: '',
                verifiedPortionUsable: false,
                externalActionAuthorized: false,
                invalidReason: reason,
            });
            if (!receipt || typeof receipt !== 'object') return fallback('analysis_quality_receipt_missing');
            const checks = (Array.isArray(receipt.checks) ? receipt.checks : []).map((check) => ({
                key: String(check?.key || ''),
                label: String(check?.label || check?.key || '未命名检查'),
                status: ['passed', 'partial', 'blocked'].includes(String(check?.status || ''))
                    ? String(check.status)
                    : 'blocked',
                message: String(check?.message || ''),
                reasonCode: String(check?.reason_code || ''),
            }));
            const passedCount = checks.filter((check) => check.status === 'passed').length;
            const partialCount = checks.filter((check) => check.status === 'partial').length;
            const blockedCount = checks.filter((check) => check.status === 'blocked').length;
            const claimStatus = String(receipt.claim_status || '');
            const expectedStatus = ({ supported: 'ready', limited: 'partial', blocked: 'blocked' })[claimStatus] || 'blocked';
            const subjectDigest = String(receipt.subject_digest || receipt.source_content_digest || '');
            const scopeDigest = String(receipt.scope_digest || '');
            const evidenceDigest = String(receipt.evidence_digest || '');
            const receiptDigest = String(receipt.receipt_digest || '');
            const usage = receipt.usage_policy && typeof receipt.usage_policy === 'object' ? receipt.usage_policy : {};
            const contractValid = String(receipt.contract_version || '') === 'hotel_data_analyst_quality_receipt.v1'
                && String(receipt.role_key || '') === 'hotel_data_analyst';
            const countsValid = Number(receipt.check_count) === checks.length
                && Number(receipt.passed_count) === passedCount
                && Number(receipt.partial_count) === partialCount
                && Number(receipt.blocked_count) === blockedCount;
            const boundariesValid = receipt.external_action_authorized === false
                && usage.external_action_authorized === false
                && usage.ota_write === false
                && usage.pms_write === false
                && usage.external_message === false
                && usage.automatic_execution === false;
            const valid = contractValid
                && ['passed', 'failed'].includes(String(receipt.quality_status || ''))
                && ['supported', 'limited', 'blocked'].includes(claimStatus)
                && String(receipt.status || '') === expectedStatus
                && /^[a-f0-9]{64}$/.test(subjectDigest)
                && /^[a-f0-9]{64}$/.test(scopeDigest)
                && /^[a-f0-9]{64}$/.test(evidenceDigest)
                && /^[a-f0-9]{64}$/.test(receiptDigest)
                && receipt.readback_verified === true
                && checks.length > 0
                && countsValid
                && boundariesValid;
            if (!valid) return fallback('analysis_quality_receipt_contract_invalid');
            return {
                visible: true,
                contractVersion: String(receipt.contract_version),
                status: expectedStatus,
                statusLabel: String(receipt.status_label || ({ ready: '质量回执通过', partial: '部分结果可用', blocked: '分析已阻断' })[expectedStatus]),
                qualityStatus: String(receipt.quality_status),
                claimStatus,
                summary: String(receipt.summary || ''),
                checks,
                checkCount: checks.length,
                passedCount,
                partialCount,
                blockedCount,
                nextActions: Array.isArray(receipt.next_actions) ? receipt.next_actions.map(String).filter(Boolean).slice(0, 4) : [],
                subjectDigest,
                scopeDigest,
                evidenceDigest,
                receiptDigest,
                verifiedPortionUsable: usage.verified_portion_usable === true,
                externalActionAuthorized: false,
                invalidReason: '',
            };
        };
        // HOTEL_DATA_ANALYST_QUALITY_UI_END
        // HOTEL_DATA_ANALYST_FEEDBACK_CLIENT_START
        const createHotelDataAnalystFeedbackUi = ({ getState, request }) => {
            const HOTEL_DATA_ANALYST_FEEDBACK_ISSUE_CODES = new Set([
                'fact_or_number',
                'scope_or_date',
                'metric_definition',
                'interpretation',
            ]);
            const hotelDataAnalystFeedbackSnapshot = (question = {}) => ({
                questionId: Number(question?.id || 0),
                hotelId: Number(question?.hotel_id || 0),
                sourceContentDigest: String(question?.content_digest || '').trim().toLowerCase(),
                qualityReceiptDigest: String(question?.analysis_quality_receipt?.receipt_digest || '').trim().toLowerCase(),
            });
            const hotelDataAnalystFeedbackSnapshotValid = (snapshot = {}) => (
                Number(snapshot.questionId || 0) > 0
                && Number(snapshot.hotelId || 0) > 0
                && /^[a-f0-9]{64}$/.test(String(snapshot.sourceContentDigest || ''))
                && /^[a-f0-9]{64}$/.test(String(snapshot.qualityReceiptDigest || ''))
            );
            const createHotelDataAnalystFeedbackState = (question = {}) => {
                const snapshot = hotelDataAnalystFeedbackSnapshot(question);
                return {
                    question_id: snapshot.questionId,
                    hotel_id: snapshot.hotelId,
                    source_content_digest: snapshot.sourceContentDigest,
                    quality_receipt_digest: snapshot.qualityReceiptDigest,
                    feedback_kind: '',
                    correction_text: '',
                    issue_codes: [],
                    phase: hotelDataAnalystFeedbackSnapshotValid(snapshot) ? 'idle' : 'unavailable',
                    error: hotelDataAnalystFeedbackSnapshotValid(snapshot)
                        ? ''
                        : '当前分析缺少可绑定的内容摘要或质量回执，暂不能保存反馈。',
                    list: [],
                    latest: null,
                    summary: { total: 0, useful: 0, needs_correction: 0 },
                    data_status: 'not_loaded',
                    loaded: false,
                    loading: false,
                    saving: false,
                    idempotency_key: '',
                    saved_feedback_id: 0,
                    saved_message: '',
                    original_analysis_mutated: false,
                    formal_evaluation_case_created: false,
                    model_training_triggered: false,
                    external_action_authorized: false,
                };
            };
            const qualityFeedbackFor = (question = {}) => {
                const snapshot = hotelDataAnalystFeedbackSnapshot(question);
                if (!snapshot.questionId) return createHotelDataAnalystFeedbackState(question);
                const state = getState();
                if (!state.quality_feedback_by_question_id || typeof state.quality_feedback_by_question_id !== 'object') {
                    state.quality_feedback_by_question_id = {};
                }
                const key = String(snapshot.questionId);
                let feedback = state.quality_feedback_by_question_id[key];
                const snapshotChanged = feedback && (
                    Number(feedback.hotel_id || 0) !== snapshot.hotelId
                    || String(feedback.source_content_digest || '') !== snapshot.sourceContentDigest
                    || String(feedback.quality_receipt_digest || '') !== snapshot.qualityReceiptDigest
                );
                if (!feedback || typeof feedback !== 'object' || snapshotChanged) {
                    feedback = createHotelDataAnalystFeedbackState(question);
                    state.quality_feedback_by_question_id[key] = feedback;
                }
                return feedback;
            };
            const updateOperatingQuestionQualityFeedbackDraft = (question = {}, patch = {}) => {
                const feedback = qualityFeedbackFor(question);
                if (!feedback.question_id || feedback.saving || feedback.phase === 'unavailable') return false;
                const next = patch && typeof patch === 'object' ? patch : {};
                if (Object.prototype.hasOwnProperty.call(next, 'feedback_kind')) {
                    const kind = String(next.feedback_kind || '');
                    if (!['', 'useful', 'needs_correction'].includes(kind)) return false;
                    feedback.feedback_kind = kind;
                    if (kind === 'useful') {
                        feedback.correction_text = '';
                        feedback.issue_codes = [];
                    }
                }
                if (Object.prototype.hasOwnProperty.call(next, 'correction_text')) {
                    feedback.correction_text = String(next.correction_text || '').slice(0, 2000);
                }
                if (Object.prototype.hasOwnProperty.call(next, 'issue_codes')) {
                    feedback.issue_codes = Array.from(new Set(
                        (Array.isArray(next.issue_codes) ? next.issue_codes : [])
                            .map((code) => String(code || '').trim().toLowerCase())
                            .filter((code) => HOTEL_DATA_ANALYST_FEEDBACK_ISSUE_CODES.has(code))
                    ));
                }
                feedback.phase = 'editing';
                feedback.error = '';
                feedback.saved_message = '';
                feedback.idempotency_key = '';
                return true;
            };
            const assertHotelDataAnalystFeedbackReadback = (row = {}, snapshot = {}, expected = {}) => {
                const kind = String(row?.feedback_kind || '');
                const correction = row?.correction && typeof row.correction === 'object' ? row.correction : {};
                const issueCodes = Array.isArray(correction.issue_codes) ? correction.issue_codes.map(String) : [];
                if (!row || typeof row !== 'object'
                    || Number(row.id || 0) <= 0
                    || Number(row.question_id || 0) !== Number(snapshot.questionId || 0)
                    || Number(row.hotel_id || 0) !== Number(snapshot.hotelId || 0)
                    || String(row.source_content_digest || '') !== String(snapshot.sourceContentDigest || '')
                    || String(row.quality_receipt_digest || '') !== String(snapshot.qualityReceiptDigest || '')
                    || !['useful', 'needs_correction'].includes(kind)
                    || !/^[a-f0-9]{64}$/.test(String(row.content_digest || ''))
                    || row.readback_verified !== true
                    || String(row.usage_policy || '') !== 'eval_candidate_only_no_training'
                    || row.formal_evaluation_case_created !== false
                    || row.model_training_triggered !== false
                    || row.external_action_authorized !== false
                    || row.boundaries?.original_analysis_mutated !== false
                    || row.boundaries?.external_action_authorized !== false
                    || (kind === 'useful' && Object.keys(correction).length > 0)
                    || (kind === 'needs_correction' && String(correction.summary || '').trim().length < 4)
                ) {
                    throw new Error('分析反馈回读合同不一致');
                }
                if (expected.requirePersistence === true && String(row.persistence_status || '') !== 'readback_verified') {
                    throw new Error('分析反馈缺少保存回读凭证');
                }
                if (Number(expected.id || 0) > 0 && Number(row.id || 0) !== Number(expected.id)) {
                    throw new Error('分析反馈按编号回读不一致');
                }
                if (expected.contentDigest && String(row.content_digest || '') !== String(expected.contentDigest)) {
                    throw new Error('分析反馈内容摘要回读不一致');
                }
                if (expected.kind && kind !== String(expected.kind)) {
                    throw new Error('分析反馈类型回读不一致');
                }
                if (expected.kind === 'needs_correction') {
                    if (String(correction.summary || '').trim() !== String(expected.correctionText || '').trim()
                        || JSON.stringify(issueCodes) !== JSON.stringify(expected.issueCodes || [])
                    ) throw new Error('分析纠错内容回读不一致');
                }
                return row;
            };
            const loadOperatingQuestionQualityFeedback = async (question = {}, options = {}) => {
                const snapshot = hotelDataAnalystFeedbackSnapshot(question);
                const feedback = qualityFeedbackFor(question);
                if (!hotelDataAnalystFeedbackSnapshotValid(snapshot)) return feedback;
                if (!options.force && feedback.loaded) return feedback;
                if (feedback.loading) return feedback;
                feedback.loading = true;
                feedback.phase = feedback.phase === 'editing' ? 'editing' : 'loading';
                feedback.error = '';
                try {
                    const response = await request(`/agent/operating-questions/${snapshot.questionId}/feedbacks/mine?limit=20`);
                    if (response.code !== 200 || !response.data || typeof response.data !== 'object') {
                        throw new Error(response.message || '分析反馈历史读取失败');
                    }
                    const data = response.data;
                    if (Number(data.question_id || 0) !== snapshot.questionId
                        || String(data.contract_version || '') !== 'hotel_data_analyst_feedback.v1'
                        || data.boundaries?.original_analysis_mutated !== false
                        || data.boundaries?.external_action_authorized !== false
                    ) throw new Error('分析反馈历史范围回读不一致');
                    if (String(data.data_status || '') === 'migration_required') {
                        feedback.data_status = 'migration_required';
                        feedback.loaded = true;
                        feedback.list = [];
                        feedback.latest = null;
                        feedback.phase = 'migration_required';
                        feedback.error = '反馈账本待完成数据库迁移；当前分析结果不受影响，也不会被改写。';
                        return feedback;
                    }
                    if (String(data.data_status || '') !== 'ready') {
                        throw new Error('分析反馈历史状态未识别');
                    }
                    const list = Array.isArray(data.list) ? data.list : [];
                    list.forEach((item) => assertHotelDataAnalystFeedbackReadback(item, snapshot));
                    if (data.latest && Number(data.latest.id || 0) !== Number(list[0]?.id || 0)) {
                        throw new Error('分析反馈最新记录与列表不一致');
                    }
                    feedback.list = list;
                    feedback.latest = list[0] || null;
                    feedback.summary = data.summary && typeof data.summary === 'object'
                        ? data.summary
                        : { total: list.length, useful: 0, needs_correction: 0 };
                    feedback.data_status = 'ready';
                    feedback.loaded = true;
                    if (!['editing', 'saving'].includes(feedback.phase)) {
                        const latest = feedback.latest;
                        feedback.feedback_kind = String(latest?.feedback_kind || '');
                        feedback.correction_text = String(latest?.correction?.summary || '');
                        feedback.issue_codes = Array.isArray(latest?.correction?.issue_codes)
                            ? latest.correction.issue_codes.map(String)
                            : [];
                        feedback.saved_feedback_id = Number(latest?.id || 0);
                        feedback.phase = latest ? 'saved' : 'ready';
                        feedback.saved_message = latest ? '已按编号回读最近一次反馈。' : '';
                    }
                    return feedback;
                } catch (error) {
                    feedback.phase = 'error';
                    feedback.error = error?.message || '分析反馈历史读取失败';
                    return feedback;
                } finally {
                    feedback.loading = false;
                }
            };
            const saveOperatingQuestionQualityFeedback = async (question = {}) => {
                const snapshot = hotelDataAnalystFeedbackSnapshot(question);
                const feedback = qualityFeedbackFor(question);
                if (!hotelDataAnalystFeedbackSnapshotValid(snapshot) || feedback.saving) return null;
                const kind = String(feedback.feedback_kind || '');
                const correctionText = String(feedback.correction_text || '').trim();
                const issueCodes = Array.from(new Set(
                    (Array.isArray(feedback.issue_codes) ? feedback.issue_codes : [])
                        .map((code) => String(code || '').trim().toLowerCase())
                        .filter((code) => HOTEL_DATA_ANALYST_FEEDBACK_ISSUE_CODES.has(code))
                ));
                feedback.error = '';
                feedback.saved_message = '';
                if (!['useful', 'needs_correction'].includes(kind)) {
                    feedback.phase = 'error';
                    feedback.error = '请先选择“有用”或“需要纠正”。';
                    return null;
                }
                if (kind === 'needs_correction' && (correctionText.length < 4 || correctionText.length > 2000)) {
                    feedback.phase = 'error';
                    feedback.error = '请用 4–2000 个字说明哪里不对以及正确口径。';
                    return null;
                }
                if (!feedback.idempotency_key) {
                    const suffix = globalThis.crypto?.randomUUID?.().replace(/-/g, '')
                        || `${Date.now()}${Math.random().toString(16).slice(2, 12)}`;
                    feedback.idempotency_key = `analyst-feedback:${snapshot.questionId}:${suffix}`;
                }
                feedback.saving = true;
                feedback.phase = 'saving';
                try {
                    const response = await request(`/agent/operating-questions/${snapshot.questionId}/feedbacks`, {
                        method: 'POST',
                        body: JSON.stringify({
                            feedback_kind: kind,
                            correction_text: kind === 'needs_correction' ? correctionText : '',
                            issue_codes: kind === 'needs_correction' ? issueCodes : [],
                            source_content_digest: snapshot.sourceContentDigest,
                            quality_receipt_digest: snapshot.qualityReceiptDigest,
                            idempotency_key: feedback.idempotency_key,
                        }),
                    });
                    if (response.code === 409) throw new Error('分析快照已变化，请重新读取当前分析后再反馈。');
                    if (response.code === 503) throw new Error('反馈账本待完成数据库迁移；本次没有改写分析结果。');
                    if (response.code !== 200 || !response.data || typeof response.data !== 'object') {
                        throw new Error(response.message || '分析反馈保存失败');
                    }
                    const saved = assertHotelDataAnalystFeedbackReadback(response.data, snapshot, {
                        requirePersistence: true,
                        kind,
                        correctionText,
                        issueCodes,
                    });
                    const feedbackId = Number(saved.id || 0);
                    const readback = await request(`/agent/operating-questions/${snapshot.questionId}/feedbacks/${feedbackId}`);
                    if (readback.code !== 200 || !readback.data || typeof readback.data !== 'object') {
                        throw new Error(readback.message || '分析反馈按编号回读失败');
                    }
                    const exact = assertHotelDataAnalystFeedbackReadback(readback.data, snapshot, {
                        requirePersistence: true,
                        id: feedbackId,
                        contentDigest: String(saved.content_digest || ''),
                        kind,
                        correctionText,
                        issueCodes,
                    });
                    const sourceReadback = await request(`/agent/operating-questions/${snapshot.questionId}`);
                    const sourceExact = sourceReadback?.data || {};
                    if (sourceReadback.code !== 200
                        || Number(sourceExact.id || 0) !== snapshot.questionId
                        || Number(sourceExact.hotel_id || 0) !== snapshot.hotelId
                        || String(sourceExact.content_digest || '') !== snapshot.sourceContentDigest
                        || String(sourceExact.analysis_quality_receipt?.receipt_digest || '') !== snapshot.qualityReceiptDigest
                    ) throw new Error('反馈保存后原分析记录发生漂移，当前反馈不会显示为已确认。');
                    feedback.list = [exact, ...feedback.list.filter((item) => Number(item?.id || 0) !== feedbackId)].slice(0, 20);
                    feedback.latest = exact;
                    feedback.summary = {
                        total: feedback.list.length,
                        useful: feedback.list.filter((item) => item.feedback_kind === 'useful').length,
                        needs_correction: feedback.list.filter((item) => item.feedback_kind === 'needs_correction').length,
                    };
                    feedback.data_status = 'ready';
                    feedback.loaded = true;
                    feedback.saved_feedback_id = feedbackId;
                    feedback.phase = 'saved';
                    feedback.saved_message = '反馈已保存并按编号回读；原分析记录保持不变。';
                    feedback.idempotency_key = '';
                    feedback.original_analysis_mutated = false;
                    feedback.formal_evaluation_case_created = false;
                    feedback.model_training_triggered = false;
                    feedback.external_action_authorized = false;
                    return exact;
                } catch (error) {
                    feedback.phase = String(error?.message || '').includes('快照已变化') ? 'stale' : 'error';
                    feedback.error = error?.message || '分析反馈保存失败';
                    return null;
                } finally {
                    feedback.saving = false;
                }
            };
            return {
                forQuestion: qualityFeedbackFor,
                updateDraft: updateOperatingQuestionQualityFeedbackDraft,
                load: loadOperatingQuestionQualityFeedback,
                save: saveOperatingQuestionQualityFeedback,
            };
        };
        // HOTEL_DATA_ANALYST_FEEDBACK_CLIENT_END
        const HOTEL_DATA_ANALYST_FEEDBACK_ISSUES = Object.freeze([
            { code: 'fact_or_number', label: '数值事实' },
            { code: 'scope_or_date', label: '范围日期' },
            { code: 'metric_definition', label: '指标口径' },
            { code: 'interpretation', label: '解释建议' },
        ]);
        const hotelDataAnalystFeedbackProjectionText = (feedback = {}) => {
            const projection = feedback?.evaluation_projection && typeof feedback.evaluation_projection === 'object'
                ? feedback.evaluation_projection
                : {};
            const status = String(projection.replay_status || '');
            if (String(feedback.feedback_kind || '') === 'useful') {
                return '有用反馈只记录使用体验，不会被当作标准答案或自动训练材料。';
            }
            if (status === 'ready_for_dry_run') {
                return '已形成隔离干跑候选；仍需人工审查，尚未创建正式评测，也未调用模型。';
            }
            if (status === 'blocked') {
                return '已记录为纠错候选；冻结事实不足或合同不兼容，暂不进入干跑。';
            }
            return '纠错仅作为待复核候选保存，不会自动改写原分析、训练模型或触发经营动作。';
        };
        const renderHotelDataAnalystFeedback = (question = {}, feedbackUi = null, options = {}) => {
            const questionId = Number(question?.id || 0);
            if (!questionId || !feedbackUi || typeof feedbackUi.forQuestion !== 'function') return null;
            const feedback = feedbackUi.forQuestion(question) || {};
            const interactive = options.interactive !== false;
            if (interactive
                && feedback.loaded !== true
                && feedback.loading !== true
                && String(feedback.phase || '') === 'idle'
                && typeof feedbackUi.load === 'function'
            ) {
                Promise.resolve().then(() => feedbackUi.load(question));
            }
            if (!interactive && !feedback.loaded && !feedback.latest) return null;
            const prefix = String(options.testId || 'hotel-data-analyst-quality-feedback');
            const selectedKind = String(feedback.feedback_kind || '');
            const correctionSelected = selectedKind === 'needs_correction';
            const issueCodes = Array.isArray(feedback.issue_codes) ? feedback.issue_codes.map(String) : [];
            const busy = feedback.loading === true || feedback.saving === true;
            const unavailable = ['unavailable', 'migration_required', 'stale'].includes(String(feedback.phase || ''));
            const disabled = busy || unavailable || !interactive;
            const latest = feedback.latest && typeof feedback.latest === 'object' ? feedback.latest : null;
            const feedbackList = Array.isArray(feedback.list) ? feedback.list : [];
            const history = feedbackList.slice(0, 5);
            const hiddenHistoryCount = Math.max(0, feedbackList.length - history.length);
            const toggleIssue = (code) => {
                const next = issueCodes.includes(code)
                    ? issueCodes.filter((item) => item !== code)
                    : [...issueCodes, code];
                feedbackUi.updateDraft?.(question, { issue_codes: next });
            };
            const statusNodes = [];
            if (feedback.loading) {
                statusNodes.push(h('p', {
                    class: 'mt-2 text-[11px] text-slate-500',
                    role: 'status',
                    'aria-live': 'polite',
                    'data-testid': `${prefix}-loading`,
                }, '正在读取你对这份分析的历史反馈…'));
            }
            if (feedback.error) {
                statusNodes.push(h('p', {
                    class: 'mt-2 rounded-lg bg-rose-50 px-2.5 py-2 text-[11px] leading-5 text-rose-700',
                    role: 'alert',
                    'data-testid': `${prefix}-error`,
                }, String(feedback.error)));
            }
            if (feedback.saved_message && !feedback.error) {
                statusNodes.push(h('p', {
                    class: 'mt-2 rounded-lg bg-emerald-50 px-2.5 py-2 text-[11px] leading-5 text-emerald-700',
                    role: 'status',
                    'aria-live': 'polite',
                    'data-testid': `${prefix}-saved`,
                }, String(feedback.saved_message)));
            }
            const controls = interactive ? [
                h('div', {
                    class: 'mt-2 flex w-full flex-wrap gap-2',
                    role: 'group',
                    'aria-label': '分析质量反馈',
                }, [
                    h('button', {
                        type: 'button',
                        disabled,
                        class: [
                            'min-h-10 rounded-lg border px-3 py-2 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50',
                            selectedKind === 'useful'
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                                : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-300',
                        ],
                        'aria-pressed': selectedKind === 'useful' ? 'true' : 'false',
                        'data-testid': `${prefix}-useful`,
                        onClick: () => feedbackUi.updateDraft?.(question, { feedback_kind: 'useful' }),
                    }, [h('i', { class: 'fas fa-thumbs-up mr-1.5' }), '有用']),
                    h('button', {
                        type: 'button',
                        disabled,
                        class: [
                            'min-h-10 rounded-lg border px-3 py-2 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50',
                            correctionSelected
                                ? 'border-amber-300 bg-amber-50 text-amber-800'
                                : 'border-slate-200 bg-white text-slate-700 hover:border-amber-300',
                        ],
                        'aria-pressed': correctionSelected ? 'true' : 'false',
                        'data-testid': `${prefix}-needs-correction`,
                        onClick: () => feedbackUi.updateDraft?.(question, { feedback_kind: 'needs_correction' }),
                    }, [h('i', { class: 'fas fa-pen mr-1.5' }), '需要纠正']),
                ]),
                correctionSelected ? h('div', {
                    class: 'mt-3 min-w-0 rounded-xl border border-amber-200 bg-amber-50/60 p-3',
                    'data-testid': `${prefix}-correction-panel`,
                }, [
                    h('p', { class: 'text-[11px] font-medium text-amber-900' }, '可选错误类型'),
                    h('div', { class: 'mt-2 flex flex-wrap gap-1.5' }, HOTEL_DATA_ANALYST_FEEDBACK_ISSUES.map((issue) => h('button', {
                        key: issue.code,
                        type: 'button',
                        disabled: busy,
                        class: [
                            'rounded-full border px-2.5 py-1 text-[11px] transition disabled:opacity-50',
                            issueCodes.includes(issue.code)
                                ? 'border-amber-400 bg-white font-medium text-amber-900'
                                : 'border-amber-200 bg-amber-50 text-amber-700',
                        ],
                        'aria-pressed': issueCodes.includes(issue.code) ? 'true' : 'false',
                        'data-testid': `${prefix}-issue-${issue.code}`,
                        onClick: () => toggleIssue(issue.code),
                    }, issue.label))),
                    h('label', { class: 'mt-3 block text-[11px] font-medium text-slate-700' }, [
                        '正确口径或需要修改的地方',
                        h('textarea', {
                            value: String(feedback.correction_text || ''),
                            maxlength: 2000,
                            rows: 4,
                            disabled: busy,
                            required: true,
                            placeholder: '例如：这里把 OTA 渠道订单当成全酒店订单了。请只保留携程渠道口径，并明确当前缺少全酒店数据。',
                            class: 'mt-1.5 block w-full max-w-full resize-y rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs leading-5 text-slate-800 outline-none focus:border-amber-400 disabled:opacity-50',
                            'data-testid': `${prefix}-correction-text`,
                            onInput: (event) => feedbackUi.updateDraft?.(question, { correction_text: event?.target?.value || '' }),
                        }),
                    ]),
                    h('p', { class: 'mt-1 text-[10px] text-slate-500' }, `${String(feedback.correction_text || '').length}/2000 · 不要填写密码、Cookie、令牌或其他凭证。`),
                ]) : null,
                h('div', { class: 'mt-3 flex flex-wrap items-center gap-2' }, [
                    h('button', {
                        type: 'button',
                        disabled: disabled || !['useful', 'needs_correction'].includes(selectedKind),
                        class: 'min-h-10 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300',
                        'data-testid': `${prefix}-save`,
                        onClick: () => feedbackUi.save?.(question),
                    }, feedback.saving ? '保存并回读中…' : '保存反馈'),
                    h('span', { class: 'text-[10px] leading-4 text-slate-500' }, '只追加反馈，不改原文，不自动执行。'),
                ]),
            ].filter(Boolean) : [];
            const latestNode = latest ? h('div', {
                class: 'mt-3 min-w-0 rounded-lg border border-slate-200 bg-white px-3 py-2',
                'data-testid': `${prefix}-latest`,
            }, [
                h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                    h('strong', { class: 'text-[11px] text-slate-700' }, `最近反馈 #${Number(latest.id || 0)}`),
                    h('span', { class: 'rounded-full bg-slate-100 px-2 py-1 text-[10px] text-slate-600' }, latest.feedback_kind === 'useful' ? '有用' : '需要纠正'),
                ]),
                latest.feedback_kind === 'needs_correction'
                    ? h('p', { class: 'mt-1 break-words text-[11px] leading-5 text-slate-700' }, String(latest.correction?.summary || ''))
                    : null,
                h('p', { class: 'mt-1 text-[10px] leading-4 text-slate-500' }, hotelDataAnalystFeedbackProjectionText(latest)),
            ].filter(Boolean)) : null;
            const historyNode = history.length > 1 ? h('details', {
                class: 'mt-2 text-[11px] text-slate-600',
                'data-testid': `${prefix}-history`,
            }, [
                h('summary', { class: 'cursor-pointer font-medium text-slate-600' }, `查看最近反馈记录（${history.length}）`),
                h('ol', { class: 'mt-2 space-y-1.5' }, history.map((item) => h('li', {
                    key: Number(item?.id || 0),
                    class: 'min-w-0 rounded-lg border border-slate-100 bg-white px-2.5 py-2',
                }, [
                    h('span', { class: 'font-medium' }, `#${Number(item?.id || 0)} · ${item?.feedback_kind === 'useful' ? '有用' : '需要纠正'}`),
                    item?.created_at ? h('span', { class: 'ml-2 text-slate-400' }, String(item.created_at)) : null,
                    item?.feedback_kind === 'needs_correction'
                        ? h('p', { class: 'mt-1 break-words leading-5 text-slate-600' }, String(item?.correction?.summary || ''))
                        : null,
                ].filter(Boolean)))),
                hiddenHistoryCount > 0
                    ? h('p', { class: 'mt-2 text-[10px] text-slate-500' }, `另有 ${hiddenHistoryCount} 条已加载记录未展示。`)
                    : null,
            ]) : null;
            return h('section', {
                class: 'mt-3 min-w-0 w-full max-w-full rounded-xl border border-slate-200 bg-slate-50/70 p-3',
                'data-testid': prefix,
                'data-feedback-kind': 'analysis_quality',
                'data-selected-feedback-kind': selectedKind || 'none',
                'data-feedback-phase': String(feedback.phase || 'idle'),
                'data-original-analysis-mutated': 'false',
                'data-formal-evaluation-case-created': 'false',
                'data-model-training-triggered': 'false',
                'data-external-action-authorized': 'false',
            }, [
                h('div', { class: 'flex flex-wrap items-start justify-between gap-2' }, [
                    h('div', { class: 'min-w-0' }, [
                        h('strong', { class: 'text-xs text-slate-800' }, '帮助酒店数据分析师继续锤炼'),
                        h('p', { class: 'mt-1 text-[11px] leading-5 text-slate-500' }, '你的反馈绑定当前酒店、平台、业务日期、原分析摘要与质量回执。'),
                    ]),
                    h('span', { class: 'rounded-full border border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-600' }, `分析 #${questionId}`),
                ]),
                ...controls,
                ...statusNodes,
                latestNode,
                historyNode,
            ].filter(Boolean));
        };
        const renderHotelDataAnalystQualityReceipt = (receipt, testId = '', options = {}) => {
            const model = normalizeHotelDataAnalystQualityReceipt(receipt);
            const badgeClass = ({
                ready: 'border-emerald-200 bg-emerald-50 text-emerald-700',
                partial: 'border-amber-200 bg-amber-50 text-amber-700',
                blocked: 'border-rose-200 bg-rose-50 text-rose-700',
            })[model.status];
            const checkStatusText = { passed: '通过', partial: '部分', blocked: '阻断' };
            const feedbackNode = renderHotelDataAnalystFeedback(
                options.question || {},
                options.feedbackUi || null,
                {
                    interactive: options.interactive !== false,
                    testId: options.feedbackTestId || '',
                }
            );
            return h('div', {
                class: 'mt-3 border-t border-slate-200 pt-3 text-xs text-slate-600',
                'data-testid': testId || 'hotel-data-analyst-quality-receipt',
                'data-analysis-quality-status': model.status,
                'data-analysis-quality-contract': model.contractVersion || 'missing',
                'data-analysis-quality-result': model.qualityStatus,
                'data-analysis-claim-status': model.claimStatus,
            }, [
                h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                    h('strong', { class: 'text-slate-800' }, '分析质量回执'),
                    h('span', { class: ['rounded-full border px-2 py-1 text-[11px] font-medium', badgeClass] }, model.statusLabel),
                    h('span', `自检合同${model.qualityStatus === 'passed' ? '通过' : '失败'} · ${model.passedCount}/${model.checkCount} 项通过`),
                    h('span', model.verifiedPortionUsable ? '仅使用已验证部分' : '当前不可形成经营主张'),
                ]),
                h('p', { class: 'mt-1 leading-5' }, model.summary),
                h('details', { class: 'mt-1' }, [
                    h('summary', { class: 'cursor-pointer font-medium text-slate-600' }, '查看自检明细'),
                    h('ul', { class: 'mt-2 space-y-1' }, model.checks.map((check) => h('li', { key: check.key }, `${check.label} · ${checkStatusText[check.status]}：${check.message}`))),
                    model.nextActions.length ? h('p', { class: 'mt-2 leading-5 text-amber-700' }, `继续锤炼：${model.nextActions.join('；')}`) : null,
                    model.subjectDigest ? h('p', { class: 'mt-1 break-all text-[11px] text-slate-400' }, `绑定分析摘要 ${model.subjectDigest.slice(0, 12)}… · 回执 ${model.receiptDigest.slice(0, 12)}…`) : null,
                ].filter(Boolean)),
                feedbackNode,
            ]);
        };
        // PRECISE_METRIC_SET_HELPERS_START
        const preciseMetricHasValue = (value) => value !== null && value !== undefined && value !== '';
        const preciseMetricUnitLabel = (value) => {
            const unit = String(value || '').trim();
            const labels = {
                people: '人',
                users: '人',
                impressions: '次',
                percent: '%',
                orders: '单',
                room_nights: '间夜',
            };
            return labels[unit.toLowerCase()] || unit;
        };
        const preciseMetricGapRows = (value) => [
            ...(Array.isArray(value?.data_gaps) ? value.data_gaps : []),
            ...(Array.isArray(value?.gaps) ? value.gaps : []),
        ].filter((gap) => gap !== null && gap !== undefined && gap !== '');
        const normalizePreciseMetricSet = (answer = {}) => {
            const precise = answer?.precise_result && typeof answer.precise_result === 'object'
                ? answer.precise_result
                : null;
            if (!precise) {
                return {
                    contractVersion: '', kind: '', isMetricSet: false, items: [],
                    totalCount: 0, readyCount: 0, blockedCount: 0,
                    isPartial: false, allBlocked: false,
                };
            }
            const nestedMetricSet = precise.metric_set && typeof precise.metric_set === 'object'
                ? precise.metric_set
                : null;
            const contractVersion = String(nestedMetricSet?.contract_version || precise.contract_version || '');
            const kind = String(nestedMetricSet?.kind || precise.kind || '');
            const declaredMetricSet = contractVersion === 'suxios.precise_metric_set.v1'
                || kind === 'operating_metric_set'
                || kind === 'operating_metric_range'
                || Boolean(nestedMetricSet)
                || Array.isArray(precise.precise_results)
                || Array.isArray(answer.precise_results);
            let rawItems = kind === 'operating_metric_range' && Array.isArray(precise.points)
                ? precise.points.map((point) => ({
                    ...point,
                    metric: precise.metric,
                    hotel: precise.hotel,
                    platform: precise.platform,
                    data_scope: precise.data_scope,
                    date_range: precise.date_range,
                }))
                : (Array.isArray(nestedMetricSet?.items)
                ? nestedMetricSet.items
                : (Array.isArray(precise.items)
                    ? precise.items
                    : (Array.isArray(precise.precise_results)
                        ? precise.precise_results
                        : (Array.isArray(answer.precise_results) ? answer.precise_results : []))));
            if (!rawItems.length && !declaredMetricSet) rawItems = [precise];
            const items = rawItems
                .filter((entry) => entry && typeof entry === 'object')
                .map((entry, index) => {
                    const raw = entry.result && typeof entry.result === 'object'
                        ? { ...entry, ...entry.result }
                        : entry;
                    const metric = raw.metric && typeof raw.metric === 'object' ? raw.metric : {};
                    const metricKey = String(metric.key || raw.metric_key || `metric_${index + 1}`);
                    const metricName = String(metric.name || raw.metric_name || raw.canonical_term || metricKey);
                    const status = String(raw.status || raw.result_status || '');
                    const value = raw.value ?? null;
                    const hasValue = preciseMetricHasValue(value);
                    const verificationStatus = String(raw.verification_status || '').trim().toLowerCase();
                    const readbackStatus = String(raw.readback_status || '').trim().toLowerCase();
                    const requiresStrictEvidence = true;
                    const statusBlocked = /^(?:blocked|missing|unavailable|failed|error|not_)/i.test(status);
                    const sourceRecords = Array.from(new Set([
                        String(raw.source_record || ''),
                        ...(Array.isArray(raw.source_records) ? raw.source_records.map((item) => String(item || '')) : []),
                    ].filter(Boolean)));
                    const strictEvidenceReady = !requiresStrictEvidence || (
                        ['verified', 'derived_verified'].includes(verificationStatus)
                        && readbackStatus === 'readback_verified'
                        && sourceRecords.length > 0
                    );
                    const blockedReason = String(raw.blocked_reason || (
                        !strictEvidenceReady ? '指标缺少 verified/derived_verified、readback_verified 或来源记录凭证' : ''
                    ));
                    const blocked = statusBlocked || blockedReason !== '' || !hasValue || !strictEvidenceReady;
                    const inputs = Array.isArray(raw.calculation_inputs)
                        ? raw.calculation_inputs
                        : (Array.isArray(raw.inputs) ? raw.inputs : []);
                    return {
                        raw,
                        index,
                        metricKey,
                        metricName,
                        status: status || (blocked ? 'blocked_by_missing_metric' : 'ready'),
                        value,
                        unit: String(raw.unit || ''),
                        unitLabel: preciseMetricUnitLabel(raw.unit),
                        blockedReason,
                        blocked,
                        ready: !blocked,
                        sourceRecords,
                        collectedAt: raw.collected_at ?? null,
                        verificationStatus,
                        readbackStatus,
                        formula: String(raw.formula || ''),
                        inputs,
                        gaps: preciseMetricGapRows(raw),
                    };
                });
            const readyCount = items.filter((item) => item.ready).length;
            const blockedCount = items.length - readyCount;
            const overallStatus = String(precise.status || nestedMetricSet?.status || answer.status || '');
            const isMetricSet = declaredMetricSet || items.length > 1;
            const isPartial = isMetricSet
                && (readyCount > 0 && blockedCount > 0 || overallStatus.toLowerCase().includes('partial'));
            return {
                contractVersion,
                kind,
                isMetricSet,
                items,
                totalCount: items.length,
                readyCount,
                blockedCount,
                isPartial,
                allBlocked: items.length > 0 && readyCount === 0,
            };
        };
        // PRECISE_METRIC_SET_HELPERS_END
        const preciseMetricGapText = (gap) => String(
            gap && typeof gap === 'object' ? (gap.message || gap.reason || gap.code || '') : (gap || '')
        ).trim();
        const preciseMetricInputText = (input) => {
            if (input === null || input === undefined) return '';
            if (typeof input !== 'object') return String(input);
            const metric = input.metric && typeof input.metric === 'object' ? input.metric : {};
            const label = String(input.label || input.name || metric.name || input.metric_name || input.metric_key || metric.key || '输入');
            const value = input.value ?? input.amount ?? null;
            const unit = preciseMetricUnitLabel(input.unit);
            return preciseMetricHasValue(value)
                ? `${label} ${String(value)}${unit ? ` ${unit}` : ''}`
                : label;
        };
        const renderPreciseMetricEvidence = (answer = {}, options = {}) => {
            const normalized = options.normalized || normalizePreciseMetricSet(answer);
            if (!normalized.items.length) return null;
            const overallGaps = Array.isArray(options.dataGaps) ? options.dataGaps : [];
            const multi = normalized.isMetricSet;
            const cardNodes = normalized.items.map((item, index) => {
                const raw = item.raw;
                const valueText = preciseMetricHasValue(item.value)
                    ? `${String(item.value)}${item.unitLabel ? ` ${item.unitLabel}` : ''}`
                    : '--';
                const matchingOverallGaps = overallGaps.filter((gap) => {
                    if (!gap || typeof gap !== 'object') return false;
                    const metric = gap.metric && typeof gap.metric === 'object' ? gap.metric : {};
                    return String(gap.metric_key || metric.key || '') === item.metricKey;
                });
                const gapTexts = Array.from(new Set([
                    ...item.gaps,
                    ...matchingOverallGaps,
                ].map(preciseMetricGapText).filter(Boolean)));
                const inputTexts = item.inputs.map(preciseMetricInputText).filter(Boolean);
                const sourceText = item.sourceRecords.join('、') || '--';
                const fields = [
                    ['酒店', String(raw.hotel?.name || `Hotel ${Number(raw.hotel?.id || 0) || '--'}`)],
                    ['平台', String(raw.platform?.name || raw.platform?.key || '--')],
                    ['业务日期', String(raw.business_date || '--')],
                    ['指标名称', item.metricName],
                    ['结果状态', item.status],
                    ['数值与单位', valueText],
                    ['来源记录', sourceText],
                    ['采集时间', String(item.collectedAt || '未记录，不用回读时间代替')],
                    ['验证状态', item.verificationStatus || '--'],
                    ['回读状态', item.readbackStatus || '--'],
                    ['数据范围', String(raw.data_scope || '--')],
                ];
                return h('article', {
                    key: `${item.metricKey}-${index}`,
                    class: [
                        'rounded-xl border p-3',
                        item.blocked
                            ? 'border-amber-200 bg-amber-50/80'
                            : 'border-emerald-200 bg-emerald-50/70',
                    ],
                    'data-testid': options.itemTestIdPrefix ? `${options.itemTestIdPrefix}-${index}` : undefined,
                    'data-metric-key': item.metricKey,
                    'data-metric-status': item.status,
                }, [
                    h('div', { class: 'mb-2 flex flex-wrap items-center justify-between gap-2' }, [
                        h('strong', { class: ['text-sm', item.blocked ? 'text-amber-950' : 'text-emerald-950'] }, item.metricName),
                        h('span', {
                            class: [
                                'rounded-full bg-white px-2 py-1 text-[11px]',
                                item.blocked ? 'text-amber-800' : 'text-emerald-800',
                            ],
                        }, item.blocked ? '明确阻塞' : '确定性可用'),
                    ]),
                    h('dl', { class: 'grid grid-cols-1 gap-2 text-xs sm:grid-cols-2' }, fields.map(([label, value], fieldIndex) => h('div', {
                        key: `${item.metricKey}-field-${fieldIndex}`,
                        class: ['rounded-lg border bg-white px-2.5 py-2', item.blocked ? 'border-amber-100' : 'border-emerald-100', ['数据范围', '来源记录'].includes(label) ? 'sm:col-span-2' : ''],
                    }, [
                        h('dt', { class: 'text-[11px] text-slate-500' }, label),
                        h('dd', { class: 'mt-0.5 break-words font-medium text-slate-800' }, value),
                    ]))),
                    item.formula ? h('p', { class: 'mt-2 text-xs leading-5 text-emerald-900' }, `计算：${item.formula}`) : null,
                    inputTexts.length ? h('p', { class: 'mt-1 text-xs leading-5 text-slate-700' }, `计算输入：${inputTexts.join('；')}`) : null,
                    item.blockedReason ? h('p', { class: 'mt-2 text-xs leading-5 text-amber-800' }, `阻塞原因：${item.blockedReason}`) : null,
                    gapTexts.length ? h('div', { class: 'mt-2 text-xs leading-5 text-amber-800' }, [
                        h('strong', '分项缺口'),
                        h('ul', { class: 'mt-1 list-disc pl-5' }, gapTexts.map((gap, gapIndex) => h('li', {
                            key: `${item.metricKey}-gap-${gapIndex}`,
                        }, gap))),
                    ]) : null,
                ].filter(Boolean));
            });
            const outerClass = normalized.allBlocked
                ? 'border-amber-200 bg-amber-50/60'
                : (normalized.isPartial ? 'border-amber-200 bg-white' : 'border-emerald-200 bg-emerald-50/40');
            return h('section', {
                class: ['mt-3 rounded-xl border p-3', outerClass],
                'data-testid': options.testId || undefined,
                'data-contract-version': normalized.contractVersion || undefined,
                'data-result-kind': normalized.kind || undefined,
            }, [
                h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                    h('strong', { class: 'text-sm text-slate-900' }, multi ? '可核对多指标结果' : '可核对经营结果'),
                    multi ? h('div', { class: 'flex flex-wrap gap-1.5 text-[11px]' }, [
                        h('span', { class: 'rounded-full bg-slate-100 px-2 py-1 text-slate-700' }, `识别 ${normalized.totalCount} 项`),
                        h('span', { class: 'rounded-full bg-emerald-100 px-2 py-1 text-emerald-800' }, `可用 ${normalized.readyCount} 项`),
                        h('span', { class: 'rounded-full bg-amber-100 px-2 py-1 text-amber-800' }, `阻塞 ${normalized.blockedCount} 项`),
                    ]) : h('span', { class: 'rounded-full bg-white px-2 py-1 text-[11px] text-slate-700' }, normalized.allBlocked ? '明确阻塞' : '数据库确定性结果'),
                ]),
                h('div', {
                    class: ['mt-3 grid gap-3', multi ? 'grid-cols-1 xl:grid-cols-2' : 'grid-cols-1'],
                    'data-testid': multi && options.metricSetTestId ? options.metricSetTestId : undefined,
                }, cardNodes),
            ]);
        };

        const hotelDataAnalystProfile = {
            name: 'HotelDataAnalystProfile',
            setup() {
                const ui = inject('operatingQuestionUi');
                const selectSuggestion = (question) => {
                    const state = ui?.state && typeof ui.state === 'object' && 'value' in ui.state
                        ? ui.state.value
                        : ui?.state;
                    if (!state || state.loading) return;
                    state.question = String(question);
                    state.error = '';
                    void nextTick(() => {
                        const input = document.querySelector('[data-testid="hotel-data-analyst-question-input"]');
                        input?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
                        input?.focus?.();
                    });
                };
                return () => h('section', {
                    class: 'rounded-2xl border p-5 text-white shadow-sm',
                    style: { borderColor: '#29473e', background: '#0d241e' },
                    'data-testid': 'hotel-data-analyst-role',
                    'data-role-key': 'hotel_data_analyst',
                    'data-contract-version': 'hotel_data_analyst.v1',
                }, [
                    h('h3', { class: 'text-xl font-bold' }, [
                        h('i', { class: 'fas fa-chart-pie mr-2 text-[#dcc591]' }),
                        '酒店数据分析师 ',
                        h('small', { class: 'text-xs text-emerald-200' }, '已接入真实入口'),
                    ]),
                    h('p', { class: 'mt-2 text-sm leading-6 text-emerald-100' }, '按酒店、平台和业务日期拆分事实、指标、异常与建议；缺失值不补零，未验证数值只供审计且不进入结论。'),
                    h('p', { class: 'mt-3 text-xs text-emerald-100', 'data-testid': 'hotel-data-analyst-capabilities' }, '经营指标诊断 · 趋势与渠道对比 · 异常与缺口识别 · 管理层分析摘要 · 人工行动建议'),
                    h('p', { class: 'mt-3 text-xs leading-5 text-amber-100', 'data-testid': 'hotel-data-analyst-contract' }, [
                        h('b', '分析合同：'),
                        '锁定范围 → 严格回读 → 按口径计算 → 输出并保存回读；建议仅供人工确认，不自动执行。OTA渠道事实不扩大为全酒店结论。',
                    ]),
                    h('div', { class: 'mt-4 grid gap-2 sm:grid-cols-2', 'data-testid': 'hotel-data-analyst-examples' }, HOTEL_DATA_ANALYST_SUGGESTIONS.map((question) => h('button', {
                        key: String(question),
                        type: 'button',
                        class: 'rounded-lg border bg-black/10 px-3 py-2 text-left text-sm',
                        style: { borderColor: 'rgba(253,230,138,.2)' },
                        onClick: () => selectSuggestion(question),
                    }, [String(question), h('i', { class: 'fas fa-arrow-right ml-2 text-[#dcc591]' })]))),
                ]);
            },
        };
        return Object.freeze({
            suggestions: HOTEL_DATA_ANALYST_SUGGESTIONS,
            createFeedbackUi: createHotelDataAnalystFeedbackUi,
            renderQualityReceipt: renderHotelDataAnalystQualityReceipt,
            renderPreciseMetricEvidence,
            hotelDataAnalystProfile,
            normalizeQualityReceipt: normalizeHotelDataAnalystQualityReceipt,
            normalizePreciseMetricSet,
        });
    };

    window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS = Object.freeze({ create });
})();
