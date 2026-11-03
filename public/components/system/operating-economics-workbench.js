(function () {
    'use strict';
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const amount = value => {
        if (value === null || value === undefined) return '未取得';
        const number = Number(value);
        return number !== 0 && Math.abs(number) < 0.0000005
            ? number.toLocaleString('zh-CN', { maximumSignificantDigits: 12, ...(Math.abs(number) < 1e-12 ? { notation: 'scientific' } : {}) })
            : number.toLocaleString('zh-CN', { maximumFractionDigits: 6 });
    };
    const metricFields = [['effective_order_amount','有效订单'],['refund_amount','退款'],['net_revenue','结算净收入'],['advertising_spend','广告花费'],['attributed_order_amount','归因订单']];
    const metricRefs = () => Object.fromEntries(metricFields.map(([key]) => [key, '']));
    const newChannel = () => ({ net_revenue: '', advertising_spend: '', attributed_order_amount: '', effective_order_amount: '', refund_amount: '', attribution_basis: '', advertising_included_in_net_revenue: false, advertising_in_direct_costs: false, cost_coverage_complete: false, operator_attested: false, source_refs: '', evidence_refs_by_metric: metricRefs(), costs: [] });
    const canonical = value => JSON.stringify(value, (_, item) => item && typeof item === 'object' && !Array.isArray(item) ? Object.fromEntries(Object.keys(item).sort().map(key => [key, item[key]])) : item);
    const newItem = () => ({ id: crypto.randomUUID(), name: '', enabled: true, unit: 'piece', source_ref: '', source_date: '', valuation_method: 'confirmed_unit_cost', opening_quantity: '', purchased_quantity: '', transfer_in_quantity: '', closing_quantity: '', transfer_out_quantity: '', returned_quantity: '', written_off_quantity: '', unit_price: '', budget_unit_price: '', budget_usage_per_room_night: '' });
    registry.OperatingEconomicsWorkbench = {
        name: 'OperatingEconomicsWorkbench',
        props: { request: { type: Function, required: true }, hotelId: { type: [String, Number], required: true }, periodMonth: { type: String, required: true }, platform: { type: String, default: 'ctrip' }, canExecute: { type: Boolean, default: false } },
        data() { return { kind: 'channel_economics', busy: false, error: '', notice: '', saved: null, overview: null, result: null, seq: 0, dirty: false, restoreRequested: null,
            draftDirty: { channel_economics: false, consumables_actual: false }, channelDraftPlatform: null,
            channel: newChannel(),
            actual: { occupied_room_nights: '', occupied_room_nights_source_ref: '', denominator_scope: 'whole_hotel', operator_attested: false, items: [] } }; },
        computed: { scope() { return { hotel_id: Number(this.hotelId), period_month: this.periodMonth, platform: this.kind === 'consumables_actual' ? 'whole_hotel' : this.platform, kind: this.kind }; },
            input() { const input = JSON.parse(JSON.stringify(this.kind === 'consumables_actual' ? this.actual : this.channel)); if (this.kind === 'channel_economics') { input.source_refs = input.source_refs.split(/[\n,，]/).map(x => x.trim()).filter(Boolean); input.evidence_refs_by_metric = Object.fromEntries(metricFields.map(([key]) => [key, String(input.evidence_refs_by_metric?.[key] || '').split(/[\n,，]/).map(x => x.trim()).filter(Boolean)])); } return input; },
            query() { return new URLSearchParams(this.scope).toString(); },
            resultCurrent() { return this.result && !this.dirty; },
            marketingCoverageMessage() { const marketing = this.overview?.sources?.marketing; if (!marketing) return ''; if (marketing.reason === 'ctrip_marketing_period_requires_manual_evidence') return '自动取得的携程广告账期尚未齐备；同酒店同月人工资料可在下方逐项登记并核对。'; const complete = marketing.complete === true; const knownGaps = Array.isArray(marketing.missing_days) && (complete || marketing.missing_days.length > 0); return '自动取得的广告完整账期：' + (complete ? '已取得' : '尚不完整') + '；' + (knownGaps ? '缺 ' + marketing.missing_days.length + ' 天。' : '缺失天数未取得。'); },
            canAdopt() { return this.kind === 'consumables_actual' && !this.dirty && this.saved?.readback_verified === true && this.saved.source_quality === 'operator_attested' && this.result?.status === 'calculated' && !!this.result?.inputs?.occupied_room_nights_source_ref && this.result?.actual_consumables_cost_per_room_night != null; } },
        watch: { scope: { deep: true, handler(next, previous) {
            if (previous) {
                this.draftDirty[previous.kind] = this.dirty;
                if (previous.kind === 'channel_economics') this.channelDraftPlatform = previous.platform;
            }
            const hotelOrMonthChanged = previous && (next.hotel_id !== previous.hotel_id || next.period_month !== previous.period_month);
            const channelSourceChanged = next.kind === 'channel_economics' && this.channelDraftPlatform !== null && next.platform !== this.channelDraftPlatform;
            if (hotelOrMonthChanged) {
                this.channel = newChannel();
                this.actual = { occupied_room_nights: '', occupied_room_nights_source_ref: '', denominator_scope: 'whole_hotel', operator_attested: false, items: [] };
                this.draftDirty = { channel_economics: false, consumables_actual: false };
            } else if (channelSourceChanged) {
                this.channel = newChannel(); this.draftDirty.channel_economics = false;
            }
            if (next.kind === 'channel_economics') this.channelDraftPlatform = next.platform;
            this.seq++; this.saved = null; this.result = null; this.overview = null; this.error = ''; this.notice = ''; this.restoreRequested = null;
            this.dirty = this.draftDirty[next.kind]; this.load();
        } } },
        mounted() { this.channel.evidence_refs_by_metric = metricRefs(); this.load(); },
        methods: {
            amount, newItem,
            metricFields() { return metricFields; },
            metricSource(key) { const refs = this.channel.evidence_refs_by_metric?.[key]; return Array.isArray(refs) ? refs.join('\n') : refs || ''; },
            setMetricSource(key, value) { this.channel.evidence_refs_by_metric ||= metricRefs(); this.channel.evidence_refs_by_metric[key] = value; this.edit(); },
            evidenceText(check) { const label = metricFields.find(([key]) => key === check.metric)?.[1] || (check.metric === 'attribution_basis' ? '归因口径/窗口' : '直接成本'); const state = { amount_missing:'金额缺失',source_missing:'来源缺失',basis_missing:'归因口径或窗口缺失',coverage_incomplete:'成本范围不完整',manual_unverified:'人工资料待核对',manual_attested:'人工已核对',saved_source_readback:'同范围已保存来源回读' }; return label + '：' + (state[check.status] || '未取得'); },
            edit() { this.seq++; this.busy = false; this.dirty = true; this.notice = ''; },
            applyInputs(inputs) { if (!inputs || typeof inputs !== 'object' || Array.isArray(inputs)) throw new Error('响应未携带核算输入，请重新读取'); const input = JSON.parse(JSON.stringify(inputs)); if (this.kind === 'channel_economics') { if (!Array.isArray(input.source_refs || [])) throw new Error('来源引用格式无效，请重新读取'); input.source_refs = (input.source_refs || []).join('\n'); this.channel = { ...newChannel(), ...input }; } else this.actual = { occupied_room_nights:'', occupied_room_nights_source_ref:'', denominator_scope:'whole_hotel', operator_attested:false, items:[], ...input }; },
            async call(path, options = {}) { const response = await this.request(path, options); if (![0, 200].includes(response?.code)) throw new Error(response?.message || response?.msg || '请求失败'); return response.data; },
            assertScope(data, scope) { if (!data || Object.keys(scope).some(key => String(data.scope?.[key]) !== String(scope[key]))) throw new Error('响应范围不一致，请重新读取'); },
            assertSavedSnapshot(data, scope, expected = null) { this.assertScope(data, scope); if (data.readback_verified !== true || !Number.isSafeInteger(Number(data.snapshot_id)) || Number(data.snapshot_id) <= 0 || !/^[a-f0-9]{64}$/i.test(data.content_digest || '') || !data.inputs || !data.result || Array.isArray(data.inputs) || Array.isArray(data.result)) throw new Error('保存版本或回读证据不完整，请重读版本'); if (expected && (Number(data.snapshot_id) !== Number(expected.snapshot_id) || data.content_digest.toLowerCase() !== expected.content_digest.toLowerCase() || ['scope','inputs','result','source_quality','contract_version','source_scope'].some(key => canonical(data[key]) !== canonical(expected[key])))) throw new Error('保存版本与独立回读内容不一致，请重读版本'); },
            async load() { const sequence = ++this.seq; this.overview = null; this.error = ''; if (!this.hotelId || !this.periodMonth) { this.busy = false; return; } const scope = { ...this.scope }; this.busy = true; try { const data = await this.call('/operating-finance/evidence/overview?' + this.query); if (sequence !== this.seq) return; this.assertScope(data, scope); this.overview = data; } catch (e) { if (sequence === this.seq) this.error = e.message; } finally { if (sequence === this.seq) this.busy = false; } },
            async restore(id, confirmed = false) {
                if (this.dirty && !confirmed) { this.restoreRequested = id; return; }
                this.restoreRequested = null; const sequence = ++this.seq; const scope = { ...this.scope };
                this.busy = true; this.error = ''; this.notice = ''; this.saved = null; this.result = null; this.dirty = true;
                try {
                    const data = await this.call('/operating-finance/evidence/snapshots/' + id + '?' + this.query);
                    if (sequence !== this.seq) return;
                    this.assertScope(data, scope);
                    if (data.readback_verified !== true) throw new Error('回读验证未通过，请重新读取');
                    if (Number(data.snapshot_id) !== Number(id)) throw new Error('回读版本不一致，请重新读取');
                    this.applyInputs(data.inputs); this.saved = data; this.result = data.result; this.dirty = false;
                    this.notice = '已按记录ID精确回读';
                } catch (e) {
                    if (sequence === this.seq) {
                        const networkFailure = e?.name === 'NetworkError' || (e?.name === 'TypeError'
                            && /^(?:Failed to fetch|NetworkError(?: when attempting to fetch resource\.)?|Load failed)$/.test(e.message));
                        this.error = networkFailure ? '版本读取失败，请检查连接后重试。' : e.message;
                    }
                }
                finally { if (sequence === this.seq) this.busy = false; }
            },
            async calculate(save) {
                if (save && !this.canExecute) return;
                const sequence = ++this.seq; const scope = { ...this.scope }; const query = this.query;
                this.busy = true; this.error = ''; this.notice = ''; let verifiedSave = false;
                this.saved = null; this.result = null; this.dirty = true;
                try {
                    let data = await this.call('/operating-finance/evidence/' + (save ? 'snapshots' : 'preview'), { method: 'POST', body: JSON.stringify({ ...scope, inputs: this.input, idempotency_key: crypto.randomUUID() }) });
                    if (sequence !== this.seq) return; this.assertScope(data, scope);
                    if (save) {
                        this.assertSavedSnapshot(data, scope);
                        const reread = await this.call('/operating-finance/evidence/snapshots/' + data.snapshot_id + '?' + query);
                        if (sequence !== this.seq) return;
                        this.assertSavedSnapshot(reread, scope, data); data = reread; verifiedSave = true;
                    }
                    this.applyInputs(data.inputs); this.result = data.result; this.saved = save ? data : null; this.dirty = false;
                    this.notice = save ? '新版本已保存并独立精确回读' : '预览未保存';
                    if (save) { this.overview = null; const overview = await this.call('/operating-finance/evidence/overview?' + query); if (sequence === this.seq) { this.assertScope(overview, scope); this.overview = overview; } }
                } catch (e) { if (sequence === this.seq) this.error = verifiedSave ? '保存版本已回读，历史列表暂不可用：' + e.message : e.message; }
                finally { if (sequence === this.seq) this.busy = false; }
            },
            exportSnapshot() { if (!this.resultCurrent) return; const blob = new Blob([JSON.stringify({ scope: this.scope, snapshot_id: this.saved?.snapshot_id || null, source_quality: this.saved?.source_quality || 'unverified', result: this.result }, null, 2)], { type: 'application/json' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = this.kind + '-' + this.periodMonth + '.json'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); },
            adopt() { if (!this.canAdopt) return; const dates = (this.result.items || []).filter(row => row.enabled).map(row => row.source_date).filter(Boolean).sort(); const reference = { hotel_id: Number(this.hotelId), snapshot_id: this.saved.snapshot_id, content_digest: this.saved.content_digest, business_month: this.periodMonth, as_of: dates.at(-1) || '', source_label: '人工核对耗材月度证据 #' + this.saved.snapshot_id, actual_consumables_cost_per_room_night: this.result.actual_consumables_cost_per_room_night }; window.SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE = Object.freeze({ ...reference }); window.dispatchEvent(new CustomEvent('suxi:actual-consumables-reference', { detail: reference })); this.notice = '本页会话已保留采用参考；打开同酒店投资项目后继续确认。'; },
        },
        template: `
            <section class="rounded-2xl border border-slate-200 bg-white p-4" data-testid="operating-economics-workbench">
                <h3 class="font-bold text-slate-900">渠道贡献与实际耗材</h3>
                <p class="mt-2 text-xs text-slate-500">当前酒店 {{ hotelId }} · {{ periodMonth }} · 人工资料、已保存来源和缺项分别保留。回读不代表独立审计。</p>
                <div class="mt-3 flex flex-wrap gap-2"><button @click="kind='channel_economics'" :disabled="busy" class="rounded border p-2">渠道净贡献</button><button @click="kind='consumables_actual'" :disabled="busy" class="rounded border p-2">实际耗材核算</button><button @click="load" :disabled="busy" class="rounded border p-2">重读版本</button></div>
                <p v-if="error" role="alert" class="mt-3 text-red-700">{{ error }}</p><p v-if="notice" role="status" class="mt-3 text-emerald-700">{{ notice }}</p>
                <div v-if="restoreRequested" class="mt-3 rounded border p-3 text-sm"><p>载入已保存版本会替换未保存输入。</p><button @click="restore(restoreRequested,true)" class="mr-3 rounded border p-2">确认载入该版本</button><button @click="restoreRequested=null" class="rounded border p-2">保留当前输入</button></div>
                <p v-if="marketingCoverageMessage" class="mt-2 text-xs text-slate-500">{{ marketingCoverageMessage }}平台归因不代表增量效果。</p>
                <form @submit.prevent="calculate(false)" @input="edit" class="mt-4">
                    <div v-if="kind==='channel_economics'">
                        <div class="grid gap-3 sm:grid-cols-2"><label v-for="field in [['net_revenue','渠道净收入'],['advertising_spend','广告花费'],['attributed_order_amount','归因订单金额'],['effective_order_amount','有效订单金额'],['refund_amount','退款金额']]" :key="field[0]" class="text-xs">{{ field[1] }}（元）<input v-model="channel[field[0]]" :disabled="!canExecute" inputmode="decimal" class="mt-1 w-full rounded border p-2"></label><label class="text-xs">归因口径/窗口<input v-model="channel.attribution_basis" :disabled="!canExecute" class="mt-1 w-full rounded border p-2"></label></div>
                        <textarea v-model="channel.source_refs" :disabled="!canExecute" placeholder="同酒店同月的订单、退款及成本来源引用，每行一条" class="mt-3 w-full rounded border p-2"></textarea>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2" data-testid="channel-metric-source-inputs"><label v-for="field in metricFields()" :key="field[0]" class="text-xs">{{ field[1] }}来源引用<textarea :value="metricSource(field[0])" @input="setMetricSource(field[0],$event.target.value)" :disabled="!canExecute" placeholder="同酒店、平台和账期，每行一条；旧通用引用不会自动归属指标" class="mt-1 w-full rounded border p-2"></textarea></label></div>
                        <label class="mt-2 block text-xs"><input v-model="channel.advertising_included_in_net_revenue" type="checkbox" :disabled="!canExecute">广告已在净收入中扣除</label><label class="mt-2 block text-xs"><input v-model="channel.advertising_in_direct_costs" type="checkbox" :disabled="!canExecute">广告已在下面直接成本中列入</label>
                        <div v-for="(row,index) in channel.costs" :key="index" class="mt-3 grid gap-2 sm:grid-cols-2"><input v-model="row.label" :disabled="!canExecute" placeholder="直接成本名称" class="rounded border p-2"><input v-model="row.amount" :disabled="!canExecute" placeholder="金额（元）" inputmode="decimal" class="rounded border p-2"><input v-model="row.source_ref" :disabled="!canExecute" placeholder="成本来源" class="rounded border p-2"><label class="text-xs"><input v-model="row.included_in_net_revenue" :disabled="!canExecute" type="checkbox">已包含在净收入中，不重复扣</label><button v-if="canExecute" type="button" @click="channel.costs.splice(index,1);edit()" class="rounded border p-2">移除该成本</button></div>
                        <button v-if="canExecute" type="button" @click="channel.costs.push({label:'',amount:'',source_ref:'',included_in_net_revenue:false});edit()" class="mt-3 rounded border p-2">添加直接成本</button>
                        <label class="mt-3 block text-xs"><input v-model="channel.cost_coverage_complete" :disabled="!canExecute" type="checkbox">当前渠道直接成本范围已完整（缺失时仅展示已知成本后的贡献）</label>
                        <label class="mt-3 block text-xs"><input v-model="channel.operator_attested" :disabled="!canExecute" type="checkbox">我已核对当前酒店、平台、账期和来源；这是人工核对</label>
                    </div>
                    <div v-else>
                        <label class="text-xs">全酒店本月已售间夜<input v-model="actual.occupied_room_nights" :disabled="!canExecute" inputmode="decimal" class="mt-1 w-full rounded border p-2"></label>
                        <label class="mt-2 block text-xs">全酒店已售间夜来源<input v-model="actual.occupied_room_nights_source_ref" :disabled="!canExecute" placeholder="同酒店同月的PMS/全酒店房晚资料引用" class="mt-1 w-full rounded border p-2"></label>
                        <p class="mt-2 text-xs text-slate-500">全部数量先换成同一基础单位；明确没有发生的流转需填写0。成本单价采用本店已确认计价方法，不从参考报价推定。</p>
                        <fieldset v-for="row in actual.items" :key="row.id" :disabled="!canExecute" class="mt-3 rounded-xl border p-3"><label class="text-xs"><input v-model="row.enabled" type="checkbox">纳入本期</label><input v-model="row.name" placeholder="耗材名称" class="mt-2 w-full rounded border p-2"><select v-model="row.unit" class="mt-2 rounded border p-2"><option value="piece">件</option><option value="ml">毫升</option><option value="g">克</option></select>
                        <div class="mt-2 grid grid-cols-2 gap-2"><label v-for="field in [['opening_quantity','期初数量'],['purchased_quantity','采购入库数量'],['transfer_in_quantity','调入'],['closing_quantity','期末数量'],['transfer_out_quantity','调出'],['returned_quantity','退货'],['written_off_quantity','单列报损'],['unit_price','已确认单位成本'],['budget_unit_price','预算单位成本'],['budget_usage_per_room_night','预算每间夜用量']]" :key="field[0]" class="text-xs">{{ field[1] }}<input v-model="row[field[0]]" inputmode="decimal" class="mt-1 w-full rounded border p-2"></label><input v-model="row.source_ref" placeholder="盘点/领用/计价来源" class="rounded border p-2"><input v-model="row.source_date" type="date" class="rounded border p-2"></div></fieldset>
                        <button v-if="canExecute" type="button" @click="actual.items.push(newItem());edit()" class="mt-3 rounded border p-2">添加耗材</button><label class="mt-3 block text-xs"><input v-model="actual.operator_attested" :disabled="!canExecute" type="checkbox">我已核对同酒店同月库存平衡、计价、损耗及全酒店间夜</label>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2"><button type="submit" :disabled="busy" class="rounded border px-4 py-3">计算预览</button><button v-if="canExecute" type="button" @click="calculate(true)" :disabled="busy" class="rounded bg-emerald-700 px-4 py-3 text-white">保存新版本并回读</button><button type="button" @click="exportSnapshot" :disabled="!resultCurrent" class="rounded border px-4 py-3">导出当前结果</button><button v-if="kind==='consumables_actual'" type="button" @click="adopt" :disabled="!canAdopt" class="rounded border px-4 py-3">采用为投资测算参考</button></div>
                </form>
                <p v-if="resultCurrent" class="mt-3 text-xs">来源质量：{{ result.source_quality === 'operator_attested' ? '人工已核对，非独立审计' : '尚未核验' }} · {{ saved?.readback_verified ? '已保存并按版本回读' : '预览，尚未保存' }}</p>
                <p v-if="resultCurrent && kind==='channel_economics'" class="mt-2 text-xs">同口径广告 ROAS {{ amount(result.attributed_roas) }} · 订单与净收入差 {{ amount(result.order_to_settlement_difference) }} 元。差额需核对账期、退款、佣金和调整，归因收入不代表增量收益。</p>
                <div v-if="result" class="mt-4 rounded-xl bg-slate-50 p-3">
                    <p v-if="dirty" class="text-amber-700">输入已修改，当前结果已过期；重新计算后才能导出或采用。</p>
                    <template v-else>
                        <p v-if="kind==='channel_economics'">净收入 {{ amount(result.net_revenue) }} 元 · 已知直接成本 {{ amount(result.known_direct_cost) }} 元 · 完整渠道贡献 {{ amount(result.channel_net_contribution_amount) }} 元 · 已知成本后贡献 {{ amount(result.known_costs_contribution_amount) }} 元</p>
                        <p v-else>经营耗用 {{ amount(result.actual_consumed_cost) }} 元 · 已知耗用 {{ amount(result.known_consumed_cost) }} 元 · 单列损耗 {{ amount(result.separate_loss_cost) }} 元 · 每已售间夜 {{ amount(result.actual_consumables_cost_per_room_night) }} 元</p>
                        <p v-for="row in result.items || []" :key="row.id" class="mt-2 text-xs">{{ row.name }} · {{ row.enabled === false ? '未纳入本期' : row.enabled === true ? '纳入本期' : '纳入状态未取得' }} · 用量 {{ amount(row.consumed_quantity) }} {{ ({piece:'件',ml:'毫升',g:'克'})[row.unit] || '单位未取得' }} · 成本 {{ amount(row.consumed_cost) }} · 价格差 {{ amount(row.price_variance) }} · 用量差 {{ amount(row.usage_variance) }}</p>
                        <p class="mt-2 text-xs text-amber-700">{{ result.missing_items?.join(' · ') || '本次计算输入无缺项；来源质量另行判断' }}</p>
                    </template>
                </div>
                <div v-if="overview?.history?.length" class="mt-3 flex flex-wrap gap-2"><button v-for="entry in overview.history" :key="entry.snapshot_id" @click="restore(entry.snapshot_id)" :disabled="busy" class="rounded border p-2 text-xs">版本 #{{ entry.snapshot_id }} · {{ entry.created_at }}</button></div>
                <div v-if="resultCurrent && kind==='channel_economics'" class="mt-3 rounded border p-3 text-xs" data-testid="channel-evidence-chain"><template v-if="result.evidence_chain"><p>经营证据闭环：{{ result.evidence_chain.status === 'ready_for_same_scope_review' ? '已结束账期资料齐备，可进行同范围复核' : '资料或账期尚未齐备' }}；自然月 {{ ({closed:'已结束',open:'尚未结束',unknown:'未确认'})[result.evidence_chain.period_status] || '未确认' }}，财务结账尚未核验。</p><p v-for="check in result.evidence_chain.checks" :key="check.metric" class="mt-1">{{ evidenceText(check) }}</p><p class="mt-2 text-slate-500">来源引用和人工核对不等于独立审计；渠道贡献不等于全酒店利润或投资人现金回收。</p></template><p v-else>旧版本尚未记录分项来源核对状态，重新计算并保存新版本后可查看。</p></div>
                <p v-if="overview?.monthly_finance?.results" class="mt-3 text-xs">另行已保存的全酒店/住宿财务范围：{{ overview.monthly_finance.results.fact_scope }}；GOP {{ amount(overview.monthly_finance.results.gop) }} 元。渠道贡献不会自动写成全酒店利润。</p>
            </section>
        `,
    };
})();
