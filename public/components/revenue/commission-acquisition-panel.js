(() => {
    'use strict';
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const defaults = () => ({ price: '300', nights: '1000', oldRate: '10', newRate: '15', costEnabled: false, cost: '', forecast: '', budgetMode: 'commission_gap', budget: '', historicalRoi: '', incrementalityPercent: '100', platform: '', period: '', historicalSource: '' });
    const schema = 'suxi.commission-acquisition-scenario.v1';
    const styles = `
      .sx-commission{color:#24332c;background:var(--sx-dashboard-surface,#fff);border:1px solid #d9ded6;border-radius:16px;text-align:left;overflow:hidden;font-family:"Microsoft YaHei","PingFang SC","Segoe UI",sans-serif}
      .sx-commission *{box-sizing:border-box}.sx-commission h3,.sx-commission h4,.sx-commission p{margin:0}.sx-commission button,.sx-commission input,.sx-commission select,.sx-commission textarea{font:inherit}
      .sx-commission .cc-head{padding:22px 24px;background:var(--sx-luxury-green,#143a31);color:#f8fafc}.sx-commission .cc-head h3{font-size:20px;font-weight:700}.sx-commission .cc-head p{font-size:13px;line-height:1.7;margin-top:7px;color:#e0e8df}.sx-commission .cc-badge{display:inline-block;border:1px solid #9a9271;color:var(--sx-luxury-gold,#dcc591);border-radius:5px;padding:3px 8px;margin-top:12px;font-size:12px}
      .sx-commission .cc-body{padding:22px 24px}.sx-commission .cc-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:22px}.sx-commission .cc-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.sx-commission .cc-field{min-width:0}.sx-commission label{display:block;font-size:13px;font-weight:600;line-height:1.7}.sx-commission .cc-field input,.sx-commission .cc-field select,.sx-commission textarea{display:block;width:100%;min-width:0;margin-top:6px;border:1px solid #cbd2c7;border-radius:8px;padding:10px 11px;background:#fff;color:#25392d;outline-offset:3px}.sx-commission .cc-field input:focus,.sx-commission .cc-field select:focus{outline:2px solid #a88a52}.sx-commission .cc-note{font-size:12px;line-height:1.7;color:#627065;margin-top:9px}.sx-commission .cc-gap{margin-top:20px}.sx-commission .cc-toggle{display:flex;align-items:center;gap:8px;font-size:13px}.sx-commission .cc-toggle input{width:17px;height:17px;accent-color:#275944}.sx-commission .cc-alert{padding:13px 15px;border:1px solid #ddc694;border-radius:9px;background:#fff8e8;color:#735a27;font-size:13px;line-height:1.7}.sx-commission .cc-alert.error{border-color:#deb4a8;background:#fff1ec;color:#943d32}.sx-commission .cc-result{background:#f2f5ed;border:1px solid #dce2d3;border-radius:12px;padding:20px}.sx-commission .cc-result h4{font-size:14px;font-weight:600}.sx-commission .cc-result strong.cc-number{display:block;font-size:45px;line-height:1.25;letter-spacing:-.03em;color:#143a31;font-variant-numeric:tabular-nums;margin:8px 0}.sx-commission .cc-stats{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:15px}.sx-commission .cc-stat{border-top:1px solid #d8dfcf;padding-top:12px;min-width:0}.sx-commission .cc-stat span{display:block;font-size:12px;line-height:1.7;color:#58685c}.sx-commission .cc-stat strong{display:block;font-size:20px;line-height:1.5;overflow-wrap:anywhere;color:#233e2e;font-variant-numeric:tabular-nums}.sx-commission .cc-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}.sx-commission .cc-button{border:1px solid #b9c5b4;border-radius:8px;background:#fff;color:#294934;padding:9px 13px;font-size:13px;cursor:pointer}.sx-commission .cc-button.primary{background:#294d3b;color:white;border-color:#294d3b}.sx-commission .cc-button:disabled{opacity:.45;cursor:not-allowed}.sx-commission .cc-divider{margin:24px 0;border:0;border-top:1px solid #dce2d7}.sx-commission .cc-paid-head{margin-bottom:17px}.sx-commission .cc-paid-head h4{font-size:17px;font-weight:700}.sx-commission table{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}.sx-commission th,.sx-commission td{text-align:right;padding:10px 7px;border-bottom:1px solid #e0e5da}.sx-commission th{font-weight:500;color:#637164;font-size:12px}.sx-commission th:first-child,.sx-commission td:first-child{text-align:left}.sx-commission .cc-forecast{margin-top:15px;font-size:13px;line-height:1.7}.sx-commission .cc-negative{color:#943d32}.sx-commission .cc-positive{color:#275944}.sx-commission details{margin-top:16px}.sx-commission summary{font-size:13px;font-weight:600;cursor:pointer}.sx-commission textarea{font-size:12px;line-height:1.8;min-height:220px;resize:vertical}.sx-commission ul{padding-left:19px;font-size:12px;line-height:1.9;color:#627065}.sx-commission .cc-context{display:flex;justify-content:space-between;gap:12px;align-items:baseline;margin-bottom:18px;font-size:13px}.sx-commission .cc-subhead{font-size:15px;font-weight:650;margin-bottom:14px}.sx-commission .cc-status{margin-top:12px;font-size:12px;color:#416a4f;line-height:1.7}
      @media(max-width:900px){.sx-commission .cc-grid{grid-template-columns:1fr}.sx-commission .cc-head,.sx-commission .cc-body{padding:18px}.sx-commission .cc-context{display:block}.sx-commission .cc-context>span{display:block}.sx-commission .cc-number{font-size:39px!important}}
      @media(max-width:420px){.sx-commission .cc-head,.sx-commission .cc-body{padding:14px}.sx-commission .cc-fields{gap:10px}.sx-commission .cc-stat strong{font-size:18px}.sx-commission th,.sx-commission td{font-size:12px;padding:9px 4px}}
    `;
    registry.CommissionAcquisitionCalculatorPanel = {
        name: 'CommissionAcquisitionCalculatorPanel',
        props: { hotelId: { type: [String, Number], default: '' }, hotels: { type: Array, default: () => [] }, request: { type: Function, default: null }, openFinance: { type: Function, default: null } },
        setup(props) {
            const { h, reactive, computed, ref, watch } = window.Vue;
            const core = window.SUXI_COMMISSION_CALCULATOR_CORE;
            const paidCore = window.SUXI_COMMISSION_PAID_TRAFFIC_CORE;
            const form = reactive(defaults());
            const notice = ref('');
            const importInput = ref(null);
            const summaryOpen = ref(false);
            const hotel = computed(() => props.hotels.find(item => String(item?.id) === String(props.hotelId)) || null);
            const hotelLabel = computed(() => hotel.value?.name || '通用人工情景（未绑定门店）');
            const basis = computed(() => {
                try { return { result: core.calculateCommission(form), error: '' }; }
                catch (error) { return { result: null, error: error.message }; }
            });
            const traffic = computed(() => {
                if (!basis.value.result) return { result: null, error: '', missing: false };
                if (!String(form.historicalRoi).trim()) return { result: null, error: '', missing: true };
                try { return { result: paidCore.calculatePaidTraffic(basis.value.result, form), error: '', missing: false }; }
                catch (error) { return { result: null, error: error.message, missing: false }; }
            });
            const money = value => '¥' + Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const number = value => Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 0 });
            const decimal = value => Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const unitMoney = value => {
                const cents = ((value < 0n ? -value : value) + 500n) / 1000n;
                return (value < 0n ? '−' : '') + '¥' + (cents / 100n).toLocaleString('zh-CN') + '.' + String(cents % 100n).padStart(2, '0');
            };
            const threshold = result => result.direction > 0 ? '至少增长' : result.direction < 0 ? '最多下降' : '保持不变';
            const metric = result => result.costEnabled ? '净贡献' : '扣佣收入';
            const platformLabel = () => ({ ctrip: '携程', meituan: '美团', other: '其他渠道', '': '未指定渠道' }[form.platform] || '未指定渠道');
            const forecast = computed(() => {
                if (!basis.value.result) return { text: '', negative: false };
                try {
                    const value = core.calculateForecast(basis.value.result, form.forecast);
                    if (!value) return { text: '', negative: false };
                    const difference = value.differenceUnits;
                    const delta = difference < 0n ? '少 ' + unitMoney(-difference) : difference > 0n ? '多 ' + unitMoney(difference) : '持平';
                    return { text: `预计 ${number(value.nights)} 间夜，总${metric(basis.value.result)} ${unitMoney(value.totalUnits)}，比原来${delta}。`, negative: difference < 0n };
                } catch (error) { return { text: error.message, negative: true }; }
            });
            const summary = computed(() => {
                const result = basis.value.result;
                if (!result) return '';
                const lines = ['宿析OS · 佣金与付费流量测算', '性质：人工输入／情景推算，未验证经营事实', `门店：${hotelLabel.value}；渠道：${platformLabel()}；周期：${form.period || '未填写'}`, `平均房价：${money(result.price)}；原间夜：${number(result.nights)}；成本：${result.costEnabled ? money(result.cost) + '/间夜' : '未填写，不按实际为零认定'}`, `佣金：${result.oldRate}% → ${result.newRate}%；${metric(result)}持平门槛：${threshold(result)} ${Math.abs(result.percent).toFixed(2)}%`, `至少保留 ${number(result.minimumNights)} 间夜；调整前总额 ${unitMoney(result.oldTotalUnits)}，最低间夜对应 ${unitMoney(result.minimumTotalUnits)}`, forecast.value.text];
                const paid = traffic.value.result;
                if (paid) lines.push(`付费流量对照：预算 ${money(paid.budget)}；历史投产比 ${paid.historicalRoi} 倍（归因营收/广告费）`, `历史依据：${form.historicalSource || '未填写；人工假设，尚未验证'}；真实增量占比假设 ${paid.incrementalityPercent.toFixed(2)}%`, `归因营收估计 ${money(paid.attributedRevenue)}；对应间夜 ${decimal(paid.attributedNights)}；真实增量间夜估计 ${decimal(paid.incrementalNights)}`, `按较低佣金 ${paid.paidCommissionRate}% 投放，${paid.costIncluded ? '扣广告后增量净贡献' : '扣佣扣广告后增量余额（未计履约成本）'} ${money(paid.amountAfterAds)}`, `保本投产比：${paid.breakEvenRoi === null ? '无有效增量，不能靠提高投产比保本' : decimal(paid.breakEvenRoi) + ' 倍'}`);
                else lines.push(traffic.value.error || '历史投产比未填写，尚未推算付费流量产出。');
                lines.push('边界：不预测实际流量；不自动调整佣金或投放。等额广告使用较低佣金，是对照方案，不与涨佣叠加；广告增量额相对较低佣金且未额外投放的情景，不含原间夜调佣后的贡献差额。历史ROI不保证未来，归因不等于增量；未计库存上限、替代渠道损失、固定成本。');
                return lines.filter(Boolean).join('\n');
            });
            const reset = () => { Object.assign(form, defaults()); notice.value = '已重置为示例假设；历史投产比未填写。'; };
            watch(() => String(props.hotelId), (current, previous) => { if (current !== previous) { reset(); notice.value = '门店已变更，测算输入已重置为示例，避免沿用其他门店的假设。'; } });
            const save = () => {
                if (!basis.value.result || traffic.value.error) return;
                const payload = { schema, source_method: 'user_scenario_input', fact_status: 'unverified', metric_scope: 'channel_acquisition_scenario', hotel_id: hotel.value?.id ?? null, hotel_name: hotel.value?.name ?? null, input: { ...form } };
                const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' }));
                const link = document.createElement('a'); link.href = url; link.download = '宿析-佣金与付费流量测算.json'; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
                notice.value = '已发起测算方案下载，可重新导入回显；没有写入酒店经营数据库。';
            };
            const restore = async event => {
                const file = event?.target?.files?.[0];
                if (!file) return;
                try {
                    if (file.size > 32768) throw new Error('测算方案文件过大，请选择本面板导出的JSON文件。');
                    const payload = JSON.parse(await file.text());
                    if (payload?.schema !== schema || !payload.input || payload.fact_status !== 'unverified') throw new Error('不是受支持的人工测算方案文件。');
                    if (payload.hotel_id != null && String(payload.hotel_id) !== String(hotel.value?.id ?? '')) throw new Error('方案门店与当前门店不同，请先选择方案对应门店；未导入任何字段。');
                    const candidate = {};
                    for (const key of Object.keys(defaults())) {
                        const value = payload.input[key];
                        if (key === 'costEnabled') { if (typeof value !== 'boolean') throw new Error('成本开关格式无效。'); }
                        else if (typeof value !== 'string' || value.length > 160) throw new Error('方案输入字段格式无效。');
                        candidate[key] = value;
                    }
                    if (!['commission_gap', 'manual'].includes(candidate.budgetMode) || !['', 'ctrip', 'meituan', 'other'].includes(candidate.platform)) throw new Error('方案渠道或预算模式无效。');
                    const result = core.calculateCommission(candidate);
                    core.calculateForecast(result, candidate.forecast);
                    if (candidate.historicalRoi.trim()) paidCore.calculatePaidTraffic(result, candidate);
                    Object.assign(form, candidate);
                    notice.value = '已从测算方案恢复输入并重新计算；仍为人工情景，不是验证后的经营事实。';
                } catch (error) { notice.value = '导入失败：' + error.message; }
                event.target.value = '';
            };
            const copy = async () => {
                try { await navigator.clipboard.writeText(summary.value); notice.value = '已复制测算摘要，包含口径与未验证提示。'; }
                catch { summaryOpen.value = true; notice.value = '浏览器未允许自动复制，请在下方摘要中全选复制。'; }
            };
            const input = (key, label, settings = {}) => h('div', { class: 'cc-field' }, [h('label', { for: 'commission-' + key }, label), h('input', { id: 'commission-' + key, 'data-testid': 'commission-field-' + key, type: 'number', inputmode: 'decimal', value: form[key], ...settings, onInput: event => { form[key] = event.target.value; notice.value = ''; } })]);
            const select = (key, label, options) => h('div', { class: 'cc-field' }, [h('label', { for: 'commission-' + key }, label), h('select', { id: 'commission-' + key, 'data-testid': 'commission-field-' + key, value: form[key], onChange: event => { form[key] = event.target.value; notice.value = ''; } }, options.map(([value, text]) => h('option', { value }, text)))]);
            const stat = (label, value, testid) => h('div', { class: 'cc-stat' }, [h('span', label), h('strong', { 'data-testid': testid }, value)]);
            const alert = (text, error = false) => h('div', { class: 'cc-alert' + (error ? ' error' : ''), role: error ? 'alert' : 'note' }, text);
            return () => {
                const result = basis.value.result;
                const paid = traffic.value.result;
                const gap = result ? Number(result.oldTotalUnits - BigInt(result.nights) * BigInt(result.newUnits)) / 100000 : null;
                const rows = result ? [...new Set([10, 11, 12, 13, 14, 15, result.oldRate, result.newRate])].sort((a, b) => a - b).map(rate => {
                    try { const value = core.calculateCommission({ ...form, newRate: String(rate) }); return [rate + '%', money(value.newMargin), threshold(value) + ' ' + Math.abs(value.percent).toFixed(2) + '%']; }
                    catch { return [rate + '%', '净贡献不为正', '无法计算门槛']; }
                }) : [];
                return h('section', { class: 'sx-commission', 'data-testid': 'commission-acquisition-panel', 'data-source-method': 'user_scenario_input', 'data-fact-status': 'unverified' }, [
                    h('style', styles),
                    h('div', { class: 'cc-head' }, [h('h3', '佣金调整与付费流量测算'), h('p', '多付的佣金，能否换成更有效的获客投入？先算间夜持平门槛，再用历史投产比估算等额广告产出。'), h('span', { class: 'cc-badge' }, '人工输入 · 情景推算 · 不自动调佣或投放')]),
                    h('div', { class: 'cc-body' }, [
                        h('div', { class: 'cc-context' }, [h('strong', hotelLabel.value), h('span', { class: 'cc-note' }, '不读取平台投放数据；历史ROI与周期需自行核对。')]),
                        h('div', { class: 'cc-fields' }, [select('platform', '测算渠道', [['', '未指定渠道'], ['ctrip', '携程'], ['meituan', '美团'], ['other', '其他渠道']]), input('period', '测算周期 / 房型（选填）', { type: 'text', inputmode: 'text', maxlength: 160, placeholder: '例如：9月工作日 · 标准房' })]),
                        h('hr', { class: 'cc-divider' }),
                        h('div', { class: 'cc-grid' }, [
                            h('div', [h('h4', { class: 'cc-subhead' }, '一、佣金变化，需要多少间夜持平？'), h('div', { class: 'cc-fields' }, [input('price', '平均房价（元/间夜）', { min: 0.01, max: 1000000, step: 0.01 }), input('nights', '调整前已入住间夜', { min: 1, max: 1000000, step: 1, inputmode: 'numeric' }), input('oldRate', '调整前佣金（%）', { min: 10, max: 15, step: 0.1 }), input('newRate', '调整后佣金（%）', { min: 10, max: 15, step: 0.1 })]), h('p', { class: 'cc-note' }, '预填房价和间夜仅作示例，佣金范围10%—15%。1间房住2晚＝2间夜。'), h('div', { class: 'cc-actions' }, [h('button', { type: 'button', class: 'cc-button', 'data-testid': 'commission-swap', onClick: () => { [form.oldRate, form.newRate] = [form.newRate, form.oldRate]; } }, '交换前后佣金'), h('button', { type: 'button', class: 'cc-button', onClick: reset }, '重置示例')]), h('div', { class: 'cc-gap' }, [h('label', { class: 'cc-toggle' }, [h('input', { type: 'checkbox', 'data-testid': 'commission-cost-enabled', checked: form.costEnabled, onChange: event => { form.costEnabled = event.target.checked; } }), '我知道每间夜变动成本']), form.costEnabled ? input('cost', '单间变动成本（元/间夜）', { min: 0, max: 1000000, step: 0.01, placeholder: '填写你店的成本' }) : null, h('p', { class: 'cc-note' }, '不知道成本时只比较扣佣收入，不认定实际成本为零。')]), h('div', { class: 'cc-gap' }, input('forecast', '预计调整后间夜（选填）', { min: 0, max: 1000000, step: 1, inputmode: 'numeric', placeholder: '同一比较期' }))]),
                            result ? h('div', [h('div', { class: 'cc-result' }, [h('h4', `间夜量${threshold(result)} · ${metric(result)}持平线`), h('strong', { class: 'cc-number', 'data-testid': 'commission-threshold' }, Math.abs(result.percent).toFixed(2) + '%'), h('p', { class: 'cc-note' }, '百分比为显示舍入，最低间夜数按完整精度向上取整。'), h('div', { class: 'cc-stats' }, [stat('原来间夜', number(result.nights)), stat('调整后至少保留', number(result.minimumNights), 'commission-minimum-nights'), stat('调整前每间夜' + metric(result), money(result.oldMargin)), stat('调整后每间夜' + metric(result), money(result.newMargin))]), h('p', { class: 'cc-note' }, '调整前总额 ' + unitMoney(result.oldTotalUnits) + '；最低间夜对应总额 ' + unitMoney(result.minimumTotalUnits)), forecast.value.text ? h('p', { class: 'cc-forecast ' + (forecast.value.negative ? 'cc-negative' : 'cc-positive'), 'data-testid': 'commission-forecast-result' }, forecast.value.text) : null]), h('div', { class: 'cc-gap' }, alert(result.costEnabled ? '已扣填写的变动成本，但未扣固定成本；渠道贡献持平不等于酒店整体盈利。' : '未计履约成本，这不是实际保本线，也不能扩大为全酒店利润。'))]) : alert(basis.value.error, true),
                        ]),
                        h('hr', { class: 'cc-divider' }),
                        h('div', { class: 'cc-paid-head' }, [h('h4', '二、把这笔支出换成付费流量，会产出多少？'), h('p', { class: 'cc-note' }, '广告对照按两者中较低的佣金计算，不与涨佣方案叠加。历史ROI在这里特指“广告归因营收 ÷ 广告费”，是倍数，不是利润率。')]),
                        h('div', { class: 'cc-grid' }, [
                            h('div', [h('div', { class: 'cc-fields' }, [select('budgetMode', '广告预算来源', [['commission_gap', '按佣金差额换算'], ['manual', '手动填写获客支出']]), form.budgetMode === 'manual' ? input('budget', '付费流量预算（元）', { min: 0, step: 0.01, placeholder: '本周期计划投入' }) : h('div', { class: 'cc-field' }, [h('label', '等额预算（原间夜量不变）'), h('p', { 'data-testid': 'commission-equivalent-budget', style: { fontSize: '22px', marginTop: '9px' } }, gap === null ? '待完善佣金参数' : money(Math.abs(gap)))]), input('historicalRoi', '历史投产比（倍）', { min: 0, max: 1000, step: 0.0001, placeholder: '例如3：每1元广告费归因3元营收' }), input('incrementalityPercent', '真实增量占比假设（%）', { min: 0, max: 100, step: 0.01 })]), h('p', { class: 'cc-note' }, '默认100%只是待校准假设。归因订单里若有自然订单、会员或其他渠道转移，应调低增量占比。'), h('div', { class: 'cc-gap' }, input('historicalSource', '历史ROI来源与期间（选填）', { type: 'text', inputmode: 'text', maxlength: 160, placeholder: '同一酒店、同一渠道和归因窗口' }))]),
                            paid ? h('div', { class: 'cc-result', 'data-testid': 'commission-paid-result' }, [h('h4', `付费流量情景 · 按 ${paid.paidCommissionRate}% 佣金`), h('div', { class: 'cc-stats' }, [stat('广告预算', money(paid.budget), 'commission-paid-budget'), stat('归因营收估计', money(paid.attributedRevenue), 'commission-paid-revenue'), stat('归因间夜估计', decimal(paid.attributedNights), 'commission-paid-nights'), stat('真实增量间夜估计', decimal(paid.incrementalNights), 'commission-paid-incremental'), stat(paid.costIncluded ? '扣广告后增量净贡献' : '扣佣扣广告后余额（未计履约成本）', money(paid.amountAfterAds), 'commission-paid-amount'), stat('保本历史投产比', paid.breakEvenRoi === null ? '无有效增量，无法保本' : decimal(paid.breakEvenRoi) + ' 倍', 'commission-paid-breakeven')]), h('p', { class: 'cc-note' }, '归因营收＝预算×历史投产比；间夜＝营收÷房价。不按整数向上取整，不保证有对应库存或实际订单。')]) : alert(traffic.value.error || (result ? '填写历史投产比后显示付费流量产出。未提供历史依据的结果仅是人工假设；缺失ROI不会按0补算。' : '请先完善上方房价、佣金和成本参数。'), !!traffic.value.error),
                        ]),
                        result ? h('details', [h('summary', '查看10%—15%各档佣金的门槛'), h('table', [h('thead', h('tr', ['调整后佣金', '每间夜' + metric(result), '间夜量门槛'].map(text => h('th', { scope: 'col' }, text)))), h('tbody', rows.map(row => h('tr', row.map(text => h('td', text)))))]), h('p', { class: 'cc-note' }, '各档统一与调整前佣金比较，非逐行环比。')]) : null,
                        h('div', { class: 'cc-actions' }, [h('button', { type: 'button', class: 'cc-button primary', disabled: !result || !!traffic.value.error, 'data-testid': 'commission-save-scenario', onClick: save }, '保存测算方案'), h('button', { type: 'button', class: 'cc-button', 'data-testid': 'commission-import-scenario', onClick: () => importInput.value?.click() }, '导入测算方案'), h('button', { type: 'button', class: 'cc-button', disabled: !result, onClick: copy }, '复制测算摘要'), h('input', { ref: importInput, type: 'file', accept: '.json,application/json', style: { display: 'none' }, 'data-testid': 'commission-import-file', onChange: restore })]),
                        notice.value ? h('p', { class: 'cc-status', role: 'status', 'data-testid': 'commission-notice' }, notice.value) : null,
                        result ? h('details', { open: summaryOpen.value, onToggle: event => { summaryOpen.value = event.target.open; } }, [h('summary', '查看可复制的完整测算摘要'), h('textarea', { readonly: true, value: summary.value, 'aria-label': '完整测算摘要', 'data-testid': 'commission-summary' })]) : null,
                        h('p', { class: 'cc-note cc-gap' }, '广告增量额相对较低佣金且未额外投放的情景，不含原间夜调佣后的贡献差额。保存仅下载人工测算文件，可导入回显，不写经营数据库。历史ROI需同店、同渠道、同一归因口径；过去效果不保证未来。未计库存上限、固定成本、其他渠道替代损失；不据此自动调佣、审批或投放。'),
                        props.request && registry.PromotionExperimentPanel ? h(registry.PromotionExperimentPanel, { hotelId: props.hotelId, request: props.request, openFinance: props.openFinance }) : null,
                    ]),
                ]);
            };
        },
    };
})();
