(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const key = () => `guest-tool-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const escape = text => String(text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const download = (content, filename, type) => {
        const url = URL.createObjectURL(new Blob([content], { type })); const a = document.createElement('a');
        a.href = url; a.download = filename; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    components.GuestOperationsTools = {
        name: 'GuestOperationsTools',
        props: { request: { type: Function, required: true }, hotelId: [String, Number], start: String, end: String, tab: String, rooms: { type: Array, default: () => [] }, entries: { type: Array, default: () => [] }, imports: { type: Array, default: () => [] }, owners: { type: Array, default: () => [] }, canExecute: Boolean },
        emits: ['saved'],
        data: () => ({ roomText: '', selected: [], owner: '', label: '房间宾客反馈', enabled: true, links: [], busy: false, error: '', notice: '', file: null, columns: [], mapping: { event: '', identity: '', stay_date: '', status: '', hotel: '' }, completedValues: '已离店,已退房,completed', singleHotel: false, preview: null, source: '', retryKeys: {} }),
        computed: { scopeKey() { return `${this.hotelId}|${this.start}|${this.end}`; }, mappingKey() { return `${JSON.stringify(this.mapping)}|${this.completedValues}|${this.singleHotel}`; }, publicEntries() { return this.entries.filter(row => row.document.access_mode === 'guest_submission_only'); } },
        watch: {
            scopeKey() { this.roomText = ''; this.selected = []; this.owner = ''; this.links = []; this.file = null; this.columns = []; this.preview = null; this.error = ''; this.notice = ''; this.retryKeys = {}; },
            mappingKey() { this.preview = null; this.retryKeys = {}; },
            rooms(value) { const active = value.filter(room => room.document.active).map(room => room.room_id); this.selected = this.selected.filter(id => active.includes(id)); this.links = this.links.filter(link => active.includes(link.room_id)); },
            entries(value) { this.links = this.links.filter(link => value.some(row => row.document.room_id === link.room_id && row.document.access_mode === 'guest_submission_only' && row.document.enabled)); },
        },
        methods: {
            async exact(saved, hotel) {
                if (saved?.contract_version !== 'guest_operations.v1' || Number(saved.hotel_id) !== hotel || saved.persistence_status !== 'readback_verified' || !saved.records?.length) throw new Error('保存没有取得当前酒店精确回读');
                for (const record of saved.records) {
                    const read = await this.request(`/guest-operations/records/${record.id}?hotel_id=${hotel}`, { businessContext: { hotelId: hotel } });
                    if (read.code !== 200 || Number(read.data?.hotel_id) !== hotel || read.data?.content_digest !== record.content_digest || !read.data?.readback_verified) throw new Error('保存返回后精确回读失败，请保留原配置重试');
                }
            },
            async execute(path, payload) {
                if (this.busy || !this.canExecute) return;
                const scope = this.scopeKey, hotel = Number(this.hotelId), signature = `${path}:${JSON.stringify(payload)}`;
                this.busy = true; this.error = ''; this.notice = ''; this.retryKeys[signature] ||= key();
                try {
                    const response = await this.request(`/guest-operations/${path}`, { method: 'POST', body: JSON.stringify({ ...payload, hotel_id: hotel, idempotency_key: this.retryKeys[signature] }), businessContext: { hotelId: hotel } });
                    if (scope !== this.scopeKey) return;
                    if (response.code !== 200) throw new Error(response.message || '保存失败');
                    // Keep issued capabilities in this panel even if the subsequent independent read fails.
                    if (path === 'public-entries' && response.data.entry_links?.length) this.links = response.data.entry_links;
                    if (path === 'public-entries' && !payload.enabled) this.links = this.links.filter(link => !payload.room_ids.includes(link.room_id));
                    await this.exact(response.data, hotel); if (scope !== this.scopeKey) return;
                    delete this.retryKeys[signature]; this.notice = '已保存并精确回读';
                    if (response.data.token_delivery === 'already_issued_regenerate_if_lost' && !this.links.length) this.notice += '；链接只交付一次，丢失后请重新生成';
                    this.$emit('saved');
                } catch (error) { if (scope === this.scopeKey) this.error = error.message || '保存失败'; }
                finally { this.busy = false; }
            },
            async saveRooms() { const numbers = this.roomText.split(/\r?\n/).map(s => s.trim()).filter(Boolean); await this.execute('rooms', { confirmed_physical_rooms: true, rooms: numbers.map(room_number => ({ room_number, active: true })) }); },
            async toggleRoom(record) { await this.execute('rooms', { confirmed_physical_rooms: true, rooms: [{ room_number: record.document.room_number, active: !record.document.active, expected_revision: record.revision }] }); },
            async configure(rotate) {
                const revisions = Object.fromEntries(this.selected.map(id => [id, this.publicEntries.find(row => row.document.room_id === id)?.revision || 0]));
                await this.execute('public-entries', { room_ids: this.selected, label: this.label, owner_user_id: Number(this.owner), enabled: this.enabled, rotate_tokens: rotate, expected_revisions: revisions });
            },
            qr(link) {
                if (!/^\/guest-feedback\.html#[a-f0-9]{64}$/.test(link.entry_path)) throw new Error('宾客链接格式无效');
                if (!window.SUXI_GUEST_FEEDBACK_QR) throw new Error('二维码编码器尚未加载');
                return window.SUXI_GUEST_FEEDBACK_QR.svg(new URL(link.entry_path, window.location.origin).href);
            },
            downloadQr(link) { try { download(this.qr(link), `hotel-${this.hotelId}-room-${link.room_id}-feedback.svg`, 'image/svg+xml'); } catch (error) { this.error = error.message; } },
            downloadAll() {
                try { const cards = this.links.map(link => `<section><h2>${escape(link.room_label)} · ${escape(link.label)}</h2>${this.qr(link)}<p>扫描提交房间反馈 · 请勿填写个人身份信息</p></section>`).join(''); download(`<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>酒店${Number(this.hotelId)}房间反馈二维码</title><style>body{font-family:Arial,sans-serif}section{display:inline-block;width:290px;padding:20px;text-align:center;break-inside:avoid}svg{width:245px;height:245px}@media print{section{page-break-inside:avoid}}</style>${cards}</html>`, `hotel-${this.hotelId}-room-feedback-qr.html`, 'text/html'); }
                catch (error) { this.error = error.message; }
            },
            options(withMapping) { return { ...(withMapping ? { mapping: Object.fromEntries(Object.entries(this.mapping).map(([k, v]) => [k, v === '' ? null : Number(v)])) } : {}), date_start: this.start, date_end: this.end, completed_values: this.completedValues.split(/[,，\n]/).map(s => s.trim()).filter(Boolean), confirmed_single_hotel: this.singleHotel, confirmed_hotel_id: Number(this.hotelId), source_reference: this.source }; },
            async upload(save, withMapping = true) {
                if (this.busy || !this.canExecute) return;
                if (!this.file) { this.error = '请选择 JD06 文件'; return; }
                const scope = this.scopeKey, hotel = Number(this.hotelId), options = this.options(withMapping); this.busy = true; this.error = ''; this.notice = '';
                if (save) { const signature = JSON.stringify(options); this.retryKeys[signature] ||= key(); options.idempotency_key = this.retryKeys[signature]; }
                const body = new FormData(); body.append('file', this.file); body.append('hotel_id', String(hotel)); body.append('options', JSON.stringify(options));
                try {
                    const response = await this.request(`/guest-operations/jd06/${save ? 'import' : 'preview'}`, { method: 'POST', body, businessContext: { hotelId: hotel } });
                    if (scope !== this.scopeKey) return;
                    if (response.code !== 200 || Number(response.data?.hotel_id) !== hotel) throw new Error(response.message || '文件解析范围回读失败');
                    if (save) { await this.exact(response.data, hotel); if (scope !== this.scopeKey) return; this.notice = 'JD06匿名事件已保存并精确回读；PMS全量覆盖仍未验证'; this.$emit('saved'); }
                    else { this.columns = response.data.columns; this.preview = response.data; }
                } catch (error) { if (scope === this.scopeKey) { this.preview = null; this.error = error.message || 'JD06上传失败'; } }
                finally { this.busy = false; }
            },
        },
        render() {
            const h = window.Vue.h, button = (label, click, disabled = false) => h('button', { type: 'button', onClick: click, disabled: this.busy || !this.canExecute || disabled, style: { minHeight: '44px', padding: '8px 14px', margin: '4px', border: '1px solid #426c5b', borderRadius: '8px', color: '#214c3d', background: 'white' } }, label);
            const input = (label, value, setter, multiline = false) => h('label', { style: { display: 'grid', gap: '4px' } }, [h('span', label), h(multiline ? 'textarea' : 'input', { value, disabled: this.busy, onInput: e => setter(e.target.value), style: { width: '100%', minHeight: multiline ? '96px' : '44px', padding: '8px', border: '1px solid #b9c9c0', borderRadius: '8px', boxSizing: 'border-box' } })]);
            const select = (label, value, setter, choices) => h('label', { style: { display: 'grid', gap: '4px' } }, [h('span', label), h('select', { 'aria-label': label, value, disabled: this.busy, onChange: e => setter(e.target.value), style: { minHeight: '44px', maxWidth: '100%' } }, choices.map(([id, text]) => h('option', { value: id }, text)))]);
            const card = (title, children) => h('section', { style: { padding: '20px', border: '1px solid #d5dfd8', background: 'white', borderRadius: '14px', minWidth: 0 } }, [h('h3', title), ...children]);
            const children = [this.error ? h('p', { role: 'alert', style: { color: '#a83232' } }, this.error) : null, this.notice ? h('p', { role: 'status' }, this.notice) : null];
            if (this.tab === 'repeat') children.push(card('JD06入住文件导入', [h('p', '直接上传 XLSX、XLS 或 CSV；先映射列并预览匿名事件，再保存。原文件和客人身份不保存，上传不证明PMS全量覆盖。'), h('label', ['JD06文件', h('input', { type: 'file', accept: '.xlsx,.xls,.csv', disabled: this.busy || !this.canExecute, onChange: e => { this.file = e.target.files[0] || null; this.columns = []; this.preview = null; this.retryKeys = {}; } })]), button('读取文件表头', () => this.upload(false, false)), ...this.columns.length ? [h('div', { style: { display: 'grid', gap: '12px', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,200px),1fr))' } }, Object.entries({ event: '入住事件列', identity: '客人标识列（上传后匿名化）', stay_date: '完成入住日期列', status: '入住状态列', hotel: '酒店范围列' }).map(([name, label]) => select(label, this.mapping[name], v => { this.mapping[name] = v; }, [['', name === 'hotel' ? '文件无酒店列，需下方人工确认' : '请选择列'], ...this.columns.map(col => [String(col.index), `${col.index + 1} · ${col.label}`])]))), input('完成入住的状态值（逗号分隔）', this.completedValues, v => { this.completedValues = v; }), h('label', [h('input', { type: 'checkbox', checked: this.singleHotel, disabled: this.busy, onChange: e => { this.singleHotel = e.target.checked; } }), `文件没有酒店列时，我确认全部记录仅属于酒店${this.hotelId}`]), input('JD06脱敏来源证据引用（最多80字）', this.source, v => { this.source = v; }), button('验证映射并预览匿名事件', () => this.upload(false)), this.preview?.preview_status !== 'mapping_required' && this.preview ? h('div', [h('p', `预览${this.preview.preview_status}：有效 ${this.preview.valid_count}；同批重复 ${this.preview.duplicate_rows}；未完成入住跳过 ${this.preview.skipped_non_completed}`), ...(this.preview.errors || []).map(e => h('p', { style: { color: '#a83232' } }, `第${e.row}行：${e.message}`)), h('p', this.preview.evidence_boundary), h('pre', { style: { whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: '220px', overflowY: 'auto' } }, JSON.stringify((this.preview.events || []).slice(0, 10), null, 2)), button('确认导入JD06匿名事件', () => this.upload(true), this.preview.preview_status !== 'ready')]) : null] : []]));
            if (this.tab === 'entry') children.push(card('真实房间登记', [h('p', '每行一个本店真实房间编号；保存表示人工确认，不推断PMS房间身份。房间ID稳定，停用后该房间公开反馈入口随即不可提交。'), input('人工确认的实际房间编号（每行一个）', this.roomText, v => { this.roomText = v; }, true), button('确认登记本店房间', () => this.saveRooms()), h('ul', this.rooms.map(room => h('li', { key: room.room_id }, [`${room.document.room_number} · 房间ID ${room.room_id} · ${room.document.active ? '启用' : '停用'}`, button(room.document.active ? '停用房间' : '启用房间', () => this.toggleRoom(room))])))]), card('宾客公开反馈二维码批量管理', [h('p', '选择本店实际房间和责任人。公开链接仅可提交该房间反馈，不授予员工权限。链接只在生成时交付；请及时下载，重新生成将撤销旧链接。'), h('div', this.rooms.filter(room => room.document.active).map(room => h('label', { style: { display: 'inline-flex', alignItems: 'center', minHeight: '44px', gap: '8px', marginRight: '16px' } }, [h('input', { type: 'checkbox', checked: this.selected.includes(room.room_id), disabled: this.busy, onChange: e => { this.selected = e.target.checked ? [...this.selected, room.room_id] : this.selected.filter(id => id !== room.room_id); } }), `${room.document.room_number}（ID ${room.room_id}）`]))), select('公开反馈责任人', this.owner, v => { this.owner = v; }, [['', '请选择本店责任人'], ...this.owners.map(owner => [String(owner.id), owner.name])]), input('公开反馈入口名称', this.label, v => { this.label = v; }), h('label', [h('input', { type: 'checkbox', checked: this.enabled, disabled: this.busy, onChange: e => { this.enabled = e.target.checked; } }), '启用所选房间宾客提交入口']), button('保存所选房间入口（保留已有链接）', () => this.configure(false), !this.selected.length), button('重新生成所选房间二维码（撤销旧链接）', () => this.configure(true), !this.selected.length), h('ul', this.publicEntries.map(row => h('li', `${row.document.room_label} · 房间ID ${row.document.room_id} · ${row.document.enabled ? '入口启用' : '入口停用'} · v${row.revision}`))), this.links.length ? h('div', [button('批量下载房间二维码打印文件', () => this.downloadAll()), ...this.links.map(link => { try { const svg = this.qr(link); return h('div', { key: link.room_id, style: { display: 'inline-grid', maxWidth: '100%', margin: '12px', gap: '8px' } }, [h('strong', link.room_label), h('img', { src: `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`, alt: `房间${link.room_label}宾客反馈二维码`, width: 245, height: 245, style: { maxWidth: '100%', height: 'auto' } }), h('a', { href: link.entry_path, target: '_blank', rel: 'noopener noreferrer' }, '打开本房间宾客提交页'), button('下载本房间二维码SVG', () => this.downloadQr(link))]); } catch (error) { return h('p', error.message); } })]) : null]));
            if (this.tab === 'repeat' && this.imports.length) children.push(card('JD06已保存导入批次', this.imports.map(record => h('details', { key: record.id }, [h('summary', `记录${record.id} · ${record.document.date_start}至${record.document.date_end} · ${record.document.event_count}条匿名事件 · 未核验来源覆盖`), h('p', record.document.source_reference), h('p', record.document.evidence_boundary), h('p', `文件摘要 ${record.document.file_digest}`), h('pre', { style: { whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' } }, JSON.stringify({ mapping: record.document.mapping, completed_values: record.document.completed_values, scope_evidence: record.document.scope_evidence, source_quality: record.document.source_quality }, null, 2))]))));
            return h('div', { style: { display: 'grid', gap: '20px', minWidth: 0 } }, children);
        },
    };
})();
