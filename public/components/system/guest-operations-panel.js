(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const windowStart = settings => {
        const days = Number(settings?.repeat_window_days);
        const windowDays = Number.isInteger(days) && days >= 1 && days <= 365 ? days : 30;
        const end = new Date(`${today()}T00:00:00+08:00`); end.setTime(end.getTime() - (windowDays - 1) * 86400000);
        return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(end);
    };
    const emptyCase = date => ({ case_key: '', expected_revision: 0, incident_date: date, category: 'complaint', summary: '', owner_user_id: '', due_at: `${date}T18:00`, source_reference: '', evidence: '', edit_reason: '' });
    const gapLabels = { source_coverage_missing: '尚未登记来源覆盖', source_coverage_partial: '来源仅部分覆盖', source_coverage_unverified: '来源覆盖未核验', explicit_denominator_missing: '尚未声明同期分母', denominator_mismatch: '导入唯一客人数与声明分母不一致', denominator_zero: '同期无完成入住客人，复购率不适用' };
    const refs = text => String(text || '').split(/\r?\n/).map(value => value.trim()).filter(Boolean);
    components.GuestOperationsPanel = {
        name: 'GuestOperationsPanel',
        inheritAttrs: false,
        props: {
            hotels: { type: Array, default: () => [] }, request: { type: Function, required: true },
            selectedHotelId: { type: [String, Number], default: '' }, owners: { type: Array, default: () => [] },
            initialTab: { type: String, default: 'repeat' }, settings: { type: Object, default: () => ({}) },
            canExecute: { type: Boolean, default: false }, entryKey: { type: String, default: '' },
        },
        emits: ['update:selected-hotel-id'],
        data() { const date = today(); return {
            hotelId: String(this.selectedHotelId || this.hotels[0]?.id || ''), start: windowStart(this.settings), end: date, platform: 'pms', tab: ['repeat', 'feedback', 'entry'].includes(this.initialTab) ? this.initialTab : 'repeat',
            overview: null, loading: false, busy: false, error: '', notice: '', sequence: 0, retryKeys: {},
            stayText: '', staySource: '', coverage: { expected_guests: '', source_quality: 'unverified', source_reference: '', expected_revision: 0 },
            caseForm: emptyCase(date), selectedCase: '', fact: { action: 'handling', occurred_at: `${date}T12:00`, note: '', evidence: '', confirmation: false, confirmed_by_role: '' },
            entry: { entry_key: '', room_label: '', label: '', enabled: true, expected_revision: 0 }, historyRows: [], historySequence: 0,
        }; },
        computed: {
            scopeKey() { return `${this.hotelId}|${this.start}|${this.end}|${this.platform}`; },
            currentCase() { return this.overview?.feedback?.find(record => record.record_key === this.selectedCase) || null; },
            availableOwners() { return Array.isArray(this.overview?.owners) ? this.overview.owners : this.owners; },
        },
        watch: {
            initialTab(value) { if (['repeat', 'feedback', 'entry'].includes(value)) this.tab = value; },
            'settings.repeat_window_days'() { this.start = windowStart(this.settings); this.end = today(); },
            selectedHotelId(value) { if (String(value || '') !== this.hotelId) this.hotelId = String(value || ''); },
            hotels(value) { if (!value.some(hotel => String(hotel.id) === this.hotelId)) this.hotelId = String(value[0]?.id || ''); },
            scopeKey() { this.resetDrafts(); void this.load(); },
        },
        mounted() { void this.load(); },
        beforeUnmount() { this.sequence += 1; this.historySequence += 1; },
        methods: {
            resetDrafts() {
                this.sequence += 1; this.historySequence += 1; this.overview = null; this.error = ''; this.notice = ''; this.historyRows = []; this.retryKeys = {};
                this.stayText = ''; this.staySource = ''; this.coverage = { expected_guests: '', source_quality: 'unverified', source_reference: '', expected_revision: 0 };
                this.caseForm = emptyCase(this.end); this.selectedCase = ''; this.fact.note = ''; this.fact.evidence = ''; this.fact.confirmation = false; this.fact.confirmed_by_role = '';
                this.entry = { entry_key: '', room_label: '', label: '', enabled: true, expected_revision: 0 };
            },
            async load() {
                this.historySequence += 1;
                const scope = this.scopeKey, seq = ++this.sequence;
                this.overview = null; this.error = ''; this.loading = true;
                try {
                    if (!this.hotels.some(hotel => String(hotel.id) === this.hotelId)) throw new Error('请选择有权限的酒店');
                    const query = new URLSearchParams({ hotel_id: this.hotelId, date_start: this.start, date_end: this.end, platform: this.platform });
                    const response = await this.request(`/guest-operations/overview?${query}`, { businessContext: { hotelId: Number(this.hotelId) } });
                    if (seq !== this.sequence || scope !== this.scopeKey) return;
                    const data = response.data;
                    if (response.code !== 200 || data?.contract_version !== 'guest_operations.v1' || Number(data.hotel_id) !== Number(this.hotelId) || data.date_start !== this.start || data.date_end !== this.end || data.platform !== this.platform) throw new Error(response.message || '宾客运营范围回读不匹配');
                    this.overview = data;
                    if (this.entryKey) {
                        const entry = data.feedback_entries.find(record => record.record_key === this.entryKey && record.document.enabled);
                        if (!entry) throw new Error('反馈入口在当前酒店不存在或已停用');
                        this.tab = 'feedback'; this.caseForm.source_reference = `guest_feedback_entry:${entry.id} ${entry.document.room_label}`;
                    }
                    const record = data.repeat_guest?.coverage;
                    if (record) this.coverage = { ...record.document, expected_revision: record.revision, expected_guests: record.document.expected_guests ?? '' };
                    this.$emit('update:selected-hotel-id', this.hotelId);
                } catch (error) { if (seq === this.sequence) { this.overview = null; this.error = error.message || '宾客运营读取失败'; } }
                finally { if (seq === this.sequence) this.loading = false; }
            },
            async write(path, payload) {
                if (this.busy || this.loading) return false;
                if (!this.canExecute) { this.error = '当前账号只有查看权限，不能登记或修改'; return false; }
                const scope = this.scopeKey, hotel = Number(this.hotelId), key = `${path}:${JSON.stringify(payload)}`;
                if (!this.overview || !hotel) { this.error = '先成功加载当前酒店范围，再保存'; return false; }
                this.busy = true; this.error = ''; this.notice = '';
                this.retryKeys[key] ||= `guest-${Date.now()}-${Math.random().toString(36).slice(2)}`;
                try {
                    const response = await this.request(`/guest-operations/${path}`, { method: 'POST', body: JSON.stringify({ ...payload, hotel_id: hotel, idempotency_key: this.retryKeys[key] }), businessContext: { hotelId: hotel } });
                    if (scope !== this.scopeKey) return false;
                    const saved = response.data;
                    if (response.code !== 200 || saved?.contract_version !== 'guest_operations.v1' || Number(saved.hotel_id) !== hotel || saved.persistence_status !== 'readback_verified' || !saved.records?.length) throw new Error(response.message || '保存未取得精确回读');
                    for (const record of saved.records) {
                        const read = await this.request(`/guest-operations/records/${record.id}?hotel_id=${hotel}`, { businessContext: { hotelId: hotel } });
                        if (scope !== this.scopeKey) return false;
                        if (read.code !== 200 || Number(read.data?.hotel_id) !== hotel || read.data?.content_digest !== record.content_digest || read.data?.readback_verified !== true) throw new Error('保存已返回，但记录精确回读失败；保留原幂等键重试');
                    }
                    delete this.retryKeys[key]; await this.load();
                    if (scope !== this.scopeKey) return false;
                    this.notice = this.error ? '记录已保存并精确回读；列表刷新失败，请重试读取。' : `已保存并精确回读 ${saved.records.length} 条记录${saved.idempotent_replay ? '（幂等重试）' : ''}`;
                    return true;
                } catch (error) { if (scope === this.scopeKey) this.error = error.message || '保存失败'; return false; }
                finally { this.busy = false; }
            },
            async importStays() {
                try { const events = JSON.parse(this.stayText); if (!Array.isArray(events)) throw new Error('入住JSON必须为数组'); if (await this.write('stays', { platform: this.platform, source_reference: this.staySource, events })) this.stayText = ''; }
                catch (error) { this.error = error.message || '匿名入住JSON格式无效'; }
            },
            async saveCoverage() {
                const expected = this.coverage.expected_guests;
                await this.write('coverage', { platform: this.platform, date_start: this.start, date_end: this.end, expected_guests: expected === '' ? null : Number(expected), source_quality: this.coverage.source_quality, source_reference: this.coverage.source_reference, expected_revision: this.coverage.expected_revision });
            },
            async saveCase() {
                const form = this.caseForm;
                if (await this.write('feedback', { case_key: form.case_key, expected_revision: form.expected_revision, incident_date: form.incident_date, category: form.category, summary: form.summary, owner_user_id: Number(form.owner_user_id), due_at: form.due_at, source_reference: form.source_reference, evidence_refs: refs(form.evidence), edit_reason: form.edit_reason })) this.caseForm = emptyCase(this.end);
            },
            editCase(record) {
                this.caseForm = { ...emptyCase(this.end), ...record.document, expected_revision: record.revision, due_at: record.document.due_at.replace(' ', 'T').slice(0, 16), evidence: (record.document.evidence_refs || []).join('\n'), edit_reason: '' };
            },
            async saveFact() {
                const record = this.currentCase;
                if (!record) { this.error = '请选择当前范围客诉记录'; return; }
                if (await this.write(`feedback/${encodeURIComponent(record.record_key)}/facts`, { expected_revision: record.revision, action: this.fact.action, occurred_at: this.fact.occurred_at, note: this.fact.note, evidence_refs: refs(this.fact.evidence), confirmation: this.fact.confirmation, confirmed_by_role: this.fact.confirmed_by_role })) { this.fact.note = ''; this.fact.evidence = ''; this.fact.confirmation = false; this.fact.confirmed_by_role = ''; }
            },
            async saveEntry() { if (await this.write('entries', { ...this.entry })) this.entry = { entry_key: '', room_label: '', label: '', enabled: true, expected_revision: 0 }; },
            editEntry(record) { this.entry = { entry_key: record.document.entry_key, room_label: record.document.room_label, label: record.document.label, enabled: record.document.enabled, expected_revision: record.revision }; },
            async history(record) {
                const scope = this.scopeKey, sequence = ++this.historySequence, kind = record.kind, key = record.record_key;
                const current = () => scope === this.scopeKey && sequence === this.historySequence;
                this.historyRows = []; this.error = '';
                try {
                    const query = new URLSearchParams({ hotel_id: this.hotelId, kind, record_key: key });
                    const response = await this.request(`/guest-operations/history?${query}`, { businessContext: { hotelId: Number(this.hotelId) } });
                    if (!current()) return;
                    if (response.code !== 200 || !Array.isArray(response.data?.records) || response.data.records.some(row => Number(row.hotel_id) !== Number(this.hotelId) || row.kind !== kind || row.record_key !== key || !row.readback_verified)) throw new Error(response.message || '历史范围回读失败');
                    this.historyRows = response.data.records;
                } catch (error) { if (current()) this.error = error.message || '历史回读失败'; }
            },
            qrData(record) {
                if (!record.document.enabled) return { error: '入口已停用' };
                if (Number(record.hotel_id) !== Number(this.hotelId)) return { error: '反馈入口酒店范围不匹配，请重新读取' };
                // Saved content keeps its original provenance; navigation uses the verified current hotel scope.
                const query = new URLSearchParams({ page: 'operating-finance', workspace: 'guests', hotel_id: String(record.hotel_id), feedback_entry: record.record_key });
                const url = new URL(`/?${query}`, window.location.origin).href;
                try { if (!window.SUXI_GUEST_FEEDBACK_QR) throw new Error('二维码编码器尚未加载'); return { url, image: `data:image/svg+xml;charset=utf-8,${encodeURIComponent(window.SUXI_GUEST_FEEDBACK_QR.svg(url))}` }; }
                catch (error) { return { url, error: error.message }; }
            },
        },
        render() {
            const h = window.Vue.h;
            const inputStyle = { width: '100%', minHeight: '44px', border: '1px solid var(--color-border, #b9c9c0)', borderRadius: '8px', padding: '8px 12px', color: 'var(--color-text, #1c3028)', background: 'white', boxSizing: 'border-box' };
            const grid = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,220px),1fr))', gap: '12px' };
            const field = (label, value, setter, options = {}) => h('label', { style: { display: 'grid', gap: '4px', minWidth: 0 } }, [h('span', label), h(options.multiline ? 'textarea' : 'input', { ...options, multiline: undefined, value, style: { ...inputStyle, ...(options.multiline ? { minHeight: '96px' } : {}) }, onInput: event => setter(event.target.value), disabled: this.busy })]);
            const select = (label, value, setter, choices) => h('label', { style: { display: 'grid', gap: '4px' } }, [h('span', label), h('select', { 'aria-label': label, value, style: inputStyle, disabled: this.busy, onChange: event => setter(event.target.value) }, choices.map(([key, text]) => h('option', { value: key }, text)))]);
            const button = (label, click, primary = false) => h('button', { type: 'button', onClick: click, disabled: this.busy || this.loading || (primary && !this.canExecute), style: { minHeight: '44px', padding: '8px 16px', borderRadius: '8px', border: '1px solid #426c5b', color: primary ? 'white' : '#214c3d', background: primary ? 'var(--color-brand, #214c3d)' : 'white' } }, label);
            const card = (title, children) => h('section', { style: { background: 'white', padding: '20px', border: '1px solid #d5dfd8', borderRadius: '14px', minWidth: 0 } }, [h('h3', { style: { margin: '0 0 12px', fontSize: '20px' } }, title), ...children]);
            const small = text => h('p', { style: { color: '#5b6d63', margin: '8px 0', overflowWrap: 'anywhere' } }, text);
            const coverage = this.coverage, form = this.caseForm, fact = this.fact, repeat = this.overview?.repeat_guest;
            const children = [h('h2', { style: { margin: 0, fontSize: '28px' } }, '宾客运营'), small('匿名入住复购、宾客反馈处理与鉴权入口。所有数据为手工登记或导入；来源覆盖不足时不显示复购率。'),
                h('nav', { 'aria-label': '宾客运营业务区', style: { display: 'flex', flexWrap: 'wrap', gap: '8px' } }, [['repeat', '客群复购'], ['feedback', '反馈闭环'], ['entry', '鉴权入口']].map(([key, label]) => h('button', { type: 'button', 'aria-pressed': this.tab === key, onClick: () => { this.tab = key; }, style: { minHeight: '44px', padding: '8px 16px', borderRadius: '8px', border: '1px solid #426c5b', color: this.tab === key ? 'white' : '#214c3d', background: this.tab === key ? '#214c3d' : 'white' } }, label))),
                h('div', { style: grid }, [select('酒店', this.hotelId, value => { this.hotelId = value; }, this.hotels.map(hotel => [String(hotel.id), hotel.name || `酒店${hotel.id}`])), field('时期开始', this.start, value => { this.start = value; }, { type: 'date' }), field('时期结束', this.end, value => { this.end = value; }, { type: 'date' }), select('入住来源', this.platform, value => { this.platform = value; }, [['pms', 'PMS匿名来源'], ['ctrip', '携程渠道'], ['meituan', '美团渠道'], ['manual', '人工客群来源']])]), button('刷新当前范围', () => this.load()),
                this.loading ? small('正在加载当前范围…') : null, this.error ? h('p', { role: 'alert', style: { color: '#a83232' } }, this.error) : null, this.notice ? h('p', { role: 'status' }, this.notice) : null,
            ];
            if (this.overview) children.push(
                this.tab === 'repeat' ? card('时期内复购', [repeat ? h('div', [h('p', { style: { fontSize: '24px', fontVariantNumeric: 'tabular-nums' } }, repeat.rate == null ? '复购率：未具备计算条件' : `复购率：${(repeat.rate * 100).toFixed(1)}%`), small(`观察分子 ${repeat.numerator} / 观察分母 ${repeat.denominator}；已完成入住 ${repeat.observed_completed_stays} 次`), small(repeat.denominator_definition), small(repeat.evidence_boundary), ...(repeat.data_gaps || []).map(gap => small(gapLabels[gap] || gap))]) : small('未取得客群口径'), h('div', { style: grid }, [field('来源声明的同期唯一客人数', coverage.expected_guests, value => { coverage.expected_guests = value; }, { type: 'number', min: 0 }), select('来源覆盖质量', coverage.source_quality, value => { coverage.source_quality = value; }, [['unverified', '尚未核验'], ['partial', '部分覆盖'], ['complete', '明确完整覆盖']]), field('覆盖证据引用', coverage.source_reference, value => { coverage.source_reference = value; })]), button('保存来源覆盖与分母', () => this.saveCoverage(), true)]) : null,
                this.tab === 'repeat' ? card('匿名入住事件导入与更正', [small('只接收来源预先匿名化的 guest_hash。每条 event_key 代表一次入住；重复导入不重复计数。更正需 expected_revision 与 correction_reason，撤销用 status=void。'), field('入住来源证据引用', this.staySource, value => { this.staySource = value; }), field('匿名事件JSON数组', this.stayText, value => { this.stayText = value; }, { multiline: true, placeholder: '[{"event_key":"来源事件键","guest_hash":"64位匿名哈希","stay_date":"YYYY-MM-DD","status":"completed","expected_revision":0}]' }), button('导入并精确回读', () => this.importStays(), true),
                    h('div', { style: { overflowX: 'auto' } }, [h('table', { style: { width: '100%', borderCollapse: 'collapse' } }, [h('thead', h('tr', ['事件键', '日期', '状态', '版本/历史'].map(label => h('th', { style: { textAlign: 'left', padding: '8px' } }, label)))), h('tbody', this.overview.stay_events.map(record => h('tr', { key: record.id }, [h('td', record.document.event_key), h('td', record.business_date), h('td', record.document.status), h('td', button(`v${record.revision} 历史`, () => this.history(record)))])))])])]) : null,
                this.tab === 'feedback' ? card(form.expected_revision ? `编辑反馈 ${form.case_key} v${form.expected_revision}` : '登记宾客反馈 / 客诉', [small('先脱敏，不登记姓名、电话和其他宾客身份。责任人必须拥有当前酒店运营权限。'), h('div', { style: grid }, [field('记录键', form.case_key, value => { form.case_key = value; }, { readOnly: form.expected_revision > 0 }), field('发生日期', form.incident_date, value => { form.incident_date = value; }, { type: 'date' }), select('类型', form.category, value => { form.category = value; }, [['complaint', '客诉'], ['feedback', '反馈']]), select('责任人', String(form.owner_user_id), value => { form.owner_user_id = value; }, [['', this.availableOwners.length ? '请选择' : '本店暂无可操作人员，请配置员工权限'], ...this.availableOwners.map(user => [String(user.id), user.name || user.username || `用户${user.id}`])]), field('处理期限（上海时间）', form.due_at, value => { form.due_at = value; }, { type: 'datetime-local' }), field('反馈来源引用', form.source_reference, value => { form.source_reference = value; })]), field('脱敏反馈内容', form.summary, value => { form.summary = value; }, { multiline: true }), field('证据引用（每行一条）', form.evidence, value => { form.evidence = value; }, { multiline: true }), form.expected_revision ? field('本次编辑原因', form.edit_reason, value => { form.edit_reason = value; }) : null, button('保存反馈并回读', () => this.saveCase(), true), form.expected_revision ? button('取消编辑', () => { this.caseForm = emptyCase(this.end); }) : null,
                    this.overview.feedback.length ? h('ul', { style: { paddingLeft: '20px' } }, this.overview.feedback.map(record => h('li', { key: record.id, style: { marginTop: '12px', overflowWrap: 'anywhere' } }, [h('strong', `${record.record_key} · ${{ open: '待处理', in_progress: '处理中', closed: '已确认关闭' }[record.document.status] || record.document.status}`), small(`${record.document.incident_date}｜责任人 ${record.document.owner_user_id}｜期限 ${record.document.due_at}`), h('p', record.document.summary), button('查看完整历史', () => this.history(record)), record.document.status !== 'closed' ? button('编辑登记', () => this.editCase(record)) : null]))) : small('当前时期无手工登记反馈记录。')]) : null,
                this.tab === 'feedback' ? card('追加处理事实与确认关闭', [h('div', { style: grid }, [select('反馈记录', this.selectedCase, value => { this.selectedCase = value; fact.confirmation = false; }, [['', '请选择'], ...this.overview.feedback.map(record => [record.record_key, record.record_key])]), select('事实类型', fact.action, value => { fact.action = value; fact.confirmation = false; }, [['handling', '追加处理事实'], ['close', '人工确认关闭'], ['reopen', '重开并说明']]), field('事实发生时间（上海时间）', fact.occurred_at, value => { fact.occurred_at = value; }, { type: 'datetime-local' })]), field('处理事实 / 关闭确认事实', fact.note, value => { fact.note = value; }, { multiline: true }), field('处理证据引用（每行一条）', fact.evidence, value => { fact.evidence = value; }, { multiline: true }), fact.action === 'close' ? h('div', [small('关闭表示已记录确认事实，不代表满意度提高或经营效果。必须有证据且由人工确认。'), select('确认者角色', fact.confirmed_by_role, value => { fact.confirmed_by_role = value; }, [['', '请选择'], ['guest', '已取得宾客确认'], ['manager', '已取得管理人员确认']]), h('label', { style: { display: 'flex', alignItems: 'center', minHeight: '44px', gap: '8px' } }, [h('input', { type: 'checkbox', checked: fact.confirmation, disabled: this.busy, onChange: event => { fact.confirmation = event.target.checked; } }), '我确认已实际取得上述确认，并有对应证据'])]) : null, button('追加事实并精确回读', () => this.saveFact(), true)]) : null,
                this.tab === 'entry' ? card('鉴权反馈入口二维码', [small('这是需登录且有酒店运营权限的员工反馈入口，不是公开宾客提交链接。二维码不会授予权限或开启匿名写入。'), h('div', { style: grid }, [field('入口键（最多32位）', this.entry.entry_key, value => { this.entry.entry_key = value; }, { maxLength: 32, readOnly: this.entry.expected_revision > 0 }), field('房间/区域标签', this.entry.room_label, value => { this.entry.room_label = value; }), field('入口名称', this.entry.label, value => { this.entry.label = value; })]), h('label', { style: { minHeight: '44px', display: 'flex', gap: '8px', alignItems: 'center' } }, [h('input', { type: 'checkbox', checked: this.entry.enabled, disabled: this.busy, onChange: event => { this.entry.enabled = event.target.checked; } }), '启用鉴权员工入口']), button('保存入口配置', () => this.saveEntry(), true), h('div', { style: grid }, this.overview.feedback_entries.map(record => { const qr = this.qrData(record); return h('div', { key: record.id, style: { overflowWrap: 'anywhere' } }, [h('h4', `${record.document.room_label} · ${record.document.label}`), qr.image ? h('img', { src: qr.image, alt: '需登录的员工反馈入口二维码', width: 245, height: 245, style: { maxWidth: '100%', height: 'auto' } }) : small(qr.error), qr.url ? h('a', { href: qr.url }, '打开已鉴权反馈入口') : null, button('编辑入口', () => this.editEntry(record)), button('入口历史', () => this.history(record))]); }))]) : null,
                this.historyRows.length ? card('精确历史回读', [small('以下为不可覆盖的保存版本；更正和处理事实均保留原版本。'), ...this.historyRows.map(record => h('details', { key: record.id, open: true }, [h('summary', `记录${record.id} · v${record.revision} · ${record.created_at} · 操作人${record.created_by}`), h('pre', { style: { whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', fontSize: '12px' } }, JSON.stringify(record.document, null, 2))]))]) : null,
            );
            return h('div', { style: { display: 'grid', gap: '20px', color: '#1c3028', fontSize: '14px', lineHeight: '22px', minWidth: 0 } }, children);
        },
    };
})();
