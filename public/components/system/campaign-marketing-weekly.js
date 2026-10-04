(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const monday = () => { const iso = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); const d = new Date(`${iso}T00:00:00Z`); d.setUTCDate(d.getUTCDate() - (d.getUTCDay() + 6) % 7); return d.toISOString().slice(0, 10); };
    const metrics = [['posts', '作品数'], ['views', '播放量'], ['likes', '点赞量'], ['reposts', '转发量']];
    const ruleForm = () => ({ name: '', metric_definition: '', weights: { posts: '', views: '', likes: '', reposts: '' }, targets: { posts: '', views: '', likes: '', reposts: '' } });
    components.CampaignMarketingWeekly = {
        name: 'CampaignMarketingWeekly',
        props: { hotels: { type: Array, default: () => [] }, request: { type: Function, required: true }, hotelId: { type: [Number, String], required: true }, canExecute: { type: Boolean, default: false } },
        data: () => ({ ids: [], weekStart: monday(), result: null, error: '', success: '', busy: false, seq: 0, ruleId: '', rule: ruleForm(), ruleKey: '', ruleExpected: 0, ruleSource: '', coverageDate: monday(), coverage: { coverage_status: 'partial', expected_posts: '', notes: '' }, coverageExpected: 0, coverageSource: '' }),
        watch: { hotelId: { immediate: true, handler(value) { this.ids = value ? [Number(value)] : []; this.clear(); this.coverageExpected = 0; this.coverage = { coverage_status: 'partial', expected_posts: '', notes: '' }; this.coverageSource = ''; } }, weekStart() { this.clear(); this.coverageExpected = 0; }, ids() { this.clear(); }, coverageDate() { this.coverageExpected = 0; this.coverage = { coverage_status: 'partial', expected_posts: '', notes: '' }; this.coverageSource = ''; } },
        beforeUnmount() { this.seq++; },
        methods: {
            clear() { this.seq++; this.result = null; this.error = this.success = ''; this.busy = false; this.ruleId = ''; this.ruleExpected = 0; this.ruleKey = ''; this.rule = ruleForm(); this.ruleSource = ''; },
            hotelName(id) { return this.hotels.find(hotel => Number(hotel.id) === id)?.name || `酒店${id}`; },
            toggle(id, checked) { this.ids = checked ? [...new Set([...this.ids, id])] : this.ids.filter(value => value !== id); },
            async load() {
                const seq = ++this.seq, ids = [...this.ids].sort((a, b) => a - b), start = this.weekStart, ruleId = this.ruleId;
                this.busy = true; this.result = null; this.error = '';
                try {
                    const response = await this.request(`/campaign-operations/weekly?hotel_ids=${ids.join(',')}&week_start=${start}&rule_id=${this.ruleId || ''}`, { businessContext: { hotelId: Number(this.hotelId) } });
                    if (seq !== this.seq) return;
                    const data = response.data;
                    if (response.code !== 200 || data?.schema_version !== 'campaign_marketing_weekly.v1' || data.platform !== 'douyin' || data.week_start !== start || JSON.stringify(data.hotel_ids) !== JSON.stringify(ids) || data.dates?.length !== 7 || (ruleId && data.rule?.id !== Number(ruleId))) throw new Error(response.message || '集团周榜范围或7日合同不匹配');
                    this.result = data;
                } catch (error) { if (seq === this.seq) this.error = error.message || '周榜读取失败，覆盖与排名未知'; }
                finally { if (seq === this.seq) this.busy = false; }
            },
            async saveRecord(kind, payload, source, expected, recordKey, date) {
                const response = await this.request('/campaign-operations/records', { method: 'POST', businessContext: { hotelId: Number(this.hotelId) }, body: JSON.stringify({ hotel_id: Number(this.hotelId), kind, payload, source_label: source, expected_id: expected, record_key: recordKey, business_date: date }) });
                const record = response.data?.record;
                if (response.code !== 200 || response.data?.request_status !== 'saved_and_readback_verified' || record?.kind !== kind || record.hotel_id !== Number(this.hotelId) || record.business_date !== date) throw new Error(response.message || '保存版本回读不匹配');
                return record;
            },
            async saveRule() {
                if (!this.canExecute || this.busy) return; const seq = ++this.seq; this.busy = true; this.error = '';
                try {
                    const record = await this.saveRecord('marketing_score_rule', this.rule, this.ruleSource, this.ruleExpected, this.ruleKey || `score_${crypto.randomUUID()}`, this.weekStart);
                    if (seq !== this.seq) return;
                    this.rule = structuredClone(record.payload); this.ruleExpected = record.id; this.ruleKey = record.record_key; this.ruleId = String(record.id);
                    this.success = `评分规则已保存并回读 #${record.id} / v${record.version_no}；重新读取周榜以应用该规则`;
                    this.result = null;
                } catch (error) { if (seq === this.seq) this.error = error.message; }
                finally { if (seq === this.seq) this.busy = false; }
            },
            async editCoverage(day) {
                if (!day.coverage_record_id || this.busy) return;
                const seq = ++this.seq; this.busy = true; this.error = '';
                try {
                    const response = await this.request(`/campaign-operations/records/${day.coverage_record_id}?hotel_id=${this.hotelId}`, { businessContext: { hotelId: Number(this.hotelId) } });
                    if (seq !== this.seq) return;
                    const record = response.data;
                    if (response.code !== 200 || record?.kind !== 'marketing_coverage' || record.hotel_id !== Number(this.hotelId) || record.business_date !== day.business_date || record.id !== day.coverage_record_id) throw new Error(response.message || '覆盖记录回读不匹配');
                    this.coverageDate = record.business_date; await this.$nextTick();
                    this.coverage = structuredClone(record.payload); this.coverageSource = record.source_label; this.coverageExpected = record.id;
                } catch (error) { if (seq === this.seq) this.error = error.message; }
                finally { if (seq === this.seq) this.busy = false; }
            },
            async saveCoverage() {
                if (!this.canExecute || this.busy) return; const seq = ++this.seq; this.busy = true; this.error = '';
                try {
                    const record = await this.saveRecord('marketing_coverage', this.coverage, this.coverageSource, this.coverageExpected, 'douyin_coverage', this.coverageDate);
                    if (seq !== this.seq) return;
                    this.coverage = structuredClone(record.payload); this.coverageExpected = record.id;
                    this.success = `日覆盖已保存并回读 #${record.id} / v${record.version_no}；重新读取周榜核对7天`;
                    this.result = null;
                } catch (error) { if (seq === this.seq) this.error = error.message; }
                finally { if (seq === this.seq) this.busy = false; }
            },
            chooseRule(value) {
                this.ruleId = value;
                const saved = this.result?.available_rules.find(row => String(row.id) === value);
                if (saved?.hotel_id === Number(this.hotelId)) { this.rule = structuredClone(saved.payload); this.ruleKey = saved.record_key; this.ruleExpected = saved.id; this.ruleSource = saved.source_label; }
                else { this.rule = ruleForm(); this.ruleKey = ''; this.ruleExpected = 0; this.ruleSource = ''; }
            },
        },
        render() {
            const h = window.Vue.h, input = (label, target, name, type = 'text') => h('label', { class: 'block text-sm' }, [label, h('input', { type, value: target[name], min: type === 'number' ? 0 : undefined, class: 'block w-full border border-slate-300 rounded-lg p-2 min-h-[44px]', disabled: this.busy || !this.canExecute, onInput: event => { target[name] = event.target.value; } })]);
            const button = (label, click, disabled = false) => h('button', { type: 'button', onClick: click, disabled: this.busy || disabled, class: 'border border-slate-300 rounded-lg px-3 py-2 min-h-[44px] text-sm disabled:opacity-50' }, label);
            const value = v => v === null || v === undefined ? '未知' : String(v);
            return h('section', { class: 'space-y-3 rounded-xl border border-slate-200 bg-white p-4', 'data-testid': 'campaign-marketing-weekly' }, [
                h('h3', { class: 'font-semibold text-slate-900' }, '集团抖音周覆盖与参考评分'),
                h('p', { class: 'text-sm text-slate-600' }, '选定同一租户酒店，核对周一至周日7天。指标统一统计至周日；缺失不计零，不完整或不同口径不排名。'),
                h('div', { class: 'flex flex-wrap gap-3' }, this.hotels.map(hotel => h('label', { class: 'text-sm flex gap-2 min-h-[44px] items-center' }, [h('input', { type: 'checkbox', checked: this.ids.includes(Number(hotel.id)), disabled: this.busy, onChange: event => this.toggle(Number(hotel.id), event.target.checked) }), hotel.name || `酒店${hotel.id}`]))),
                h('label', { class: 'block text-sm' }, ['周一', h('input', { type: 'date', value: this.weekStart, class: 'block border border-slate-300 rounded-lg p-2 min-h-[44px]', onInput: event => { this.weekStart = event.target.value; } })]),
                h('label', { class: 'block text-sm' }, ['使用已保存评分规则', h('select', { value: this.ruleId, class: 'block w-full border border-slate-300 rounded-lg p-2 min-h-[44px]', disabled: this.busy, onChange: event => this.chooseRule(event.target.value) }, [h('option', { value: '' }, '请选择规则；首次读取获取可用规则'), ...(this.result?.available_rules || []).map(row => h('option', { value: String(row.id) }, `${row.payload.name} · #${row.id}/v${row.version_no} · ${this.hotelName(row.hotel_id)}`)), ...(!this.result && this.ruleId ? [h('option', { value: this.ruleId }, `${this.rule.name} · #${this.ruleId}`)] : [])])]),
                button('读取7日覆盖与周榜', this.load, !this.ids.length),
                this.error ? h('p', { role: 'alert', class: 'text-red-800 bg-red-50 rounded-lg p-3' }, this.error) : null,
                this.success ? h('p', { role: 'status', class: 'text-emerald-900 bg-emerald-50 rounded-lg p-3' }, this.success) : null,
                this.result ? h('div', { class: 'space-y-3' }, [h('p', { class: 'text-xs text-slate-600' }, `租户${this.result.tenant_id} · 抖音 · ${this.result.week_start}—${this.result.week_end} · 统计日 ${this.result.metric_as_of_date} · 规则 ${this.result.rule ? this.result.rule.payload.name + ' #' + this.result.rule.id + '/v' + this.result.rule.version_no : '未配置'} · 人工未核验 · ${this.result.boundary}`),
                    this.result.rule ? h('details', {}, [h('summary', { class: 'cursor-pointer min-h-[44px] text-sm' }, '查看当前评分公式、权重与来源'), h('p', { class: 'text-xs text-slate-600' }, `${this.result.rule.payload.metric_definition} · 来源：${this.result.rule.source_label} · 评分=Σ权重×min(周累计/周目标,1)`), ...metrics.map(([metric, label]) => h('p', { class: 'text-xs text-slate-600' }, `${label}：权重${this.result.rule.payload.weights[metric]}，周目标${this.result.rule.payload.targets[metric]}`))]) : null,
                    h('div', { class: 'overflow-x-auto' }, [h('table', { class: 'w-full min-w-[580px] text-sm' }, [h('thead', {}, [h('tr', {}, ['酒店', '7日覆盖', '作品', '播放', '点赞', '转发', '得分', '排名'].map(label => h('th', { class: 'p-2 text-left border-b' }, label)))]), h('tbody', {}, this.result.rows.map(row => h('tr', {}, [this.hotelName(row.hotel_id), `${row.coverage_days}/7`, ...metrics.map(([metric]) => value(row.totals[metric])), value(row.score), row.rank === null ? '未入榜' : String(row.rank)].map(text => h('td', { class: 'p-2 border-b whitespace-nowrap' }, text)))))])]),
                    ...this.result.rows.map(row => h('details', {}, [h('summary', { class: 'cursor-pointer min-h-[44px] text-sm' }, `${this.hotelName(row.hotel_id)} · ${row.eligible_for_rank ? '可比较的人工记录' : '未入榜：' + row.exclusion_reasons.join('；')}`), ...row.daily_coverage.map(day => h('p', { class: 'flex flex-wrap gap-2 text-xs text-slate-600 py-1' }, [`${day.business_date} · ${day.status === 'missing' ? '缺少覆盖' : day.status === 'partial' ? '部分/数量不符' : '人工核对完整'} · 预期${value(day.expected_posts)} / 已保存${day.saved_posts} · 来源${day.source_label || '未知'}`, row.hotel_id === Number(this.hotelId) && day.coverage_record_id ? button('回读该日覆盖', () => this.editCoverage(day)) : null]))])),
                ]) : null,
                this.canExecute ? h('details', {}, [h('summary', { class: 'cursor-pointer min-h-[44px] text-sm font-medium' }, `录入 ${this.hotelName(Number(this.hotelId))} 的日覆盖与评分规则`),
                    h('div', { class: 'space-y-3 mt-2' }, [input('覆盖业务日期', this, 'coverageDate', 'date'), input('覆盖来源说明', this, 'coverageSource'), h('label', { class: 'block text-sm' }, ['覆盖状态', h('select', { value: this.coverage.coverage_status, class: 'block w-full border border-slate-300 rounded-lg p-2 min-h-[44px]', onChange: event => { this.coverage.coverage_status = event.target.value; } }, [['partial', '部分/未核对'], ['complete', '已核对完整'], ['no_posts', '已核对无作品（数量填0）']].map(([v, label]) => h('option', { value: v }, label)))]), input('当天发布作品数（未知留空）', this.coverage, 'expected_posts', 'number'), input('覆盖核对说明', this.coverage, 'notes'), button('保存并回读日覆盖', this.saveCoverage),
                        h('p', { class: 'text-sm text-slate-600' }, '评分 = Σ 权重 × min(已核对周累计/周目标, 1)。权重总和100，目标正整数。先明确口径并保存规则，不自动应用示例。'), input('评分规则名称', this.rule, 'name'), input('统计口径与核对说明', this.rule, 'metric_definition'), input('规则来源说明', this, 'ruleSource'),
                        h('div', { class: 'grid gap-3 sm:grid-cols-2' }, metrics.flatMap(([metric, label]) => [input(`${label}权重（0至100）`, this.rule.weights, metric, 'number'), input(`${label}周目标（正整数）`, this.rule.targets, metric, 'number')])), button('保存并回读评分规则', this.saveRule),
                    ]),
                ]) : null,
            ]);
        },
    };
})();
