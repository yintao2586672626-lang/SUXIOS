(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const amounts = { spend: '广告消耗', attributed_revenue: '平台归因收入（退款前）', refunds: '退款金额', commission: '退款后净佣金', fulfillment_cost: '退款后履约成本', other_cost: '其他同群成本', attributed_orders: '归因订单数', refunded_orders: '全额退款订单数' };
    const outcomes = { treated_before: '处理组前期间夜', treated_after: '处理组后期间夜', control_before: '对照组前期间夜', control_after: '对照组后期间夜', treated_before_exposure: '处理组前期可售间夜', treated_after_exposure: '处理组后期可售间夜', control_before_exposure: '对照组前期可售间夜', control_after_exposure: '对照组后期可售间夜', sample_size: '独立观测样本量', contribution_per_incremental_room_night: '每增量间夜净贡献（元，未扣本次广告/额外折扣）', discount_cost: '本次额外折扣成本（元）' };
    const changeNames = { holiday: '节假日', price: '价格', inventory: '库存', channel_mix: '渠道结构' };
    const blankInput = () => ({ as_of: '', plan: { name: '', hypothesis: '', primary_metric: 'room_nights_per_available_room_night', treatment: '', control: '', stopping_rule: '', design_quality: 'none', before_start: '', before_end: '', attribution_window_days: '' }, observation: { ...Object.fromEntries(Object.keys(outcomes).map(k => [k, ''])), pretrend_status: 'unknown', notes: '', source_ref: '' }, concurrent_changes: Object.fromEntries(Object.keys(changeNames).map(k => [k, { status: 'unknown', note: '' }])), records: [] });
    const blankRow = () => ({ campaign_id: '', period_start: '', period_end: '', snapshot_kind: 'daily_total', collected_at: '', source_ref: '', cost_source_ref: '', ...Object.fromEntries(Object.keys(amounts).map(k => [k, ''])) });
    const uuid = () => window.crypto.randomUUID();
    const clone = v => JSON.parse(JSON.stringify(v));
    const fmt = v => v == null ? '未知' : Number(v).toLocaleString('zh-CN', { maximumFractionDigits: 6 });

    components.PromotionExperimentPanel = {
        name: 'PromotionExperimentPanel',
        props: { hotelId: { type: [Number, String], default: '' }, request: { type: Function, default: null }, openFinance: { type: Function, default: null } },
        setup(props) {
            const { h, reactive, ref, watch, onBeforeUnmount } = window.Vue;
            const scope = reactive({ platform: 'ctrip', platform_store_id: '', period_start: '', period_end: '' });
            const input = reactive(blankInput());
            const row = reactive(blankRow());
            const state = reactive({ busy: false, error: '', notice: '', result: null, saved: null, history: [], truncated: false, key: uuid(), version: 0 });
            const fileRef = ref(null);
            let seq = 0, revision = 0, importSeq = 0, hydrating = false, pending = null;
            const currentScope = () => ({ system_hotel_id: Number(props.hotelId), ...scope });
            const scopeKey = () => JSON.stringify(currentScope());
            const invalidate = () => { if (hydrating) return; revision++; seq++; importSeq++; state.busy = false; state.result = null; state.saved = null; state.error = ''; state.notice = ''; pending = null; };
            const resetRecord = () => Object.assign(row, blankRow());
            watch(input, invalidate, { deep: true, flush: 'sync' });
            watch(scopeKey, () => {
                hydrating = true; Object.assign(input, blankInput()); resetRecord(); hydrating = false;
                invalidate(); state.history = []; state.truncated = false; state.key = uuid(); state.version = 0;
                state.notice = '范围已改变，计划与观察输入已清空；可读取当前范围历史版本。';
            }, { flush: 'sync' });
            onBeforeUnmount(() => { seq++; importSeq++; });
            const compatible = s => s && Object.entries(currentScope()).every(([k, v]) => String(s[k]) === String(v)) && Number(s.tenant_id) > 0;
            const validScope = () => {
                if (!Number.isSafeInteger(Number(props.hotelId)) || Number(props.hotelId) <= 0) throw new Error('请先选择单个酒店。');
                if (!scope.platform_store_id.trim() || !scope.period_start || !scope.period_end) throw new Error('请填写平台门店及观察日期。');
                if (!props.request) throw new Error('当前页面未接入实验接口，请重新打开功能入口。');
            };
            const call = async (kind, id) => {
                if (state.busy) return;
                let ticket;
                try {
                    validScope();
                    importSeq++;
                    ticket = ++seq; const capturedScope = scopeKey(), capturedRevision = revision;
                    const active = () => ticket === seq && capturedScope === scopeKey() && capturedRevision === revision;
                    state.busy = true; state.error = ''; state.notice = ''; state.result = null; state.saved = null;
                    const params = new URLSearchParams(currentScope());
                    let path = '/promotion-experiments/' + (kind === 'preview' ? 'preview' : 'versions');
                    let options = { businessContext: { hotelId: Number(props.hotelId) } };
                    if (kind === 'history' || kind === 'read') path += (kind === 'read' ? '/' + id : '') + '?' + params;
                    else {
                        const body = { scope: currentScope(), input: clone(input) };
                        if (kind === 'save') {
                            const identity = JSON.stringify([state.key, state.version, body]);
                            if (!pending || pending.identity !== identity) pending = { identity, key: uuid() };
                            Object.assign(body, { experiment_key: state.key, expected_version: state.version, idempotency_key: pending.key });
                        }
                        options = { ...options, method: 'POST', body: JSON.stringify(body) };
                    }
                    const response = await props.request(path, options);
                    if (!active()) return;
                    if (response?.code !== 200 || !response.data) throw new Error(response?.message || '请求失败，请重试；未确认保存成功。');
                    const data = response.data;
                    if (!compatible(data.scope)) throw new Error('返回范围与当前酒店、门店、渠道或日期不同，结果已拒绝。');
                    if (kind === 'history') {
                        if (!Array.isArray(data.items)) throw new Error('历史版本响应格式无效，请重试。');
                        state.history = data.items; state.truncated = data.truncated; state.notice = data.items.length ? '已读取当前范围历史版本。' : '当前范围尚无实验版本。';
                    }
                    else if (kind === 'preview') {
                        if (data.schema_version !== 'promotion_experiment.v1' || !compatible(data.accounting?.scope)) throw new Error('计算版本或明细范围不一致，结果已拒绝。');
                        state.result = data; state.notice = '已计算，尚未保存。人工证据仍为未验证。';
                    }
                    else {
                        if (!compatible(data.result?.scope) || !compatible(data.result?.accounting?.scope) || !compatible(data.input?.scope) || data.result?.schema_version !== 'promotion_experiment.v1' || data.readback_status !== 'exact') throw new Error('版本回读未通过一致性检查。');
                        hydrating = true;
                        const restored = clone(data.input); delete restored.scope;
                        Object.assign(input, blankInput(), restored); resetRecord();
                        hydrating = false;
                        state.key = data.experiment_key; state.version = data.version_no; state.result = data.result; state.saved = data;
                        pending = null; state.notice = `版本 ${data.version_no} 已${kind === 'save' ? '保存并' : ''}精确回读；来源质量未升级。`;
                    }
                } catch (error) { if (ticket == null || ticket === seq) { state.error = error.message; state.result = null; state.saved = null; } }
                finally { if (ticket === seq) state.busy = false; }
            };
            const addRow = () => {
                try {
                    validScope();
                    const candidate = { ...currentScope(), ...clone(row), attribution_window_days: input.plan.attribution_window_days, currency: 'CNY', amount_unit: 'yuan', attribution_model: 'single_touch', source_method: 'manual_input', source_quality: 'manual_unverified' };
                    for (const key of Object.keys(amounts)) {
                        if (candidate[key] === '') candidate[key] = null;
                        else if (!/^\d+(?:\.\d{1,2})?$/.test(String(candidate[key]))) throw new Error('金额与订单需为非负数；未知留空，合法零填写0。');
                    }
                    if (!candidate.campaign_id.trim() || !candidate.source_ref.trim() || !candidate.collected_at) throw new Error('请填写活动、来源凭据和含时区的采集时间。');
                    if (input.records.length >= 1000) throw new Error('最多1000条记录。');
                    input.records.push(candidate); resetRecord(); state.notice = '记录已加入待保存列表；后端会核对快照、日期、退款和成本范围。';
                } catch (e) { state.error = e.message; }
            };
            const importRows = async event => {
                const file = event.target.files?.[0]; if (!file) return;
                const ticket = ++importSeq, capturedScope = scopeKey(), capturedRevision = revision;
                const active = () => ticket === importSeq && scopeKey() === capturedScope && capturedRevision === revision;
                try {
                    validScope(); if (file.size > 1000000) throw new Error('文件最多1MB。');
                    const text = await file.text();
                    if (!active()) return;
                    const data = JSON.parse(text);
                    if (!Array.isArray(data) || data.length + input.records.length > 1000) throw new Error('导入需为最多1000条推广明细的JSON数组。');
                    for (const r of data) {
                        if (!r || Array.isArray(r) || typeof r !== 'object' || !Object.entries(currentScope()).every(([k, v]) => ['period_start', 'period_end'].includes(k) || String(r[k]) === String(v))) throw new Error('导入明细门店或平台范围不一致。');
                    }
                    // Finish this file selection before the input mutation invalidates its generation.
                    event.target.value = '';
                    input.records.push(...data); state.notice = '明细已加入待保存列表；重复导入会按快照去重，人工导入不升级事实质量。';
                } catch (e) { if (active()) state.error = e.message; }
                finally { if (active()) event.target.value = ''; }
            };
            const download = () => {
                if (!state.saved) return;
                const url = URL.createObjectURL(new Blob([JSON.stringify(state.saved, null, 2)], { type: 'application/json' }));
                const a = document.createElement('a'); a.href = url; a.download = `推广实验-版本${state.version}.json`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
            };
            const field = (obj, key, label, type = 'text') => h('label', { class: 'cc-field' }, [label, h('input', { type, value: obj[key] ?? '', 'data-testid': 'pe-' + key, onInput: e => { obj[key] = e.target.value; }, ...(type === 'number' ? { min: 0, step: 'any', placeholder: '未知留空' } : {}) })]);
            const select = (obj, key, label, values) => h('label', { class: 'cc-field' }, [label, h('select', { value: obj[key], 'data-testid': 'pe-' + key, onChange: e => { obj[key] = e.target.value; } }, values.map(([v, text]) => h('option', { value: v }, text)))]);
            const button = (text, action, test, disabled = false) => h('button', { type: 'button', class: 'cc-button', disabled: state.busy || disabled, onClick: action, 'data-testid': test }, text);
            const details = (title, children) => h('details', [h('summary', title), h('div', { class: 'cc-gap' }, children)]);
            return () => h('section', { class: 'cc-gap', 'data-testid': 'promotion-experiment-panel' }, [
                h('hr', { class: 'cc-divider' }), h('h3', '三、推广账面回报与增量实验'),
                h('p', { class: 'cc-note' }, '此处保存实际观察和实验计划。金额均为人民币元；手工录入/导入保留未验证状态。平台归因、账面余额、真实增量分别展示。'),
                h('p', { class: 'cc-note' }, `当前系统酒店：${props.hotelId || '未选择'} · 编辑版本：${state.version || '新计划'}；保存不启动投放。`),
                h('div', { class: 'cc-fields cc-gap' }, [select(scope, 'platform', '平台', [['ctrip', '携程'], ['meituan', '美团']]), field(scope, 'platform_store_id', '平台门店ID'), field(scope, 'period_start', '投放观察开始', 'date'), field(scope, 'period_end', '投放观察结束', 'date')]),
                h('div', { class: 'cc-fields cc-gap' }, [field(input.plan, 'name', '实验名称'), field(input.plan, 'hypothesis', '待验证假设'), field(input.plan, 'before_start', '实验前期开始', 'date'), field(input.plan, 'before_end', '实验前期结束', 'date'), field(input.plan, 'treatment', '处理组及投放安排'), field(input.plan, 'control', '对照组及分组依据（无对照请明确写无）'), select(input.plan, 'design_quality', '实验设计', [['none', '无可验证对照'], ['randomized', '随机分组'], ['validated_matched', '已核对的匹配组']]), field(input.plan, 'stopping_rule', '事先约定的停止规则'), field(input.plan, 'attribution_window_days', '归因窗口天数（结束后等待）', 'number'), field(input, 'as_of', '本次观察截至日', 'date')]),
                h('p', { class: 'cc-note' }, '主指标：每可售间夜的成交间夜。前后组必须同店同平台、同一订单/入住口径；不能把总收入涨幅填作广告归因收入。'),
                details(`推广明细（待核对 ${input.records.length} 条）`, [
                    h('div', { class: 'cc-fields' }, [field(row, 'campaign_id', '活动ID'), select(row, 'snapshot_kind', '记录粒度', [['daily_total', '单日总量'], ['cumulative_snapshot', '累计期间快照']]), field(row, 'period_start', '明细开始日', 'date'), field(row, 'period_end', '明细结束日', 'date'), field(row, 'collected_at', '采集时间，含时区，例如 2026-09-08T10:00:00+08:00'), field(row, 'source_ref', '平台报表/导出凭据编号'), field(row, 'cost_source_ref', '财务/结算成本凭据编号（同归因订单群）'), ...Object.entries(amounts).map(([key, label]) => field(row, key, label + (key.includes('orders') ? '（单）' : '（元）'), 'number'))]),
                    h('p', { class: 'cc-note' }, '仅接受同平台单触点、活动互斥的归因报表。归因窗口使用上方计划天数，需与报表一致。退款为同订单群退款；净佣金/成本不重复扣退款。请从“净收与恢复”核对成本凭据，不能将全店月度成本直接填入。未知成本留空，明确没有成本可填0并附依据。'),
                    props.openFinance ? button('打开净收与恢复（请先保存实验）', props.openFinance, 'pe-open-finance') : null,
                    h('div', { class: 'cc-actions' }, [button('加入明细', addRow, 'pe-add-row'), button('导入推广明细JSON', () => fileRef.value?.click(), 'pe-import'), h('input', { ref: fileRef, type: 'file', accept: '.json', hidden: true, onChange: importRows, 'data-testid': 'pe-import-file' })]),
                    ...input.records.map((r, i) => h('p', { class: 'cc-note', key: i }, [String(r.campaign_id) + ' · ' + r.period_start + '—' + r.period_end + ' · 消耗 ' + fmt(r.spend), button('移除此待存明细', () => input.records.splice(i, 1), 'pe-remove-' + i)])),
                ]),
                details('观察记录与可售间夜（可先留空保存计划）', [h('div', { class: 'cc-fields' }, [...Object.entries(outcomes).map(([k, label]) => field(input.observation, k, label, 'number')), select(input.observation, 'pretrend_status', '投放前趋势', [['unknown', '未检查'], ['passed', '通过检查'], ['parallel', '趋势平行'], ['failed', '未通过']]), field(input.observation, 'source_ref', '分组/观察证据编号'), field(input.observation, 'collected_at', '分组观察采集时间（含时区）'), field(input.observation, 'notes', '观察说明')])]),
                details('同期变化检查', Object.entries(changeNames).map(([key, label]) => h('div', { class: 'cc-fields cc-gap' }, [select(input.concurrent_changes[key], 'status', label, [['unknown', '未检查'], ['unchanged', '核对无变化'], ['changed', '同时变化，未排除'], ['controlled', '对照设计已控制']]), field(input.concurrent_changes[key], 'note', label + '检查依据')]))),
                h('div', { class: 'cc-actions' }, [button('计算当前观察', () => call('preview'), 'pe-preview'), button('保存新版本并回读', () => call('save'), 'pe-save'), button('读取当前范围版本', () => call('history'), 'pe-history'), button('另建实验', () => { invalidate(); state.key = uuid(); state.version = 0; state.notice = '已切换为新实验，当前输入保留供修改。'; }, 'pe-new'), button('下载当前已保存版本', download, 'pe-download', !state.saved)]),
                state.busy ? h('p', { role: 'status' }, '正在处理当前范围…') : null,
                state.error ? h('p', { role: 'alert', class: 'cc-alert error', 'data-testid': 'pe-error' }, state.error) : null,
                state.notice ? h('p', { class: 'cc-status', 'data-testid': 'pe-notice' }, state.notice) : null,
                ...state.history.map(v => h('p', { class: 'cc-note', key: v.id }, [v.name + ' · v' + v.version_no + ' · ' + v.created_at, button('回读该版本', () => call('read', v.id), 'pe-read-' + v.id)])),
                state.truncated ? h('p', { class: 'cc-note' }, '仅列出最近50个版本；已知版本ID可通过专属接口精确读取。') : null,
                state.result ? h('div', { class: 'cc-result cc-gap', 'data-testid': 'pe-result' }, [
                    h('p', `${state.result.accounting.coverage.amount_label} · ${state.result.accounting.source_quality === 'verified' ? '来源已验证' : '来源未验证/缺失'} · 窗口${state.result.accounting.maturity.status === 'mature' ? '已成熟' : '未成熟/缺成熟快照'}（${state.result.accounting.maturity.mature_on}）`),
                    h('div', { class: 'cc-stats' }, [['消耗（元）', state.result.accounting.totals.spend], ['退款后平台归因收入（元）', state.result.accounting.platform_attribution.net_revenue], ['净归因ROAS（倍）', state.result.accounting.platform_attribution.net_roas], ['归因账面余额（元）', state.result.accounting.book_return.balance_after_known_scope_costs], ['增量间夜', state.result.incrementality.room_nights], ['增量净贡献（元）', state.result.incrementality.net_contribution_after_ads]].map(([label, value]) => h('div', { class: 'cc-stat' }, [h('span', label), h('strong', fmt(value))]))),
                    h('p', { class: 'cc-alert cc-gap', 'data-testid': 'pe-incrementality' }, state.result.incrementality.statement),
                    details(`查看当前证据缺口（${(state.result.incrementality.evidence_gaps || []).length}项）`, [h('ul', (state.result.incrementality.evidence_gaps || []).map(gap => h('li', gap.message)))]),
                    details('展开计算、来源、缺口与下一次实验', [h('p', { class: 'cc-note' }, `快照接收 ${state.result.accounting.deduplication.received} 条，采用 ${state.result.accounting.deduplication.selected} 条；缺失日期 ${state.result.accounting.coverage.missing_dates.join('、') || '无'}。`),
                        ...Object.entries(state.result.accounting.coverage.missing_campaign_dates || {}).map(([campaign, dates]) => h('p', { class: 'cc-note' }, `活动 ${campaign} 缺失日期：${dates.join('、')}`)),
                        ...state.result.accounting.calculation.map(c => h('p', { class: 'cc-note' }, `${c.label}：${c.formula} = ${fmt(c.value)}`)),
                        h('p', { class: 'cc-note' }, '缺失字段：' + (state.result.accounting.missing_fields.map(k => amounts[k] || k).join('、') || '无')),
                        h('ul', state.result.next_experiment.map(x => h('li', x))),
                        h('textarea', { readonly: true, value: JSON.stringify(state.result, null, 2), 'aria-label': '当前实验完整计算与证据', 'data-testid': 'pe-result-json' }),
                    ]),
                ]) : null,
            ]);
        },
    };
})();
