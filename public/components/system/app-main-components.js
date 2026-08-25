(() => {
    'use strict';

    const create = ({ Vue, h }) => {
    const resolveRevenueCockpitIntentLifecycle = (intent = {}) => {
        const tasks = Array.isArray(intent?.tasks) ? intent.tasks : [];
        const latestTask = tasks.length ? tasks[tasks.length - 1] : {};
        const intentStatus = String(intent?.status || '');
        const taskStatus = String(latestTask?.status || '');
        const latestReview = intent?.action_management?.latest_review || null;
        const label = intentStatus === 'cancelled' || intentStatus === 'rejected' || taskStatus === 'cancelled'
            ? '已取消'
            : latestReview && Number(latestReview.id || 0) > 0
                ? '复盘完成'
                : taskStatus === 'failed'
                    ? '执行失败'
                    : taskStatus === 'executed'
                        ? '观察中'
                        : taskStatus === 'executing'
                            ? '执行中'
                            : taskStatus === 'pending_execute'
                                ? '已审批·待执行'
                                : intentStatus === 'approved'
                                    ? '已审批'
                                    : '待审批';
        return {
            label,
            intentId: Number(intent?.id || 0),
            taskCount: tasks.length,
            latestTask,
            latestReview,
        };
    };
    const parseOperationEvidenceNumber = (value, label) => {
        const text = String(value ?? '').trim();
        if (!text) throw new Error(`${label}不能为空`);
        const number = Number(text.replace(/[,，]/g, ''));
        if (!Number.isFinite(number)) throw new Error(`${label}必须是数字`);
        return number;
    };
    const parseOptionalOperationEvidenceNumber = (value, label) => {
        const text = String(value ?? '').trim();
        return text ? parseOperationEvidenceNumber(text, label) : null;
    };
    const operationEvidenceFirstText = (sources = [], keys = []) => {
        for (const source of (Array.isArray(sources) ? sources : [sources])) {
            if (!source || typeof source !== 'object') continue;
            for (const key of keys) {
                const value = source[key];
                if (value !== undefined && value !== null && String(value).trim() !== '') return String(value);
            }
        }
        return '';
    };
    const operationEvidenceCleanObject = (value = {}) => Object.fromEntries(
        Object.entries(value).filter(([, entry]) => entry !== undefined && entry !== null && String(entry).trim() !== '')
    );
    const operationEvidenceLocalTimestamp = () => {
        const date = new Date();
        const pad = number => String(number).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    };
    const normalizeOperationEvidenceDateTime = (value) => {
        const text = String(value ?? '').trim().replace('T', ' ');
        if (!text) return operationEvidenceLocalTimestamp();
        if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(text)) throw new Error('执行时间格式需为 YYYY-MM-DD HH:mm:ss');
        return text.length === 16 ? `${text}:00` : text;
    };
    const normalizeOperationReviewStatus = (value) => {
        const text = String(value ?? '').trim().toLowerCase();
        const status = ({
            '1': 'success', '2': 'near_success', '3': 'failed', '4': 'observing',
            ok: 'success', success: 'success', near: 'near_success', near_success: 'near_success',
            failed: 'failed', fail: 'failed', observing: 'observing', wait: 'observing',
            '达成': 'success', '成功': 'success', '接近达成': 'near_success', '接近': 'near_success',
            '未达成': 'failed', '失败': 'failed', '观察': 'observing', '继续观察': 'observing',
        })[text] || '';
        if (!status) throw new Error('复盘结论必须是 success、near_success、failed 或 observing');
        return status;
    };
    const RevenueCockpitOpportunityDetails = {
        name: 'RevenueCockpitOpportunityDetails',
        props: {
            card: { type: Object, default: () => ({}) },
            intent: { type: Object, default: null },
            loadingKey: { type: String, default: '' },
            snapshotSaving: { type: Boolean, default: false },
            readbackBlocked: { type: Boolean, default: false },
        },
        emits: ['create', 'open'],
        render() {
            const card = this.card || {};
            const row = (label, value) => h('div', null, [
                h('span', { class: 'font-semibold text-slate-800' }, `${label}：`),
                String(value || '无'),
            ]);
            const lifecycle = resolveRevenueCockpitIntentLifecycle(this.intent || {});
            const intentId = lifecycle.intentId;
            const tasks = Array.isArray(this.intent?.tasks) ? this.intent.tasks : [];
            const lifecycleLabel = lifecycle.label;
            const busy = String(this.loadingKey || '') === String(card.opportunityKey || '');
            const button = intentId > 0
                ? h('button', {
                    type: 'button',
                    class: 'mt-1 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs font-medium text-emerald-800 hover:bg-emerald-50',
                    'data-testid': `revenue-cockpit-opportunity-open-${card.opportunityKey}`,
                    onClick: () => this.$emit('open', this.intent),
                }, `${lifecycleLabel} · 行动 #${intentId} · 任务 ${tasks.length} 个 · 进入查看`)
                : h('button', {
                    type: 'button',
                    disabled: card.canCreatePendingApproval !== true || this.snapshotSaving || !!this.loadingKey || this.readbackBlocked,
                    class: 'mt-1 rounded-lg px-3 py-2 text-xs font-medium text-white hover:opacity-90 disabled:opacity-40',
                    style: 'background:#173f34',
                    'data-testid': `revenue-cockpit-opportunity-approval-${card.opportunityKey}`,
                    onClick: () => this.$emit('create', card),
                }, [
                    h('i', { class: `${busy ? 'fas fa-spinner fa-spin' : 'fas fa-clipboard-check'} mr-1.5` }),
                    busy
                        ? '保存快照并送审中…'
                        : (this.readbackBlocked
                            ? '生命周期恢复失败，禁止重复送审'
                            : (card.canCreatePendingApproval ? '转为 pending_approval' : '证据不足，暂不可送审')),
                ]);
            return h('div', {
                class: 'mt-3 space-y-2 rounded-lg border border-amber-100 bg-amber-50/60 p-3 text-[11px] leading-5 text-slate-700',
                'data-testid': 'revenue-cockpit-opportunity-chain',
            }, [
                row('事实变化', card.factChange),
                row('可能原因', card.possibleCause),
                row('证据支持', `${card.evidenceSupport || '无'} · ${card.evidenceLevel || 'unknown'}`),
                row('尚缺证据', Array.isArray(card.missingEvidence) ? card.missingEvidence.join('、') : '无'),
                row('建议核查', card.recommendedCheckAction),
                h('div', { class: 'border-t border-amber-100 pt-2 text-amber-900' },
                    `关系类型：${card.relationshipType || 'unknown'} · 相关性 ${card.correlationStatus || 'unknown'} · 因果结论：${card.causalityClaimed ? '已声明' : '未声明'} · 自动审批/调价/OTA 写入：否`),
                button,
            ]);
        },
    };
    const RevenueCockpitSnapshotStatus = {
        name: 'RevenueCockpitSnapshotStatus',
        props: {
            snapshot: { type: Object, default: null },
            status: { type: String, default: 'not_saved' },
            error: { type: String, default: '' },
        },
        render() {
            if (this.snapshot) {
                const stale = this.status === 'stale_current_evidence';
                return h('div', {
                    class: 'mt-3 rounded-lg border px-3 py-2 text-xs leading-5',
                    style: stale
                        ? 'border-color:rgba(251,191,36,.45);background:rgba(120,53,15,.2);color:#fde68a'
                        : 'border-color:rgba(52,211,153,.35);background:rgba(6,78,59,.22);color:#d1fae5',
                    'data-testid': 'revenue-cockpit-snapshot-readback',
                }, `快照 #${this.snapshot.id} 已精确回读 · 内容 ${String(this.snapshot.content_digest || '').slice(0, 12)} · 证据 ${String(this.snapshot.evidence_digest || '').slice(0, 12)}${stale ? ' · 当前事实身份已变化，页面保留原快照；点击“刷新事实”可查看当前模型' : ''}`);
            }
            if (this.error) {
                return h('div', {
                    class: 'mt-3 rounded-lg border px-3 py-2 text-xs leading-5',
                    style: 'border-color:rgba(251,113,133,.45);background:rgba(127,29,29,.18);color:#fecdd3',
                    'data-testid': 'revenue-cockpit-snapshot-error',
                }, `快照回读失败：${this.error}`);
            }
            return this.status === 'not_saved'
                ? h('div', {
                    class: 'mt-3 text-xs leading-5',
                    style: 'color:rgba(236,253,245,.65)',
                    'data-testid': 'revenue-cockpit-snapshot-not-saved',
                }, '当前酒店、平台、营业日尚未保存决策快照。')
                : null;
        },
    };
    const RevenueCockpitActionRestoreStatus = {
        name: 'RevenueCockpitActionRestoreStatus',
        props: {
            intent: { type: Object, default: null },
            status: { type: String, default: 'idle' },
            error: { type: String, default: '' },
        },
        emits: ['open'],
        render() {
            if (this.intent) {
                const lifecycle = resolveRevenueCockpitIntentLifecycle(this.intent);
                return h('section', {
                    class: 'rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950',
                    'data-testid': 'revenue-cockpit-restored-action',
                }, [h('div', { class: 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between' }, [
                    h('div', null, [
                        h('div', { class: 'font-semibold' }, `已恢复同一运营行动 #${lifecycle.intentId}`),
                        h('div', { class: 'mt-1 text-xs leading-5 text-emerald-800' }, `${lifecycle.label} · 真实任务 ${lifecycle.taskCount} 个 · 当前状态来自保存后精确回读，不会重新创建行动。`),
                    ]),
                    h('button', {
                        type: 'button',
                        class: 'shrink-0 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-xs font-medium text-emerald-800 hover:bg-emerald-100',
                        'data-testid': 'revenue-cockpit-restored-action-open',
                        onClick: () => this.$emit('open', this.intent),
                    }, '进入运营管理'),
                ])]);
            }
            if (this.status === 'error') {
                return h('section', {
                    class: 'rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800',
                    'data-testid': 'revenue-cockpit-restored-action-error',
                }, `已保存运营行动恢复失败：${this.error || '未知错误'}。为防止重复创建，当前送审入口保持关闭，请刷新事实后重试。`);
            }
            return null;
        },
    };
    const AiDecisionQualityDetails = {
        name: 'AiDecisionQualityDetails',
        props: {
            item: { type: Object, default: () => ({}) },
        },
        render() {
            const item = this.item || {};
            const basis = item.data_basis || {};
            const effect = item.expected_effect || {};
            const risk = item.risk || {};
            const refs = Array.isArray(basis.refs)
                ? basis.refs.map(ref => ref?.source || ref?.ref || '').filter(Boolean).slice(0, 2).join('、')
                : '';
            const basisText = basis.summary || basis.quality_note || '未提供可追溯依据';
            const basisMeta = [basis.scope, basis.platform, basis.date, refs].filter(Boolean).join(' · ');
            const effectText = effect.summary || '未定义效果指标';
            const effectMeta = [effect.metric_label || effect.metric, effect.review_window].filter(Boolean).join(' · ');
            const riskLevel = risk.level || item.risk_level || '待核验';
            const riskText = risk.summary || '未提供风险说明';
            const priorityBasis = item.priority_basis || {};
            const priorityText = [item.priority || '待排序', Number.isFinite(Number(priorityBasis.score)) ? `${Number(priorityBasis.score)}/100` : '', item.priority_reason || ''].filter(Boolean).join(' · ');
            const quality = item.decision_quality || {};
            const blocked = quality.generic_talk_rejected === true
                || quality.contract_version !== 'ai_recommendation_quality.v2'
                || quality.complete === false
                || quality.execution_ready !== true
                || item.can_create_execution_intent !== true;
            return Vue.h('div', { class: 'mt-2 space-y-1 text-xs leading-5 text-gray-600' }, [
                blocked ? Vue.h('div', {
                    class: 'rounded border border-red-200 bg-red-50 px-2 py-1 font-medium text-red-700',
                    'data-testid': 'ai-decision-quality-blocked',
                }, `质量门禁：不合格，不可执行。${item.blocked_reason || '请补齐必需字段后重新生成。'}`) : null,
                Vue.h('div', null, [Vue.h('span', { class: 'font-medium text-gray-700' }, '优先级：'), priorityText]),
                Vue.h('div', null, [Vue.h('span', { class: 'font-medium text-gray-700' }, '数据依据：'), basisText, basisMeta ? Vue.h('small', { class: 'ml-1 text-gray-400' }, `（${basisMeta}）`) : null]),
                Vue.h('div', null, [Vue.h('span', { class: 'font-medium text-gray-700' }, '预期效果：'), effectText, effectMeta ? Vue.h('small', { class: 'ml-1 text-gray-400' }, `（${effectMeta}）`) : null]),
                Vue.h('div', { class: 'text-amber-700' }, [Vue.h('span', { class: 'font-medium' }, `风险（${riskLevel}）：`), riskText]),
            ]);
        },
    };
    const OnlineTruthSummary = {
        name: 'OnlineTruthSummary',
        props: {
            truth: { type: Object, default: () => ({}) },
            testid: { type: String, default: '' },
        },
        render() {
            const helpers = window.SUXI_DATA_HEALTH_STATIC || {};
            const summary = typeof helpers.onlineTruthSummaryText === 'function'
                ? helpers.onlineTruthSummaryText(this.truth)
                : '未验证：缺少可信凭证';
            const nextAction = typeof helpers.onlineTruthNextActionText === 'function'
                ? helpers.onlineTruthNextActionText(this.truth)
                : '';
            const rows = typeof helpers.onlineTruthMetaRows === 'function'
                ? helpers.onlineTruthMetaRows(this.truth)
                : [];
            const testid = String(this.testid || '').trim();
            return h('div', {
                class: 'mt-2 text-[11px] leading-5 text-slate-500',
                'data-testid': testid || undefined,
            }, [
                h('div', { class: 'font-medium text-slate-600' }, summary),
                nextAction
                    ? h('div', { class: 'mt-0.5 text-amber-700' }, `下一步：${nextAction}`)
                    : null,
                h('details', {
                    class: 'mt-1.5',
                    'data-testid': testid ? `${testid}-details` : undefined,
                }, [
                    h('summary', {
                        class: 'cursor-pointer select-none text-slate-400 hover:text-slate-600',
                    }, '查看详情'),
                    h('dl', { class: 'mt-1.5 space-y-1 border-t border-slate-100 pt-1.5' }, rows.map(row => h('div', {
                        key: row.key,
                        class: 'grid grid-cols-[52px_minmax(0,1fr)] gap-1',
                    }, [
                        h('dt', { class: 'text-slate-400' }, row.label),
                        h('dd', { class: 'break-words text-slate-600' }, row.value),
                    ]))),
                ]),
            ]);
        },
    };
    const dualOtaReceiptReasonLabels = Object.freeze({
        login_expired: '登录已过期',
        session_expired: '会话已过期',
        account_profile_binding_missing: '账号与门店绑定缺失',
        account_profile_binding_scope_conflict: '账号与门店绑定冲突',
        hotel_binding_not_ready: '平台门店身份未就绪',
        hotel_identity_mismatch: '平台门店身份不匹配',
        binding_missing: '平台门店绑定缺失',
        target_date_mismatch: '来源业务日期不匹配',
        target_date_scope_mismatch: '任务业务日期与目标日不一致',
        target_date_data_missing: '目标日数据缺失',
        field_facts_incomplete: '字段事实未闭合',
        critical_fields_incomplete: '关键字段未闭合',
        required_metric_nonzero_evidence_missing: '关键字段缺少非零真实证据',
        required_metric_explicit_evidence_missing: '关键字段缺少显式真实证据',
        collection_strategy_unverified: '本次采集技术证据未记录',
        structured_response_required: '缺少结构化响应证据',
        raw_save_missing: '原始响应未保存',
        organized_save_missing: '标准字段未保存',
        database_readback_not_verified: '数据库精确回读未验证',
        readback_mismatch: '保存与回读不一致',
        saved_readback_count_unverified: '保存与回读数量未闭合',
        exact_run_readback_scope_mismatch: '精确回执行身份不一致',
        p0_not_ready: 'P0 验证未通过',
        collection_outcome_not_success: '本次采集任务失败',
        credential_execution_failed: '授权采集执行失败',
    });
    const DualOtaAcceptanceReceipt = {
        name: 'DualOtaAcceptanceReceipt',
        props: {
            receipt: { type: Object, default: () => ({}) },
            hotelName: { type: String, default: '' },
            platform: { type: String, default: '' },
        },
        render() {
            const receipt = this.receipt && typeof this.receipt === 'object' ? this.receipt : {};
            const counts = receipt.counts && typeof receipt.counts === 'object' ? receipt.counts : {};
            const fields = receipt.critical_fields && typeof receipt.critical_fields === 'object'
                ? receipt.critical_fields
                : {};
            const strategy = receipt.capture_strategy && typeof receipt.capture_strategy === 'object'
                ? receipt.capture_strategy
                : {};
            const readbackScope = receipt.run_readback_scope && typeof receipt.run_readback_scope === 'object'
                ? receipt.run_readback_scope
                : {};
            const platform = ['ctrip', 'meituan'].includes(String(this.platform || '').toLowerCase())
                ? String(this.platform).toLowerCase()
                : 'ota';
            const text = value => value === null || value === undefined || value === '' ? '未返回' : String(value);
            const count = value => {
                const number = Number(value);
                return value !== null && value !== undefined && value !== '' && Number.isInteger(number) && number >= 0
                    ? String(number)
                    : '未返回';
            };
            const match = value => value === true ? '一致' : (value === false ? '不一致/未验证' : '未返回');
            const scopeCount = key => Object.prototype.hasOwnProperty.call(readbackScope, key)
                ? count(readbackScope[key])
                : '未返回';
            const currentRows = Number(readbackScope.receipt_current_row_count);
            const identityMismatchRows = Number(readbackScope.receipt_identity_mismatch_count);
            const exactIdentityRows = Object.prototype.hasOwnProperty.call(readbackScope, 'receipt_current_row_count')
                && Object.prototype.hasOwnProperty.call(readbackScope, 'receipt_identity_mismatch_count')
                && Number.isInteger(currentRows)
                && currentRows >= 0
                && Number.isInteger(identityMismatchRows)
                && identityMismatchRows >= 0
                && identityMismatchRows <= currentRows
                ? String(currentRows - identityMismatchRows)
                : '未返回';
            const keys = value => {
                if (!Array.isArray(value)) return '未返回';
                if (value.length === 0) return '无';
                const normalized = value
                    .map(item => String(item || '').trim())
                    .filter(Boolean);
                return normalized.length ? normalized.join('、') : '未返回';
            };
            const cell = (key, label, value, breakClass = 'break-words') => h('div', {
                key,
                class: 'rounded-md border border-slate-100 bg-slate-50 px-2.5 py-2',
            }, [
                h('div', { class: 'text-[10px] font-medium text-slate-400' }, label),
                h('div', {
                    class: `mt-0.5 ${breakClass} text-[11px] font-semibold text-slate-700`,
                    'data-testid': `dual-ota-${key}-${platform}`,
                }, value),
            ]);
            const reasons = [...new Set((Array.isArray(receipt.reason_codes) ? receipt.reason_codes : [])
                .map(code => String(code || '').trim().toLowerCase()).filter(Boolean))];
            const reasonText = reasons.map(code => dualOtaReceiptReasonLabels[code]
                ? `${dualOtaReceiptReasonLabels[code]} (${code})`
                : code).join('；');
            return h('div', {
                class: 'mt-3',
                'data-testid': `dual-ota-acceptance-receipt-${platform}`,
            }, [
                h('div', { class: 'grid gap-2 sm:grid-cols-2' }, [
                    cell('system-hotel', '系统酒店', `${this.hotelName || '未返回'} · #${text(receipt.system_hotel_id)}`),
                    cell('platform-hotel', '平台门店', `${text(receipt.platform_hotel_id)} · ${text(receipt.platform_hotel_status)}`, 'break-all'),
                    cell('target-date', '目标日期 / 来源日期', `${text(receipt.target_date)} / ${text(receipt.observed_target_date)} · ${text(receipt.target_date_status)}`),
                    cell('captured-at', '采集时间', text(receipt.captured_at)),
                    cell('source', '采集来源', `${text(receipt.source_method)} · ${text(strategy.selected)} / ${text(strategy.status)}`),
                    cell('task-identity', '数据源 / 同步任务', `source #${text(receipt.data_source_id)} · task #${text(receipt.sync_task_id)}`),
                    cell('task-counts', '本任务保存 / 回读', `${count(counts.saved)} / ${count(counts.readback)} · ${match(counts.saved_readback_match)}`),
                    cell('target-counts', '目标口径保存 / 回读', `${count(counts.target_saved)} / ${count(counts.target_readback)} · ${match(counts.target_saved_readback_match)}`),
                ]),
                h('div', {
                    class: 'mt-2 rounded-md border border-slate-100 bg-slate-50 px-2.5 py-2 text-[11px] leading-5 text-slate-600',
                    'data-testid': `dual-ota-run-readback-scope-${platform}`,
                }, [
                    h('span', { class: 'font-medium text-slate-700' }, '精确回执行：'),
                    `${text(readbackScope.status)} · 回执 ${scopeCount('receipt_row_count')} · 当前身份一致 ${exactIdentityRows} · 身份漂移 ${scopeCount('receipt_identity_mismatch_count')} · 缺失 ${scopeCount('receipt_missing_row_count')} · 权威流量 ${scopeCount('authoritative_row_count')}`,
                ]),
                h('div', { class: 'mt-2 rounded-md border border-slate-100 bg-slate-50 px-2.5 py-2 text-[11px] leading-5 text-slate-600' }, [
                    h('div', { 'data-testid': `dual-ota-complete-fields-${platform}` }, [h('span', { class: 'font-medium text-slate-700' }, '已闭合关键字段：'), keys(fields.complete)]),
                    h('div', { 'data-testid': `dual-ota-missing-fields-${platform}` }, [h('span', { class: 'font-medium text-slate-700' }, '缺失关键字段：'), keys(fields.missing)]),
                ]),
                reasonText ? h('div', {
                    class: 'mt-1 text-[11px] leading-5 text-red-700',
                    'data-testid': `dual-ota-blockers-${platform}`,
                }, `状态原因：${reasonText}`) : null,
                h('div', { class: 'mt-1 text-[10px] leading-4 text-slate-400' }, `页面核对：${text(receipt.live_page_verification_status)}；只证明当前页面合同已核对，不提升数据真值。`),
            ]);
        },
    };
    const DualOtaPageVerificationPanel = {
        name: 'DualOtaPageVerificationPanel',
        props: {
            ctx: { type: Object, required: true },
        },
        emits: ['confirm'],
        render() {
            const ctx = this.ctx || {};
            const verifiedAt = String(ctx.verification?.verified_at || '').trim();
            const receiptId = Number(ctx.verification?.receipt_id || 0);
            return h('div', {
                class: 'rounded-lg border border-slate-200 bg-white p-3',
                'data-testid': 'dual-ota-page-verification',
            }, [
                h('div', { class: 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between' }, [
                    h('div', {}, [
                        h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                            h('span', { class: 'text-xs font-semibold text-slate-800' }, '页面人工核对回执'),
                            h('span', {
                                class: `rounded-full border px-2 py-0.5 font-mono text-[10px] font-semibold ${ctx.statusClass || ''}`,
                                'data-testid': 'dual-ota-page-verification-status',
                            }, ctx.statusText || '尚未核对'),
                        ]),
                        h('p', {
                            class: 'mt-1 text-[11px] leading-5 text-slate-500',
                            'data-testid': 'dual-ota-page-verification-reason',
                        }, ctx.reasonText || ''),
                        verifiedAt ? h('p', { class: 'text-[10px] text-slate-400' }, `确认时间：${verifiedAt} · 回执 #${receiptId || '未返回'}`) : null,
                    ]),
                    h('button', {
                        type: 'button',
                        disabled: !ctx.canConfirm,
                        class: 'shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50',
                        'data-testid': 'dual-ota-confirm-page-verification',
                        onClick: () => this.$emit('confirm'),
                    }, ctx.submitting ? '精确回读中' : (ctx.status === 'verified' ? '重新核对当前回执' : '确认本页已显示并核对')),
                ]),
                ctx.error ? h('p', {
                    class: 'mt-2 text-[11px] text-red-700',
                    'data-testid': 'dual-ota-page-verification-error',
                }, ctx.error) : null,
                h('p', { class: 'mt-2 text-[10px] text-slate-400' }, '只确认当前精确酒店、日期、数据源和任务；不会提升 OTA claim、P0 或连续定时验收。'),
            ]);
        },
    };
    const onlineDataComponents = window.SUXI_ONLINE_DATA_COMPONENTS || (window.SUXI_ONLINE_DATA_COMPONENTS = {});
    if (!onlineDataComponents || typeof onlineDataComponents !== 'object') {
        throw new Error('线上数据本地组件注册表异常：components/online-data/*.js');
    }
    const onlineDataComponentScriptPromises = new Map();
    const loadOnlineDataComponentScript = (src) => {
        if (!src) return Promise.reject(new Error('缺少线上数据组件脚本路径'));
        if (onlineDataComponentScriptPromises.has(src)) {
            return onlineDataComponentScriptPromises.get(src);
        }
        const promise = new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-suxi-online-data-component="${src}"]`);
            if (existing?.dataset?.loaded === '1') {
                resolve();
                return;
            }
            const script = existing || document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.suxiOnlineDataComponent = src;
            script.onload = () => {
                script.dataset.loaded = '1';
                resolve();
            };
            script.onerror = () => {
                onlineDataComponentScriptPromises.delete(src);
                script.remove();
                reject(new Error(`线上数据组件加载失败：${src}`));
            };
            if (!existing) {
                document.head.appendChild(script);
            }
        });
        onlineDataComponentScriptPromises.set(src, promise);
        return promise;
    };
    const readOnlineDataComponent = (key) => {
        if (!Object.prototype.hasOwnProperty.call(onlineDataComponents, key)) {
            return null;
        }
        return onlineDataComponents[key];
    };
    const requireOnlineDataComponent = (key) => {
        const component = readOnlineDataComponent(key);
        if (!component) {
            throw new Error(`缺少线上数据本地组件：${key}`);
        }
        return component;
    };
    const systemComponents = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const ctripOrderAnalysisPanelBodyKey = 'CtripOrderAnalysisPanelBody';
    const ctripOrderAnalysisPanelBodyScript = 'components/online-data/ctrip-order-analysis-panel.js?v=20260813-order-analysis-h6119e31dc4';
    let ctripOrderAnalysisPanelBodyPromise = null;
    const loadCtripOrderAnalysisPanelBody = () => {
        if (systemComponents[ctripOrderAnalysisPanelBodyKey]) {
            return Promise.resolve(systemComponents[ctripOrderAnalysisPanelBodyKey]);
        }
        if (ctripOrderAnalysisPanelBodyPromise) return ctripOrderAnalysisPanelBodyPromise;
        ctripOrderAnalysisPanelBodyPromise = new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-suxi-ctrip-order-analysis-body="${ctripOrderAnalysisPanelBodyScript}"]`);
            const script = existing || document.createElement('script');
            const finish = () => {
                const component = systemComponents[ctripOrderAnalysisPanelBodyKey];
                if (component) {
                    resolve(component);
                    return;
                }
                ctripOrderAnalysisPanelBodyPromise = null;
                reject(new Error('订单分析组件未完成注册'));
            };
            if (existing && systemComponents[ctripOrderAnalysisPanelBodyKey]) {
                finish();
                return;
            }
            script.src = ctripOrderAnalysisPanelBodyScript;
            script.async = true;
            script.dataset.suxiCtripOrderAnalysisBody = ctripOrderAnalysisPanelBodyScript;
            script.addEventListener('load', finish, { once: true });
            script.addEventListener('error', () => {
                ctripOrderAnalysisPanelBodyPromise = null;
                if (!existing) script.remove();
                reject(new Error('订单分析组件加载失败'));
            }, { once: true });
            if (!existing) document.head.appendChild(script);
        });
        return ctripOrderAnalysisPanelBodyPromise;
    };
    const CtripOrderAnalysisPanel = systemComponents.CtripOrderAnalysisPanel || Vue.defineAsyncComponent({
        loader: loadCtripOrderAnalysisPanelBody,
        delay: 0,
        timeout: 15000,
        loadingComponent: {
            inheritAttrs: false,
            render: () => h('section', {
                'data-testid': 'ctrip-order-analysis-loading',
                class: 'rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500',
            }, '正在加载订单深度分析…'),
        },
        errorComponent: {
            inheritAttrs: false,
            render: () => h('section', {
                'data-testid': 'ctrip-order-analysis-load-error',
                class: 'rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700',
            }, '订单分析组件加载失败，请刷新页面重试。'),
        },
    });
    const requireSystemComponent = (key) => {
        const component = systemComponents?.[key];
        if (!component) {
            throw new Error(`缺少系统管理本地组件：${key}`);
        }
        return component;
    };
    const operatingOpportunityLabScript = 'components/system/operating-opportunity-lab.js?v=20260822-selling-points-v3';
    const OperatingOpportunityLab = systemComponents.OperatingOpportunityLabBody || Vue.defineAsyncComponent({
        loader: () => loadOnlineDataComponentScript(operatingOpportunityLabScript)
            .then(() => requireSystemComponent('OperatingOpportunityLabBody')),
        delay: 0,
        timeout: 15000,
        loadingComponent: {
            inheritAttrs: false,
            render: () => h('section', {
                'data-testid': 'operating-opportunity-lab-loading',
                class: 'rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-500',
            }, '正在加载经营机会功能…'),
        },
        errorComponent: {
            inheritAttrs: false,
            render: () => h('section', {
                'data-testid': 'operating-opportunity-lab-load-error',
                class: 'rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-700',
            }, '经营机会功能加载失败，请刷新页面重试。'),
        },
    });
    const platformAutoPanelsScript = 'components/online-data/platform-auto-settings-panels.js?v=20260811-windows-scheduler-h80-v3';
    const ctripProfileFieldConfigPanelScript = 'components/online-data/ctrip-profile-field-config-panel.js?v=20260613-profile-template-split';
    const competitorDeviceManagementScript = 'components/admin/competitor-device-management.js?v=20260719-device-lifecycle-v3';
    const dataConfigDialogsScript = 'components/system/data-config-dialogs.js?v=20260720-data-config-template-split-v1';
    const automationCollectionContractScript = 'components/operations/automation-collection-contract.js?v=20260811-h80-binding-onboarding-v1';
    const PlatformAutoSettingsPanels = {
        name: 'PlatformAutoSettingsPanels',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        template: `
            <component
                :is="ctx.platformAutoSettingsPanelsBody"
                v-if="ctx.platformAutoSettingsPanelsBody"
                :ctx="ctx">
            </component>
            <div v-else data-testid="platform-auto-settings-panels-loading" class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500">
                加载中...
            </div>
        `,
    };
    const PlatformAutoSecondaryPanels = {
        name: 'PlatformAutoSecondaryPanels',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        template: `
            <component
                :is="ctx.platformAutoSecondaryPanelsBody"
                v-if="ctx.platformAutoSecondaryPanelsBody"
                :ctx="ctx">
            </component>
            <div v-else data-testid="platform-auto-secondary-panels-loading" class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500">
                加载中...
            </div>
        `,
    };
    const CtripProfileFieldConfigPanel = {
        name: 'CtripProfileFieldConfigPanel',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        template: `
            <component
                :is="ctx.ctripProfileFieldConfigPanelBody"
                v-if="ctx.ctripProfileFieldConfigPanelReady && ctx.ctripProfileFieldConfigPanelBody"
                :ctx="ctx">
            </component>
            <div v-else data-testid="ctrip-profile-field-config-loading" class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500">
                加载中...
            </div>
        `,
    };
    const CompetitorDeviceManagement = {
        name: 'CompetitorDeviceManagement',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        render() {
            if (this.ctx.competitorDeviceManagementReady && this.ctx.competitorDeviceManagementBody) {
                return Vue.h(this.ctx.competitorDeviceManagementBody, { ctx: this.ctx });
            }
            if (this.ctx.competitorDeviceManagementError) {
                return Vue.h('div', {
                    class: 'mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700',
                    'data-testid': 'competitor-device-load-error',
                }, [
                    Vue.h('div', { class: 'font-medium' }, this.ctx.competitorDeviceManagementError),
                    Vue.h('button', {
                        type: 'button',
                        class: 'mt-2 underline',
                        onClick: this.ctx.retryCompetitorDeviceManagement,
                    }, '重新加载组件'),
                ]);
            }
            return Vue.h('div', {
                class: 'mt-6 rounded-lg border bg-white p-4 text-sm text-gray-500',
                'data-testid': 'competitor-device-loading',
            }, '正在加载竞对采集设备管理...');
        },
    };
    const DataConfigDialogs = {
        name: 'DataConfigDialogs',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        render() {
            if (this.ctx.dataConfigDialogsReady && this.ctx.dataConfigDialogsBody) {
                return h(this.ctx.dataConfigDialogsBody, { ctx: this.ctx });
            }
            const loadError = String(this.ctx.dataConfigDialogsError || '').trim();
            return h('div', {
                class: 'fixed inset-0 z-50 flex items-center justify-center modal-overlay',
                'data-testid': loadError ? 'data-config-dialogs-load-error' : 'data-config-dialogs-loading',
            }, [
                h('div', { class: 'mx-4 w-full max-w-md rounded-lg bg-white p-6 text-center shadow-xl' }, [
                    h('div', { class: loadError ? 'font-medium text-red-700' : 'font-medium text-gray-700' }, loadError || '正在加载数据配置...'),
                    h('div', { class: 'mt-4 flex justify-center gap-3' }, [
                        loadError ? h('button', {
                            type: 'button',
                            class: 'rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700',
                            onClick: this.ctx.retryDataConfigDialogs,
                        }, '重新加载') : null,
                        h('button', {
                            type: 'button',
                            class: 'rounded-lg border px-4 py-2 text-sm hover:bg-gray-50',
                            onClick: () => { this.ctx.showDataConfigModal = false; },
                        }, '关闭'),
                    ]),
                ]),
            ]);
        },
    };

    // AI_DAILY_REPORT_TASK_HELPERS_START
    const aiDailyReportTaskPositiveInteger = (value) => {
        const number = Number(value ?? 0);
        return Number.isInteger(number) && number > 0 ? number : null;
    };
    const aiDailyReportModelIsLimited = (modelStatus = '') => {
        const status = String(modelStatus || '').trim().toLowerCase();
        return status === 'blocked'
            || status.startsWith('blocked_')
            || status.includes('data_quality')
            || status === 'partial'
            || status === 'failed'
            || status === 'invalid_output';
    };
    const normalizeAiDailyReportGenerationTask = (payload = {}, expectedHotelId = null, expectedTaskId = '') => {
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
            throw new Error('AI日报任务响应格式无效');
        }
        const taskId = String(payload.task_id ?? payload.taskId ?? '').trim();
        if (!taskId) throw new Error('AI日报任务响应缺少 task_id');

        const hotelId = aiDailyReportTaskPositiveInteger(payload.hotel_id ?? payload.hotelId);
        const normalizedExpectedHotelId = aiDailyReportTaskPositiveInteger(expectedHotelId);
        if (!hotelId) throw new Error('AI日报任务响应缺少有效 hotel_id');
        if (normalizedExpectedHotelId && hotelId !== normalizedExpectedHotelId) {
            throw new Error('AI日报任务酒店范围不一致');
        }

        const normalizedExpectedTaskId = String(expectedTaskId || '').trim();
        if (normalizedExpectedTaskId && taskId !== normalizedExpectedTaskId) {
            throw new Error('AI日报任务标识不一致');
        }

        const rawProgress = Number(payload.progress_percent ?? payload.progressPercent);
        const progressPercent = Number.isFinite(rawProgress)
            ? Math.min(100, Math.max(0, Math.round(rawProgress)))
            : null;
        const status = String(payload.status || '').trim().toLowerCase() || 'unknown';
        const stage = String(payload.stage || '').trim().toLowerCase() || status;
        const resultReportId = aiDailyReportTaskPositiveInteger(payload.result_report_id ?? payload.resultReportId);

        return {
            taskId,
            hotelId,
            reportDate: String(payload.report_date ?? payload.reportDate ?? '').trim(),
            status,
            stage,
            progressPercent,
            resultReportId,
            modelStatus: String(payload.model_status ?? payload.modelStatus ?? '').trim().toLowerCase(),
            cacheHit: payload.cache_hit === true || payload.cacheHit === true,
            deduplicated: payload.deduplicated === true,
            errorCode: String(payload.error_code ?? payload.errorCode ?? '').trim(),
            errorMessage: String(payload.error_message ?? payload.errorMessage ?? '').trim(),
            done: payload.done === true,
            createdAt: String(payload.created_at ?? payload.createdAt ?? '').trim(),
            startedAt: String(payload.started_at ?? payload.startedAt ?? '').trim(),
            finishedAt: String(payload.finished_at ?? payload.finishedAt ?? '').trim(),
            updatedAt: String(payload.updated_at ?? payload.updatedAt ?? '').trim(),
        };
    };
    const formatAiDailyReportGenerationStage = (task = {}) => {
        const status = String(task.status || '').trim().toLowerCase();
        const stage = String(task.stage || '').trim().toLowerCase();
        if (status === 'failed') return '生成失败';
        if (status === 'blocked') return task.resultReportId ? '规则报告已生成，AI增强受阻' : '生成已阻断';
        if (status === 'partial') return task.resultReportId ? '规则报告部分完成' : '生成部分完成';
        if (status === 'succeeded' && (stage === 'completed_with_data_gap' || aiDailyReportModelIsLimited(task.modelStatus))) {
            return '规则报告已生成，数据质量受限';
        }
        if (status === 'succeeded') return '生成完成';
        if (status === 'running' || stage === 'generating') return '正在生成日报';
        if (status === 'queued' || stage === 'queued') return '任务排队中';
        return stage && stage !== 'unknown' ? `任务阶段：${stage}` : '等待任务状态';
    };
    const resolveAiDailyReportGenerationOutcome = (task = {}) => {
        const status = String(task.status || '').trim().toLowerCase();
        const stage = String(task.stage || '').trim().toLowerCase();
        const hasReport = aiDailyReportTaskPositiveInteger(task.resultReportId) !== null;
        const fallbackError = task.errorMessage || 'AI经营日报生成失败，请稍后重试';

        if (status === 'failed') {
            return { kind: 'failed', limited: false, message: fallbackError };
        }
        if (status === 'blocked' || status === 'partial') {
            if (!hasReport) {
                return {
                    kind: 'failed',
                    limited: true,
                    message: task.errorMessage || (status === 'blocked'
                        ? 'AI日报生成被阻断，且没有可回读的规则报告'
                        : 'AI日报仅部分完成，且没有可回读的规则报告'),
                };
            }
            return {
                kind: 'limited',
                limited: true,
                message: task.errorMessage || (status === 'blocked'
                    ? '规则报告已生成，但AI增强被数据质量闸门阻断'
                    : '规则报告已生成，但任务仅部分完成'),
            };
        }
        if (status === 'succeeded') {
            if (!hasReport) {
                return { kind: 'failed', limited: false, message: 'AI日报任务已结束，但未返回可回读的报告ID' };
            }
            const limited = stage === 'completed_with_data_gap' || aiDailyReportModelIsLimited(task.modelStatus);
            return {
                kind: limited ? 'limited' : 'succeeded',
                limited,
                message: limited ? '规则报告已生成，但AI增强受数据质量限制' : '',
            };
        }
        if (task.done === true) {
            return {
                kind: 'failed',
                limited: false,
                message: task.errorMessage || `AI日报任务以未知终态结束：${status || 'unknown'}`,
            };
        }
        return { kind: 'pending', limited: false, message: '' };
    };
    const pollAiDailyReportGenerationTask = async ({
        taskId,
        expectedHotelId,
        initialTask = null,
        requestTask,
        wait,
        intervalMs = 1200,
        maxAttempts = 150,
        onProgress = () => {},
        isCurrent = () => true,
    } = {}) => {
        const normalizedTaskId = String(taskId || '').trim();
        if (!normalizedTaskId || typeof requestTask !== 'function' || typeof wait !== 'function') {
            throw new Error('AI日报任务轮询参数无效');
        }
        const attempts = Math.max(1, Number(maxAttempts) || 1);
        let pendingInitialTask = initialTask;
        for (let attempt = 0; attempt < attempts; attempt += 1) {
            if (!isCurrent()) return { task: null, outcome: { kind: 'cancelled', limited: false, message: '' } };
            let taskPayload = pendingInitialTask;
            pendingInitialTask = null;
            if (!taskPayload) {
                const response = await requestTask(normalizedTaskId);
                if (!response || response.code !== 200) {
                    throw new Error(response?.message || 'AI日报任务状态查询失败');
                }
                taskPayload = response.data;
            }
            const task = normalizeAiDailyReportGenerationTask(taskPayload, expectedHotelId, normalizedTaskId);
            onProgress(task);
            const outcome = resolveAiDailyReportGenerationOutcome(task);
            if (outcome.kind !== 'pending') return { task, outcome };
            if (attempt + 1 < attempts) await wait(intervalMs);
        }
        throw new Error('AI日报生成仍在后台执行，等待超时后可刷新页面查看最新结果');
    };
    // AI_DAILY_REPORT_TASK_HELPERS_END

    const SessionProofNotice = {
        name: 'SessionProofNotice',
        props: {
            result: { type: Object, default: () => ({}) },
            platform: { type: String, default: 'ota' },
        },
        render() {
            const result = this.result || {};
            if (String(result.session_proof_status || '').trim() !== 'not_recorded') return null;
            const savedCount = Number(result.saved_fact_row_count !== undefined
                ? result.saved_fact_row_count
                : (result.saved_count || 0));
            const detailRow = (label, value) => h('div', { class: 'mt-1' }, [
                h('span', { class: 'font-semibold' }, label),
                value,
            ]);
            return h('div', {
                'data-testid': `${this.platform}-session-proof-not-recorded`,
                class: 'mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950',
            }, [
                h('div', { class: 'font-semibold' }, savedCount > 0
                    ? '数据已保存，但登录证据未持久化'
                    : '本次未形成可持久化登录证据'),
                detailRow('原因：', result.session_proof_message || '当前响应没有返回可复用登录证据。'),
                detailRow('影响：', '本次数据结果与 Profile 登录证据分开判断；当前 Profile 暂不标记为可复用登录态，也不代表其他门店或账号已验证。'),
                detailRow('下一步：', result.session_proof_next_action || '刷新登录状态后重新执行一次最小采集。'),
            ]);
        },
    };

    const LocalCollectorLoginHandoff = {
        name: 'LocalCollectorLoginHandoff',
        render() {
            const ctx = this.$root || {};
            const tasks = Array.isArray(ctx.localCollectorLoginTaskRows) ? ctx.localCollectorLoginTaskRows : [];
            if (!tasks.length) return null;
            return h('section', {
                class: 'rounded border p-2 text-xs',
                'data-testid': 'local-collector-login-handoff',
            }, [
                '仅原设备继续；页面每3秒刷新，最长约10分钟，不新建任务或换设备。等待登录仅表示原设备已领取；请看专用Profile窗口，窗口与登录结果待确认。 ',
                h('button', { type: 'button', onClick: () => ctx.refreshLocalCollectorStatus?.() }, '手刷'),
                ...tasks.map(task => h('p', {
                    key: task.taskId,
                    'data-testid': `local-collector-login-task-${task.taskId}`,
                }, [
                    `任务#${task.taskId} ${task.platformText} ${task.taskLabel} `,
                    h('span', { class: task.statusClass, 'data-testid': 'local-collector-login-task-status' }, task.statusText),
                    h('br'),
                    h('span', { 'data-testid': 'local-collector-login-task-hotel' }, `酒店：${task.hotelText}`),
                    h('br'),
                    h('span', { 'data-testid': 'local-collector-login-task-device' }, `设备：${task.deviceName}`),
                    h('br'),
                    `账户：${task.accountAlias}；${task.handoffText}`,
                    h('br'),
                    h('span', { 'data-testid': 'local-collector-login-task-progress' }, `消息：${task.progressText}`),
                    h('br'),
                    h('span', { 'data-testid': 'local-collector-login-task-recovery' }, `恢复：${task.recoveryAction}`),
                ])),
            ]);
        },
    };

    const PmsRealtimeSyncResult = {
        name: 'PmsRealtimeSyncResult',
        render() {
            const ctx = this.$root || {};
            const result = ctx.operatingPmsRealtimeSyncResult || null;
            if (!result) {
                const bindingReady = ctx.operatingHotelPmsBinding?.binding_status === 'configured';
                return h('section', {
                    class: 'rounded-2xl border border-[#eadfc9] bg-[#fffdf8] p-4 text-sm leading-6 text-slate-700 shadow-sm',
                    'data-testid': 'pms-execution-environment',
                    role: 'status',
                }, [
                    h('p', { class: 'font-semibold text-slate-900' }, '正式 PMS 执行环境'),
                    h('p', { 'data-testid': 'pms-execution-environment-state' }, '尚未检测 · 订单来了 PMS 专用 Google Chrome'),
                    h('p', '当前 Codex 内置浏览器不是 PMS 执行浏览器。'),
                    h('p', '仅使用原绑定设备；不复制 Cookie/Profile；不自动换设备代采。'),
                    h('button', {
                        type: 'button',
                        class: 'mt-2 rounded-lg border border-[#d8c49f] bg-white px-3 py-1.5 font-medium text-[#826333] disabled:opacity-50',
                        disabled: Boolean(ctx.operatingPmsControlsBusy) || !bindingReady,
                        title: bindingReady ? '' : '请先为当前门店配置唯一 PMS',
                        'data-testid': 'pms-execution-environment-check',
                        onClick: () => ctx.syncOperatingPmsRealtime?.(),
                    }, ctx.operatingPmsControlsBusy ? '正在检测...' : '检测正式环境并继续'),
                ]);
            }
            const capturedAt = String(result.captured_at || '').trim();
            const resultText = `${result.message || '实时同步未返回结果'}${capturedAt ? ` · ${capturedAt}` : ''}`;
            const resultClass = result.status === 'synced' ? 'is-success' : 'is-blocked';
            const handoff = result.login_handoff && typeof result.login_handoff === 'object'
                ? result.login_handoff
                : null;
            if (!handoff) {
                return h('p', {
                    class: ['pms-realtime-sync-result', resultClass],
                    'data-testid': 'pms-realtime-sync-result',
                    role: 'status',
                }, resultText);
            }
            const ready = handoff.status === 'ready';
            const foreground = handoff.window_foreground_requested === true;
            const targetText = handoff.activated_target_scope === 'login_entry'
                ? '订单来了登录入口'
                : '订单来了 PMS 页面';
            const windowText = ready
                ? (foreground
                    ? `系统已在原绑定设备请求切到${targetText}；请在该专用 Google Chrome 完成登录。`
                    : `${targetText}已在原绑定设备打开；请从任务栏选择订单来了 PMS 专用 Google Chrome。`)
                : '尚未能切到专用 PMS 窗口；请保持原绑定设备在线后重试。';
            return h('section', {
                class: ['pms-realtime-sync-result', resultClass],
                'data-testid': 'pms-realtime-sync-result',
                role: 'status',
            }, [
                h('p', resultText),
                h('div', {
                    class: 'mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-950',
                    'data-testid': 'pms-login-handoff',
                }, [
                    h('p', { class: 'font-semibold' }, '请在正式 PMS 执行窗口登录'),
                    h('p', { 'data-testid': 'pms-login-handoff-window' }, windowText),
                    h('p', '当前 Codex 内置浏览器不是 PMS 执行浏览器。'),
                    h('p', { 'data-testid': 'pms-login-handoff-scope' }, `系统酒店 ${handoff.system_hotel_id || '-'} · 采集沙箱 ${handoff.sandbox_id || '-'}`),
                    h('p', { 'data-testid': 'pms-login-handoff-policy' }, '仅原绑定设备；不复制 Cookie/Profile；不自动换设备代采。'),
                    h('p', '打开窗口不代表登录已验证；只有重新采集、保存并完成数据库回读后才算成功。'),
                    h('button', {
                        type: 'button',
                        class: 'mt-2 rounded-lg border border-amber-300 bg-white px-3 py-1.5 font-medium text-amber-900 disabled:opacity-50',
                        disabled: Boolean(ctx.operatingPmsControlsBusy),
                        'data-testid': 'pms-login-handoff-retry',
                        onClick: () => ctx.syncOperatingPmsRealtime?.(),
                    }, ctx.operatingPmsControlsBusy ? '正在重新校验...' : '登录完成，重新校验并采集'),
                ]),
            ]);
        },
    };

    const OperatingLoopAuthority = {
        name: 'OperatingLoopAuthority',
        render() {
            const ctx = this.$root || {};
            const loop = ctx.operatingLoop && typeof ctx.operatingLoop === 'object'
                ? ctx.operatingLoop
                : {};
            const scope = loop.scope && typeof loop.scope === 'object' ? loop.scope : {};
            const issue = loop.priority_issue && typeof loop.priority_issue === 'object' ? loop.priority_issue : {};
            const nextAction = loop.next_action && typeof loop.next_action === 'object' ? loop.next_action : {};
            const owner = nextAction.owner && typeof nextAction.owner === 'object' ? nextAction.owner : {};
            const result = loop.yesterday_result && typeof loop.yesterday_result === 'object' ? loop.yesterday_result : {};
            const actors = loop.actors && typeof loop.actors === 'object' ? loop.actors : {};
            const experience = loop.experience && typeof loop.experience === 'object' ? loop.experience : {};
            const stages = Array.isArray(loop.stages) ? loop.stages : [];
            const stateClass = String(ctx.operatingLoopStateClass || 'border-slate-200 bg-slate-50 text-slate-600');
            const answer = (label, primary, secondary = '') => h('article', {
                class: 'rounded-xl border border-slate-200 bg-slate-50 p-3',
            }, [
                h('div', { class: 'text-xs font-semibold text-slate-500' }, label),
                h('p', { class: 'mt-2 text-sm font-semibold leading-6 text-slate-900' }, primary),
                secondary ? h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, secondary) : null,
            ]);
            const actor = (label, value) => h('span', `${label}：${Number(value) > 0 ? value : '未记录'}`);
            const scopedHotelId = String(scope.system_hotel_id || ctx.filterReportHotel || '').trim();
            const scopedHotelName = String(
                scope.hotel_name
                || (typeof ctx.getHotelNameById === 'function' ? ctx.getHotelNameById(scopedHotelId) : '')
                || ''
            ).trim();
            const yesterdayResultStatus = String(result.status || 'pending').trim().toLowerCase();
            const yesterdayResultStatusLabel = ({
                pending: '待回读',
                not_started: '未开始',
                observing: '观察中',
                supported: '已验证达到',
                contradicted: '已验证未达到',
                indeterminate: '证据不足',
                success: '已验证',
                failed: '未通过',
                no_action: '无需动作',
            }[yesterdayResultStatus] || yesterdayResultStatus || '待回读');

            return h('section', {
                class: 'mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm',
                'data-testid': 'operating-loop-authority',
            }, [
                h('div', { class: 'border-b border-slate-100 bg-slate-50/80 px-4 py-4 sm:px-5' }, [
                    h('div', { class: 'flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between' }, [
                        h('div', [
                            h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                h('h2', { class: 'text-lg font-bold text-slate-950' }, '宿析经营闭环内核'),
                                h('span', { class: ['rounded-full border px-2.5 py-1 text-xs font-semibold', stateClass] }, String(ctx.operatingLoopStateLabel || '未核验')),
                                h('span', {
                                    class: ['rounded-full border px-2.5 py-1 text-xs', loop.readback_verified
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                        : 'border-amber-200 bg-amber-50 text-amber-700'],
                                }, loop.readback_verified ? '精确回读通过' : '未通过精确回读'),
                            ]),
                            h('p', { class: 'mt-1 text-sm leading-6 text-slate-600' }, '这里只显示唯一权威状态；线上数据、Revenue AI、运营优化、目标、执行和知识均为专业下钻，不能自行宣布成功。'),
                        ]),
                        ctx.canReconcileOperatingLoop ? h('button', {
                            type: 'button',
                            class: 'inline-flex items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60',
                            disabled: Boolean(ctx.operatingLoopSyncing) || !ctx.filterReportHotel,
                            onClick: () => ctx.reconcileOperatingLoop?.(),
                        }, [
                            h('i', { class: ctx.operatingLoopSyncing ? 'fas fa-spinner fa-spin' : 'fas fa-rotate' }),
                            ctx.operatingLoopSyncing ? '正在按正式记录同步' : '同步权威状态',
                        ]) : null,
                    ]),
                    h('div', { class: 'mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500' }, [
                        h('span', `酒店：${scopedHotelName || (scopedHotelId ? `ID ${scopedHotelId}` : '未确认')}`),
                        h('span', `业务日：${scope.business_date || ctx.operationYesterday || '未确认'}`),
                        h('span', `指标版本：${scope.metric_version || '未冻结'}`),
                        h('span', `Kernel：${loop.kernel_id || '未建立'} · revision ${Number(loop.revision || 0)}`),
                    ]),
                    ctx.operatingLoopError ? h('div', { class: 'mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700' }, ctx.operatingLoopError) : null,
                ]),
                h('div', { class: 'grid grid-cols-1 gap-3 p-4 sm:px-5 lg:grid-cols-2 xl:grid-cols-4' }, [
                    answer('什么是真的', loop.what_is_true || '尚无通过权威证据链成立的经营事实。'),
                    answer('最重要的问题', issue.title || '等待权威事实形成后判断。', issue.detail || ''),
                    answer('下一步谁做什么', nextAction.action || '先确认酒店、来源门店、业务日和指标版本。', `负责人：${owner.role || (owner.user_id ? `用户 ${owner.user_id}` : '待明确')}`),
                    answer('昨天动作有没有结果', yesterdayResultStatusLabel, result.result_summary || '尚无同酒店、同平台、同指标口径的结果回读。'),
                ]),
                h('div', { class: 'border-t border-slate-100 px-4 py-4 sm:px-5' }, [
                    h('div', { class: 'mb-2 flex flex-wrap items-center justify-between gap-2' }, [
                        h('div', { class: 'text-sm font-semibold text-slate-900' }, '唯一八阶段状态链'),
                        h('div', { class: 'text-xs text-slate-500' }, `证据引用 ${Number(loop.evidence_ref_count || 0)} 条`),
                    ]),
                    h('div', { class: 'grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4' }, stages.map((stage, index) => h('div', {
                        key: stage.key,
                        class: ['rounded-xl border px-3 py-2', ctx.operatingLoopStageClass?.(stage.status)],
                    }, [
                        h('div', { class: 'flex items-center gap-2' }, [
                            h('span', { class: 'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-current text-[10px] font-bold' }, String(index + 1)),
                            h('span', { class: 'text-xs font-semibold' }, stage.label || stage.key),
                        ]),
                        h('div', { class: 'mt-1 pl-7 text-[11px] opacity-80' }, stage.status === 'complete' ? '证据成立' : (stage.status === 'missing' ? '当前阻断' : '尚未证明')),
                    ]))),
                    h('div', { class: 'mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500' }, [
                        actor('判断人', actors.judged_by), actor('批准人', actors.approved_by),
                        actor('执行人', actors.executed_by), actor('复盘人', actors.reviewed_by),
                        h('span', `经验：${experience.status || 'not_reviewed'}`),
                    ]),
                ]),
            ]);
        },
    };

    const managerCapabilityToday = () => {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
        }).formatToParts(new Date());
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}`;
    };
    const managerCapabilityKey = () => {
        const randomPart = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
        return `manager-case-${randomPart}`;
    };
    const managerCapabilityForm = () => ({
        business_date: managerCapabilityToday(),
        problem_facts: '',
        action_taken: '',
        verification_status: 'observed_result',
        verification_text: '',
        followup_due_date: managerCapabilityToday(),
        evidence_type: 'onsite_observation',
        evidence_reference: '',
        evidence_date: managerCapabilityToday(),
        idempotency_key: managerCapabilityKey(),
    });
    const managerCapabilityFollowupKey = () => managerCapabilityKey().replace('manager-case-', 'manager-followup-');
    const managerCapabilityFollowupForm = () => ({
        followup_date: managerCapabilityToday(),
        followup_outcome: 'resolved',
        verification_text: '',
        sample_count: 1,
        evidence_type: 'onsite_observation',
        evidence_reference: '',
        evidence_date: managerCapabilityToday(),
        next_followup_date: managerCapabilityToday(),
        recurrence_problem_facts: '',
        recurrence_action_taken: '',
        recurrence_verification_plan: '',
        idempotency_key: managerCapabilityFollowupKey(),
    });
    const managerCapabilityAdjustmentKey = () => managerCapabilityKey().replace('manager-case-', 'manager-adjustment-');
    const managerCapabilityReviewKey = () => managerCapabilityKey().replace('manager-case-', 'manager-review-');
    const managerCapabilityAdjustmentForm = () => ({
        adjustment_type: 'voided', reason: '', business_date: managerCapabilityToday(),
        problem_facts: '', action_taken: '', verification_status: 'observed_result',
        verification_text: '', followup_due_date: managerCapabilityToday(),
        evidence_type: 'onsite_observation', evidence_reference: '', evidence_date: managerCapabilityToday(),
        idempotency_key: managerCapabilityAdjustmentKey(),
    });
    const managerCapabilityReviewForm = () => ({
        review_outcome: 'confirmed', reason: '', source_score_digest: '',
        dimension_overrides: {}, idempotency_key: managerCapabilityReviewKey(),
    });
    const managerCapabilityEvidenceTypes = [
        ['onsite_observation', '现场观察'], ['signed_checklist', '签字清单/台账'],
        ['system_record', '系统记录/报表'], ['guest_feedback', '客诉或宾客反馈'],
        ['photo_record', '照片/附件记录'], ['other', '其他人工证据'],
    ];
    const managerCapabilityDefaultDimensions = [
        ['problem_discovery', '问题发现'],
        ['cause_analysis', '原因分析'],
        ['solution_management', '管理解决'],
        ['coaching', '带教能力'],
        ['execution_prevention', '执行与预防'],
        ['closure', '闭环能力'],
    ];
    const ManagerCapabilityPanel = {
        name: 'ManagerCapabilityPanel',
        props: {
            hotelId: { type: [String, Number], default: '' },
            request: { type: Function, required: true },
        },
        data: () => ({
            managers: [],
            selectedManagerId: '',
            profile: null,
            loading: false,
            saving: false,
            followupSaving: false,
            adjustmentSaving: false,
            reviewSaving: false,
            queueLoading: false,
            error: '',
            queueError: '',
            form: managerCapabilityForm(),
            selectedFollowupCaseId: '',
            selectedCaseRecord: null,
            followupForm: managerCapabilityFollowupForm(),
            followupQueue: null,
            followupQueueFilter: 'all',
            selectedAdjustmentCaseId: '',
            adjustmentForm: managerCapabilityAdjustmentForm(),
            selectedReviewCaseId: '',
            reviewForm: managerCapabilityReviewForm(),
            managerRequestSeq: 0,
            profileRequestSeq: 0,
            queueRequestSeq: 0,
        }),
        computed: {
            normalizedHotelId() {
                return Number(this.hotelId || 0);
            },
            dimensions() {
                if (Array.isArray(this.profile?.dimensions) && this.profile.dimensions.length === 6) {
                    return this.profile.dimensions;
                }
                return managerCapabilityDefaultDimensions.map(([key, label]) => ({
                    key, label, score: null, status: 'data_insufficient', level_label: '数据不足',
                    sample_count: 0, required_sample_count: 3,
                }));
            },
            recentCases() {
                return Array.isArray(this.profile?.recent_cases) ? this.profile.recent_cases : [];
            },
            dailySubmission() {
                const daily = this.profile?.daily_submission;
                if (daily && typeof daily === 'object') return daily;
                return {
                    business_date: '', status: 'data_insufficient', label: '三问状态待读取',
                    case_count: 0, last_submission_date: null, consecutive_missing_days: null,
                    attention_status: 'unknown', closure_inferred: false,
                    closure_note: '已提交不等于已闭环；仍以复查事件和可核对的验证结果为准',
                };
            },
            isTodayForm() {
                return String(this.form.business_date || '') === managerCapabilityToday();
            },
            canViewEvidenceDetail() {
                return this.profile?.permissions?.can_view_evidence_detail === true;
            },
            canManageEvidence() {
                return this.profile?.permissions?.can_manage_evidence === true;
            },
            dueQueueRows() {
                const rows = Array.isArray(this.followupQueue?.rows) ? this.followupQueue.rows : [];
                return this.followupQueueFilter === 'all'
                    ? rows
                    : rows.filter(item => item.due_bucket === this.followupQueueFilter);
            },
            selectedFollowupCase() {
                if (Number(this.selectedCaseRecord?.id || 0) === Number(this.selectedFollowupCaseId || 0)) {
                    return this.selectedCaseRecord;
                }
                return [...this.recentCases, ...(Array.isArray(this.followupQueue?.rows) ? this.followupQueue.rows : [])]
                    .find(item => Number(item.id) === Number(this.selectedFollowupCaseId || 0)) || null;
            },
            selectedAdjustmentCase() {
                return this.recentCases.find(item => Number(item.id) === Number(this.selectedAdjustmentCaseId || 0)) || null;
            },
            selectedReviewCase() {
                return this.recentCases.find(item => Number(item.id) === Number(this.selectedReviewCaseId || 0)) || null;
            },
        },
        watch: {
            hotelId: { immediate: true, handler() { void this.load(); } },
        },
        methods: {
            notify(message, type = 'success') {
                this.$root?.showToast?.(message, type);
            },
            profileIsScoped(profile, hotelId, managerUserId) {
                return Number(profile?.hotel_id || 0) === Number(hotelId || 0)
                    && Number(profile?.manager_user_id || 0) === Number(managerUserId || 0)
                    && Array.isArray(profile?.dimensions)
                    && profile.dimensions.length === 6
                    && String(profile?.scoring_contract?.version || '') === 'manager_capability_evidence_v1'
                    && String(profile?.daily_submission?.business_date || '') === String(profile?.window?.date_to || '')
                    && profile?.daily_submission?.closure_inferred === false
                    && /^[a-f0-9]{64}$/i.test(String(profile?.source?.fingerprint || ''));
            },
            async loadProfile() {
                const hotelId = this.normalizedHotelId;
                const managerUserId = Number(this.selectedManagerId || 0);
                const requestSeq = ++this.profileRequestSeq;
                this.selectedFollowupCaseId = '';
                this.selectedCaseRecord = null;
                this.followupForm = managerCapabilityFollowupForm();
                this.selectedAdjustmentCaseId = '';
                this.adjustmentForm = managerCapabilityAdjustmentForm();
                this.selectedReviewCaseId = '';
                this.reviewForm = managerCapabilityReviewForm();
                this.followupQueue = null;
                this.queueError = '';
                if (hotelId <= 0 || managerUserId <= 0) {
                    this.profile = null;
                    this.error = hotelId > 0 ? '请选择店长或负责人' : '';
                    return null;
                }
                this.loading = true;
                this.error = '';
                try {
                    const params = new URLSearchParams({
                        hotel_id: String(hotelId), manager_user_id: String(managerUserId),
                    });
                    const res = await this.request(`/operation/manager-capability/profile?${params}`, {
                        businessContext: { hotelId },
                    });
                    if (requestSeq !== this.profileRequestSeq
                        || hotelId !== this.normalizedHotelId
                        || managerUserId !== Number(this.selectedManagerId || 0)) return null;
                    if (res.code !== 200) throw new Error(res.message || '店长能力档案加载失败');
                    if (!this.profileIsScoped(res.data, hotelId, managerUserId)) {
                        throw new Error('店长能力档案未按酒店、人员和评分版本精确回读');
                    }
                    this.profile = res.data;
                    if (this.canManageEvidence) await this.loadFollowupQueue();
                    return res.data;
                } catch (error) {
                    if (requestSeq !== this.profileRequestSeq) return null;
                    this.profile = null;
                    this.error = error?.message || '店长能力档案加载失败';
                    return null;
                } finally {
                    if (requestSeq === this.profileRequestSeq) this.loading = false;
                }
            },
            async loadFollowupQueue() {
                const hotelId = this.normalizedHotelId;
                const managerUserId = Number(this.selectedManagerId || 0);
                const requestSeq = ++this.queueRequestSeq;
                if (!this.canManageEvidence || hotelId <= 0 || managerUserId <= 0) {
                    this.followupQueue = null;
                    this.queueError = '';
                    return null;
                }
                this.queueLoading = true;
                this.queueError = '';
                try {
                    const params = new URLSearchParams({
                        hotel_id: String(hotelId), manager_user_id: String(managerUserId),
                    });
                    const res = await this.request(`/operation/manager-capability/followup-queue?${params}`, {
                        businessContext: { hotelId },
                    });
                    if (requestSeq !== this.queueRequestSeq
                        || hotelId !== this.normalizedHotelId
                        || managerUserId !== Number(this.selectedManagerId || 0)) return null;
                    if (res.code !== 200) throw new Error(res.message || '待复查工作台加载失败');
                    if (Number(res.data?.hotel_id || 0) !== hotelId
                        || Number(res.data?.manager_user_id || 0) !== managerUserId
                        || !Array.isArray(res.data?.rows)) {
                        throw new Error('待复查工作台未按当前门店和负责人精确回读');
                    }
                    this.followupQueue = res.data;
                    return res.data;
                } catch (error) {
                    if (requestSeq !== this.queueRequestSeq) return null;
                    this.followupQueue = null;
                    this.queueError = error?.message || '待复查工作台加载失败';
                    return null;
                } finally {
                    if (requestSeq === this.queueRequestSeq) this.queueLoading = false;
                }
            },
            async load() {
                const hotelId = this.normalizedHotelId;
                const requestSeq = ++this.managerRequestSeq;
                this.profileRequestSeq++;
                if (hotelId <= 0) {
                    this.managers = [];
                    this.selectedManagerId = '';
                    this.profile = null;
                    this.followupQueue = null;
                    this.error = '';
                    this.loading = false;
                    return null;
                }
                this.loading = true;
                this.error = '';
                try {
                    const res = await this.request(`/operation/manager-capability/managers?hotel_id=${hotelId}`, {
                        businessContext: { hotelId },
                    });
                    if (requestSeq !== this.managerRequestSeq || hotelId !== this.normalizedHotelId) return null;
                    if (res.code !== 200) throw new Error(res.message || '店长列表加载失败');
                    if (Number(res.data?.hotel_id || 0) !== hotelId || !Array.isArray(res.data?.list)) {
                        throw new Error('店长列表未按当前酒店精确回读');
                    }
                    this.managers = res.data.list;
                    if (!this.managers.some(item => Number(item.id) === Number(this.selectedManagerId))) {
                        const preferred = this.managers.find(item => item.is_current_user === true) || this.managers[0];
                        this.selectedManagerId = preferred?.id ? String(preferred.id) : '';
                    }
                    return this.selectedManagerId ? await this.loadProfile() : null;
                } catch (error) {
                    if (requestSeq !== this.managerRequestSeq) return null;
                    this.managers = [];
                    this.selectedManagerId = '';
                    this.profile = null;
                    this.followupQueue = null;
                    this.error = error?.message || '店长列表加载失败';
                    return null;
                } finally {
                    if (requestSeq === this.managerRequestSeq) this.loading = false;
                }
            },
            caseStatusText(item = {}) {
                if (item.is_voided === true || item.case_status === 'voided') return '已作废，不计入档案';
                const latest = item.latest_followup || null;
                if (latest?.followup_outcome === 'resolved') return `${latest.followup_date} 已复查关闭`;
                if (latest?.followup_outcome === 'still_open') {
                    return latest.next_followup_date ? `${latest.next_followup_date} 继续复查` : '复查后仍待处理';
                }
                if (latest?.followup_outcome === 'recurred') {
                    return latest.linked_recurrence_case_id
                        ? `再次发生 · 已关联新案例 #${latest.linked_recurrence_case_id}`
                        : '再次发生';
                }
                if (item.verification_status === 'planned_verification') {
                    return item.followup_due_date ? `${item.followup_due_date} 待复查` : '待复查';
                }
                return item.score_snapshot?.score_status === 'scored' ? '已闭环评分' : '已记录，证据待补';
            },
            caseScoreText(item = {}) {
                const score = item.score_snapshot?.case_score;
                const count = Number(item.score_snapshot?.scored_dimension_count || 0);
                return score === null || score === undefined ? `${count}/6 维有证据` : `案例分 ${score}`;
            },
            dimensionSummary(item = {}) {
                return (Array.isArray(item.score_snapshot?.dimensions) ? item.score_snapshot.dimensions : [])
                    .map(dimension => `${dimension.label} ${dimension.score === null || dimension.score === undefined ? '未观察' : dimension.score}`)
                    .join(' · ');
            },
            evidenceConfidenceText(item = {}) {
                const evidence = item.evidence || {};
                return `证据置信度：${evidence.confidence_label || '未核验'}${evidence.type_label ? ` · ${evidence.type_label}` : ''}`;
            },
            dueQueueText(item = {}) {
                const offset = Number(item.days_offset || 0);
                if (item.due_bucket === 'overdue') return `逾期 ${Math.abs(offset)} 天`;
                if (item.due_bucket === 'today') return '今天到期';
                return `${offset} 天后到期`;
            },
            openFollowup(item = {}) {
                const caseId = Number(item.id || 0);
                if (caseId <= 0) return;
                this.selectedFollowupCaseId = String(caseId);
                this.selectedCaseRecord = item;
                this.followupForm = managerCapabilityFollowupForm();
                this.error = '';
            },
            closeFollowup() {
                this.selectedFollowupCaseId = '';
                this.selectedCaseRecord = null;
                this.followupForm = managerCapabilityFollowupForm();
            },
            openAdjustment(item = {}, type = 'voided') {
                const caseId = Number(item.id || 0);
                if (caseId <= 0 || !this.canManageEvidence) return;
                this.selectedAdjustmentCaseId = String(caseId);
                this.adjustmentForm = {
                    ...managerCapabilityAdjustmentForm(), adjustment_type: type,
                    business_date: String(item.business_date || managerCapabilityToday()),
                    problem_facts: String(item.problem_facts || ''),
                    action_taken: String(item.action_taken || ''),
                    verification_status: String(item.verification_status || 'observed_result'),
                    verification_text: String(item.verification_text || ''),
                    followup_due_date: String(item.current_followup_due_date || item.followup_due_date || managerCapabilityToday()),
                    evidence_type: String(item.evidence?.type || 'onsite_observation'),
                    evidence_reference: String(item.evidence?.reference || ''),
                    evidence_date: String(item.evidence?.date || item.business_date || managerCapabilityToday()),
                };
                this.selectedReviewCaseId = '';
                this.error = '';
            },
            closeAdjustment() {
                this.selectedAdjustmentCaseId = '';
                this.adjustmentForm = managerCapabilityAdjustmentForm();
            },
            openScoreReview(item = {}) {
                const caseId = Number(item.id || 0);
                if (caseId <= 0 || !this.canManageEvidence || item.is_voided === true) return;
                this.selectedReviewCaseId = String(caseId);
                this.reviewForm = managerCapabilityReviewForm();
                this.reviewForm.source_score_digest = String(item.score_snapshot?.evidence_digest || '');
                this.reviewForm.dimension_overrides = Object.fromEntries(
                    managerCapabilityDefaultDimensions.map(([key]) => [key, 'keep'])
                );
                this.selectedAdjustmentCaseId = '';
                this.error = '';
            },
            closeScoreReview() {
                this.selectedReviewCaseId = '';
                this.reviewForm = managerCapabilityReviewForm();
            },
            async submitFollowup() {
                const hotelId = this.normalizedHotelId;
                const managerUserId = Number(this.selectedManagerId || 0);
                const caseId = Number(this.selectedFollowupCaseId || 0);
                const selectedCase = this.selectedFollowupCase;
                if (hotelId <= 0 || managerUserId <= 0 || caseId <= 0 || !selectedCase) {
                    this.notify('请先选择当前门店、负责人和待复查案例', 'warning');
                    return;
                }
                if (!String(this.followupForm.verification_text || '').trim()) {
                    this.notify('请填写本次复查结果或继续计划', 'warning');
                    return;
                }
                if (!String(this.followupForm.evidence_type || '').trim()
                    || !String(this.followupForm.evidence_reference || '').trim()
                    || !String(this.followupForm.evidence_date || '').trim()) {
                    this.notify('请完整填写证据类型、日期和引用', 'warning');
                    return;
                }
                const outcome = String(this.followupForm.followup_outcome || '');
                if (outcome !== 'resolved' && !String(this.followupForm.next_followup_date || '').trim()) {
                    this.notify('待继续或再发生时必须填写下次复查日期', 'warning');
                    return;
                }
                if (outcome === 'recurred' && [
                    this.followupForm.recurrence_problem_facts,
                    this.followupForm.recurrence_action_taken,
                    this.followupForm.recurrence_verification_plan,
                ].some(value => !String(value || '').trim())) {
                    this.notify('再次发生时请完整填写新的问题、动作和验证计划', 'warning');
                    return;
                }

                const payload = {
                    hotel_id: hotelId,
                    followup_date: String(this.followupForm.followup_date || ''),
                    followup_outcome: outcome,
                    verification_text: String(this.followupForm.verification_text || '').trim(),
                    sample_count: Number(this.followupForm.sample_count || 0),
                    evidence_type: String(this.followupForm.evidence_type || ''),
                    evidence_reference: String(this.followupForm.evidence_reference || '').trim(),
                    evidence_date: String(this.followupForm.evidence_date || ''),
                    next_followup_date: outcome === 'resolved'
                        ? null : String(this.followupForm.next_followup_date || ''),
                    recurrence_problem_facts: outcome === 'recurred'
                        ? String(this.followupForm.recurrence_problem_facts || '').trim() : null,
                    recurrence_action_taken: outcome === 'recurred'
                        ? String(this.followupForm.recurrence_action_taken || '').trim() : null,
                    recurrence_verification_plan: outcome === 'recurred'
                        ? String(this.followupForm.recurrence_verification_plan || '').trim() : null,
                    idempotency_key: String(this.followupForm.idempotency_key || managerCapabilityFollowupKey()),
                };
                this.followupSaving = true;
                this.error = '';
                try {
                    const res = await this.request(`/operation/manager-capability/cases/${caseId}/followups`, {
                        method: 'POST', businessContext: { hotelId }, body: JSON.stringify(payload),
                    });
                    if (res.code !== 200) throw new Error(res.message || '店长能力复查保存失败');
                    const savedCase = res.data?.case || {};
                    const followup = res.data?.followup || {};
                    const linkedCase = res.data?.linked_recurrence_case || null;
                    if (res.data?.readback_verified !== true
                        || Number(savedCase.id || 0) !== caseId
                        || Number(savedCase.hotel_id || 0) !== hotelId
                        || Number(savedCase.manager_user_id || 0) !== managerUserId
                        || Number(followup.case_id || 0) !== caseId
                        || String(followup.followup_outcome || '') !== outcome
                        || !/^[a-f0-9]{64}$/i.test(String(followup.input_digest || ''))
                        || !/^[a-f0-9]{64}$/i.test(String(followup.score_snapshot?.evidence_digest || ''))
                        || String(followup.score_snapshot?.scoring_version || '') !== 'manager_capability_evidence_v1'
                        || !this.profileIsScoped(res.data?.profile, hotelId, managerUserId)
                        || (outcome === 'recurred' && (
                            Number(linkedCase?.parent_case_id || 0) !== caseId
                            || Number(linkedCase?.origin_followup_id || 0) !== Number(followup.id || 0)
                            || Number(followup.linked_recurrence_case_id || 0) !== Number(linkedCase?.id || 0)
                        ))) {
                        throw new Error('店长能力复查保存后未完成精确回读');
                    }
                    this.profile = res.data.profile;
                    this.closeFollowup();
                    await this.loadFollowupQueue();
                    this.notify(res.data.replayed ? '已读取同一次复查' : (outcome === 'recurred' ? '复查已保存，并生成关联新案例' : '复查已保存并回读'));
                } catch (error) {
                    this.error = error?.message || '店长能力复查保存失败';
                    this.notify(this.error, 'error');
                } finally {
                    this.followupSaving = false;
                }
            },
            async submitAdjustment() {
                const hotelId = this.normalizedHotelId;
                const managerUserId = Number(this.selectedManagerId || 0);
                const caseId = Number(this.selectedAdjustmentCaseId || 0);
                const selectedCase = this.selectedAdjustmentCase;
                if (!this.canManageEvidence || hotelId <= 0 || managerUserId <= 0 || caseId <= 0 || !selectedCase) {
                    this.notify('当前账号不能修正该案例', 'warning');
                    return;
                }
                const type = String(this.adjustmentForm.adjustment_type || '');
                if (!String(this.adjustmentForm.reason || '').trim()) {
                    this.notify('请填写纠错、作废或恢复原因', 'warning');
                    return;
                }
                if (type === 'corrected' && [
                    this.adjustmentForm.problem_facts, this.adjustmentForm.action_taken,
                    this.adjustmentForm.verification_text, this.adjustmentForm.evidence_reference,
                ].some(value => !String(value || '').trim())) {
                    this.notify('纠错时请完整填写三问和结构化证据', 'warning');
                    return;
                }
                const payload = {
                    hotel_id: hotelId,
                    adjustment_type: type,
                    reason: String(this.adjustmentForm.reason || '').trim(),
                    idempotency_key: String(this.adjustmentForm.idempotency_key || managerCapabilityAdjustmentKey()),
                };
                if (type === 'corrected') Object.assign(payload, {
                    business_date: String(this.adjustmentForm.business_date || ''),
                    problem_facts: String(this.adjustmentForm.problem_facts || '').trim(),
                    action_taken: String(this.adjustmentForm.action_taken || '').trim(),
                    verification_status: String(this.adjustmentForm.verification_status || ''),
                    verification_text: String(this.adjustmentForm.verification_text || '').trim(),
                    followup_due_date: this.adjustmentForm.verification_status === 'planned_verification'
                        ? String(this.adjustmentForm.followup_due_date || '') : null,
                    evidence_type: String(this.adjustmentForm.evidence_type || ''),
                    evidence_reference: String(this.adjustmentForm.evidence_reference || '').trim(),
                    evidence_date: String(this.adjustmentForm.evidence_date || ''),
                });
                this.adjustmentSaving = true;
                this.error = '';
                try {
                    const res = await this.request(`/operation/manager-capability/cases/${caseId}/adjustments`, {
                        method: 'POST', businessContext: { hotelId }, body: JSON.stringify(payload),
                    });
                    if (res.code !== 200) throw new Error(res.message || '店长能力案例修正失败');
                    const saved = res.data?.case || {};
                    const adjustment = res.data?.adjustment || {};
                    if (res.data?.readback_verified !== true
                        || Number(saved.id || 0) !== caseId
                        || Number(saved.hotel_id || 0) !== hotelId
                        || Number(saved.manager_user_id || 0) !== managerUserId
                        || String(adjustment.adjustment_type || '') !== type
                        || !/^[a-f0-9]{64}$/i.test(String(adjustment.input_digest || ''))
                        || !/^[a-f0-9]{64}$/i.test(String(adjustment.score_snapshot?.evidence_digest || ''))
                        || !this.profileIsScoped(res.data?.profile, hotelId, managerUserId)) {
                        throw new Error('案例修正保存后未完成精确回读');
                    }
                    this.profile = res.data.profile;
                    this.closeAdjustment();
                    await this.loadFollowupQueue();
                    this.notify(res.data.replayed ? '已读取同一次案例修正' : '案例修正已追加并回读');
                } catch (error) {
                    this.error = error?.message || '店长能力案例修正失败';
                    this.notify(this.error, 'error');
                } finally {
                    this.adjustmentSaving = false;
                }
            },
            async submitScoreReview() {
                const hotelId = this.normalizedHotelId;
                const managerUserId = Number(this.selectedManagerId || 0);
                const caseId = Number(this.selectedReviewCaseId || 0);
                const selectedCase = this.selectedReviewCase;
                if (!this.canManageEvidence || hotelId <= 0 || managerUserId <= 0 || caseId <= 0 || !selectedCase) {
                    this.notify('当前账号不能复核该案例', 'warning');
                    return;
                }
                const outcome = String(this.reviewForm.review_outcome || '');
                if (!String(this.reviewForm.reason || '').trim()) {
                    this.notify('请填写人工复核依据', 'warning');
                    return;
                }
                const overrides = {};
                if (outcome === 'adjusted') {
                    Object.entries(this.reviewForm.dimension_overrides || {}).forEach(([key, value]) => {
                        if (value === 'keep') return;
                        overrides[key] = value === 'unknown' ? null : Number(value);
                    });
                    if (!Object.keys(overrides).length) {
                        this.notify('人工调整至少需要修改一个维度', 'warning');
                        return;
                    }
                }
                const payload = {
                    hotel_id: hotelId,
                    review_outcome: outcome,
                    reason: String(this.reviewForm.reason || '').trim(),
                    dimension_overrides: overrides,
                    source_score_digest: String(this.reviewForm.source_score_digest || ''),
                    idempotency_key: String(this.reviewForm.idempotency_key || managerCapabilityReviewKey()),
                };
                this.reviewSaving = true;
                this.error = '';
                try {
                    const res = await this.request(`/operation/manager-capability/cases/${caseId}/score-reviews`, {
                        method: 'POST', businessContext: { hotelId }, body: JSON.stringify(payload),
                    });
                    if (res.code !== 200) throw new Error(res.message || '店长评分人工复核失败');
                    const saved = res.data?.case || {};
                    const review = res.data?.score_review || {};
                    if (res.data?.readback_verified !== true
                        || Number(saved.id || 0) !== caseId
                        || Number(saved.hotel_id || 0) !== hotelId
                        || Number(saved.manager_user_id || 0) !== managerUserId
                        || String(review.review_outcome || '') !== outcome
                        || !/^[a-f0-9]{64}$/i.test(String(review.input_digest || ''))
                        || !/^[a-f0-9]{64}$/i.test(String(review.score_snapshot?.evidence_digest || ''))
                        || !this.profileIsScoped(res.data?.profile, hotelId, managerUserId)) {
                        throw new Error('人工复核保存后未完成精确回读');
                    }
                    this.profile = res.data.profile;
                    this.closeScoreReview();
                    await this.loadFollowupQueue();
                    this.notify(res.data.replayed ? '已读取同一次人工复核' : '人工复核已追加并回读');
                } catch (error) {
                    this.error = error?.message || '店长评分人工复核失败';
                    this.notify(this.error, 'error');
                } finally {
                    this.reviewSaving = false;
                }
            },
            async submit() {
                const hotelId = this.normalizedHotelId;
                const managerUserId = Number(this.selectedManagerId || 0);
                if (hotelId <= 0 || managerUserId <= 0) {
                    this.notify(hotelId <= 0 ? '请先选择单个酒店' : '请选择店长或负责人', 'warning');
                    return;
                }
                if (!String(this.form.problem_facts || '').trim()
                    || !String(this.form.action_taken || '').trim()
                    || !String(this.form.verification_text || '').trim()) {
                    this.notify('请完整填写管理层三问', 'warning');
                    return;
                }
                if (!String(this.form.evidence_type || '').trim()
                    || !String(this.form.evidence_reference || '').trim()
                    || !String(this.form.evidence_date || '').trim()) {
                    this.notify('请完整填写证据类型、日期和引用', 'warning');
                    return;
                }
                const payload = {
                    hotel_id: hotelId,
                    manager_user_id: managerUserId,
                    business_date: String(this.form.business_date || ''),
                    problem_facts: String(this.form.problem_facts || '').trim(),
                    action_taken: String(this.form.action_taken || '').trim(),
                    verification_status: String(this.form.verification_status || ''),
                    verification_text: String(this.form.verification_text || '').trim(),
                    followup_due_date: this.form.verification_status === 'planned_verification'
                        ? String(this.form.followup_due_date || '') : null,
                    evidence_type: String(this.form.evidence_type || ''),
                    evidence_reference: String(this.form.evidence_reference || '').trim(),
                    evidence_date: String(this.form.evidence_date || ''),
                    idempotency_key: String(this.form.idempotency_key || managerCapabilityKey()),
                };
                this.saving = true;
                this.error = '';
                try {
                    const res = await this.request('/operation/manager-capability/cases', {
                        method: 'POST', businessContext: { hotelId }, body: JSON.stringify(payload),
                    });
                    if (res.code !== 200) throw new Error(res.message || '店长评分案例保存失败');
                    const saved = res.data?.case || {};
                    if (res.data?.readback_verified !== true
                        || Number(saved.hotel_id || 0) !== hotelId
                        || Number(saved.manager_user_id || 0) !== managerUserId
                        || !/^[a-f0-9]{64}$/i.test(String(saved.input_digest || ''))
                        || !/^[a-f0-9]{64}$/i.test(String(saved.score_snapshot?.evidence_digest || ''))
                        || String(saved.score_snapshot?.scoring_version || '') !== 'manager_capability_evidence_v1'
                        || !this.profileIsScoped(res.data?.profile, hotelId, managerUserId)) {
                        throw new Error('店长评分案例保存后未完成精确回读');
                    }
                    this.profile = res.data.profile;
                    this.form = managerCapabilityForm();
                    await this.loadFollowupQueue();
                    this.notify(res.data.replayed
                        ? '已读取同一三问案例'
                        : (payload.business_date === managerCapabilityToday()
                            ? '今日三问已保存、评分与回读已更新'
                            : '补录三问已保存、评分与回读已更新'));
                } catch (error) {
                    this.error = error?.message || '店长评分案例保存失败';
                    this.notify(this.error, 'error');
                } finally {
                    this.saving = false;
                }
            },
        },
        render() {
            const field = (label, node) => h('label', { class: 'block' }, [
                h('span', { class: 'mb-1 block text-xs font-medium text-slate-600' }, label), node,
            ]);
            const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm';
            const textArea = (key, placeholder, minLength) => h('textarea', {
                value: this.form[key], rows: 3, minlength: minLength, maxlength: 2000, required: true,
                placeholder, class: `${inputClass} leading-6`,
                onInput: event => { this.form[key] = event.target.value; },
            });
            const followupTextArea = (key, placeholder, minLength) => h('textarea', {
                value: this.followupForm[key], rows: 3, minlength: minLength, maxlength: 2000, required: true,
                placeholder, class: `${inputClass} leading-6`,
                onInput: event => { this.followupForm[key] = event.target.value; },
            });
            const modelTextArea = (model, key, placeholder, minLength) => h('textarea', {
                value: model[key], rows: 3, minlength: minLength, maxlength: 2000, required: true,
                placeholder, class: `${inputClass} leading-6`,
                onInput: event => { model[key] = event.target.value; },
            });
            const evidenceFields = (model, testPrefix) => h('div', {
                class: 'grid gap-3 sm:grid-cols-2', 'data-testid': `${testPrefix}-evidence`,
            }, [
                field('证据类型', h('select', {
                    value: model.evidence_type, required: true, class: inputClass,
                    onChange: event => { model.evidence_type = event.target.value; },
                }, managerCapabilityEvidenceTypes.map(([value, label]) => h('option', { value }, label)))),
                field('证据日期', h('input', {
                    value: model.evidence_date, type: 'date', required: true, class: inputClass,
                    onInput: event => { model.evidence_date = event.target.value; },
                })),
                h('div', { class: 'sm:col-span-2' }, [field('证据引用', h('input', {
                    value: model.evidence_reference, type: 'text', maxlength: 500, required: true,
                    placeholder: '例如：交接台账 2026-08-22 / 系统报表编号 / 附件名称', class: inputClass,
                    onInput: event => { model.evidence_reference = event.target.value; },
                }))]),
            ]);
            const profileStatusClass = this.profile?.profile_status === 'scored'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-amber-200 bg-amber-50 text-amber-700';
            const queueCounts = this.followupQueue?.counts || { overdue: 0, today: 0, upcoming: 0, all: 0 };
            const queueFilters = [
                ['all', '全部', queueCounts.all], ['overdue', '逾期', queueCounts.overdue],
                ['today', '今天', queueCounts.today], ['upcoming', '未来7天', queueCounts.upcoming],
            ];
            const trendPoints = Array.isArray(this.profile?.trend?.points) ? this.profile.trend.points : [];
            const coaching = Array.isArray(this.profile?.coaching_suggestions) ? this.profile.coaching_suggestions : [];
            const pilot = this.profile?.pilot_readiness || {};
            const confidence = this.profile?.evidence_confidence_summary?.counts || {};
            const daily = this.dailySubmission;
            const dailyStatusClass = daily.status === 'submitted'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : (daily.attention_status === 'three_day_missing'
                    ? 'border-rose-200 bg-rose-50 text-rose-700'
                    : 'border-amber-200 bg-amber-50 text-amber-700');
            const dailyDetail = daily.status === 'submitted'
                ? `${Number(daily.case_count || 0)} 条三问案例已正式保存`
                : (daily.last_submission_date
                    ? `最近提交 ${daily.last_submission_date} · 连续 ${Number(daily.consecutive_missing_days || 0)} 天未提交`
                    : '当前范围未找到历史提交；缺失天数保持未知');

            return h('section', {
                class: 'overflow-hidden rounded-2xl border border-[#d8e4df] bg-white shadow-sm',
                'data-testid': 'manager-capability-panel',
            }, [
                h('div', { class: 'flex flex-col gap-3 border-b border-slate-100 bg-[#f6faf8] px-5 py-4 lg:flex-row lg:items-start lg:justify-between' }, [
                    h('div', [
                        h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                            h('h3', { class: 'font-bold text-slate-900' }, '店长能力评分'),
                            h('span', { class: 'rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700' }, '管理层三问 · 六维证据分'),
                            h('span', { class: `rounded-full border px-2 py-0.5 text-xs font-medium ${profileStatusClass}` }, this.profile?.profile_label || '数据不足'),
                        ]),
                        h('p', { class: 'mt-1 max-w-3xl text-sm leading-6 text-slate-600' }, '记录问题事实、采取动作和验证结果，形成问题发现、原因分析、管理解决、带教、执行预防、闭环六项可解释分数。'),
                        h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, '仅用于当前门店管理复盘；人工录入事实未被系统独立核验，不用于跨店排名、处罚或自动触发运营动作。'),
                    ]),
                    h('button', {
                        type: 'button', disabled: this.loading || this.normalizedHotelId <= 0,
                        class: 'rounded-lg border border-[#315d50] bg-white px-3 py-2 text-sm font-medium text-[#315d50] hover:bg-emerald-50 disabled:opacity-50',
                        onClick: () => this.load(),
                    }, this.loading ? '刷新中' : '刷新评分档案'),
                ]),
                this.normalizedHotelId <= 0
                    ? h('div', { style: 'margin:1.25rem', class: 'rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800' }, '请先在页面顶部选择一个门店；评分不会汇总多个酒店。')
                    : h('div', { class: 'grid gap-5 p-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(360px,0.9fr)]' }, [
                        h('div', { class: 'space-y-4' }, [
                            h('div', { class: 'flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between' }, [
                                field('店长 / 负责人', h('select', {
                                    value: this.selectedManagerId,
                                    class: 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800',
                                    'data-testid': 'manager-capability-manager',
                                    onChange: event => { this.selectedManagerId = event.target.value; void this.loadProfile(); },
                                }, [h('option', { value: '' }, '请选择'), ...this.managers.map(manager => h('option', {
                                    key: manager.id, value: String(manager.id),
                                }, `${manager.display_name}${manager.role_display_name ? ` · ${manager.role_display_name}` : ''}${manager.is_current_user ? '（当前账号）' : ''}`))])),
                                h('div', { class: 'rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-right' }, [
                                    h('div', { class: 'text-xs text-slate-500' }, '近90天档案总分'),
                                    h('div', { class: `mt-0.5 text-xl font-semibold ${this.profile?.overall_score == null ? 'text-slate-400' : 'text-[#315d50]'}` }, this.profile?.overall_score == null ? '数据不足' : String(this.profile.overall_score)),
                                ]),
                            ]),
                            this.profile ? h('section', {
                                class: `rounded-xl border px-4 py-3 ${dailyStatusClass}`,
                                'data-testid': 'manager-capability-daily-status',
                            }, [
                                h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between' }, [
                                    h('div', [
                                        h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                            h('h4', { class: 'text-sm font-semibold' }, daily.business_date === managerCapabilityToday() ? '今日店长三问' : '所选日期店长三问'),
                                            h('span', { class: 'rounded-full border border-current/20 bg-white/70 px-2 py-0.5 text-[11px] font-medium' }, daily.label || '状态待读取'),
                                        ]),
                                        h('p', { class: 'mt-1 text-xs leading-5' }, dailyDetail),
                                    ]),
                                    daily.attention_status === 'three_day_missing'
                                        ? h('span', { class: 'rounded-lg bg-white/70 px-2 py-1 text-[11px] font-semibold' }, '连续3天及以上，需人工确认')
                                        : null,
                                ]),
                                h('p', { class: 'mt-2 border-t border-current/15 pt-2 text-[11px] leading-5 opacity-90' }, daily.closure_note || '已提交不等于已闭环；仍以待复查队列为准。'),
                                h('p', { class: 'mt-1 text-[11px] leading-5 opacity-80' }, '状态来自当前门店、当前负责人名下的人工声明案例；不自动提醒、建任务、处罚或外发。'),
                            ]) : null,
                            this.error ? h('div', { class: 'rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700' }, this.error) : null,
                            this.managers.length === 0 && !this.loading ? h('div', { class: 'rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800' }, '当前酒店暂无可选用户，请先完成用户与酒店授权。') : null,
                            this.profile && !this.canViewEvidenceDetail ? h('div', {
                                class: 'rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm leading-6 text-sky-800',
                                'data-testid': 'manager-capability-privacy-summary',
                            }, '当前账号只显示该店长的汇总分、趋势和带教建议；案例原文、证据引用和操作入口已隐藏。') : null,
                            h('div', { class: 'grid grid-cols-2 gap-3 md:grid-cols-3', 'data-testid': 'manager-capability-dimensions' }, this.dimensions.map(dimension => h('div', {
                                key: dimension.key, class: 'rounded-xl border border-slate-200 bg-white p-3',
                            }, [
                                h('div', { class: 'flex items-start justify-between gap-2' }, [
                                    h('span', { class: 'text-xs font-semibold text-slate-600' }, dimension.label),
                                    h('span', { class: `text-lg font-semibold ${dimension.status === 'scored' ? 'text-[#315d50]' : 'text-slate-400'}` }, dimension.score == null ? '--' : String(dimension.score)),
                                ]),
                                h('div', { class: 'mt-2 text-[11px] text-slate-500' }, `${dimension.level_label || '数据不足'} · 案例 ${dimension.sample_count || 0}/${dimension.required_sample_count || 3}`),
                            ]))),
                            this.canManageEvidence ? h('section', {
                                class: 'rounded-xl border border-[#cbded6] bg-[#f6faf8] p-4',
                                'data-testid': 'manager-capability-followup-queue',
                            }, [
                                h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between' }, [
                                    h('div', [
                                        h('h4', { class: 'text-sm font-semibold text-slate-900' }, '待复查工作台'),
                                        h('p', { class: 'mt-1 text-xs text-slate-500' }, '覆盖全部有效案例，不受最近 10 条限制；只提供人工复查入口。'),
                                    ]),
                                    h('button', {
                                        type: 'button', disabled: this.queueLoading,
                                        class: 'text-xs font-medium text-[#315d50] disabled:opacity-50',
                                        onClick: () => this.loadFollowupQueue(),
                                    }, this.queueLoading ? '加载中' : '刷新队列'),
                                ]),
                                h('div', { class: 'mt-3 flex flex-wrap gap-2' }, queueFilters.map(([key, label, count]) => h('button', {
                                    key, type: 'button',
                                    class: ['rounded-full border px-3 py-1 text-xs font-medium transition', this.followupQueueFilter === key
                                        ? 'border-[#315d50] bg-[#315d50] text-white'
                                        : 'border-slate-200 bg-white text-slate-600 hover:border-[#315d50]'],
                                    onClick: () => { this.followupQueueFilter = key; },
                                }, `${label} ${Number(count || 0)}`))),
                                this.queueError ? h('div', { class: 'mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700' }, this.queueError) : null,
                                this.queueLoading && !this.followupQueue ? h('div', { class: 'mt-3 text-sm text-slate-500' }, '正在读取当前门店复查日期…') : null,
                                !this.queueLoading && this.followupQueue?.data_status === 'empty'
                                    ? h('div', { class: 'mt-3 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm text-emerald-700' }, `截至 ${this.followupQueue.business_date}，已确认当前范围没有逾期、今天或未来7天待复查案例。`)
                                    : null,
                                this.dueQueueRows.length ? h('div', { class: 'mt-3 space-y-2' }, this.dueQueueRows.map(item => h('div', {
                                    key: item.id, class: 'flex flex-col gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 sm:flex-row sm:items-center sm:justify-between',
                                    'data-testid': `manager-capability-due-row-${item.id}`,
                                }, [
                                    h('div', { class: 'min-w-0 text-xs text-slate-600' }, [
                                        h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                            h('b', { class: 'text-slate-900' }, `#${item.id} · ${item.current_followup_due_date}`),
                                            h('span', { class: ['rounded-full px-2 py-0.5 font-medium', item.due_bucket === 'overdue'
                                                ? 'bg-rose-50 text-rose-700' : (item.due_bucket === 'today' ? 'bg-amber-50 text-amber-700' : 'bg-sky-50 text-sky-700')] }, this.dueQueueText(item)),
                                        ]),
                                        h('p', { class: 'mt-1 line-clamp-2' }, item.problem_facts),
                                    ]),
                                    h('button', {
                                        type: 'button', class: 'shrink-0 rounded-lg bg-[#315d50] px-3 py-1.5 text-xs font-semibold text-white',
                                        'data-testid': `manager-capability-due-followup-${item.id}`,
                                        onClick: () => this.openFollowup(item),
                                    }, '立即复查'),
                                ]))) : null,
                            ]) : null,
                            this.profile ? h('div', { class: 'grid gap-3 lg:grid-cols-2', 'data-testid': 'manager-capability-development' }, [
                                h('section', { class: 'rounded-xl border border-slate-200 bg-white p-4' }, [
                                    h('div', { class: 'flex items-center justify-between gap-2' }, [
                                        h('h4', { class: 'text-sm font-semibold text-slate-900' }, '个人趋势'),
                                        h('span', { class: 'text-[11px] text-slate-500' }, this.profile.trend?.direction === 'improving' ? '改善中' : (this.profile.trend?.direction === 'needs_attention' ? '需关注' : '观察中')),
                                    ]),
                                    trendPoints.length ? h('div', { class: 'mt-3 space-y-2' }, trendPoints.map(point => h('div', { key: point.period }, [
                                        h('div', { class: 'flex justify-between text-[11px] text-slate-500' }, [h('span', point.period), h('span', point.average_score == null ? '证据不足' : `${point.average_score} · ${point.case_count}例`)]),
                                        h('div', { class: 'mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100' }, [h('div', {
                                            class: 'h-full rounded-full bg-[#315d50]', style: `width:${Math.max(0, Math.min(100, Number(point.average_score || 0)))}%`,
                                        })]),
                                    ]))) : h('p', { class: 'mt-3 text-xs text-slate-500' }, '暂无可形成个人趋势的案例。'),
                                    h('p', { class: 'mt-3 text-[11px] leading-5 text-slate-500' }, '月度观察均值仅看当前店长，不用于跨店或跨人员排名。'),
                                ]),
                                h('section', { class: 'rounded-xl border border-slate-200 bg-white p-4' }, [
                                    h('h4', { class: 'text-sm font-semibold text-slate-900' }, '带教建议与试点状态'),
                                    coaching.length ? h('div', { class: 'mt-3 space-y-2' }, coaching.map(item => h('div', { key: item.dimension_key, class: 'rounded-lg bg-slate-50 px-3 py-2' }, [
                                        h('b', { class: 'text-xs text-slate-800' }, item.dimension_label),
                                        h('p', { class: 'mt-1 text-xs leading-5 text-slate-600' }, item.suggestion),
                                    ]))) : null,
                                    h('div', { class: 'mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800', 'data-testid': 'manager-capability-pilot-readiness' }, [
                                        h('b', pilot.status === 'ready_for_review' ? '可开始人工阶段复盘' : (pilot.status === 'collecting' ? '试点采集中' : '试点尚未开始')),
                                        h('p', `有效案例 ${Number(pilot.case_count || 0)} · 覆盖 ${Number(pilot.active_days || 0)} 天 · 建议观察 14–28 天。`),
                                        h('p', '达到条件不代表现场效果已验证。'),
                                    ]),
                                ]),
                            ]) : null,
                            h('div', { class: 'rounded-xl border border-slate-200 bg-slate-50 p-4' }, [
                                h('div', { class: 'flex items-center justify-between gap-3' }, [h('h4', { class: 'text-sm font-semibold text-slate-800' }, '最近评分案例'), h('span', { class: 'text-xs text-slate-500' }, '按案例 ID 精确回读')]),
                                !this.canViewEvidenceDetail && this.profile ? h('p', { class: 'mt-3 text-sm text-slate-500' }, '当前为汇总视图，案例与证据明细不展示。') : null,
                                this.canViewEvidenceDetail && this.recentCases.length ? h('div', { class: 'mt-3 space-y-2' }, this.recentCases.map(item => h('div', {
                                    key: item.id, class: ['rounded-lg border bg-white px-3 py-2 text-xs text-slate-600', item.is_voided ? 'border-rose-200 opacity-75' : 'border-slate-200'],
                                    'data-testid': `manager-capability-case-${item.id}`,
                                }, [
                                    h('div', { class: 'flex flex-wrap items-start justify-between gap-2' }, [
                                        h('div', [h('b', { class: 'text-slate-800' }, `#${item.id} · ${item.business_date}`), ` · ${this.caseStatusText(item)} · ${this.caseScoreText(item)}`]),
                                        this.canManageEvidence ? h('div', { class: 'flex flex-wrap gap-1.5' }, item.is_voided ? [
                                            h('button', { type: 'button', class: 'rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px]', onClick: () => this.openAdjustment(item, 'restored') }, '恢复计分'),
                                        ] : [
                                            h('button', { type: 'button', class: 'rounded-md border border-[#315d50] bg-white px-2 py-1 text-[11px] font-medium text-[#315d50]', 'data-testid': `manager-capability-followup-open-${item.id}`, onClick: () => this.openFollowup(item) }, '追加复查'),
                                            h('button', { type: 'button', class: 'rounded-md border border-sky-200 bg-white px-2 py-1 text-[11px] text-sky-700', onClick: () => this.openScoreReview(item) }, '人工复核'),
                                            h('button', { type: 'button', class: 'rounded-md border border-amber-200 bg-white px-2 py-1 text-[11px] text-amber-700', onClick: () => this.openAdjustment(item, 'corrected') }, '纠错'),
                                            h('button', { type: 'button', class: 'rounded-md border border-rose-200 bg-white px-2 py-1 text-[11px] text-rose-700', onClick: () => this.openAdjustment(item, 'voided') }, '作废'),
                                        ]) : null,
                                    ]),
                                    h('div', { class: 'mt-1 line-clamp-2' }, item.problem_facts),
                                    h('div', { class: 'mt-1 flex flex-wrap gap-2 text-[11px]' }, [
                                        h('span', { class: ['rounded-full px-2 py-0.5', item.evidence?.confidence === 'high' ? 'bg-emerald-50 text-emerald-700' : (item.evidence?.confidence === 'medium' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600')] }, this.evidenceConfidenceText(item)),
                                        item.score_source === 'human_review' ? h('span', { class: 'rounded-full bg-sky-50 px-2 py-0.5 text-sky-700' }, '人工复核分') : null,
                                        item.latest_adjustment ? h('span', { class: 'rounded-full bg-amber-50 px-2 py-0.5 text-amber-700' }, `追加修正：${item.latest_adjustment.adjustment_type}`) : null,
                                    ]),
                                    h('div', { class: 'mt-1 text-[11px] text-slate-500' }, this.dimensionSummary(item)),
                                    h('div', { class: 'mt-1 text-[11px] leading-5 text-slate-500' }, (Array.isArray(item.score_snapshot?.dimensions) ? item.score_snapshot.dimensions : [])
                                        .slice(0, 2).flatMap(dimension => Array.isArray(dimension.reasons) ? dimension.reasons.slice(0, 1) : []).join('；')),
                                    item.latest_followup ? h('div', { class: 'mt-1 rounded-md bg-slate-50 px-2 py-1 text-[11px] text-slate-600' }, `最近复查：${item.latest_followup.followup_date} · 样本 ${item.latest_followup.sample_count} · ${item.latest_followup.verification_text}`) : null,
                                ]))) : (this.canViewEvidenceDetail ? h('div', { class: 'mt-3 text-sm text-slate-500' }, '暂无当前门店、当前负责人的评分案例。') : null),
                                this.selectedFollowupCase ? h('form', {
                                    class: 'mt-4 space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/40 p-4',
                                    'data-testid': 'manager-capability-followup-form',
                                    onSubmit: event => { event.preventDefault(); void this.submitFollowup(); },
                                }, [
                                    h('div', { class: 'flex items-start justify-between gap-3' }, [
                                        h('div', [
                                            h('h5', { class: 'text-sm font-semibold text-slate-900' }, `追加复查 · 案例 #${this.selectedFollowupCase.id}`),
                                            h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, '原始三问不会被覆盖；本次结果将作为新的有效评分快照。'),
                                        ]),
                                        h('button', { type: 'button', class: 'text-xs text-slate-500 hover:text-slate-800', onClick: () => this.closeFollowup() }, '取消'),
                                    ]),
                                    h('div', { class: 'grid gap-3 sm:grid-cols-3' }, [
                                        field('复查日期', h('input', { value: this.followupForm.followup_date, type: 'date', required: true, class: inputClass, onInput: event => { this.followupForm.followup_date = event.target.value; } })),
                                        field('复查结论', h('select', { value: this.followupForm.followup_outcome, required: true, class: inputClass, 'data-testid': 'manager-capability-followup-outcome', onChange: event => { this.followupForm.followup_outcome = event.target.value; } }, [
                                            h('option', { value: 'resolved' }, '已解决'),
                                            h('option', { value: 'still_open' }, '待继续'),
                                            h('option', { value: 'recurred' }, '再发生'),
                                        ])),
                                        field('核对样本数', h('input', { value: this.followupForm.sample_count, type: 'number', min: this.followupForm.followup_outcome === 'still_open' ? 0 : 1, max: 100000, required: true, class: inputClass, onInput: event => { this.followupForm.sample_count = event.target.value; } })),
                                    ]),
                                    field(this.followupForm.followup_outcome === 'still_open' ? '本次观察与继续计划' : '本次复查结果', followupTextArea('verification_text', '写清观察结果、核对方法和样本。', 8)),
                                    evidenceFields(this.followupForm, 'manager-capability-followup'),
                                    this.followupForm.followup_outcome !== 'resolved' ? field('下次复查日期', h('input', { value: this.followupForm.next_followup_date, type: 'date', required: true, class: inputClass, onInput: event => { this.followupForm.next_followup_date = event.target.value; } })) : null,
                                    this.followupForm.followup_outcome === 'recurred' ? h('div', { class: 'space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-3' }, [
                                        h('div', { class: 'text-xs font-semibold text-amber-900' }, '复发后生成关联新案例'),
                                        field('新的问题事实', followupTextArea('recurrence_problem_facts', '写清这次再次发生的时间、岗位、对象和事实。', 10)),
                                        field('新的处理动作', followupTextArea('recurrence_action_taken', '写清责任人、动作、标准和时间。', 8)),
                                        field('新的验证计划', followupTextArea('recurrence_verification_plan', '写清下次如何核对是否再次解决。', 8)),
                                    ]) : null,
                                    h('button', {
                                        type: 'submit', disabled: this.followupSaving,
                                        class: 'w-full rounded-xl bg-[#315d50] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#264a40] disabled:opacity-50',
                                    }, this.followupSaving ? '保存并回读中' : '保存本次复查'),
                                ]) : null,
                                this.selectedAdjustmentCase ? h('form', {
                                    class: 'mt-4 space-y-3 rounded-xl border border-amber-200 bg-amber-50/50 p-4',
                                    'data-testid': 'manager-capability-adjustment-form',
                                    onSubmit: event => { event.preventDefault(); void this.submitAdjustment(); },
                                }, [
                                    h('div', { class: 'flex items-start justify-between gap-3' }, [
                                        h('div', [h('h5', { class: 'text-sm font-semibold text-slate-900' }, `追加纠错/作废 · 案例 #${this.selectedAdjustmentCase.id}`), h('p', { class: 'mt-1 text-xs text-slate-500' }, '原始记录保持不变；作废案例从档案分和复查队列排除。')]),
                                        h('button', { type: 'button', class: 'text-xs text-slate-500', onClick: () => this.closeAdjustment() }, '取消'),
                                    ]),
                                    field('处理类型', h('select', { value: this.adjustmentForm.adjustment_type, class: inputClass, onChange: event => { this.adjustmentForm.adjustment_type = event.target.value; } }, [
                                        h('option', { value: 'corrected' }, '纠错并形成新有效投影'), h('option', { value: 'voided' }, '作废并排除计分'), h('option', { value: 'restored' }, '恢复计分'),
                                    ])),
                                    field('原因', h('input', { value: this.adjustmentForm.reason, type: 'text', minlength: 4, maxlength: 500, required: true, class: inputClass, placeholder: '写清为什么纠错、作废或恢复。', onInput: event => { this.adjustmentForm.reason = event.target.value; } })),
                                    this.adjustmentForm.adjustment_type === 'corrected' ? h('div', { class: 'space-y-3 rounded-xl border border-amber-200 bg-white p-3' }, [
                                        field('纠错后的案例日期', h('input', { value: this.adjustmentForm.business_date, type: 'date', required: true, class: inputClass, onInput: event => { this.adjustmentForm.business_date = event.target.value; } })),
                                        field('纠错后的问题事实', modelTextArea(this.adjustmentForm, 'problem_facts', '完整填写纠错后的问题事实。', 10)),
                                        field('纠错后的处理动作', modelTextArea(this.adjustmentForm, 'action_taken', '完整填写纠错后的处理动作。', 8)),
                                        field('第三问类型', h('select', { value: this.adjustmentForm.verification_status, class: inputClass, onChange: event => { this.adjustmentForm.verification_status = event.target.value; } }, [h('option', { value: 'observed_result' }, '已有观察结果'), h('option', { value: 'planned_verification' }, '尚待复查')])) ,
                                        field('纠错后的验证内容', modelTextArea(this.adjustmentForm, 'verification_text', '填写纠错后的结果或计划。', 8)),
                                        this.adjustmentForm.verification_status === 'planned_verification' ? field('计划复查日期', h('input', { value: this.adjustmentForm.followup_due_date, type: 'date', required: true, class: inputClass, onInput: event => { this.adjustmentForm.followup_due_date = event.target.value; } })) : null,
                                        evidenceFields(this.adjustmentForm, 'manager-capability-adjustment'),
                                    ]) : null,
                                    h('button', { type: 'submit', disabled: this.adjustmentSaving, class: 'w-full rounded-xl bg-amber-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50' }, this.adjustmentSaving ? '保存并回读中' : '追加修正记录'),
                                ]) : null,
                                this.selectedReviewCase ? h('form', {
                                    class: 'mt-4 space-y-3 rounded-xl border border-sky-200 bg-sky-50/50 p-4',
                                    'data-testid': 'manager-capability-score-review-form',
                                    onSubmit: event => { event.preventDefault(); void this.submitScoreReview(); },
                                }, [
                                    h('div', { class: 'flex items-start justify-between gap-3' }, [
                                        h('div', [h('h5', { class: 'text-sm font-semibold text-slate-900' }, `人工评分复核 · 案例 #${this.selectedReviewCase.id}`), h('p', { class: 'mt-1 text-xs text-slate-500' }, '必须绑定当前评分摘要并填写依据；不会修改原始评分。')]),
                                        h('button', { type: 'button', class: 'text-xs text-slate-500', onClick: () => this.closeScoreReview() }, '取消'),
                                    ]),
                                    field('复核结论', h('select', { value: this.reviewForm.review_outcome, class: inputClass, onChange: event => { this.reviewForm.review_outcome = event.target.value; } }, [h('option', { value: 'confirmed' }, '确认原评分'), h('option', { value: 'adjusted' }, '人工调整')])) ,
                                    field('复核依据', h('input', { value: this.reviewForm.reason, type: 'text', minlength: 4, maxlength: 500, required: true, class: inputClass, placeholder: '写清复核证据和调整原因。', onInput: event => { this.reviewForm.reason = event.target.value; } })),
                                    this.reviewForm.review_outcome === 'adjusted' ? h('div', { class: 'grid grid-cols-2 gap-3 md:grid-cols-3' }, managerCapabilityDefaultDimensions.map(([key, label]) => field(label, h('select', { value: this.reviewForm.dimension_overrides[key] || 'keep', class: inputClass, onChange: event => { this.reviewForm.dimension_overrides[key] = event.target.value; } }, [h('option', { value: 'keep' }, '保持原分'), h('option', { value: '90' }, '90 证据充分'), h('option', { value: '75' }, '75 基本成立'), h('option', { value: '50' }, '50 需补强'), h('option', { value: 'unknown' }, '未观察')])))) : null,
                                    h('button', { type: 'submit', disabled: this.reviewSaving, class: 'w-full rounded-xl bg-sky-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50' }, this.reviewSaving ? '保存并回读中' : '保存人工复核'),
                                ]) : null,
                            ]),
                        ]),
                        this.canManageEvidence ? h('div', { class: 'space-y-4' }, [h('form', {
                            class: 'space-y-3 rounded-2xl border border-[#d8e4df] bg-[#fbfdfc] p-4',
                            'data-testid': 'manager-capability-form',
                            onSubmit: event => { event.preventDefault(); void this.submit(); },
                        }, [
                            h('div', [h('h4', { class: 'font-semibold text-slate-900' }, this.isTodayForm ? '今日店长三问' : '补录三问案例'), h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, '按事实、动作、验证填写；90 证据充分、75 基本成立、50 需补强，无证据留空。')]),
                            field('案例日期', h('input', { value: this.form.business_date, type: 'date', required: true, class: 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm', onInput: event => { this.form.business_date = event.target.value; } })),
                            field('第一问：发现了什么问题事实？', textArea('problem_facts', '写清何时、何地、何人、何事。', 10)),
                            field('第二问：实际采取了什么动作？', textArea('action_taken', '写清责任人、动作、标准和时间。', 8)),
                            h('div', { class: 'grid gap-3 sm:grid-cols-2' }, [
                                field('第三问类型', h('select', { value: this.form.verification_status, required: true, class: 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm', onChange: event => { this.form.verification_status = event.target.value; } }, [h('option', { value: 'observed_result' }, '已有观察结果'), h('option', { value: 'planned_verification' }, '尚待复查')])),
                                this.form.verification_status === 'planned_verification' ? field('计划复查日期', h('input', { value: this.form.followup_due_date, type: 'date', required: true, class: 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm', onInput: event => { this.form.followup_due_date = event.target.value; } })) : null,
                            ]),
                            field(this.form.verification_status === 'observed_result' ? '第三问：结果如何证明？' : '第三问：准备怎么验证？', textArea('verification_text', this.form.verification_status === 'observed_result' ? '写可核对的结果。' : '写复查对象、日期和方法。', 8)),
                            evidenceFields(this.form, 'manager-capability-case'),
                            h('div', { class: 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs leading-5 text-slate-500' }, `证据置信度与能力分分开：较高 ${Number(confidence.high || 0)} · 一般 ${Number(confidence.medium || 0)} · 未核验 ${Number(confidence.unverified || 0)}。`),
                            h('button', {
                                type: 'submit', disabled: this.saving || this.loading || !this.selectedManagerId,
                                class: 'w-full rounded-xl bg-[#315d50] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#264a40] disabled:opacity-50',
                            }, this.saving ? '保存并回读中' : '保存三问并回读'),
                        ]), h('section', { class: 'rounded-2xl border border-slate-200 bg-white p-4' }, [
                            h('h4', { class: 'text-sm font-semibold text-slate-900' }, '评分解释'),
                            h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, '规则只做确定性证据分；人工复核必须留原因。无案例证据继续留空，不按 0 分。'),
                            h('div', { class: 'mt-3 space-y-2' }, managerCapabilityDefaultDimensions.map(([key, label]) => h('details', { key, class: 'rounded-lg border border-slate-100 px-3 py-2' }, [
                                h('summary', { class: 'cursor-pointer text-xs font-semibold text-slate-700' }, label),
                                h('ul', { class: 'mt-2 list-disc space-y-1 pl-4 text-[11px] leading-5 text-slate-500' }, (this.profile?.scoring_contract?.dimension_rubrics?.[key] || []).map(item => h('li', item))),
                            ]))),
                        ])]) : h('aside', { class: 'rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-800' }, [
                            h('h4', { class: 'font-semibold' }, '汇总查看权限'),
                            h('p', { class: 'mt-2' }, this.profile?.permissions?.policy || '当前账号可看汇总，但不能查看或修改案例证据。'),
                            h('p', { class: 'mt-2 text-xs' }, '如需新增、复查、纠错、作废或人工复核，请由具备当前门店运营执行权限的管理者操作。'),
                        ]),
                    ]),
            ]);
        },
    };

        return Object.freeze({ AiDecisionQualityDetails, OnlineTruthSummary, DualOtaAcceptanceReceipt, DualOtaPageVerificationPanel, resolveRevenueCockpitIntentLifecycle, parseOperationEvidenceNumber, parseOptionalOperationEvidenceNumber, operationEvidenceFirstText, operationEvidenceCleanObject, operationEvidenceLocalTimestamp, normalizeOperationEvidenceDateTime, normalizeOperationReviewStatus, RevenueCockpitOpportunityDetails, RevenueCockpitSnapshotStatus, RevenueCockpitActionRestoreStatus, onlineDataComponents, loadOnlineDataComponentScript, readOnlineDataComponent, requireOnlineDataComponent, systemComponents, CtripOrderAnalysisPanel, requireSystemComponent, operatingOpportunityLabScript, OperatingOpportunityLab, platformAutoPanelsScript, ctripProfileFieldConfigPanelScript, competitorDeviceManagementScript, dataConfigDialogsScript, automationCollectionContractScript, PlatformAutoSettingsPanels, PlatformAutoSecondaryPanels, CtripProfileFieldConfigPanel, CompetitorDeviceManagement, DataConfigDialogs, aiDailyReportTaskPositiveInteger, aiDailyReportModelIsLimited, normalizeAiDailyReportGenerationTask, formatAiDailyReportGenerationStage, resolveAiDailyReportGenerationOutcome, pollAiDailyReportGenerationTask, SessionProofNotice, LocalCollectorLoginHandoff, PmsRealtimeSyncResult, OperatingLoopAuthority, ManagerCapabilityPanel });
    };

    const exportedFactory = Object.freeze({ create });
    window.SUXI_APP_MAIN_COMPONENTS_FULL = exportedFactory;
    if (!window.SUXI_APP_MAIN_COMPONENTS) {
        window.SUXI_APP_MAIN_COMPONENTS = exportedFactory;
    }
})();
