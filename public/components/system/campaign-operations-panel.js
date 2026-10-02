(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const labels = { handover: '班次交接', marketing: '营销作品数据', poster: '节日海报', video_brief: '酒店短视频', report_reconciliation: '日报与记录核对' };
    const reviewLabels = { pending_review: '待审核', reviewed: '人工已审核', rejected: '未通过' };
    const initialKinds = { campaign: 'marketing', poster: 'poster', video: 'video_brief', daily: 'report_reconciliation', shift: 'handover', reconcile: 'report_reconciliation' };
    const day = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const key = () => window.crypto?.randomUUID?.() || `local_${Date.now()}_${Math.random().toString(36).slice(2)}`;
    const brandInk = color => {
        const rgb = String(color).slice(1).match(/../g).map(part => { const s = parseInt(part, 16) / 255; return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; });
        return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722 > 0.179 ? '#1c3028' : '#ffffff';
    };
    const split = (text, count) => {
        const lines = [];
        String(text || '').split('\n').forEach(line => {
            const chars = [...line];
            if (!chars.length) lines.push('');
            while (chars.length) lines.push(chars.splice(0, count).join(''));
        });
        return lines;
    };
    const download = (blob, filename) => {
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url; anchor.download = filename; anchor.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    const renderWebm = async (record, { signal, onProgress } = {}) => {
        if (record?.kind !== 'video_brief' || !record?.id || !record?.payload) throw new Error('先保存并精确回读视频制作单');
        if (!window.MediaRecorder || !HTMLCanvasElement.prototype.captureStream) throw new Error('当前浏览器不支持本地WebM，请使用支持MediaRecorder和Canvas captureStream的Chromium浏览器');
        const mime = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'].find(value => MediaRecorder.isTypeSupported(value));
        if (!mime) throw new Error('当前浏览器没有可用的WebM编码器');
        const p = record.payload;
        const canvas = document.createElement('canvas'); canvas.width = 1280; canvas.height = 720;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('浏览器无法创建视频画面');
        const stream = canvas.captureStream(24);
        const recorder = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 1800000 });
        const chunks = [];
        const textLines = split(`${p.copy}\n素材说明：${p.material_notes}`, 38);
        const pages = Math.max(1, Math.ceil(textLines.length / 7));
        const duration = Number(p.duration_seconds) * 1000;
        if (!Number.isFinite(duration) || duration < 3000 || duration > 30000) throw new Error('保存的制作单视频时长无效');
        let animation = 0;
        let abortListener;
        const finish = new Promise((resolve, reject) => {
            recorder.ondataavailable = event => { if (event.data?.size) chunks.push(event.data); };
            recorder.onerror = () => reject(new Error('本地视频编码失败，没有生成可用作品'));
            recorder.onstop = () => {
                if (signal?.aborted) return reject(new Error('酒店、日期或页面已切换，视频生成已取消'));
                const blob = new Blob(chunks, { type: mime });
                blob.size > 0 ? resolve(blob) : reject(new Error('视频编码结果为空，请重试'));
            };
            abortListener = () => { if (recorder.state !== 'inactive') recorder.stop(); };
            signal?.addEventListener('abort', abortListener, { once: true });
        });
        const draw = progress => {
            ctx.fillStyle = '#f4f6f4'; ctx.fillRect(0, 0, 1280, 720);
            ctx.fillStyle = p.brand_color; ctx.fillRect(0, 0, 1280, 230);
            ctx.fillStyle = brandInk(p.brand_color); ctx.font = '25px Microsoft YaHei, sans-serif'; split(p.hotel_name, 42).forEach((line, i) => ctx.fillText(line, 60, 32 + i * 25));
            ctx.font = 'bold 38px Microsoft YaHei, sans-serif';
            split(p.title, 28).forEach((line, i) => ctx.fillText(line, 60, 126 + i * 37));
            ctx.fillStyle = '#1c3028'; ctx.font = '28px Microsoft YaHei, sans-serif';
            const page = Math.min(pages - 1, Math.floor(progress * pages));
            textLines.slice(page * 7, page * 7 + 7).forEach((line, i) => ctx.fillText(line, 60, 270 + i * 40));
            ctx.fillStyle = '#5b6d63'; ctx.font = '16px Microsoft YaHei, sans-serif';
            const provenance = `制作单 #${record.id} / v${record.version_no} · 当前酒店${record.hotel_id} · 来源酒店${record.source_hotel_id || record.hotel_id} · ${record.business_date} · 来源：${record.source_label}`;
            split(provenance, 68).forEach((line, i) => ctx.fillText(line, 60, 555 + i * 20));
            ctx.fillText(`品牌：${reviewLabels[p.brand_review_status]} · 素材：${reviewLabels[p.material_review_status]} · 文字画面生成，未提供实拍素材与音乐`, 60, 675);
            ctx.fillStyle = p.brand_color; ctx.fillRect(0, 710, 1280 * progress, 10);
        };
        try {
            if (signal?.aborted) throw new Error('视频生成已取消');
            draw(0); recorder.start(250);
            const start = performance.now();
            const frame = () => {
                if (signal?.aborted || recorder.state === 'inactive') return;
                const progress = Math.min(1, (performance.now() - start) / duration);
                draw(progress); onProgress?.(Math.round(progress * 100));
                if (progress >= 1) recorder.stop(); else animation = requestAnimationFrame(frame);
            };
            animation = requestAnimationFrame(frame);
            return await finish;
        } finally {
            cancelAnimationFrame(animation);
            signal?.removeEventListener('abort', abortListener);
            if (recorder.state !== 'inactive') recorder.stop();
            stream.getTracks().forEach(track => track.stop());
        }
    };
    window.SUXI_CAMPAIGN_MEDIA = Object.freeze({ renderWebm });

    const newForm = kind => ({
        kind, record_key: key(), expected_id: 0, source_label: '',
        payload: kind === 'handover' ? { shift_label: '', notes: '', previous_id: null, new_items: [] }
            : kind === 'marketing' ? { platform: 'douyin', work_id: '', title: '', views: '', likes: '', reservations: '', effective_leads: '', actual_arrivals: '', actual_room_nights: '', actual_revenue: '', result_business_date: '', result_source_label: '', attribution_notes: '' }
                : kind === 'report_reconciliation' ? { daily_report_id: '', notes: '' }
                    : { hotel_name: '', title: '', copy: '', brand_color: '#143a31', material_notes: '', brand_review_status: 'pending_review', material_review_status: 'pending_review', duration_seconds: 8 },
    });
    const itemForm = () => ({ title: '', owner: '', due_at: '', task_id: '' });
    components.CampaignOperationsPanel = {
        name: 'CampaignOperationsPanel',
        props: { hotels: { type: Array, default: () => [] }, request: { type: Function, required: true }, selectedHotelId: { type: [String, Number], default: '' }, canExecute: { type: Boolean, default: false }, initialTab: { type: String, default: 'shift' }, settings: { type: Object, default: () => ({}) } },
        emits: ['update:selected-hotel-id', 'navigate'],
        data: () => ({ hotelId: '', businessDate: day(), activeKind: 'handover', form: newForm('handover'), pendingItem: itemForm(), overview: null, saved: null, loading: false, busy: false, error: '', success: '', seq: 0, opSeq: 0, videoProgress: 0, closureEvidence: {}, inheritPrevious: true }),
        computed: {
            visibleRecords() { return (this.overview?.records || []).filter(row => row.kind === this.activeKind); },
            previousHandover() { return this.overview?.previous_handover || null; },
        },
        watch: {
            selectedHotelId(value) { if (String(value || '') !== this.hotelId) this.hotelId = String(value || ''); },
            hotelId() { this.reset(); this.$emit('update:selected-hotel-id', this.hotelId); if (this.hotelId) void this.load(); },
            businessDate() { this.reset(); if (this.hotelId) void this.load(); },
            activeKind() {
                const reload = this.loading;
                this.seq += 1; this.opSeq += 1; this._videoAbort?.abort(); this.loading = this.busy = false; this.saved = null;
                this.form = newForm(this.activeKind); this.pendingItem = itemForm(); this.closureEvidence = {}; this.error = ''; this.success = '';
                if (reload && this.hotelId) void this.load();
            },
            initialTab(value) { this.activeKind = initialKinds[value] || 'handover'; },
        },
        mounted() { this.activeKind = initialKinds[this.initialTab] || 'handover'; this.hotelId = String(this.selectedHotelId || ''); },
        beforeUnmount() { this.seq += 1; this._videoAbort?.abort(); },
        methods: {
            reset() {
                this.seq += 1; this.opSeq += 1; this._videoAbort?.abort(); this.overview = this.saved = null;
                this.form = newForm(this.activeKind); this.pendingItem = itemForm(); this.closureEvidence = {}; this.inheritPrevious = true;
                this.error = this.success = ''; this.loading = this.busy = false; this.videoProgress = 0;
            },
            async load() {
                const seq = ++this.seq; const hotelId = Number(this.hotelId); const date = this.businessDate;
                this.loading = true; this.error = ''; this.overview = null;
                try {
                    const result = await this.request(`/campaign-operations/overview?hotel_id=${hotelId}&business_date=${date}`, { businessContext: { hotelId } });
                    if (seq !== this.seq) return;
                    if (result?.code !== 200 || result.data?.hotel_id !== hotelId || result.data?.business_date !== date) throw new Error(result?.message || '工作区响应酒店或日期不匹配');
                    this.overview = result.data;
                } catch (error) { if (seq === this.seq) this.error = error.message || '读取失败'; }
                finally { if (seq === this.seq) this.loading = false; }
            },
            addItem() {
                if (!this.pendingItem.title || !this.pendingItem.owner || !this.pendingItem.due_at) { this.error = '事项、责任人和截止时间必填'; return; }
                this.form.payload.new_items.push({ ...this.pendingItem, task_id: this.pendingItem.task_id ? Number(this.pendingItem.task_id) : null });
                this.pendingItem = itemForm(); this.error = '';
            },
            checkRecord(record, expectedDate = this.businessDate) {
                if (record?.hotel_id !== Number(this.hotelId) || record?.business_date !== expectedDate || record?.schema_version !== 'campaign_operations.v1') throw new Error('回读版本酒店、日期或合同不匹配');
                return record;
            },
            async edit(record) {
                const seq = this.seq;
                try {
                    const result = await this.request(`/campaign-operations/records/${record.id}?hotel_id=${this.hotelId}`, { businessContext: { hotelId: Number(this.hotelId) } });
                    if (seq !== this.seq) return;
                    if (result.code !== 200) throw new Error(result.message);
                    const exact = this.checkRecord(result.data);
                    this.saved = exact;
                    this.form = { kind: exact.kind, record_key: exact.record_key, expected_id: exact.id, source_label: exact.source_label, payload: JSON.parse(JSON.stringify(exact.payload)) };
                    if (exact.kind === 'handover') this.form.payload.new_items = [];
                    this.success = `已回读 #${exact.id} / v${exact.version_no}`;
                } catch (error) { this.error = error.message || '回读失败'; }
            },
            async save() {
                if (!this.canExecute || this.busy) return;
                if (this.activeKind === 'handover' && this.pendingItem.title) { this.error = '先点击加入交接清单，再保存'; return; }
                const seq = this.seq; const op = ++this.opSeq; this.busy = true; this.error = this.success = '';
                const input = JSON.parse(JSON.stringify(this.form));
                input.hotel_id = Number(this.hotelId); input.business_date = this.businessDate;
                if (input.kind === 'handover' && !input.expected_id) input.payload.previous_id = this.inheritPrevious ? this.previousHandover?.id || null : null;
                try {
                    const result = await this.request('/campaign-operations/records', { method: 'POST', body: JSON.stringify(input), businessContext: { hotelId: input.hotel_id } });
                    if (seq !== this.seq) return;
                    if (result.code !== 200 || result.data?.request_status !== 'saved_and_readback_verified') throw new Error(result.message || '保存回读失败');
                    this.saved = this.checkRecord(result.data.record);
                    this.form.record_key = this.saved.record_key; this.form.expected_id = this.saved.id;
                    this.form.payload = JSON.parse(JSON.stringify(this.saved.payload));
                    if (input.kind === 'handover') this.form.payload.new_items = [];
                    this.success = `${result.data.reused ? '相同记录已存在' : '已保存'} #${this.saved.id} / v${this.saved.version_no}；人工录入，未核验`;
                    await this.load();
                } catch (error) { if (seq === this.seq) this.error = error.message || '保存失败'; }
                finally { if (op === this.opSeq) this.busy = false; }
            },
            async handoverAction(record, action, item) {
                if (!this.canExecute || this.busy) return;
                const seq = this.seq; const op = ++this.opSeq; this.busy = true; this.error = '';
                try {
                    const result = await this.request(`/campaign-operations/records/${record.id}/handover-action`, { method: 'POST', businessContext: { hotelId: Number(this.hotelId) }, body: JSON.stringify({ hotel_id: Number(this.hotelId), action, item_id: item?.item_id, closure_evidence: item ? this.closureEvidence[item.item_id] : undefined }) });
                    if (seq !== this.seq) return;
                    if (result.code !== 200 || result.data?.request_status !== 'saved_and_readback_verified') throw new Error(result.message || '交接保存失败');
                    this.saved = this.checkRecord(result.data.record);
                    this.success = `交接已保存至 v${this.saved.version_no}`; await this.load();
                } catch (error) { if (seq === this.seq) this.error = error.message || '交接操作失败'; }
                finally { if (op === this.opSeq) this.busy = false; }
            },
            async artifact(record, format) {
                const seq = this.seq; this.error = '';
                try {
                    const result = await this.request(`/campaign-operations/records/${record.id}/artifact?hotel_id=${this.hotelId}&format=${format}`, { businessContext: { hotelId: Number(this.hotelId) } });
                    if (seq !== this.seq) return;
                    if (result.code !== 200 || result.data?.hotel_id !== Number(this.hotelId) || result.data?.id !== record.id || result.data?.version_no !== record.version_no || result.data?.business_date !== record.business_date) throw new Error(result.message || '作品回读范围不匹配');
                    download(new Blob([result.data.content], { type: result.data.mime_type }), result.data.filename);
                } catch (error) { this.error = error.message || '作品下载失败'; }
            },
            async video(record) {
                if (this.busy) return; const seq = this.seq; this.busy = true; this.error = ''; this.videoProgress = 0;
                this._videoAbort = new AbortController();
                try {
                    const result = await this.request(`/campaign-operations/records/${record.id}?hotel_id=${this.hotelId}`, { businessContext: { hotelId: Number(this.hotelId) } });
                    if (seq !== this.seq) return;
                    if (result.code !== 200) throw new Error(result.message);
                    const exact = this.checkRecord(result.data);
                    const blob = await renderWebm(exact, { signal: this._videoAbort.signal, onProgress: value => { if (seq === this.seq) this.videoProgress = value; } });
                    if (seq !== this.seq) return;
                    download(blob, `hotel-${exact.hotel_id}-video-${exact.id}-v${exact.version_no}.webm`);
                    this.success = `本地文字画面WebM已生成（${Math.round(blob.size / 1024)}KB），制作单 #${exact.id} / v${exact.version_no}`;
                } catch (error) { if (seq === this.seq) this.error = error.message || '视频生成失败'; }
                finally { if (seq === this.seq) this.busy = false; }
            },
        },
        render() {
            const h = window.Vue.h;
            const p = this.form.payload;
            const field = (label, name, type = 'text', target = p) => h('label', { class: 'block text-sm text-slate-700' }, [h('span', { class: 'block mb-1' }, label), h(type === 'textarea' ? 'textarea' : 'input', { name, type: type === 'textarea' ? undefined : type, value: target[name] ?? '', rows: type === 'textarea' ? 4 : undefined, min: type === 'number' ? 0 : undefined, class: 'w-full rounded-lg border border-slate-300 p-2 min-h-[44px]', disabled: !this.canExecute || this.busy, onInput: event => { target[name] = event.target.value; } })]);
            const button = (label, click, disabled = false, primary = false) => h('button', { type: 'button', onClick: click, disabled, class: `rounded-lg px-3 py-2 min-h-[44px] text-sm ${primary ? 'bg-emerald-950 text-white' : 'border border-slate-300 bg-white text-slate-700'} disabled:opacity-50` }, label);
            const review = name => h('label', { class: 'text-sm' }, [h('span', { class: 'block mb-1' }, name === 'brand_review_status' ? '品牌审核状态' : '素材审核状态'), h('select', { name, value: p[name], class: 'w-full rounded-lg border border-slate-300 p-2 min-h-[44px]', disabled: !this.canExecute || this.busy, onChange: event => { p[name] = event.target.value; } }, Object.entries(reviewLabels).map(([value, label]) => h('option', { value }, label)))]);
            let inputs;
            if (this.activeKind === 'handover') inputs = [
                field('交接班次', 'shift_label'), field('交接说明', 'notes', 'textarea'),
                h('label', { class: 'flex gap-2 text-sm' }, [h('input', { type: 'checkbox', checked: this.inheritPrevious, disabled: !!this.form.expected_id, onChange: event => { this.inheritPrevious = event.target.checked; } }), `继承上一交班未结事项${this.previousHandover ? `（#${this.previousHandover.id} / v${this.previousHandover.version_no}）` : '（当前无已保存上一交班）'}`]),
                h('div', { class: 'grid gap-3 sm:grid-cols-2' }, [field('未结事项', 'title', 'text', this.pendingItem), field('责任人', 'owner', 'text', this.pendingItem), field('截止时间（上海）', 'due_at', 'datetime-local', this.pendingItem), field('关联原任务编号（可选）', 'task_id', 'number', this.pendingItem)]),
                button('加入交接清单', this.addItem, !this.canExecute || this.busy),
                h('ul', { class: 'text-sm space-y-2' }, p.new_items.map((item, i) => h('li', { key: i }, [`${item.title} · ${item.owner} · ${item.due_at}`, button('移除草稿事项', () => p.new_items.splice(i, 1), !this.canExecute)]))),
            ];
            else if (this.activeKind === 'marketing') inputs = [
                h('label', { class: 'text-sm' }, ['来源平台', h('select', { name: 'platform', value: p.platform, class: 'w-full rounded-lg border border-slate-300 p-2 min-h-[44px]', disabled: !this.canExecute, onChange: event => { p.platform = event.target.value; } }, [['douyin', '抖音'], ['xiaohongshu', '小红书'], ['other', '其他']].map(([value, label]) => h('option', { value }, label)))]),
                field('作品唯一编号（同平台/作品/日期重复输入幂等）', 'work_id'), field('作品标题', 'title'),
                h('div', { class: 'grid gap-3 sm:grid-cols-2' }, [['views', '播放量'], ['likes', '点赞'], ['reservations', '预约数'], ['effective_leads', '有效线索']].map(([name, label]) => field(`${label}（未知留空）`, name, 'number'))),
                h('p', { class: 'text-sm text-slate-600' }, '实际到店结果单独核对，不把预约或线索视为成交。'),
                h('div', { class: 'grid gap-3 sm:grid-cols-2' }, [['actual_arrivals', '实际到店'], ['actual_room_nights', '实际间夜'], ['actual_revenue', '实际收入（元）']].map(([name, label]) => field(`${label}（未知留空）`, name, 'number'))),
                field('实际结果业务日期', 'result_business_date', 'date'), field('实际结果来源', 'result_source_label'), field('归因/核对说明', 'attribution_notes', 'textarea'),
            ];
            else if (this.activeKind === 'report_reconciliation') inputs = [
                h('p', { class: 'text-sm text-slate-600' }, '日报使用现有最小配置/录入/保存/回读。本区仅关联已有报表记录补录或核对说明，不生成平台订单。'),
                button('打开每日事实录入', () => this.$emit('navigate', { page: 'operating-targets' })), button('打开实际经营数据', () => this.$emit('navigate', { page: 'pms-operating-data' })), field('已有日报编号', 'daily_report_id', 'number'), field('补录/核对说明', 'notes', 'textarea'),
            ];
            else inputs = [field('酒店显示名称', 'hotel_name'), field('作品标题/节日', 'title'), field('作品文案', 'copy', 'textarea'), field('品牌色', 'brand_color', 'color'), field('素材名称、来源与授权说明（无需URL）', 'material_notes', 'textarea'), review('brand_review_status'), review('material_review_status'), ...(this.activeKind === 'video_brief' ? [field('视频秒数（3至30秒）', 'duration_seconds', 'number'), h('p', { class: 'text-sm text-slate-600' }, '保存制作单后可本地生成文字画面WebM。未自动取得酒店实拍和音乐；作品带制作单版本、来源、素材与品牌审核状态。')] : [h('p', { class: 'text-sm text-slate-600' }, '保存后下载原生SVG/HTML海报。修改内容会自动撤销旧版素材和品牌审核。')])];
            return h('section', { class: 'space-y-4', 'data-testid': 'campaign-operations-panel' }, [
                h('header', { class: 'flex flex-wrap items-end gap-3' }, [h('div', { class: 'grow' }, [h('h2', { class: 'text-xl font-bold text-slate-900' }, '交接与营销工作区'), h('p', { class: 'text-sm text-slate-600' }, '人工记录 · 精确版本回读 · 实际结果独立核对')]), h('label', { class: 'text-sm' }, ['酒店', h('select', { value: this.hotelId, class: 'block rounded-lg border border-slate-300 p-2 min-h-[44px]', onChange: event => { this.hotelId = event.target.value; } }, [h('option', { value: '' }, '请选择酒店'), ...this.hotels.map(hotel => h('option', { value: String(hotel.id) }, hotel.name || `酒店${hotel.id}`))])]), h('label', { class: 'text-sm' }, ['业务日期', h('input', { type: 'date', value: this.businessDate, class: 'block rounded-lg border border-slate-300 p-2 min-h-[44px]', onInput: event => { this.businessDate = event.target.value; } })]), button('刷新', this.load, !this.hotelId || this.loading)]),
                h('nav', { class: 'flex flex-wrap gap-2', 'aria-label': '工作区功能' }, Object.entries(labels).map(([kind, label]) => button(label, () => { this.activeKind = kind; }, false, this.activeKind === kind))),
                this.error ? h('p', { role: 'alert', class: 'rounded-lg bg-red-50 p-3 text-red-800' }, this.error) : null,
                this.success ? h('p', { role: 'status', class: 'rounded-lg bg-emerald-50 p-3 text-emerald-900' }, this.success) : null,
                this.loading ? h('p', { role: 'status', class: 'text-sm text-slate-600' }, '正在读取当前酒店/日期…') : null,
                !this.hotelId ? h('p', { class: 'rounded-lg border border-slate-200 p-5' }, '请选择一个酒店开始。') : h('div', { class: 'grid gap-4 xl:grid-cols-2' }, [
                    h('form', { class: 'space-y-3 rounded-xl border border-slate-200 bg-white p-4', onSubmit: event => { event.preventDefault(); void this.save(); } }, [h('h3', { class: 'font-semibold text-slate-900' }, `${labels[this.activeKind]}${this.form.expected_id ? ` · 编辑 #${this.form.expected_id}` : ' · 新记录'}`), field('来源说明', 'source_label', 'text', this.form), ...inputs, h('p', { class: 'text-xs text-slate-600' }, '未知数值留空；人工记录不会升级为平台或全酒店核验事实。'), h('div', { class: 'flex flex-wrap gap-2' }, [button(this.busy ? (this.videoProgress ? `视频生成 ${this.videoProgress}%` : '保存中…') : '保存并回读', this.save, !this.canExecute || this.busy || this.loading, true), button('新记录', () => { this.form = newForm(this.activeKind); this.saved = null; }, this.busy)]), !this.canExecute ? h('p', { class: 'text-sm text-slate-600' }, '当前账号只有查看权限。') : null]),
                    h('div', { class: 'space-y-3 min-w-0' }, [h('h3', { class: 'font-semibold text-slate-900' }, '当前日期保存版本'), this.overview?.data_status === 'partial' ? h('p', { class: 'text-sm text-amber-800' }, `共有${this.overview.total}条，仅展示最近100条。`) : null, ...this.visibleRecords.map(record => h('article', { key: record.id, class: 'space-y-3 rounded-xl border border-slate-200 bg-white p-4', 'data-record-id': record.id }, [
                        h('div', { class: 'flex flex-wrap justify-between gap-2' }, [h('strong', { class: 'text-slate-900' }, record.payload.title || record.payload.shift_label || `日报核对 #${record.payload.daily_report_id}`), h('span', { class: 'text-xs text-slate-600' }, `#${record.id} / v${record.version_no} · 人工录入未核验`)]), h('p', { class: 'text-xs text-slate-600 break-words' }, `${record.business_date} · 当前酒店${record.hotel_id} / 来源酒店${record.source_hotel_id || record.hotel_id} · 来源：${record.source_label}`),
                        h('p', { class: 'text-xs text-slate-600 break-words' }, record.integrity_status === 'immutable_metadata_verified'
                            ? '保存内容、原始酒店、版本号与记录人一致；经营结果仍待核对。'
                            : record.integrity_status === 'legacy_content_only' ? '旧版本可回读；原始酒店、版本号及记录人尚不能独立校验。'
                                : '未返回原始酒店、版本号与记录人的校验结果。'),
                        record.kind === 'handover' ? h('div', { class: 'space-y-2 text-sm' }, [h('p', {}, record.payload.acknowledged_by ? `接班已确认（操作人 ${record.payload.acknowledged_by}）` : '待接班人确认'), ...record.payload.items.map(item => h('div', { key: item.item_id, class: 'border-t border-slate-100 pt-2 space-y-2' }, [h('p', { class: 'break-words' }, `${item.title} · ${item.owner} · 截止 ${item.due_at} · ${item.status === 'closed' ? '已关闭' : '未结'}${item.task_id ? ` · 原任务#${item.task_id}` : ''}${item.inherited_from_id ? ` · 继承#${item.inherited_from_id}` : ''}`), item.status === 'closed' ? h('p', { class: 'text-slate-600 break-words' }, `关闭证据：${item.closure_evidence}`) : this.canExecute ? h('div', { class: 'flex flex-wrap gap-2' }, [h('input', { 'aria-label': `${item.title}关闭证据`, placeholder: '关闭证据（必填）', value: this.closureEvidence[item.item_id] || '', class: 'min-w-0 grow rounded-lg border border-slate-300 p-2 min-h-[44px]', onInput: event => { this.closureEvidence[item.item_id] = event.target.value; } }), button('关闭事项', () => this.handoverAction(record, 'close_item', item), this.busy || !record.payload.acknowledged_by)]) : null]))]) : record.kind === 'marketing' ? h('dl', { class: 'grid grid-cols-2 gap-2 text-sm' }, [['views', '播放'], ['reservations', '预约'], ['effective_leads', '有效线索'], ['actual_arrivals', '实际到店'], ['actual_room_nights', '实际间夜'], ['actual_revenue', '实际收入元']].flatMap(([name, label]) => [h('dt', {}, label), h('dd', {}, record.payload[name] === null ? '未知' : String(record.payload[name]))])) : h('p', { class: 'text-sm text-slate-700 whitespace-pre-wrap break-words' }, record.payload.copy || record.payload.notes),
                        ['poster', 'video_brief'].includes(record.kind) ? h('p', { class: 'text-xs text-slate-600' }, `品牌${reviewLabels[record.payload.brand_review_status]} · 素材${reviewLabels[record.payload.material_review_status]} · ${record.payload.material_notes}`) : null,
                        record.kind === 'marketing' ? h('p', { class: 'text-xs text-slate-600 break-words' }, `实际结果日期：${record.payload.result_business_date || '未知'} · 实际结果来源：${record.payload.result_source_label || '未知'} · 归因核对：${record.payload.attribution_notes || '未核对'}`) : null,
                        h('div', { class: 'flex flex-wrap gap-2' }, [button(this.canExecute ? '回读并编辑新版本' : '精确回读', () => this.edit(record), this.busy), record.kind === 'handover' && !record.payload.acknowledged_by ? button('确认接班', () => this.handoverAction(record, 'acknowledge'), !this.canExecute || this.busy) : null, record.kind === 'poster' ? button('下载SVG海报', () => this.artifact(record, 'svg')) : null, ['poster', 'video_brief'].includes(record.kind) ? button(record.kind === 'poster' ? '下载HTML海报' : '导出制作单HTML', () => this.artifact(record, 'html')) : null, record.kind === 'video_brief' ? button('生成并下载WebM', () => this.video(record), this.busy) : null]),
                    ])), !this.loading && !this.visibleRecords.length ? h('p', { class: 'rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600' }, this.overview ? '当前酒店/日期没有此类已保存记录。' : '工作区尚未成功读取，记录状态未知。') : null]),
                ]),
            ]);
        },
    };
})();
