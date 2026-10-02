(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const shanghaiParts = date => Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit',
        minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).formatToParts(date).map(part => [part.type, part.value]));
    const today = () => { const p = shanghaiParts(new Date()); return `${p.year}-${p.month}-${p.day}`; };
    const dateAfter = (date, days) => new Date(Date.parse(`${date}T00:00:00+08:00`) + days * 86400000).toLocaleDateString('en-CA', { timeZone: 'Asia/Shanghai' });
    const blankForm = () => ({ hotelId: '', roomTypeId: '0', stayDate: '', capturedAt: '', rooms: '', revenue: '', cancelled: '', gross: '', sourceRef: '', attested: false, correctionId: '', correctionCapturedAt: '' });
    const statuses = { ready: '可比', partial: '部分完成', blocked: '缺少基线', missing: '未取得', stale: '快照过期',
        late: '时点后才采集', approximate: '近时点，不能作24小时基线', unverified: '未核验', not_comparable: '口径不可比' };
    const gapLabels = { fixed_observation_time_not_reached: '尚未到固定观察时点', exact_24h_same_scope_baseline_required: '缺少严格24小时同范围基线',
        on_books_fact_scope_changed: '前后快照指标范围不同', cancellation_counter_reset_or_mismatch: '累计取消计数重置或口径变化',
        cumulative_cancel_room_nights_missing: '累计取消间夜缺失', cancellation_gross_booking_base_missing: '累计毛预订基数缺失',
        on_books_room_revenue_missing: '房费缺失', on_books_room_nights_missing: '在手间夜缺失',
        cumulative_cancel_room_nights_exceeds_gross_booking_room_nights: '累计取消超过毛预订，须重建基线',
        previous_cumulative_cancel_room_nights_exceeds_gross_booking_room_nights: '基线累计取消超过毛预订，须核对',
    };
    const formatNumber = value => value === null || value === undefined ? '未取得' : Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 });
    const parseNumber = value => {
        const text = String(value ?? '').trim();
        if (text === '') return null;
        if (!/^\d+(?:\.\d{1,4})?$/.test(text)) throw new Error('间夜和金额须为非负数，最多四位小数；未知请留空。');
        return Number(text);
    };
    const metricFields = ['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'];
    const sha256 = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
    const normalizedCapture = value => {
        const match = /^(\d{4})-(\d{1,2})-(\d{1,2}) (\d{1,2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?$/.exec(String(value ?? '').trim().replace('T', ' '));
        return match ? `${match[1]}-${match[2].padStart(2, '0')}-${match[3].padStart(2, '0')} ${match[4].padStart(2, '0')}:${match[5]}:${match[6] || '00'}.${(match[7] || '').padEnd(6, '0')}` : null;
    };
    const normalizedMetric = value => value === null || value === undefined || value === '' ? null
        : ['number', 'string'].includes(typeof value) && Number.isFinite(Number(value)) && Number(value) >= 0 ? Math.round(Number(value) * 10000) / 10000 : NaN;
    const expectedSnapshot = async row => {
        if (!window.crypto?.subtle) throw new Error('来源指纹核对不可用，请在本机安全页面重试。');
        // This source fingerprint contract is authored by BookingDemandPlanningService; content digests are read back from the server.
        const sourceRef = String(row.source_ref ?? '').replace(/^[\x00\t\n\v\r ]+|[\x00\t\n\v\r ]+$/g, '');
        const source = new TextEncoder().encode('on-books-source-v1|' + sourceRef);
        const hash = await window.crypto.subtle.digest('SHA-256', source);
        return { hotel_id: Number(row.hotel_id), source_hotel_id: Number(row.hotel_id), room_type_id: Number(row.room_type_id || 0),
            platform: String(row.platform ?? '').trim().toLowerCase(), fact_scope: String(row.fact_scope ?? '').trim().toLowerCase(),
            stay_date: String(row.stay_date ?? '').trim(), captured_at: normalizedCapture(row.captured_at), source_method: 'manual_file_import',
            source_ref_hash: Array.from(new Uint8Array(hash), byte => byte.toString(16).padStart(2, '0')).join(''),
            quality_status: ['true', '1', 'yes', 'on'].includes(String(row.operator_attested ?? '').trim().toLowerCase()) ? 'manual_confirmed' : 'unverified',
            supersedes_snapshot_id: Number(row.supersedes_snapshot_id || 0) || null,
            ...Object.fromEntries(metricFields.map(field => [field, normalizedMetric(row[field])])) };
    };
    const validSnapshot = (snapshot, expected, tenantId) => snapshot?.contract_version === 'room_type_on_books_snapshot.v1'
        && Number.isSafeInteger(Number(snapshot.id)) && Number(snapshot.id) > 0 && Number(snapshot.tenant_id) === tenantId
        && Number(snapshot.readback_verified) === 1 && snapshot.external_write_count === 0
        && sha256(snapshot.content_digest) && sha256(snapshot.source_ref_hash) && sha256(snapshot.idempotency_key)
        && typeof snapshot.room_type_name === 'string' && expected.captured_at !== null
        && Object.entries(expected).every(([field, value]) => Object.hasOwn(snapshot, field)
            && (metricFields.includes(field) ? normalizedMetric(snapshot[field]) === value
                : ['hotel_id', 'source_hotel_id', 'room_type_id'].includes(field) ? Number(snapshot[field]) === value
                    : field === 'supersedes_snapshot_id' ? (Number(snapshot[field] || 0) || null) === value
                        : field === 'captured_at' ? normalizedCapture(snapshot[field]) === value : snapshot[field] === value));

    components.BookingMonitoringPanel = {
        name: 'BookingMonitoringPanel',
        props: { hotels: { type: Array, default: () => [] }, selectedHotelId: { type: [String, Number], default: '' },
            request: { type: Function, required: true }, canExecute: { type: Boolean, default: false }, workspaceSettings: { type: Object, default: () => ({}) } },
        data() { return { selectedIds: [], businessDate: today(), platform: this.workspaceSettings?.preferred_platform || 'ctrip', fixedTime: this.workspaceSettings?.booking_fixed_time || '09:00', horizonDays: String(this.workspaceSettings?.booking_horizon_days || 7),
            overview: null, loading: false, error: '', saving: false, notice: '', receipt: null, seq: 0, writeSeq: 0,
            form: blankForm(), importText: '', importedFileName: '', expandedHistory: '' }; },
        computed: {
            normalizedHotels() { return this.hotels.filter(hotel => Number(hotel?.id) > 0); },
            scopeKey() { return [this.selectedIds.map(Number).sort((a, b) => a - b).join(','), this.businessDate, this.platform, this.fixedTime, this.horizonDays].join('|'); },
            selectedRoomTypes() { return (this.overview?.room_types || []).filter(room => Number(room.hotel_id) === Number(this.form.hotelId)); },
        },
        watch: {
            workspaceSettings: { deep: true, handler(value) {
                if (['ctrip', 'meituan'].includes(value?.preferred_platform)) this.platform = value.preferred_platform;
                if (/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value?.booking_fixed_time || '')) this.fixedTime = value.booking_fixed_time;
                if (Number.isInteger(value?.booking_horizon_days) && value.booking_horizon_days >= 1 && value.booking_horizon_days <= 30) this.horizonDays = String(value.booking_horizon_days);
            } },
            selectedHotelId: { immediate: true, handler(value, previous) {
                if (!this.normalizedHotels.some(hotel => String(hotel.id) === String(value))) return;
                if (previous !== undefined || this.selectedIds.length === 0) {
                    this.selectedIds = [String(value)]; this.resetDrafts(); this.form.hotelId = String(value); void this.load();
                }
            } },
            hotels: { immediate: true, handler() {
                const valid = this.selectedIds.filter(id => this.normalizedHotels.some(hotel => String(hotel.id) === String(id)));
                if (valid.length === 0 && this.normalizedHotels.length) valid.push(String(this.normalizedHotels[0].id));
                if (valid.join(',') !== this.selectedIds.join(',')) { this.selectedIds = valid; this.resetDrafts(); void this.load(); }
            } },
            scopeKey(value, previous) { if (value !== previous) { this.seq += 1; this.overview = null; this.resetDrafts(); void this.load(); } },
        },
        methods: {
            resetDrafts() { this.writeSeq += 1; this.form = blankForm(); this.form.hotelId = String(this.selectedIds[0] || '');
                this.form.stayDate = this.businessDate ? dateAfter(this.businessDate, 1) : ''; this.importText = ''; this.importedFileName = '';
                this.receipt = null; this.notice = ''; this.error = ''; this.expandedHistory = ''; },
            statusText(value) { return statuses[value] || value || '未取得'; },
            gapText(value) {
                if (gapLabels[value]) return gapLabels[value];
                const match = /^(current|baseline)_slot_(.+)$/.exec(value);
                return match ? `${match[1] === 'current' ? '观察快照' : '基线快照'}：${this.statusText(match[2])}` : '快照数据需核对';
            },
            number: formatNumber,
            async load() {
                const seq = ++this.seq;
                const key = this.scopeKey;
                this.overview = null; this.error = '';
                if (this.selectedIds.length === 0) { this.loading = false; return; }
                this.loading = true;
                try {
                    const ids = this.selectedIds.map(Number).sort((a, b) => a - b);
                    const params = new URLSearchParams({ hotel_ids: ids.join(','), business_date: this.businessDate,
                        platform: this.platform, fixed_time: this.fixedTime, horizon_days: String(this.horizonDays) });
                    const response = await this.request(`/booking-monitoring/overview?${params}`, { businessContext: { hotelId: ids[0] } });
                    if (seq !== this.seq || key !== this.scopeKey) return;
                    const data = response?.data;
                    if (response?.code !== 200) throw new Error(response?.message || '预订监测读取失败。');
                    if (data?.contract_version !== 'booking_fixed_baseline_monitor.v1'
                        || JSON.stringify(data.hotel_ids) !== JSON.stringify(ids) || data.platform !== this.platform
                        || data.business_date !== this.businessDate || data.fixed_time !== this.fixedTime
                        || Number(data.horizon_days) !== Number(this.horizonDays) || data.timezone !== 'Asia/Shanghai'
                        || data.boundaries?.external_write_count !== 0 || !Array.isArray(data.cells)) throw new Error('预订监测返回范围不匹配，请重试。');
                    this.overview = data;
                } catch (error) { if (seq === this.seq && key === this.scopeKey) { this.overview = null; this.error = error?.message || '预订监测读取失败'; } }
                finally { if (seq === this.seq) this.loading = false; }
            },
            toggleHotel(id, checked) {
                const value = String(id);
                this.selectedIds = checked ? [...new Set([...this.selectedIds, value])] : this.selectedIds.filter(item => item !== value);
            },
            async saveForm() {
                try {
                    const f = this.form;
                    if (!f.capturedAt || !f.sourceRef.trim()) throw new Error('请填写实际捕获时间和来源引用；固定观察时点不能代填采集时间。');
                    const row = { hotel_id: Number(f.hotelId), room_type_id: Number(f.roomTypeId), platform: this.platform,
                        fact_scope: ['ctrip', 'meituan'].includes(this.platform) ? 'ota_channel' : 'accommodation_room_fee',
                        stay_date: f.stayDate, captured_at: f.correctionId && f.correctionCapturedAt ? f.correctionCapturedAt : f.capturedAt.replace('T', ' '), on_books_room_nights: parseNumber(f.rooms),
                        on_books_room_revenue: parseNumber(f.revenue), cumulative_cancel_room_nights: parseNumber(f.cancelled),
                        gross_booking_room_nights: parseNumber(f.gross), source_ref: f.sourceRef.trim(), operator_attested: f.attested,
                        supersedes_snapshot_id: f.correctionId ? Number(f.correctionId) : null };
                    if (row.on_books_room_nights === null) throw new Error('请填写实际在手间夜，未知不能按0保存。');
                    await this.saveRows([row]);
                } catch (error) { this.error = error.message; }
            },
            async saveImport() {
                try {
                    const payload = JSON.parse(this.importText);
                    const rows = Array.isArray(payload) ? payload : payload.rows;
                    if (!Array.isArray(rows) || rows.length === 0 || rows.length > 200) throw new Error('每次须导入1至200条JSON快照。');
                    await this.saveRows(rows);
                } catch (error) { this.error = error?.message || 'JSON快照解析失败'; }
            },
            async readFile(event) {
                const file = event?.target?.files?.[0];
                if (!file) return;
                const key = this.scopeKey;
                try {
                    if (file.size > 262144) throw new Error('快照文件须小于256KB。');
                    const value = await file.text();
                    if (key !== this.scopeKey) return;
                    JSON.parse(value); this.importText = value; this.importedFileName = file.name; this.error = '';
                } catch (error) { if (key === this.scopeKey) { this.importText = ''; this.importedFileName = ''; this.error = error.message; } }
                finally { if (event.target) event.target.value = ''; }
            },
            async saveRows(rows) {
                if (!this.canExecute || this.saving) return;
                const ids = this.selectedIds.map(Number);
                if (rows.some(row => !row || !ids.includes(Number(row.hotel_id)) || row.platform !== this.platform)) throw new Error('导入酒店或平台与当前选择不一致，请先切换到对应范围。');
                const key = this.scopeKey;
                const seq = ++this.writeSeq;
                this.saving = true; this.error = ''; this.notice = ''; this.receipt = null;
                let postCompleted = false;
                try {
                    const expected = await Promise.all(rows.map(expectedSnapshot));
                    if (seq !== this.writeSeq || key !== this.scopeKey) return;
                    const response = await this.request('/booking-monitoring/snapshots', { method: 'POST', body: JSON.stringify({ rows }), businessContext: { hotelId: ids[0] } });
                    if (seq !== this.writeSeq || key !== this.scopeKey) return;
                    const data = response?.data;
                    if (response?.code !== 200) throw new Error(response?.message || '快照保存失败。');
                    postCompleted = true;
                    const tenantId = Number(data?.tenant_id);
                    if (data?.contract_version !== 'booking_fixed_baseline_monitor.v1'
                        || data.save_status !== 'saved_readback_verified' || data.readback_verified !== true || data.external_write_count !== 0
                        || !Number.isSafeInteger(tenantId) || tenantId <= 0
                        || data.row_count !== rows.length || !Array.isArray(data.snapshots)
                        || data.snapshots.length !== rows.length || data.snapshots.some((snapshot, index) => !validSnapshot(snapshot, expected[index], tenantId))) throw new Error('保存回读与提交内容或范围不匹配。');
                    for (let offset = 0; offset < data.snapshots.length; offset += 10) {
                        const batch = data.snapshots.slice(offset, offset + 10);
                        const rereads = await Promise.all(batch.map(snapshot => this.request(`/booking-monitoring/snapshots/${snapshot.id}?hotel_id=${snapshot.hotel_id}`,
                            { businessContext: { hotelId: Number(snapshot.hotel_id) } })));
                        if (seq !== this.writeSeq || key !== this.scopeKey) return;
                        if (rereads.some((reread, index) => reread?.code !== 200 || !validSnapshot(reread.data, expected[offset + index], tenantId)
                            || Number(reread.data.id) !== Number(batch[index].id) || reread.data.content_digest !== batch[index].content_digest
                            || reread.data.idempotency_key !== batch[index].idempotency_key || reread.data.room_type_name !== batch[index].room_type_name)) throw new Error('独立快照回读与保存结果不匹配。');
                    }
                    this.receipt = data;
                    this.notice = `已保存并精确回读${data.row_count}条快照。来源状态由服务端保留；需覆盖固定时点才会形成24小时比较。`;
                    await this.load();
                } catch (error) { if (seq === this.writeSeq && key === this.scopeKey) this.error = (error?.message || '快照保存失败')
                    + (postCompleted ? ' 保存已响应，但精确回读未通过；请先按快照ID核对，当前输入保留。' : ''); }
                finally { this.saving = false; }
            },
            correct(snapshot) {
                this.form = { hotelId: String(snapshot.hotel_id), roomTypeId: String(snapshot.room_type_id), stayDate: snapshot.stay_date,
                    capturedAt: snapshot.captured_at.replace(' ', 'T').slice(0, 19), rooms: String(snapshot.on_books_room_nights ?? ''),
                    revenue: String(snapshot.on_books_room_revenue ?? ''), cancelled: String(snapshot.cumulative_cancel_room_nights ?? ''),
                    gross: String(snapshot.gross_booking_room_nights ?? ''), sourceRef: '', attested: false, correctionId: String(snapshot.id), correctionCapturedAt: snapshot.captured_at };
                this.notice = '已载入更正草稿；请填写新的来源引用并核对。原快照保留，更正另存一条。';
            },
            async loadCorrection() {
                const id = Number(this.form.correctionId);
                const hotelId = Number(this.form.hotelId);
                if (!Number.isSafeInteger(id) || id <= 0 || !this.selectedIds.map(Number).includes(hotelId)) { this.error = '请填写当前酒店的快照ID。'; return; }
                const key = this.scopeKey;
                try {
                    const response = await this.request(`/booking-monitoring/snapshots/${id}?hotel_id=${hotelId}`, { businessContext: { hotelId } });
                    if (key !== this.scopeKey || Number(this.form.correctionId) !== id) return;
                    const snapshot = response?.data;
                    if (response?.code !== 200) throw new Error(response?.message || '快照回读失败。');
                    if (snapshot?.contract_version !== 'room_type_on_books_snapshot.v1'
                        || snapshot.id !== id || snapshot.hotel_id !== hotelId || snapshot.platform !== this.platform
                        || snapshot.external_write_count !== 0 || Number(snapshot.readback_verified) !== 1) throw new Error('快照回读范围不匹配。');
                    this.correct(snapshot); this.error = '';
                } catch (error) { if (key === this.scopeKey) this.error = error.message; }
            },
            downloadTemplate() {
                const payload = { contract_version: 'booking_fixed_baseline_monitor.v1', rows: [{ hotel_id: Number(this.form.hotelId || this.selectedIds[0]),
                    room_type_id: Number(this.form.roomTypeId || 0), platform: this.platform, fact_scope: ['ctrip', 'meituan'].includes(this.platform) ? 'ota_channel' : 'accommodation_room_fee',
                    stay_date: this.form.stayDate || dateAfter(this.businessDate, 1), captured_at: '', on_books_room_nights: null,
                    on_books_room_revenue: null, cumulative_cancel_room_nights: null, gross_booking_room_nights: null, source_ref: '', operator_attested: false }] };
                const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' }));
                const link = document.createElement('a'); link.href = url; link.download = '宿析-固定时点预订快照空白模板.json'; link.click(); URL.revokeObjectURL(url);
            },
        },
        render() {
            const h = Vue.h;
            const input = (key, label, attrs = {}) => h('label', { class: 'grid gap-1 text-xs text-slate-600' }, [label,
                h('input', { class: 'rounded-lg border p-2 text-sm', value: this.form[key], ...attrs,
                    onInput: event => { this.form[key] = event.target.value; } })]);
            const option = (value, label) => h('option', { value: String(value) }, label);
            const select = (label, value, change, options) => h('label', { class: 'grid gap-1 text-xs text-slate-600' }, [label,
                h('select', { class: 'rounded-lg border p-2 text-sm', value, onChange: change, 'aria-label': label }, options)]);
            const slotText = slot => [this.statusText(slot.status), slot.captured_at ? slot.captured_at.slice(0, 19) : '没有固定时点快照',
                ({ manual_confirmed: '人工核对', verified: '来源核验', unverified: '来源未核验', partial: '来源不完整', blocked: '来源阻塞' })[slot.quality_status] || '',
                slot.evidence_ref ? `依据 ${slot.evidence_ref}` : ''].filter(Boolean).join(' · ');
            const td = value => h('td', { class: 'px-3 py-3 align-top border-t border-slate-100 whitespace-nowrap' }, value);
            const data = this.overview;
            return h('section', { class: 'rounded-2xl border border-slate-200 bg-white p-5 mt-4', 'data-testid': 'booking-fixed-monitor' }, [
                h('div', { class: 'flex flex-wrap items-start justify-between gap-3' }, [
                    h('div', [h('h3', { class: 'font-bold text-slate-900' }, '固定时点预订监测'),
                        h('p', { class: 'mt-1 text-xs text-slate-500' }, '上海固定时点 · 24小时净新增 · 同提前期历史 · 授权多店房型')]),
                    h('button', { type: 'button', class: 'rounded-lg border px-3 py-2 text-xs', disabled: this.loading, onClick: () => this.load() }, this.loading ? '读取中…' : '刷新当前范围'),
                ]),
                h('div', { class: 'mt-4 grid gap-3 md:grid-cols-4' }, [
                    h('label', { class: 'grid gap-1 text-xs text-slate-600' }, ['观察日期', h('input', { type: 'date', value: this.businessDate, class: 'rounded-lg border p-2 text-sm', onInput: event => { this.businessDate = event.target.value; } })]),
                    select('平台/来源', this.platform, event => { this.platform = event.target.value; }, [option('ctrip', '携程渠道'), option('meituan', '美团渠道'), option('dingdandao_pms', 'PMS房费'), option('manual_all_channels', '人工全渠道房费')]),
                    h('label', { class: 'grid gap-1 text-xs text-slate-600' }, ['固定观察时点（上海）', h('input', { type: 'time', step: 60, value: this.fixedTime, class: 'rounded-lg border p-2 text-sm', onInput: event => { this.fixedTime = event.target.value; } })]),
                    select('未来入住日', this.horizonDays, event => { this.horizonDays = event.target.value; }, Array.from({ length: 30 }, (_, index) => option(index + 1, index === 0 ? '明天' : `未来${index + 1}天`))),
                ]),
                h('fieldset', { class: 'mt-3 flex flex-wrap gap-3 text-xs' }, [h('legend', { class: 'text-slate-500 mb-2' }, '选择同一租户的授权酒店（最多20家）'),
                    ...this.normalizedHotels.map(hotel => h('label', { class: 'flex items-center gap-2' }, [h('input', { type: 'checkbox', checked: this.selectedIds.includes(String(hotel.id)), onChange: event => this.toggleHotel(hotel.id, event.target.checked) }), String(hotel.name || `酒店${hotel.id}`)]))]),
                this.error ? h('p', { role: 'alert', class: 'mt-3 text-sm text-red-700', 'data-testid': 'booking-monitor-error' }, this.error) : null,
                this.notice ? h('p', { role: 'status', class: 'mt-3 text-sm text-emerald-700', 'data-testid': 'booking-monitor-receipt-notice' }, this.notice) : null,
                this.loading ? h('p', { class: 'mt-4 text-sm text-slate-500' }, '当前范围读取中，旧范围结果已清除。') : null,
                !this.selectedIds.length ? h('p', { class: 'mt-4 text-sm text-slate-500' }, '请选择至少一家授权酒店。') : null,
                data ? h('div', { class: 'mt-4' }, [
                    h('p', { class: 'text-xs text-slate-600', 'data-testid': 'booking-monitor-scope' }, `状态：${this.statusText(data.status)}；观察 ${data.observation_time}；基线 ${data.baseline_time}；可比 ${data.ready_cell_count}/${data.cell_count}格。`),
                    h('p', { class: 'mt-2 text-xs text-slate-500' }, '主24小时指标要求两个固定时点均有同范围快照。近时点、迟到、过期与未核验单独显示；汇总旧数据不拆成房型，不与房型再次相加。OTA仅说明当前渠道。'),
                    h('div', { class: 'mt-3 overflow-x-auto' }, [h('table', { class: 'min-w-full text-left text-xs', 'data-testid': 'booking-monitor-matrix' }, [
                        h('thead', { class: 'text-slate-500' }, [h('tr', ['酒店', '房型', '入住日 / 提前期', '观察快照', '基线快照', '在手间夜', '24h净新增', '24h房费变化', '同提前期历史', '状态'].map(label => h('th', { class: 'px-3 py-2 whitespace-nowrap' }, label)))]),
                        h('tbody', data.cells.map(cell => h('tr', { key: `${cell.hotel_id}-${cell.room_type_id}-${cell.stay_date}` }, [
                            td(cell.hotel_name), td(cell.room_type_name), td(`${cell.stay_date} / ${cell.lead_time_days}天`), td(slotText(cell.current)), td(slotText(cell.baseline)),
                            td(this.number(cell.current.on_books_room_nights)), td(this.number(cell.net_pickup_24h_room_nights)), td(this.number(cell.room_revenue_delta_24h)),
                            td(h('details', [h('summary', { class: 'cursor-pointer' }, `前4周中位数 ${this.number(cell.same_lead_time_median_room_nights)}；覆盖${cell.history_coverage}/4`),
                                h('p', { class: 'mt-1' }, `与历史中位数差 ${this.number(cell.delta_vs_same_lead_time_median)}`),
                                ...cell.history.map(item => h('p', { class: 'mt-1' }, `${item.stay_date}：${this.number(item.observed_on_books_room_nights)} · ${item.comparable ? '同提前期同范围' : '当前范围缺失或不可比'} · ${slotText(item.slot)}`))])),
                            td([this.statusText(cell.status), ...(cell.data_gaps || []).map(gap => this.gapText(gap))].join(' · ')),
                        ]))),
                    ])]),
                ]) : null,
                this.canExecute ? h('details', { class: 'mt-5 border-t pt-4' }, [h('summary', { class: 'cursor-pointer font-semibold text-sm' }, '保存或导入真实快照'),
                    h('p', { class: 'mt-2 text-xs text-slate-500' }, '仅录入授权来源的实际观测；间夜和金额最多保留四位小数，未知留空。人工核对仍是人工来源。更正保留原快照，另存并回读。'),
                    h('form', { class: 'mt-3 grid gap-3 md:grid-cols-3', 'data-testid': 'booking-monitor-form', onSubmit: event => { event.preventDefault(); void this.saveForm(); } }, [
                        select('快照酒店', this.form.hotelId, event => { this.form.hotelId = event.target.value; this.form.roomTypeId = '0'; this.form.correctionId = ''; }, this.normalizedHotels.filter(hotel => this.selectedIds.includes(String(hotel.id))).map(hotel => option(hotel.id, hotel.name))),
                        select('房型（0保留汇总）', this.form.roomTypeId, event => { this.form.roomTypeId = event.target.value; }, [option(0, '酒店汇总'), ...this.selectedRoomTypes.map(room => option(room.id, `${room.name} · ID ${room.id}`))]),
                        input('stayDate', '实际入住日', { type: 'date', required: true }), input('capturedAt', '实际捕获时间（上海）', { type: 'datetime-local', step: 1, required: true, disabled: Boolean(this.form.correctionId && this.form.correctionCapturedAt) }),
                        input('rooms', '在手间夜（必填，实际0可填写）', { inputmode: 'decimal', required: true }), input('revenue', '在手房费（未知留空）', { inputmode: 'decimal' }),
                        input('cancelled', '累计取消间夜（未知留空）', { inputmode: 'decimal' }), input('gross', '累计毛预订间夜（未知留空）', { inputmode: 'decimal' }),
                        input('sourceRef', '授权来源引用或文件指纹', { required: true }), input('correctionId', '更正原快照ID（选填）', { inputmode: 'numeric' }),
                        h('button', { type: 'button', class: 'rounded-lg border px-3 py-2 text-xs', disabled: !this.form.correctionId || this.saving, onClick: () => this.loadCorrection() }, '按ID回读并载入更正'),
                        h('label', { class: 'flex items-start gap-2 text-xs md:col-span-2' }, [h('input', { type: 'checkbox', checked: this.form.attested, onChange: event => { this.form.attested = event.target.checked; } }), '我已核对酒店、房型、平台、入住日、实际捕获时间和来源。未勾选保存为未核验，不能进入24小时比较。']),
                        h('button', { type: 'submit', class: 'operating-finance-primary-action rounded-xl px-4 py-2.5 text-sm text-white disabled:opacity-50', disabled: this.saving }, this.saving ? '保存并回读中…' : '保存并精确回读'),
                    ]),
                    h('div', { class: 'mt-4 grid gap-2' }, [h('label', { class: 'text-xs text-slate-600' }, ['批量JSON文件（1至200行）', h('input', { type: 'file', accept: '.json,application/json', onChange: event => this.readFile(event), class: 'block mt-1 text-xs' })]),
                        h('textarea', { class: 'rounded-lg border p-2 text-xs w-full', rows: 5, value: this.importText, placeholder: '下载空白模板，填写实际观测后导入；未知字段保留null。', onInput: event => { this.importText = event.target.value; } }),
                        h('div', { class: 'flex flex-wrap gap-2' }, [h('button', { type: 'button', class: 'rounded-lg border px-3 py-2 text-xs', onClick: () => this.downloadTemplate() }, '下载空白模板'),
                            h('button', { type: 'button', class: 'rounded-lg border px-3 py-2 text-xs', disabled: this.saving || !this.importText.trim(), onClick: () => this.saveImport() }, '校验、保存整批并回读')]),
                    ]),
                ]) : h('p', { class: 'mt-4 text-xs text-slate-500' }, '当前账号只能查看监测结果。'),
                this.receipt ? h('div', { class: 'mt-4 text-xs', 'data-testid': 'booking-monitor-save-receipt' }, this.receipt.snapshots.map(snapshot => h('p', { class: 'mt-2' }, [
                    `快照#${snapshot.id} · 酒店${snapshot.hotel_id} · ${snapshot.room_type_name} · ${snapshot.stay_date} · 捕获${snapshot.captured_at.slice(0, 19)} · 在手${this.number(snapshot.on_books_room_nights)} · ${snapshot.quality_status === 'manual_confirmed' ? '人工核对' : '未核验'} · 回读指纹${snapshot.content_digest.slice(0, 12)}`,
                    this.canExecute ? h('button', { type: 'button', class: 'ml-2 underline', onClick: () => this.correct(snapshot) }, '追加更正') : null,
                ]))) : null,
            ]);
        },
    };
})();
