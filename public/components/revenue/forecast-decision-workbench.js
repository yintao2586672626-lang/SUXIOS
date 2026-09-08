(() => {
    'use strict';
    const endpoint = '/revenue-ai/forecast-workbench';
    // Independent state machine lets slow responses fail closed after any input/scope edit.
    function createController(request) {
        const state = { busy: false, error: '', result: null, savedId: '', inputRestricted: false, history: [], historyError: '', historyStatus: 'idle' };
        let epoch = 0;
        let historyEpoch = 0;
        const invalidate = (clearHistory = false) => {
            epoch++; state.busy = false; state.result = null; state.savedId = ''; state.inputRestricted = false; state.error = '';
            if (clearHistory) { historyEpoch++; state.history = []; state.historyError = ''; state.historyStatus = 'idle'; }
        };
        const scopeMatches = (actual, expected) => actual && ['hotel_id', 'platform', 'platform_store_id', 'room_scope'].every(k => String(actual[k]) === String(expected[k]));
        async function action(kind, payload, id = '') {
            if (state.busy) return null;
            const ticket = ++epoch;
            state.busy = true; state.error = ''; state.result = null; state.savedId = ''; state.inputRestricted = false;
            try {
                const query = new URLSearchParams(Object.fromEntries(['hotel_id', 'platform', 'platform_store_id', 'room_scope'].map(k => [k, payload[k]])));
                const res = await request(kind === 'read' ? `${endpoint}/plans/${encodeURIComponent(id)}?${query}` : `${endpoint}/${kind === 'save' ? 'plans' : 'preview'}`,
                    kind === 'read' ? { method: 'GET', businessContext: { hotelId: payload.hotel_id } } : { method: 'POST', body: JSON.stringify(payload), businessContext: { hotelId: payload.hotel_id } });
                if (ticket !== epoch) return null;
                if (res?.code !== 200) throw new Error(res?.message || '预测请求失败。');
                const doc = kind === 'preview' ? null : res.data;
                const result = doc ? doc.payload?.result : res.data;
                if (!scopeMatches(result?.replay?.scope, payload) || (doc && (doc.readback_verified !== true || !scopeMatches(doc.payload?.scope, payload)))) throw new Error('返回范围或精确回读标记不一致。');
                if (doc && (typeof doc.id !== 'string' || !/^[a-f0-9]{64}$/.test(doc.id) || (kind === 'read' && doc.id !== id))) throw new Error('返回方案编号与请求不一致，未确认精确回读。');
                const inputRestricted = Boolean(doc && res.redacted === true);
                if (doc && !inputRestricted && (!doc.payload?.input?.evidence || typeof doc.payload.input.evidence !== 'object' || Array.isArray(doc.payload.input.evidence))) throw new Error('方案原始输入缺失，未恢复为可编辑方案。');
                state.result = result; state.savedId = doc?.id || ''; state.inputRestricted = inputRestricted;
                return doc || result;
            } catch (e) { if (ticket === epoch) state.error = e.message || '请求失败，请重试。'; return null; }
            finally { if (ticket === epoch) state.busy = false; }
        }
        async function history(scope) {
            const ticket = ++historyEpoch;
            state.historyError = ''; state.history = []; state.historyStatus = 'loading';
            try {
                const res = await request(`${endpoint}/plans?${new URLSearchParams(scope)}`, { method: 'GET', businessContext: { hotelId: scope.hotel_id } });
                if (ticket !== historyEpoch) return;
                if (res?.code !== 200 || !scopeMatches(res.data?.scope, scope) || !Array.isArray(res.data?.items)) throw new Error(res?.message || '历史范围或响应错误。');
                state.history = res.data.items;
                state.historyStatus = state.history.length ? 'ready' : 'empty';
            } catch (e) { if (ticket === historyEpoch) { state.historyError = e.message; state.historyStatus = 'error'; } }
        }
        return { state, invalidate, action, history };
    }
    function syntheticEvidence(scope) {
        const observations = [];
        for (let day = 0; day < 243; day++) {
            const date = new Date(Date.UTC(2026, 0, 1 + day));
            const available = new Date(Date.UTC(2026, 0, 2 + day));
            observations.push({ ...scope, business_date: date.toISOString().slice(0, 10), available_at: `${available.toISOString().slice(0, 10)}T06:00:00+08:00`,
                value: 20 + (day % 7) * 2, quality_status: 'ready', source_ref: `synthetic-day-${day}` });
        }
        return { schema_version: 'temporal_replay.v1', source_kind: 'synthetic', metric_definition: 'net_stay_room_nights_excluding_cancelled', unit: 'room_nights', date_basis: 'stay_date',
            as_of_at: '2026-09-01T08:00:00+08:00', evaluation_at: '2026-09-01T08:00:00+08:00', backtest_start: '2026-03-01', backtest_end: '2026-08-31', observations };
    }
    window.SUXI_FORECAST_WORKBENCH = Object.freeze({ createController, syntheticEvidence });
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    registry.ForecastDecisionWorkbench = {
        name: 'ForecastDecisionWorkbench',
        props: { request: { type: Function, required: true }, hotels: { type: Array, default: () => [] }, hotelId: { type: [String, Number], default: '' } },
        setup(props) {
            const { h, reactive, ref, watch, onBeforeUnmount } = window.Vue;
            const form = reactive({ hotel_id: props.hotelId || '', platform: 'ctrip', platform_store_id: '', room_scope: '', horizon_days: 7, current_price: '', proposed_price: '', elasticity: '', inventory_room_nights: '' });
            const evidence = ref('');
            const controller = createController((...args) => props.request(...args));
            onBeforeUnmount(() => controller.invalidate(true));
            const revision = ref(0);
            const redraw = () => revision.value++;
            const scope = () => ({ hotel_id: Number(form.hotel_id), platform: form.platform, platform_store_id: form.platform_store_id.trim(), room_scope: form.room_scope.trim() });
            const inputKey = () => JSON.stringify([form, evidence.value]);
            watch(inputKey, () => { controller.invalidate(); redraw(); }, { flush: 'sync' });
            watch(() => JSON.stringify(scope()), () => { controller.invalidate(true); redraw(); }, { flush: 'sync' });
            watch(() => props.hotelId, value => { form.hotel_id = value || ''; });
            const field = (label, key, type = 'text') => h('label', { class: 'fw-field' }, [label, h('input', { type, value: form[key], 'data-testid': `forecast-${key}`, onInput: e => { form[key] = e.target.value; } })]);
            const payload = () => {
                const value = { ...scope(), evidence: JSON.parse(evidence.value) };
                const keys = ['current_price', 'proposed_price', 'elasticity', 'inventory_room_nights'];
                if (keys.some(k => form[k] !== '')) {
                    if (keys.some(k => String(form[k]).trim() === '')) throw new Error('价格情景四项均须填写，留空不按零计算。');
                    value.scenario = Object.fromEntries(keys.map(k => [k, Number(form[k])]));
                    Object.assign(value.scenario, { horizon_days: Number(form.horizon_days), price_unit: 'CNY_per_room_night', inventory_scope: value.room_scope });
                }
                return value;
            };
            async function run(kind, id) {
                let p;
                try { p = kind === 'read' ? scope() : payload(); }
                catch (e) { controller.invalidate(); controller.state.error = e.message; redraw(); return; }
                const pending = controller.action(kind, p, id); redraw();
                const result = await pending;
                if (result?.payload && kind === 'read') {
                    const documentResult = result.payload.result;
                    const inputRestricted = controller.state.inputRestricted;
                    evidence.value = inputRestricted ? '' : JSON.stringify(result.payload.input.evidence, null, 2);
                    const scenario = inputRestricted ? null : result.payload.input.scenario;
                    for (const key of ['current_price', 'proposed_price', 'elasticity', 'inventory_room_nights']) form[key] = scenario?.[key] ?? '';
                    form.horizon_days = scenario?.horizon_days ?? 7;
                    controller.state.result = documentResult; controller.state.savedId = result.id; controller.state.inputRestricted = inputRestricted;
                }
                redraw();
                if (kind === 'save' && result) await loadHistory();
            }
            async function sample() {
                controller.invalidate(); redraw();
                const before = inputKey();
                try {
                    if (!form.hotel_id || !form.platform_store_id || !form.room_scope) throw new Error('先选择酒店，并声明门店和房型范围。');
                    const res = await props.request(`${endpoint}/context?${new URLSearchParams(scope())}`, { method: 'GET', businessContext: { hotelId: Number(form.hotel_id) } });
                    if (before !== inputKey()) return;
                    if (res?.code !== 200) throw new Error(res?.message || '酒店范围读取失败。');
                    evidence.value = JSON.stringify(syntheticEvidence(res.data.scope), null, 2);
                } catch (e) { if (before === inputKey()) controller.state.error = e.message; }
                redraw();
            }
            const display = value => value === null || value === undefined ? '缺失' : Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 });
            const labels = { insufficient_samples: '样本不足', better_on_this_sample: '本样本优于两基线', not_better_than_baseline: '未优于基线' };
            const button = (label, fn, testId, disabled = false) => h('button', { type: 'button', disabled: controller.state.busy || disabled, onClick: fn, 'data-testid': testId }, label);
            async function loadHistory() { const pending = controller.history(scope()); redraw(); await pending; redraw(); }
            return () => {
                void revision.value;
                const current = controller.state;
                const replay = current.result?.replay;
                const scenario = current.result?.scenario;
                return h('section', { class: 'fw-panel', 'data-testid': 'forecast-decision-workbench' }, [
                    h('style', '.fw-panel{min-width:0;overflow-wrap:anywhere}.fw-panel *{box-sizing:border-box}'),
                    h('style', '.fw-panel{padding:20px;border:1px solid #dce4de;border-radius:14px;background:#fff;color:#20372b}.fw-panel h3{font-size:20px;margin-bottom:8px}.fw-panel p{line-height:1.8;font-size:13px;margin:8px 0}.fw-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:14px 0}.fw-field{display:flex;flex-direction:column;font-size:13px;gap:6px}.fw-panel input,.fw-panel select,.fw-panel textarea{border:1px solid #aabbb0;border-radius:6px;padding:8px;min-width:0;width:100%;color:#20372b;background:white}.fw-panel textarea{min-height:180px;font:12px/1.6 monospace}.fw-actions{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.fw-panel button{background:#265540;color:white;border-radius:7px;padding:8px 12px;cursor:pointer}.fw-panel button:disabled{opacity:.45;cursor:wait}.fw-panel table{width:100%;border-collapse:collapse;min-width:690px;font-size:13px}.fw-panel td,.fw-panel th{padding:10px;text-align:left;border-bottom:1px solid #dce4de}.fw-panel pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px;max-height:360px;overflow:auto}.fw-panel [role=alert]{color:#a42e2e}.fw-panel summary{cursor:pointer;margin:12px 0}.fw-panel .fw-note{background:#f0f5ee;padding:10px;border-radius:8px}'),
                    h('h3', '时点回测与定价情景'),
                    h('p', { class: 'fw-note' }, '输入脱敏证据，冻结预测时点可见版本，比较未来7/14/30天净入住间夜。人工导入待核验，示例仅synthetic；不自动改价。'),
                    h('div', { class: 'fw-grid' }, [h('label', { class: 'fw-field' }, ['酒店', h('select', { value: form.hotel_id, 'data-testid': 'forecast-hotel', onChange: e => { form.hotel_id = e.target.value; } }, [h('option', { value: '' }, '选择酒店'), ...props.hotels.map(item => h('option', { value: item.id }, item.name || String(item.id)))])]),
                        h('label', { class: 'fw-field' }, ['渠道', h('select', { value: form.platform, onChange: e => { form.platform = e.target.value; } }, [h('option', { value: 'ctrip' }, '携程'), h('option', { value: 'meituan' }, '美团')])]), field('平台门店标识（导入声明待核）', 'platform_store_id'), field('房型/库存范围', 'room_scope')]),
                    h('div', { class: 'fw-actions' }, [button('载入 synthetic 验收示例', sample, 'forecast-sample'), button(current.historyStatus === 'loading' ? '正在读取历史…' : '读取历史方案', loadHistory, 'forecast-history', current.historyStatus === 'loading')]),
                    h('label', { class: 'fw-field' }, ['证据 JSON（含带时区 available_at、入住日、净间夜、质量状态；synthetic 来源用 synthetic- 编号，manual_unverified 用 manual- 编号，仅接受字母数字/下划线/连字符，禁止URL及凭证；证据范围编号不得含首尾空白）', h('textarea', { value: evidence.value, 'data-testid': 'forecast-evidence', onInput: e => { evidence.value = e.target.value; } })]),
                    h('label', { class: 'fw-field' }, ['导入证据 JSON 文件', h('input', { type: 'file', accept: '.json,application/json', onChange: async e => { const file = e.target.files?.[0]; if (!file) return; if (file.size > 2000000) { controller.state.error = '文件超过2MB'; redraw(); return; } const before = inputKey(); const value = await file.text(); if (before === inputKey()) evidence.value = value; } })]),
                    h('p', '情景可选：全部留空只回测。填写后四项均必填；弹性是人工假设，库存为同渠道同房型全周期可用间夜。'),
                    h('div', { class: 'fw-grid' }, [h('label', { class: 'fw-field' }, ['情景周期', h('select', { value: form.horizon_days, onChange: e => { form.horizon_days = Number(e.target.value); } }, [7, 14, 30].map(n => h('option', { value: n }, `${n}天`)))]), field('当前房价（元/间夜）', 'current_price', 'number'), field('方案房价（元/间夜）', 'proposed_price', 'number'), field('价格弹性假设（-5至0）', 'elasticity', 'number'), field('全周期渠道库存（间夜）', 'inventory_room_nights', 'number')]),
                    h('div', { class: 'fw-actions' }, [button(current.busy ? '处理中…' : '运行回测和情景', () => run('preview'), 'forecast-run', !evidence.value.trim()), button('保存并精确回读', () => run('save'), 'forecast-save', !evidence.value.trim())]),
                    current.error ? h('p', { role: 'alert' }, current.error) : null,
                    current.savedId ? h('p', { 'data-testid': 'forecast-saved' }, current.inputRestricted ? `服务器已校验方案，当前仅显示摘要：${current.savedId}。原始输入受权限限制；历史回读后需重新导入证据再计算/保存。` : `方案已精确回读：${current.savedId}。编辑输入后须重新计算/保存。`) : null,
                    replay ? h('div', { 'data-testid': 'forecast-result' }, [h('p', `来源：${replay.source_kind} · 状态：${replay.data_status} · 预测时点：${replay.as_of_at} · 实际评价时点：${replay.evaluation_at}`),
                        h('p', `证据版本 ${replay.input_evidence.version_count}；可见训练样本 ${replay.input_evidence.training_sample_count}；排除预测时点后版本 ${replay.input_evidence.excluded_after_as_of_count}。单位：间夜，取消已排除。`),
                        h('p', { 'data-testid': 'forecast-training-quality' }, replay.input_evidence.training_quality ? `56天训练窗口：有效 ${display(replay.input_evidence.training_quality.ready)} 天；声明缺失 ${display(replay.input_evidence.training_quality.missing)} 天；采集失败 ${display(replay.input_evidence.training_quality.failed)} 天；无可见版本 ${display(replay.input_evidence.training_quality.unobserved)} 天。` : '旧方案未记录训练质量计数；需重新计算后核验。'),
                        h('div', { 'data-testid': 'forecast-backtest-quality' }, [7, 14, 30].map(n => {
                            const c = replay.comparisons[n]; const q = c.actual_quality;
                            return h('p', q ? `${n}天回测目标：有效 ${display(q.ready)}，声明缺失 ${display(q.missing)}，采集失败 ${display(q.failed)}，无可见版本 ${display(q.unobserved)}；训练含缺失/失败的时间折 ${display(c.unavailable_training_fold_count)}。缺口存在时不支持完整优劣比较，误差仅为已配对样本统计。` : `${n}天旧方案未记录回测质量细分；需重新计算后核验。`);
                        })),
                        h('div', { style: 'overflow-x:auto' }, [h('table', [h('thead', [h('tr', ['周期', '预测间夜/状态', '配对样本/完整折', '模型MAE', '周基线MAE', '均值基线MAE', '区间覆盖%', '判断'].map(s => h('th', s)))]), h('tbody', [7, 14, 30].map(n => { const c = replay.comparisons[n]; const f = replay.forecasts[n]; return h('tr', [h('td', `${n}天`), h('td', `${display(f.total_predicted_room_nights)} / ${f.status}`), h('td', `${c.sample_count}/${c.complete_fold_count}（缺${c.unpaired_point_count}点）`), h('td', display(c.metrics.model.mae)), h('td', display(c.metrics.weekly.mae)), h('td', display(c.metrics.mean7.mae)), h('td', display(c.interval_coverage_percent)), h('td', labels[c.assessment.status] || c.assessment.status)]); }))])]),
                        h('p', replay.interval_semantics), ...replay.applicability.map(s => h('p', s)),
                        h('details', [h('summary', '逐日预测、时间折、来源与全部误差（RMSE/WAPE/偏差）'), h('pre', JSON.stringify(replay, null, 2))])]) : null,
                    scenario ? h('div', { 'data-testid': 'forecast-scenario' }, [h('h4', '价格假设对照（不代表因果增收）'), scenario.status === 'blocked' ? h('p', '预测缺失，情景被阻塞。') : h('p', `基准金额 ${display(scenario.base_amount_cny)} 元 → 假设方案金额 ${display(scenario.proposed_amount_cny)} 元；假设差额 ${display(scenario.hypothetical_difference_cny)} 元。`), ...(scenario.limitations || []).map(s => h('p', s))]) : null,
                    current.historyError ? h('p', { role: 'alert' }, current.historyError) : null,
                    ['idle', 'loading', 'empty'].includes(current.historyStatus) ? h('p', { role: 'status', 'data-testid': 'forecast-history-status' }, { idle: '尚未读取当前范围的历史方案。', loading: '正在读取当前范围的历史方案…', empty: '已读取：当前范围暂无已保存方案。' }[current.historyStatus]) : null,
                    h('details', { open: current.history.length > 0 }, [h('summary', '历史方案（当前酒店/渠道/门店/房型；最近50条）'), ...current.history.map(item => h('p', [button(`方案 ${item.id.slice(0, 12)} · 保存 ${item.created_at || '时间未知'} · 预测 ${item.as_of_at || '时点未知'} · ${item.source_kind || ''} · ${item.status}`, () => run('read', item.id), 'forecast-read')]))]),
                ]);
            };
        },
    };
})();
