(() => {
    'use strict';

    const create = ({ Vue, h }) => {
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

    const HotelThreeSourceOnboardingPanel = {
        name: 'HotelThreeSourceOnboardingPanel',
        render() {
            const ctx = this.$root || {};
                    const primaryButton = 'rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50';
                    const secondaryButton = 'rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:border-blue-200 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50';
                    const smallPrimaryButton = 'rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50';
                    const smallSecondaryButton = 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-blue-200 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50';
                    const inputClass = 'mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-normal focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100';
                    const renderInput = ({ label, value, onInput, placeholder = '', required = false, readonly = false, testid = '' }) => h('label', {
                        class: 'block text-xs font-medium text-slate-700',
                    }, [
                        required ? h('span', { class: 'text-red-500' }, '* ') : null,
                        label,
                        h('input', {
                            value,
                            type: 'text',
                            autocomplete: 'off',
                            placeholder,
                            readonly,
                            'data-testid': testid || undefined,
                            class: [inputClass, readonly ? 'bg-slate-50 text-slate-500' : ''],
                            onInput,
                        }),
                    ]);
                    const renderSteps = () => h('div', {
                        class: 'border-b border-slate-100 bg-white px-4 py-3 sm:px-6',
                        'data-testid': 'hotel-onboarding-steps',
                    }, [
                        h('div', { class: 'grid grid-cols-4 gap-2 text-center text-xs' }, [
                            ['hotel', '1 门店资料'],
                            ['authorization', '2 云端登录'],
                            ['verification', '3 身份回读'],
                            ['complete', '4 完成'],
                        ].map(([key, label]) => h('div', {
                            key,
                            class: [
                                'rounded-lg border px-2 py-2 font-semibold',
                                ctx.hotelOnboardingStep === key
                                    ? 'border-blue-300 bg-blue-50 text-blue-700'
                                    : 'border-slate-200 bg-slate-50 text-slate-500',
                            ],
                        }, label))),
                        ctx.hotelOnboardingHotelId
                            ? h('p', {
                                class: 'mt-2 text-xs text-slate-500',
                                'data-testid': 'hotel-onboarding-exact-id',
                            }, `当前精确门店 ID：${ctx.hotelOnboardingHotelId}`)
                            : null,
                    ]);
                    const renderHotelStep = () => {
                        const pmsSelected = ['dingdandao_pms', 'meituan_cloud_pms'].includes(ctx.hotelForm.pms_provider);
                        return h('section', {
                            class: 'space-y-4',
                            'data-testid': 'hotel-onboarding-hotel-step',
                        }, [
                            renderInput({
                                label: '门店名称',
                                value: ctx.hotelForm.name,
                                required: true,
                                placeholder: '请输入门店名称',
                                onInput: event => { ctx.hotelForm.name = event.target.value; },
                            }),
                            h('div', [
                                h('div', { class: 'mb-1.5 text-sm font-medium text-slate-700' }, '适用平台（可多选）'),
                                h('div', { class: 'grid grid-cols-2 gap-2 text-sm' }, ['ctrip', 'meituan'].map(platform => h('label', {
                                    key: platform,
                                    class: [
                                        'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 font-medium',
                                        ctx.hotelFormChannelSelected(platform)
                                            ? (platform === 'ctrip' ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-orange-200 bg-orange-50 text-orange-700')
                                            : 'border-slate-200 bg-slate-50 text-slate-600',
                                    ],
                                }, [
                                    h('input', {
                                        type: 'checkbox',
                                        checked: ctx.hotelFormChannelSelected(platform),
                                        class: 'h-4 w-4 rounded border-slate-300',
                                        onChange: () => ctx.toggleHotelFormChannel(platform),
                                    }),
                                    platform === 'ctrip' ? '携程' : '美团',
                                ]))),
                                h('p', { class: 'mt-1.5 text-xs text-slate-500' }, '这里只确定接入范围。创建、授权或保存身份不会自动采集，也不会向企业微信发送消息。'),
                            ]),
                            h(ctx.hotelBusinessProfileEditor),
                            h('div', {
                                class: 'rounded-xl border border-[#eadfc9] bg-[#fffdf8] p-3',
                                'data-testid': 'hotel-pms-configuration',
                            }, [
                                h('label', { class: 'block text-xs font-medium text-slate-700' }, [
                                    '经营系统（PMS）',
                                    h('select', {
                                        value: ctx.hotelForm.pms_provider,
                                        'data-testid': 'hotel-pms-provider',
                                        'aria-label': '当前使用的 PMS',
                                        disabled: ctx.hotelPmsBindingLoading || !!ctx.hotelPmsBindingError,
                                        class: inputClass,
                                        onChange: event => {
                                            ctx.hotelForm.pms_provider = event.target.value;
                                            ctx.handleHotelPmsProviderChange();
                                        },
                                    }, [
                                        h('option', { value: 'none' }, '暂不配置 PMS'),
                                        h('option', { value: 'dingdandao_pms' }, '订单来了 PMS'),
                                        h('option', { value: 'meituan_cloud_pms' }, '美团云 PMS'),
                                    ]),
                                ]),
                                pmsSelected ? h('div', {
                                    class: 'mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2',
                                    'data-testid': 'hotel-pms-public-identity',
                                }, [
                                    renderInput({
                                        label: '平台公开门店 ID',
                                        value: ctx.hotelForm.pms_provider_hotel_id,
                                        required: true,
                                        placeholder: '页面显示的公开 ID',
                                        onInput: event => { ctx.hotelForm.pms_provider_hotel_id = event.target.value; },
                                    }),
                                    renderInput({
                                        label: '平台公开门店名称',
                                        value: ctx.hotelForm.pms_provider_hotel_name,
                                        required: true,
                                        placeholder: '与 PMS 页面完全一致',
                                        onInput: event => { ctx.hotelForm.pms_provider_hotel_name = event.target.value; },
                                    }),
                                ]) : null,
                                pmsSelected ? h('p', { class: 'mt-2 text-xs text-slate-500' }, '这里只填写平台页面公开的门店身份；没有账号、密码、Cookie 或验证码输入。') : null,
                                ctx.hotelPmsBindingError ? h('p', { class: 'mt-2 text-xs text-red-700' }, ctx.hotelPmsBindingError) : null,
                            ]),
                            h('div', { class: 'flex justify-end gap-3 border-t border-slate-100 pt-5' }, [
                                h('button', {
                                    type: 'button',
                                    class: secondaryButton,
                                    onClick: () => { ctx.showHotelModal = false; },
                                }, '稍后完成'),
                                h('button', {
                                    type: 'button',
                                    class: primaryButton,
                                    disabled: ctx.hotelSaving || ctx.hotelPmsBindingLoading,
                                    'data-testid': 'hotel-onboarding-create',
                                    onClick: ctx.saveHotel,
                                }, ctx.hotelSaving ? '保存并回读中' : (ctx.hotelOnboardingHotelId ? '保存并继续' : '创建门店并继续')),
                            ]),
                        ]);
                    };
                    const renderSourceHeader = row => h('div', {
                        class: 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between',
                    }, [
                        h('div', { class: 'flex items-center gap-3' }, [
                            h('span', { class: 'flex h-9 w-9 items-center justify-center rounded-lg bg-white text-slate-700' }, [h('i', { class: row.icon })]),
                            h('div', [
                                h('div', { class: 'font-semibold text-slate-900' }, row.label),
                                h('div', { class: 'mt-0.5 text-xs text-slate-600' }, row.detail || '等待正式状态回读'),
                            ]),
                        ]),
                        h('span', {
                            class: ['self-start rounded-full border px-2.5 py-1 text-xs font-semibold sm:self-auto', ctx.hotelOnboardingStatusClass(row)],
                        }, ctx.hotelOnboardingStatusText(row)),
                    ]);
                    const renderAuthorizationStep = () => {
                        const rows = ctx.hotelOnboardingSourceRows;
                        const profilesReady = rows.length > 0 && rows.every(row => row.profileReady === true);
                        return h('section', {
                            class: 'space-y-4',
                            'data-testid': 'hotel-onboarding-authorization-step',
                        }, [
                            h('div', { class: 'rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900' }, [
                                h('div', { class: 'font-semibold' }, '逐一打开云端可视浏览器登录'),
                                h('p', { class: 'mt-1 text-xs leading-5 text-blue-800' }, '一次只处理一个来源。账号、密码和验证码只在平台自己的云端浏览器页面输入，宿析OS没有这些输入框。'),
                            ]),
                            rows.length === 0 ? h('div', { class: 'rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800' }, '尚未选择携程、美团或 PMS 来源，请返回补充。') : null,
                            ...rows.map(row => h('article', {
                                key: row.platform,
                                class: ['rounded-xl border p-4', row.accent],
                                'data-testid': `hotel-onboarding-source-${row.platform}`,
                            }, [
                                renderSourceHeader(row),
                                h('div', { class: 'mt-3 flex flex-wrap gap-2' }, [
                                    !ctx.hotelOnboardingLoginSessions[row.platform]
                                        ? h('button', {
                                            type: 'button',
                                            class: smallPrimaryButton,
                                            disabled: ctx.hotelOnboardingLoading || !!ctx.hotelOnboardingBusyPlatform,
                                            onClick: () => ctx.openHotelOnboardingCloudLogin(row),
                                        }, '打开云端登录')
                                        : h('button', {
                                            type: 'button',
                                            class: smallPrimaryButton,
                                            disabled: ctx.hotelOnboardingLoading,
                                            onClick: () => ctx.completeHotelOnboardingCloudLogin(row),
                                        }, '我已在云端页面完成登录'),
                                    h('button', {
                                        type: 'button',
                                        class: smallSecondaryButton,
                                        disabled: ctx.hotelOnboardingLoading || !!ctx.hotelOnboardingBusyPlatform,
                                        onClick: () => ctx.loadHotelThreeSourceOnboarding({ silent: false }),
                                    }, '刷新状态'),
                                ]),
                            ])),
                            h('p', { class: 'text-xs text-slate-500' }, '完成登录只更新授权状态；本向导不会自动开始采集，也不会发送企业微信消息。'),
                            h('div', { class: 'flex justify-between gap-3 border-t border-slate-100 pt-5' }, [
                                h('button', {
                                    type: 'button',
                                    class: secondaryButton,
                                    disabled: !!ctx.hotelOnboardingBusyPlatform,
                                    onClick: () => { ctx.hotelOnboardingStep = 'hotel'; },
                                }, '返回门店资料'),
                                h('button', {
                                    type: 'button',
                                    class: primaryButton,
                                    disabled: ctx.hotelOnboardingLoading || !!ctx.hotelOnboardingBusyPlatform || !profilesReady,
                                    onClick: ctx.goToHotelOnboardingVerification,
                                }, profilesReady ? '继续核对身份' : '全部登录就绪后继续'),
                            ]),
                        ]);
                    };
                    const renderVerificationStep = () => h('section', {
                        class: 'space-y-4',
                        'data-testid': 'hotel-onboarding-verification-step',
                    }, [
                        h('div', { class: 'rounded-xl border border-slate-200 bg-slate-50 px-4 py-3' }, [
                            h('div', { class: 'text-sm font-semibold text-slate-900' }, '核对平台公开门店身份'),
                            h('p', { class: 'mt-1 text-xs leading-5 text-slate-600' }, '公开门店 ID 和名称都必须填写，保存后立即按当前精确门店 ID 回读；缺失或不一致不会标记完成。'),
                        ]),
                        ...ctx.hotelOnboardingSourceRows.map(row => h('article', {
                            key: row.platform,
                            class: ['rounded-xl border p-4', row.accent],
                        }, [
                            renderSourceHeader(row),
                            h('div', { class: 'mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2' }, [
                                renderInput({
                                    label: '平台公开门店 ID',
                                    value: row.form.platform_hotel_id,
                                    required: true,
                                    placeholder: '页面显示的公开 ID',
                                    onInput: event => ctx.setHotelOnboardingBindingField(row.platform, 'platform_hotel_id', event.target.value),
                                }),
                                renderInput({
                                    label: '平台公开门店名称',
                                    value: row.form.platform_hotel_name,
                                    required: true,
                                    placeholder: '与平台页面完全一致',
                                    onInput: event => ctx.setHotelOnboardingBindingField(row.platform, 'platform_hotel_name', event.target.value),
                                }),
                            ]),
                            h('button', {
                                type: 'button',
                                class: ['mt-3', smallSecondaryButton],
                                disabled: ctx.hotelOnboardingLoading || !!ctx.hotelOnboardingBusyPlatform,
                                onClick: () => ctx.saveHotelOnboardingBinding(row),
                            }, '保存并回读此来源'),
                        ])),
                        ctx.hotelOnboardingError ? h('div', {
                            class: 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700',
                            'data-testid': 'hotel-onboarding-error',
                        }, ctx.hotelOnboardingError) : null,
                        h('p', { class: 'text-xs text-slate-500' }, '本页没有账号、密码、Cookie 或验证码字段；身份回读通过也不会自动采集或发送。'),
                        ctx.hotelOnboardingCollectionPlanStatus === 'active'
                            ? h('div', {
                                class: 'rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700',
                                'data-testid': 'hotel-onboarding-collection-active',
                            }, '三源采集计划已启用，并通过执行授权与计划状态回读；云端定时器运行状态需单独确认。')
                            : h('div', { class: 'rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-left' }, [
                                h('div', { class: 'text-sm font-semibold text-slate-900' }, '启用三源采集计划'),
                                h('p', { class: 'mt-1 text-xs leading-5 text-slate-600' }, ctx.hotelOnboardingCollectionPlanEligible
                                    ? '三源授权和门店身份已就绪。启用后会保存并精确回读计划；此操作本身不采集，也不发送企业微信。'
                                    : '请先让携程、美团和 PMS 的 Profile、公开门店 ID 与名称全部就绪。'),
                                h('button', {
                                    type: 'button',
                                    class: ['mt-3', primaryButton],
                                    disabled: !ctx.hotelOnboardingCollectionPlanEligible || ctx.hotelOnboardingCollectionPlanStatus === 'saving',
                                    'data-testid': 'hotel-onboarding-enable-collection',
                                    onClick: ctx.enableHotelOnboardingHourlyCollection,
                                }, ctx.hotelOnboardingCollectionPlanStatus === 'saving' ? '启用并回读中' : '启用三源采集计划'),
                                ctx.hotelOnboardingCollectionPlanError ? h('p', { class: 'mt-2 text-xs text-red-700' }, ctx.hotelOnboardingCollectionPlanError) : null,
                            ]),
                        h('div', { class: 'flex flex-wrap justify-between gap-3 border-t border-slate-100 pt-5' }, [
                            h('button', {
                                type: 'button',
                                class: secondaryButton,
                                onClick: () => { ctx.hotelOnboardingStep = 'authorization'; },
                            }, '返回云端登录'),
                            h('div', { class: 'flex gap-2' }, [
                                h('button', {
                                    type: 'button',
                                    class: secondaryButton,
                                    disabled: ctx.hotelOnboardingLoading,
                                    onClick: () => ctx.loadHotelThreeSourceOnboarding({ silent: false }),
                                }, '刷新回读'),
                                h('button', {
                                    type: 'button',
                                    class: primaryButton,
                                    disabled: ctx.hotelOnboardingLoading || !ctx.hotelOnboardingReady,
                                    onClick: ctx.finishHotelOnboarding,
                                }, '完成设置'),
                            ]),
                        ]),
                    ]);
                    const renderCompleteStep = () => h('section', {
                        class: 'space-y-4 text-center',
                        'data-testid': 'hotel-onboarding-complete-step',
                    }, [
                        h('div', { class: 'mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-700' }, [h('i', { class: 'fas fa-check text-xl' })]),
                        h('div', [
                            h('h4', { class: 'text-lg font-semibold text-slate-900' }, '已选来源接入状态已通过回读'),
                            h('p', { class: 'mt-2 text-sm text-slate-600' }, `门店 ID ${ctx.hotelOnboardingHotelId} 的已选来源均返回可用状态。`),
                        ]),
                        h('div', { class: 'rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-left text-xs leading-5 text-amber-800' }, '这一步没有启动采集，也没有发送任何企业微信消息。下一步可进入企业微信配置，选择发送范围与启用时间。'),
                        h('div', {
                            class: 'rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700',
                            'data-testid': 'hotel-onboarding-collection-active',
                        }, '三源采集计划已启用，并通过执行授权与计划状态回读；云端定时器运行状态需单独确认。'),
                        h('div', { class: 'flex justify-between gap-3 border-t border-slate-100 pt-5' }, [
                            h('button', { type: 'button', class: secondaryButton, onClick: () => { ctx.showHotelModal = false; } }, '关闭'),
                            h('button', {
                                type: 'button',
                                class: primaryButton,
                                'data-testid': 'hotel-onboarding-open-wechat',
                                onClick: ctx.openHotelOnboardingWechatConfig,
                            }, '进入企业微信配置'),
                        ]),
                    ]);
                    return h('div', { class: 'flex min-h-0 flex-1 flex-col overflow-hidden' }, [
                        renderSteps(),
                        h('div', { class: 'overflow-y-auto p-4 sm:p-6' }, [
                            ctx.hotelOnboardingStep === 'hotel' ? renderHotelStep() : null,
                            ctx.hotelOnboardingStep === 'authorization' ? renderAuthorizationStep() : null,
                            ctx.hotelOnboardingStep === 'verification' ? renderVerificationStep() : null,
                            ctx.hotelOnboardingStep === 'complete' ? renderCompleteStep() : null,
                            ctx.hotelOnboardingLoading ? h('p', { class: 'mt-4 text-xs text-slate-500' }, '正在按精确门店 ID 保存或回读...') : null,
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
                        h('span', `酒店：${scope.hotel_name || `ID ${scope.system_hotel_id || ctx.filterReportHotel || '未确认'}`}`),
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
                    answer('昨天动作有没有结果', result.status || 'pending', result.result_summary || '尚无同酒店、同平台、同指标口径的结果回读。'),
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

        return Object.freeze({ AiDecisionQualityDetails, OnlineTruthSummary, DualOtaAcceptanceReceipt, DualOtaPageVerificationPanel, onlineDataComponents, loadOnlineDataComponentScript, readOnlineDataComponent, requireOnlineDataComponent, systemComponents, CtripOrderAnalysisPanel, requireSystemComponent, platformAutoPanelsScript, ctripProfileFieldConfigPanelScript, competitorDeviceManagementScript, dataConfigDialogsScript, automationCollectionContractScript, PlatformAutoSettingsPanels, PlatformAutoSecondaryPanels, CtripProfileFieldConfigPanel, CompetitorDeviceManagement, DataConfigDialogs, aiDailyReportTaskPositiveInteger, aiDailyReportModelIsLimited, normalizeAiDailyReportGenerationTask, formatAiDailyReportGenerationStage, resolveAiDailyReportGenerationOutcome, pollAiDailyReportGenerationTask, SessionProofNotice, LocalCollectorLoginHandoff, PmsRealtimeSyncResult, HotelThreeSourceOnboardingPanel, OperatingLoopAuthority });
    };

    const exportedFactory = Object.freeze({ create });
    window.SUXI_APP_MAIN_COMPONENTS_FULL = exportedFactory;
    if (!window.SUXI_APP_MAIN_COMPONENTS) {
        window.SUXI_APP_MAIN_COMPONENTS = exportedFactory;
    }
})();
