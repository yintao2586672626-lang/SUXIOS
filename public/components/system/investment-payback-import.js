(() => {
    'use strict';
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const asciiText = value => String(value ?? '').replace(/[０-９．－，／]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xfee0));
    const cleanHeader = value => asciiText(value).trim().replace(/[\s（）()：:]/g, '').replace(/(?:万元|人民币|元)$/g, '');
    const aliases = {
        project_name: ['项目', '项目名称', '酒店', '酒店名称', '门店', '门店名称'],
        investor_name: ['投资人', '投资主体', '股东', '姓名'],
        opening_invested: ['累计投入', '投入金额', '投资金额', '总投入', '本金'],
        opening_recovered: ['累计收回', '累计净收回', '已收回', '累计回款', '累计分红'],
        opening_as_of: ['截至日', '余额截至日', '统计日期', '截至日期'],
        date: ['日期', '业务日期', '收回日期', '分红日期', '时间'],
        kind: ['类型', '资金类型', '收支类型'],
        amount: ['金额', '收回金额', '实收金额', '分红金额', '回款金额'],
        note: ['备注', '说明'],
    };
    const suggestHeader = matrix => {
        let best = { index: 0, score: 0 };
        for (let i = 0; i < Math.min(matrix.length, 15); i++) {
            const cells = matrix[i].map(cleanHeader);
            const score = Object.values(aliases).filter(names => cells.some(cell => names.includes(cell))).length;
            if (score > best.score) best = { index: i, score };
        }
        return best;
    };
    const suggestMapping = headers => Object.fromEntries(Object.entries(aliases).map(([key, names]) => [key, headers.findIndex(cell => names.includes(cleanHeader(cell)))]));
    const parseMoney = (value, unit = 'auto') => {
        let text = asciiText(value).trim().replace(/[，,\s￥¥]/g, '').replace(/(\d)[·•](?=\d)/g, '$1.');
        if (!text) return '';
        const wan = /万(?:元)?$/.test(text) || (!/元$/.test(text) && unit === 'wan');
        text = text.replace(/万(?:元)?$|元$/g, '');
        if (!/^-?\d+(?:\.\d+)?$/.test(text)) return null;
        const negative = text.startsWith('-');
        const [whole, decimal = ''] = text.replace(/^-/, '').split('.');
        const scale = wan ? 6 : 2;
        if (decimal.length > scale && /[1-9]/.test(decimal.slice(scale))) return null;
        const cents = (BigInt(whole) * (wan ? 1000000n : 100n) + BigInt(decimal.slice(0, scale).padEnd(scale, '0') || '0')) * (negative ? -1n : 1n);
        if (cents > 99999999999999n || cents < -99999999999999n) return null;
        const absolute = cents < 0n ? -cents : cents;
        return `${cents < 0n ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
    };
    const parseDate = (value, year = '') => {
        let text = asciiText(value).trim().replace(/\s/g, '').replace(/(\d)一(?=\d)/g, '$1-').replace(/月$/g, '').replace(/年|月|[/.]/g, '-').replace(/日$/g, '');
        if (/^\d{1,2}-\d{1,2}$/.test(text)) {
            if (!/^\d{4}$/.test(String(year))) return { date: '', precision: 'day', error: '日期缺少年份，请填写原表所属年份' };
            text = `${year}-${text}`;
        }
        const match = text.match(/^(\d{4})-(\d{1,2})(?:-(\d{1,2}))?$/);
        if (!match) return { date: '', precision: 'day', error: '日期未识别，请填写完整日期或月份' };
        const y = Number(match[1]), m = Number(match[2]), d = Number(match[3] || 1);
        const check = new Date(Date.UTC(y, m - 1, d));
        if (y < 1900 || y > 9999 || check.getUTCFullYear() !== y || check.getUTCMonth() !== m - 1 || check.getUTCDate() !== d) return { date: '', precision: 'day', error: '日期无效' };
        return { date: `${match[1]}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`, precision: match[3] ? 'day' : 'month', error: '' };
    };
    const parseKind = value => ({ '投入': 'investment', '投资': 'investment', '出资': 'investment', 'investment': 'investment', '收回': 'recovery', '回款': 'recovery', '分红': 'recovery', '收入': 'recovery', 'recovery': 'recovery' }[String(value ?? '').trim().toLowerCase()] || '');
    const parsePastedTable = raw => {
        const rows = [], text = raw.replace(/\r\n?/g, '\n');
        let headerQuoted = false, hasTab = false, hasComma = false, sampledRows = 0;
        let rowHasText = false, rowHasTab = false, rowHasComma = false;
        const sampleRow = () => {
            if (rowHasText) { hasTab ||= rowHasTab; hasComma ||= rowHasComma; sampledRows++; }
            rowHasText = false; rowHasTab = false; rowHasComma = false;
        };
        for (let i = 0; i < text.length && sampledRows < 8; i++) {
            const char = text[i];
            if (char === '"') {
                rowHasText = true;
                if (headerQuoted && text[i + 1] === '"') i++;
                else headerQuoted = !headerQuoted;
            } else if (!headerQuoted) {
                if (char === '\n') sampleRow();
                else {
                    if (/\S/.test(char)) rowHasText = true;
                    if (char === '\t') rowHasTab = true;
                    if (char === ',') rowHasComma = true;
                }
            }
        }
        if (sampledRows < 8) sampleRow();
        const delimiter = hasTab || !hasComma ? '\t' : ',';
        let cells = [], cell = '', quoted = false, closed = false;
        const finishCell = () => { cells.push(cell); cell = ''; closed = false; };
        for (let i = 0; i < text.length; i++) {
            const char = text[i];
            if (quoted) {
                if (char === '"' && text[i + 1] === '"') { cell += '"'; i++; }
                else if (char === '"') { quoted = false; closed = true; }
                else cell += char;
            } else if (char === delimiter || char === '\n') {
                finishCell();
                if (char === '\n') { rows.push(cells); cells = []; }
            } else if (char === '"' && !cell && !closed) quoted = true;
            else if (closed) throw new Error('粘贴内容的引号后有异常字符，请核对原表或改用文件导入。');
            else cell += char;
        }
        if (quoted) throw new Error('粘贴内容的引号未闭合，请重新复制完整单元格。');
        finishCell(); rows.push(cells);
        return rows;
    };
    const exactTotal = (rows, field) => {
        let cents = 0n;
        for (const row of rows) {
            const amount = parseMoney(row[field], 'yuan');
            if (amount === null || amount === '') return '待核对';
            cents += BigInt(amount.replace('.', ''));
        }
        const absolute = cents < 0n ? -cents : cents;
        return `${cents < 0n ? '-' : ''}${(absolute / 100n).toLocaleString('zh-CN')}.${String(absolute % 100n).padStart(2, '0')} 元`;
    };
    window.SUXI_PAYBACK_IMPORT = { parseMoney, parseDate, parseKind, parsePastedTable, suggestHeader, suggestMapping, exactTotal };
    registry.InvestmentPaybackImport = {
        name: 'InvestmentPaybackImport',
        props: { request: { type: Function, required: true }, projects: { type: Array, default: () => [] }, project: { type: Object, default: null }, today: { type: String, required: true } },
        emits: ['close', 'saved'],
        setup(props, { emit }) {
            const { ref, reactive, computed } = Vue;
            const busy = ref(false), error = ref(''), preview = ref(null), image = ref(''), pasted = ref(''), closePrompt = ref(false), pendingChange = ref(false);
            const mode = ref(props.project ? 'entries' : 'projects'), projectId = ref(props.project?.id || '');
            const sheetIndex = ref(0), headerRow = ref(1), year = ref(''), fallbackDate = ref(''), defaultKind = ref('');
            const unit = ref('auto'), reviewed = ref(false), staged = ref([]);
            const review = ref(null), checking = ref(false), similarConfirmed = ref(false);
            const mapping = reactive(Object.fromEntries(Object.keys(aliases).map(key => [key, -1])));
            let requestIdentity = '', requestPayload = '', generation = 0, disposed = false, sourceError = '';
            let reviewIdentity = '', reviewSequence = 0, reviewTimer = null;
            let generatedRows = '', generatedSettings = null, pendingApply = null;
            const sheets = computed(() => preview.value?.sheets || []);
            const matrix = computed(() => sheets.value[Number(sheetIndex.value)]?.rows || []);
            const headers = computed(() => Number(headerRow.value) > 0 ? matrix.value[Number(headerRow.value) - 1] || [] : Array.from({ length: Math.max(0, ...matrix.value.map(row => row.length)) }, (_, i) => `第 ${i + 1} 列`));
            const activeRows = computed(() => staged.value.filter(row => row.selected));
            const modeLabel = computed(() => mode.value === 'projects' ? '新建项目累计余额' : '当前项目资金明细');
            const rowErrors = row => {
                const problems = [];
                if (mode.value === 'projects') {
                    if (!row.project_name?.trim()) problems.push('填写项目名称');
                    if (!row.investor_name?.trim()) problems.push('填写投资主体');
                    for (const field of ['opening_invested', 'opening_recovered']) {
                        const amount = parseMoney(row[field], 'yuan');
                        if (amount === null || amount === '' || (field === 'opening_invested' && Number(amount) < 0)) problems.push(field === 'opening_invested' ? '核对累计投入' : '核对累计收回');
                    }
                    const date = parseDate(row.opening_as_of);
                    if (date.error || date.precision !== 'day' || date.date > props.today) problems.push('填写有效的余额截至日');
                } else {
                    const date = parseDate(row.date);
                    if (date.error || date.precision !== row.precision) problems.push(row.date_error || '填写与粒度一致的有效日期');
                    if (!date.error && date.date > (row.precision === 'month' ? props.today.slice(0, 7) : props.today)) problems.push('未来日期不能作为实际资金导入');
                    if (!['investment', 'recovery'].includes(row.kind)) problems.push('选择投入或收回');
                    const amount = parseMoney(row.amount, 'yuan');
                    if (amount === null || amount === '' || Number(amount) < 0 || (row.kind === 'investment' && Number(amount) === 0)) problems.push('核对金额');
                }
                return problems;
            };
            const invalidCount = computed(() => activeRows.value.filter(row => rowErrors(row).length).length);
            const rowPayload = row => mode.value === 'projects' ? { row_number: row.row_number, project_name: row.project_name?.trim() || '', investor_name: row.investor_name?.trim() || '', opening_invested: parseMoney(row.opening_invested, 'yuan') ?? row.opening_invested, opening_recovered: parseMoney(row.opening_recovered, 'yuan') ?? row.opening_recovered, opening_as_of: parseDate(row.opening_as_of).date || row.opening_as_of } : { row_number: row.row_number, date: parseDate(row.date).date || row.date, precision: row.precision, kind: row.kind, amount: parseMoney(row.amount, 'yuan') ?? row.amount, note: row.note || '', confirmed_zero: row.kind === 'recovery' && parseMoney(row.amount, 'yuan') === '0.00' };
            const reviewPayload = () => ({ mode: mode.value, project_id: mode.value === 'entries' ? Number(projectId.value) : null, rows: staged.value.map(row => ({ ...rowPayload(row), selected: row.selected === true })) });
            const reviewCurrent = computed(() => review.value !== null && reviewIdentity === JSON.stringify(reviewPayload()));
            const canConfirm = computed(() => !busy.value && !pendingChange.value && !closePrompt.value && !checking.value && reviewCurrent.value && review.value.can_confirm === true && reviewed.value && (review.value.similar_count === 0 || similarConfirmed.value) && activeRows.value.length > 0 && activeRows.value.length <= 200 && invalidCount.value === 0 && (mode.value === 'projects' || Number(projectId.value) > 0));
            const request = async (path, payload, timeoutMs = 45000) => {
                const controller = new AbortController();
                const timer = window.setTimeout(() => controller.abort(), timeoutMs);
                try {
                    const response = await props.request(`/investment-payback/import/${path}`, { method: 'POST', withBusinessContext: false, signal: controller.signal, body: JSON.stringify(payload) });
                    if (Number(response?.code) !== 200 || !response?.data) { const failure = new Error(response?.message || response?.msg || '导入请求未完成'); failure.code = Number(response?.code); throw failure; }
                    return response.data;
                } catch (caught) {
                    if (controller.signal.aborted) throw new Error(path === 'confirm' ? '导入结果尚未确认，请用同一份预览重试；系统会核对重复记录。' : '识别超时，请重试或改用表格文件。');
                    throw caught;
                } finally { window.clearTimeout(timer); }
            };
            const invalidateReview = () => { ++reviewSequence; review.value = null; reviewIdentity = ''; checking.value = false; reviewed.value = false; similarConfirmed.value = false; if (reviewTimer !== null) { window.clearTimeout(reviewTimer); reviewTimer = null; } };
            const clearRows = () => { invalidateReview(); staged.value = []; generatedRows = ''; generatedSettings = null; sourceError = ''; error.value = ''; };
            const recognitionSettings = () => ({ mode: mode.value, sheetIndex: sheetIndex.value, headerRow: headerRow.value, year: year.value, fallbackDate: fallbackDate.value, defaultKind: defaultKind.value, unit: unit.value, mapping: { ...mapping } });
            const restoreSettings = settings => {
                mode.value = settings.mode; sheetIndex.value = settings.sheetIndex; headerRow.value = settings.headerRow;
                year.value = settings.year; fallbackDate.value = settings.fallbackDate; defaultKind.value = settings.defaultKind; unit.value = settings.unit;
                Object.assign(mapping, settings.mapping);
            };
            const preserveRevisions = (action, regenerate = false) => {
                if (disposed || busy.value || pendingChange.value) return;
                if (staged.value.length && generatedSettings && JSON.stringify(staged.value) !== generatedRows) {
                    const nextSettings = recognitionSettings();
                    restoreSettings(generatedSettings);
                    pendingApply = () => { restoreSettings(nextSettings); action(); return buildRows(); };
                    closePrompt.value = false; pendingChange.value = true;
                    Vue.nextTick?.(() => { if (!disposed && pendingChange.value && !busy.value) document.querySelector('[data-testid="payback-import-keep-preview"]')?.focus(); });
                    return;
                }
                const result = action();
                return regenerate ? buildRows() : result;
            };
            const focusAfterChoice = () => Vue.nextTick?.(() => { if (!disposed && !busy.value && !pendingChange.value) document.querySelector('[data-testid="payback-import-dialog"]')?.focus(); });
            const keepPreview = () => { if (!busy.value) { pendingApply = null; pendingChange.value = false; focusAfterChoice(); } };
            const regeneratePreview = () => {
                if (disposed || busy.value || !pendingChange.value || !pendingApply) return;
                const apply = pendingApply; pendingApply = null; pendingChange.value = false;
                const result = apply(); focusAfterChoice(); return result;
            };
            const resetRows = () => preserveRevisions(clearRows);
            const checkRows = async () => {
                if (!staged.value.length || busy.value || (mode.value === 'entries' && Number(projectId.value) <= 0)) return;
                if (reviewTimer !== null) { window.clearTimeout(reviewTimer); reviewTimer = null; }
                const payload = reviewPayload(), identity = JSON.stringify(payload), sequence = ++reviewSequence;
                review.value = null; reviewIdentity = ''; reviewed.value = false; similarConfirmed.value = false; checking.value = true; error.value = sourceError;
                try {
                    const result = await request('preview', { review_rows: true, ...payload });
                    if (disposed || sequence !== reviewSequence || identity !== JSON.stringify(reviewPayload())) return;
                    if (!/^[a-f0-9]{64}$/.test(result.review_token || '') || !Array.isArray(result.rows) || result.rows.length !== payload.rows.length || result.rows.some((row, index) => row.row_number !== payload.rows[index].row_number || !['errors', 'exact_matches', 'batch_duplicates', 'similar_matches'].every(key => Array.isArray(row[key]))) || !result.impact || !['actual_invested_delta', 'actual_net_recovered_delta', 'opening_invested_total', 'opening_net_recovered_total'].every(key => /^-?\d+\.\d{2}$/.test(result.impact[key] || '')) || typeof result.can_confirm !== 'boolean' || !Number.isSafeInteger(result.similar_count)) throw new Error('重复检查或金额影响未完整返回，请重新检查。');
                    review.value = result; reviewIdentity = identity;
                } catch (caught) { if (!disposed && sequence === reviewSequence && identity === JSON.stringify(reviewPayload())) error.value = `${sourceError ? `${sourceError} ` : ''}导入检查失败：${caught.message}`; }
                finally { if (sequence === reviewSequence) checking.value = false; }
            };
            const changed = () => { invalidateReview(); reviewTimer = window.setTimeout(() => { reviewTimer = null; checkRows(); }, 250); };
            const rowReview = row => reviewCurrent.value ? review.value.rows.find(item => item.row_number === row.row_number) : null;
            const excludeExact = () => { if (!reviewCurrent.value) return; const blocked = new Set(review.value.rows.filter(row => row.exact_matches.length || row.batch_duplicates.length).map(row => row.row_number)); staged.value.forEach(row => { if (blocked.has(row.row_number)) row.selected = false; }); changed(); };
            const initializeSheet = () => { clearRows(); const found = suggestHeader(matrix.value); headerRow.value = found.score ? found.index + 1 : 0; Object.assign(mapping, suggestMapping(headers.value)); };
            const selectSheet = () => preserveRevisions(initializeSheet);
            const remap = () => preserveRevisions(() => { clearRows(); Object.assign(mapping, suggestMapping(headers.value)); });
            const sourceFailure = message => { sourceError = preview.value ? `${message} 原预览与人工修改已保留，仍属于此前来源“${preview.value.file_name}”。` : message; error.value = sourceError; };
            const withinTableBounds = rows => Array.isArray(rows) && rows.length <= 500 && rows.every(row => Array.isArray(row) && row.length <= 32);
            const hasTableContent = rows => rows.some(row => row.some(cell => String(cell ?? '').trim()));
            const readFile = async file => {
                if (!file || busy.value || pendingChange.value) return;
                sourceError = ''; error.value = '';
                if (!/\.(xlsx|xls|csv|png|jpe?g|webp)$/i.test(file.name)) { sourceFailure('请选择 Excel、CSV 或 PNG / JPG / WebP 图片。'); return; }
                if (file.size <= 0 || file.size > 10 * 1024 * 1024) { sourceFailure('文件不能为空，且不能超过 10 MB。'); return; }
                const current = ++generation;
                busy.value = true;
                try {
                    const dataUrl = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = () => reject(new Error('文件读取失败，请重新选择文件。')); reader.readAsDataURL(file); });
                    const result = await request('preview', { file_name: file.name, file_base64: dataUrl.slice(dataUrl.indexOf(',') + 1) });
                    if (disposed || generation !== current) return;
                    if (!Array.isArray(result.sheets) || !result.sheets.length || !/^[a-f0-9]{64}$/i.test(result.sha256 || '')) throw new Error('识别结果缺少表格或来源信息，请重试。');
                    if (!result.sheets.every(sheet => withinTableBounds(sheet?.rows))) throw new Error('识别表格无效或过大，请分批导入（最多 500 行、32 列）。');
                    if (!result.sheets.some(sheet => hasTableContent(sheet.rows))) throw new Error('识别结果没有表格内容，请核对来源后重试。');
                    preview.value = result; sheetIndex.value = 0;
                    image.value = /\.(png|jpe?g|webp)$/i.test(file.name) ? dataUrl : '';
                    initializeSheet();
                } catch (caught) { if (!disposed && current === generation) sourceFailure(caught.message); }
                finally { if (current === generation) busy.value = false; }
            };
            const pickFile = event => {
                const file = event.target.files?.[0];
                event.target.value = '';
                return readFile(file);
            };
            const dropFile = event => readFile(event.dataTransfer?.files?.[0]);
            const pasteImage = event => { const file = Array.from(event.clipboardData?.files || []).find(item => item.type.startsWith('image/')); if (file) { event.preventDefault(); readFile(file); } };
            const usePasted = async () => {
                if (busy.value || pendingChange.value) return;
                sourceError = ''; error.value = '';
                const raw = pasted.value.trim();
                if (!raw) { sourceFailure('请先从表格复制并粘贴单元格。'); return; }
                if (raw.length > 1000000) { sourceFailure('粘贴内容过大，请分批导入（最多 500 行、32 列）。'); return; }
                let rows;
                try { rows = parsePastedTable(raw); } catch (caught) { sourceFailure(caught.message); return; }
                if (!withinTableBounds(rows)) { sourceFailure('粘贴内容过大，请分批导入（最多 500 行、32 列）。'); return; }
                if (!hasTableContent(rows)) { sourceFailure('粘贴内容没有表格内容，请重新复制单元格。'); return; }
                const current = ++generation;
                busy.value = true;
                try {
                    const bytes = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
                    if (disposed || generation !== current) return;
                    const hash = Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('');
                    preview.value = { file_name: '粘贴表格.txt', sha256: hash, source_method: 'pasted_table', sheets: [{ name: '粘贴表格', rows }], warnings: [] };
                    image.value = ''; sheetIndex.value = 0; initializeSheet();
                } catch (caught) { if (!disposed && generation === current) sourceFailure(`粘贴表格读取失败：${caught.message}`); }
                finally { if (generation === current) busy.value = false; }
            };
            const buildRows = () => {
                clearRows();
                const cell = (cells, key) => Number(mapping[key]) >= 0 ? String(cells[Number(mapping[key])] ?? '').trim() : '';
                staged.value = matrix.value.slice(Number(headerRow.value) || 0).flatMap((cells, index) => {
                    if (!cells.some(value => String(value).trim())) return [];
                    const primaryColumn = Number(mapping[mode.value === 'projects' ? 'project_name' : 'date']);
                    const rowLabel = primaryColumn >= 0 ? cells[primaryColumn] : cells.find(value => String(value).trim());
                    if (/^(合计|总计|小计)$/.test(String(rowLabel ?? '').trim())) return [];
                    const row = { selected: true, row_number: index + (Number(headerRow.value) || 0) + 1 };
                    if (mode.value === 'projects') {
                        row.project_name = cell(cells, 'project_name'); row.investor_name = cell(cells, 'investor_name');
                        for (const field of ['opening_invested', 'opening_recovered']) { const effectiveUnit = unit.value === 'auto' && /万/.test(headers.value[Number(mapping[field])] || '') ? 'wan' : unit.value; row[field] = parseMoney(cell(cells, field), effectiveUnit) ?? cell(cells, field); }
                        const rawDate = cell(cells, 'opening_as_of') || fallbackDate.value;
                        const date = parseDate(rawDate, year.value); row.opening_as_of = date.error || date.precision !== 'day' ? rawDate : date.date;
                    } else {
                        const rawDate = cell(cells, 'date'); const date = parseDate(rawDate, year.value);
                        row.date = date.error ? rawDate : date.precision === 'month' ? date.date.slice(0, 7) : date.date; row.date_error = date.error; row.precision = date.precision;
                        row.kind = Number(mapping.kind) >= 0 ? parseKind(cell(cells, 'kind')) : defaultKind.value;
                        const effectiveUnit = unit.value === 'auto' && /万/.test(headers.value[Number(mapping.amount)] || '') ? 'wan' : unit.value;
                        row.amount = parseMoney(cell(cells, 'amount'), effectiveUnit) ?? cell(cells, 'amount'); row.note = cell(cells, 'note');
                    }
                    return [row];
                });
                generatedRows = JSON.stringify(staged.value); generatedSettings = recognitionSettings();
                if (!staged.value.length) error.value = '没有可导入的数据行，请核对工作表和表头位置。';
                else return checkRows();
            };
            const generate = () => preserveRevisions(() => {}, true);
            const payloadRows = () => activeRows.value.map(rowPayload);
            const confirm = async () => {
                if (!canConfirm.value) return;
                const payload = { confirmed: true, source_file_name: preview.value.file_name, source_sha256: preview.value.sha256, source_method: preview.value.source_method, mode: mode.value, project_id: mode.value === 'entries' ? Number(projectId.value) : null, rows: payloadRows(), review_token: review.value.review_token, similar_confirmed: similarConfirmed.value === true };
                const digest = JSON.stringify({ ...payload, review_token: null, similar_confirmed: null });
                if (requestPayload !== digest) { requestIdentity = window.crypto.randomUUID(); requestPayload = digest; }
                busy.value = true; error.value = '';
                try {
                    const result = await request('confirm', { ...payload, client_request_id: requestIdentity });
                    if (disposed) return;
                    if (!Number.isSafeInteger(result.imported_count) || result.imported_count !== payload.rows.length) throw new Error('导入数量未确认，请用同一份预览重试。');
                    emit('saved', result);
                } catch (caught) { if (caught.code === 409) invalidateReview(); error.value = caught.message; }
                finally { busy.value = false; }
            };
            const close = () => {
                if (busy.value) return;
                keepPreview();
                if (preview.value || pasted.value.trim()) {
                    closePrompt.value = true;
                    Vue.nextTick?.(() => { if (!disposed && closePrompt.value && !busy.value) document.querySelector('[data-testid="payback-import-continue"]')?.focus(); });
                    return;
                }
                emit('close');
            };
            const continueImport = () => { if (!busy.value) { closePrompt.value = false; Vue.nextTick?.(() => { if (!disposed && !closePrompt.value && !busy.value) document.querySelector('[data-testid="payback-import-dialog"]')?.focus(); }); } };
            const discardImport = () => { if (!busy.value && closePrompt.value) emit('close'); };
            const dialogKey = event => {
                if (event.key === 'Escape') { event.preventDefault(); if (pendingChange.value) keepPreview(); else close(); return; }
                if (event.key !== 'Tab') return;
                const controls = [...event.currentTarget.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary')].filter(el => !el.matches(':disabled') && el.getClientRects().length);
                const first = controls[0], last = controls[controls.length - 1];
                if (!first || document.activeElement === event.currentTarget) { event.preventDefault(); (first ? event.shiftKey ? last : first : event.currentTarget).focus(); return; }
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            };
            Vue.watch?.(busy, processing => { if (processing) Vue.nextTick?.(() => { if (!disposed && busy.value) document.querySelector('[data-testid="payback-import-dialog"]')?.focus(); }); });
            Vue.onMounted?.(() => Vue.nextTick?.(() => document.querySelector('[data-testid="payback-import-dialog"] input[type="file"]')?.focus()));
            Vue.onUnmounted?.(() => { disposed = true; ++generation; pendingApply = null; invalidateReview(); });
            return { busy, error, preview, image, pasted, closePrompt, pendingChange, keepPreview, regeneratePreview, mode, projectId, sheetIndex, headerRow, year, fallbackDate, defaultKind, unit, reviewed, staged, mapping, sheets, matrix, headers, activeRows, modeLabel, invalidCount, canConfirm, rowErrors, exactTotal, resetRows, selectSheet, remap, pickFile, dropFile, pasteImage, usePasted, generate, confirm, close, continueImport, discardImport, dialogKey, review, checking, similarConfirmed, reviewCurrent, checkRows, changed, rowReview, excludeExact };
        },
        template: `
          <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/30 p-3 sm:p-6" @click.self="close">
            <section data-testid="payback-import-dialog" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="payback-import-title" class="w-full overflow-y-auto rounded-xl bg-white p-5 shadow-xl sm:p-6" style="max-height:90vh;max-width:1080px" @keydown="dialogKey" @paste="pasteImage">
              <header class="mb-5 flex items-start justify-between gap-3"><div><h3 id="payback-import-title" class="text-lg font-semibold text-gray-900">导入表格 / 图片</h3><p class="mt-1 text-sm text-gray-500">上传 → 核对识别结果 → 确认入账</p></div><button type="button" class="px-3 text-gray-500" style="min-height:44px;min-width:44px" :disabled="busy" @click="close" aria-label="关闭导入">✕</button></header>
              <fieldset :disabled="busy || pendingChange" class="min-w-0 space-y-4">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4" @dragover.prevent @drop.prevent="dropFile"><label class="block text-sm font-medium text-gray-700">选择表格或图片<input type="file" accept=".xlsx,.xls,.csv,.png,.jpg,.jpeg,.webp" class="mt-2 block w-full min-w-0 text-sm" @change="pickFile" /></label><p class="mt-2 text-xs text-gray-500">支持 Excel、CSV、PNG / JPG / WebP，最多 10 MB。图片在本机识别，也可拖入文件或粘贴截图。</p></div>
                <details class="border-b pb-3"><summary class="cursor-pointer py-2 text-sm text-gray-500">粘贴 Excel 单元格或 CSV 表格</summary><textarea v-model="pasted" aria-label="粘贴表格内容" rows="3" class="mt-2 w-full min-w-0 rounded-lg border px-3 py-2 text-sm" placeholder="粘贴包含表头的单元格或 CSV 内容"></textarea><button type="button" class="mt-2 rounded-lg border px-3 py-2 text-sm" style="min-height:44px" @click="usePasted">读取粘贴表格</button></details>
                <template v-if="preview">
                  <div class="flex flex-wrap gap-3 text-sm text-gray-700"><span>{{ preview.file_name }}</span><span>{{ preview.source_method === 'image_ocr' ? '图片识别，金额与日期请逐项核对' : '表格读取，待核对' }}</span></div>
                  <p v-for="(warning,index) in preview.warnings || []" :key="index" class="text-sm text-amber-800">{{ warning }}</p>
                  <details v-if="image || preview.raw_text"><summary class="cursor-pointer py-2 text-sm text-gray-500">查看原图与识别文字</summary><img v-if="image" :src="image" alt="待核对的导入原图" class="mt-2 max-w-full rounded-lg border" style="max-height:320px;object-fit:contain" /><pre v-if="preview.raw_text" class="mt-3 overflow-x-auto whitespace-pre-wrap text-xs text-gray-600">{{ preview.raw_text }}</pre></details>
                  <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="block text-sm text-gray-700">导入内容<select :key="pendingChange" v-model="mode" @change="resetRows" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px"><option value="projects">新建项目累计余额</option><option value="entries">项目资金明细</option></select></label>
                    <label v-if="mode === 'entries'" class="block text-sm text-gray-700">入账项目 *<select v-model="projectId" @change="changed" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px"><option value="">请选择已有项目</option><option v-for="item in projects" :key="item.id" :value="item.id">{{ item.project_name }} · {{ item.investor_name || '投资人未填' }}</option></select></label>
                    <label class="block text-sm text-gray-700">工作表<select :key="pendingChange" v-model="sheetIndex" @change="selectSheet" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px"><option v-for="(sheet,index) in sheets" :key="index" :value="index">{{ sheet.name }}</option></select></label>
                    <label class="block text-sm text-gray-700">表头所在行<input :key="pendingChange" v-model="headerRow" @change="remap" type="number" min="0" :max="matrix.length" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px" /><span class="mt-1 block text-xs text-gray-500">无表头填 0。标题行不会作为账目。</span></label>
                  </div>
                  <details><summary class="cursor-pointer py-2 text-sm text-gray-500">查看读取到的表格</summary><div class="mt-2 overflow-x-auto rounded-lg border"><table class="w-full text-xs"><tbody><tr v-for="(cells,index) in matrix.slice(0,12)" :key="index"><td class="border-b px-2 py-2 text-gray-500">{{ index+1 }}</td><td v-for="(cell,column) in cells" :key="column" class="border-b px-2 py-2" style="min-width:100px">{{ cell }}</td></tr></tbody></table></div><p class="mt-1 text-xs text-gray-500">预览前 12 行，共 {{ matrix.length }} 行。</p></details>
                  <details class="border-t pt-4" :open="!staged.length"><summary class="cursor-pointer text-sm font-medium text-gray-800">核对列对应关系</summary><p v-if="mode === 'entries'" class="mt-1 text-xs text-gray-500">多人分红表请选择属于你的实收金额列，确认原表单位。</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <label v-for="field in mode==='projects' ? [['project_name','项目名称'],['investor_name','投资主体 *'],['opening_invested','累计投入'],['opening_recovered','累计净收回'],['opening_as_of','余额截至日']] : [['date','日期'],['kind','类型（选填）'],['amount','金额'],['note','备注（选填）']]" :key="field[0]" class="block text-sm text-gray-700">{{ field[1] }}<select :key="pendingChange" v-model="mapping[field[0]]" @change="resetRows" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px"><option :value="-1">未匹配，预览中补填</option><option v-for="(name,index) in headers" :key="index" :value="index">第 {{ index+1 }} 列 · {{ name || '空表头' }}</option></select></label>
                      <label class="block text-sm text-gray-700">原表金额单位<select :key="pendingChange" v-model="unit" @change="resetRows" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px"><option value="auto">按单元格 / 表头单位识别，无单位按元</option><option value="yuan">元</option><option value="wan">万元</option></select></label>
                      <label class="block text-sm text-gray-700">原表年份（日期缺少年份时填写）<input :key="pendingChange" v-model="year" @change="resetRows" inputmode="numeric" maxlength="4" placeholder="例如 2026，不自动推断年份" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px" /></label>
                      <label v-if="mode==='projects'" class="block text-sm text-gray-700">统一余额截至日（原表无日期时填写）<input :key="pendingChange" v-model="fallbackDate" @change="resetRows" type="date" :max="today" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px" /></label>
                      <label v-if="mode==='entries' && Number(mapping.kind)<0" class="block text-sm text-gray-700">无类型列时按<select :key="pendingChange" v-model="defaultKind" @change="resetRows" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2" style="min-height:44px"><option value="">请选择投入或收回，也可逐行指定</option><option value="recovery">实际收回</option><option value="investment">实际投入</option></select></label>
                    </div>
                    <button type="button" class="mt-4 rounded-lg border px-4 py-2 text-sm font-medium" style="min-height:44px" @click="generate">{{ staged.length ? '重新生成入账预览' : '生成入账预览' }}</button>
                  </details>
                  <div v-if="staged.length" class="border-t pt-4">
                    <h4 class="text-sm font-medium text-gray-800">入账预览 · {{ modeLabel }}</h4><p class="mt-1 text-xs text-gray-500">编辑或排除行后会重新检查。完全相同的记录请明确排除；相似候选须核对为不同的真实资金，确认后整批入账。</p>
                    <div class="mt-3 overflow-x-auto rounded-lg border"><table class="w-full text-sm" style="min-width:760px"><thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">导入 / 原行</th><template v-if="mode==='projects'"><th class="px-3 py-2">项目</th><th class="px-3 py-2">投资人</th><th class="px-3 py-2">截至日</th><th class="px-3 py-2">投入（元）</th><th class="px-3 py-2">净收回（元）</th></template><template v-else><th class="px-3 py-2">日期</th><th class="px-3 py-2">精度</th><th class="px-3 py-2">类型</th><th class="px-3 py-2">金额（元）</th><th class="px-3 py-2">备注</th></template><th class="px-3 py-2">核对</th></tr></thead><tbody><tr v-for="row in staged" :key="row.row_number" class="border-t"><td class="px-3 py-2"><label class="flex items-center gap-2"><input v-model="row.selected" type="checkbox" @change="changed" :aria-label="'导入第'+row.row_number+'行'" />{{ row.row_number }}</label></td><template v-if="mode==='projects'"><td class="px-2 py-2"><input v-model="row.project_name" @input="changed" :aria-label="'第'+row.row_number+'行项目名称'" class="w-full rounded border px-2" style="min-height:44px;min-width:140px" maxlength="120" /></td><td class="px-2 py-2"><input v-model="row.investor_name" @input="changed" :aria-label="'第'+row.row_number+'行投资人'" class="w-full rounded border px-2" style="min-height:44px;min-width:100px" maxlength="120" /></td><td class="px-2 py-2"><input v-model="row.opening_as_of" @input="changed" :aria-label="'第'+row.row_number+'行截至日'" type="date" :max="today" class="w-full rounded border px-2" style="min-height:44px" /></td><td class="px-2 py-2"><input v-model="row.opening_invested" @input="changed" :aria-label="'第'+row.row_number+'行累计投入'" inputmode="decimal" class="w-full rounded border px-2 text-right" style="min-height:44px;min-width:110px" /></td><td class="px-2 py-2"><input v-model="row.opening_recovered" @input="changed" :aria-label="'第'+row.row_number+'行累计收回'" inputmode="decimal" class="w-full rounded border px-2 text-right" style="min-height:44px;min-width:110px" /></td></template><template v-else><td class="px-2 py-2"><input v-model="row.date" @input="changed" :aria-label="'第'+row.row_number+'行日期'" :type="row.precision==='month'?'month':'text'" placeholder="YYYY-MM-DD" class="w-full rounded border px-2" style="min-height:44px;min-width:140px" /></td><td class="px-2 py-2"><select v-model="row.precision" @change="changed" :aria-label="'第'+row.row_number+'行日期精度'" class="rounded border px-2" style="min-height:44px"><option value="day">实际日期</option><option value="month">业务月份</option></select></td><td class="px-2 py-2"><select v-model="row.kind" @change="changed" :aria-label="'第'+row.row_number+'行类型'" class="rounded border px-2" style="min-height:44px"><option value="">待核对</option><option value="investment">投入</option><option value="recovery">收回</option></select></td><td class="px-2 py-2"><input v-model="row.amount" @input="changed" :aria-label="'第'+row.row_number+'行金额'" inputmode="decimal" class="w-full rounded border px-2 text-right" style="min-height:44px;min-width:110px" /></td><td class="px-2 py-2"><textarea v-model="row.note" @input="changed" :aria-label="'第'+row.row_number+'行备注'" rows="2" class="w-full rounded border px-2 py-1" style="min-height:44px;min-width:140px" maxlength="1500"></textarea></td></template><td class="px-3 py-2 text-xs" style="min-width:130px"><span v-if="rowErrors(row).length" class="text-red-700">{{ rowErrors(row).join('；') }}</span><span v-else-if="!row.selected" class="text-gray-500">已排除</span><span v-else-if="!reviewCurrent" class="text-gray-500">待重新检查</span><span v-else-if="rowReview(row)?.errors.length" class="text-red-700">{{ rowReview(row).errors.join('；') }}</span><span v-else-if="rowReview(row)?.exact_matches.length || rowReview(row)?.batch_duplicates.length" class="text-red-700">完全重复，请排除</span><span v-else-if="rowReview(row)?.similar_matches.length" class="text-amber-800">相似候选，请核对</span><span v-else-if="rowReview(row)?.impact_excluded_reason" class="text-amber-800">不计本次实际增量</span><span v-else class="text-gray-500">检查通过，待确认</span></td></tr></tbody></table></div>
                    <div class="mt-3 flex flex-wrap items-center gap-3"><span class="text-sm text-gray-700">选中 {{ activeRows.length }} 行</span><button type="button" :disabled="checking" class="rounded-lg border px-3 py-2 text-sm" style="min-height:44px" @click="checkRows">{{ checking ? '检查中…' : '重新检查重复与金额影响' }}</button><button v-if="reviewCurrent && review.exact_count" type="button" class="rounded-lg border px-3 py-2 text-sm" style="min-height:44px" @click="excludeExact">排除全部完全重复行</button></div>
                    <p v-if="!reviewCurrent" role="status" class="mt-2 text-sm text-gray-600">{{ checking ? '正在检查当前项目账目、重复记录与金额影响…' : '预览检查已失效，请重新检查后确认。' }}</p>
                    <div v-if="reviewCurrent" data-testid="payback-import-impact" class="mt-3 rounded-lg border bg-gray-50 p-3 text-sm text-gray-700"><p v-if="mode==='entries'">截至 {{ review.as_of }}，本次实际投入增量 {{ exactTotal([{amount:review.impact.actual_invested_delta}],'amount') }} · 净收回增量 {{ exactTotal([{amount:review.impact.actual_net_recovered_delta}],'amount') }}</p><p v-else>本次新建项目期初投入 {{ exactTotal([{amount:review.impact.opening_invested_total}],'amount') }} · 期初净收回 {{ exactTotal([{amount:review.impact.opening_net_recovered_total}],'amount') }}</p><p class="mt-1 text-xs text-gray-500">仅合计有效选中且不重复的行；期初累计余额单独显示，计划、未来、期初重叠和未结束月份不计入本次实际增量。相似候选仍需人工核对。</p><p v-if="review.exact_count || review.invalid_count" role="alert" class="mt-2 text-red-700">{{ review.exact_count }} 行完全重复，{{ review.invalid_count }} 行无效；修正或排除后再确认。</p></div>
                    <div v-if="reviewCurrent" class="mt-3 space-y-2"><details v-for="item in review.rows.filter(item => item.errors.length || item.exact_matches.length || item.batch_duplicates.length || item.similar_matches.length || item.impact_excluded_reason)" :key="item.row_number" :open="item.selected && (item.exact_matches.length || item.batch_duplicates.length || item.errors.length)" class="rounded-lg border p-3 text-xs text-gray-700"><summary class="cursor-pointer text-sm">原第 {{ item.row_number }} 行 · {{ item.selected ? '选中' : '已排除' }} · {{ item.exact_matches.length || item.batch_duplicates.length ? '完全重复' : item.errors.length ? '待修正' : item.similar_matches.length ? '相似候选，待人工核对' : '不计本次实际增量' }}</summary><p v-for="message in item.errors" :key="message" class="mt-2 text-red-700">{{ message }}</p><p v-if="item.impact_excluded_reason==='period_after_today'" class="mt-2">本月尚未结束，按完整月份口径暂不计入当前实际增量。</p><p v-for="(match,index) in item.exact_matches.concat(item.batch_duplicates,item.similar_matches)" :key="index" class="mt-2 break-words">{{ match.row_number ? '本批原第 '+match.row_number+' 行' : '已有记录 #'+match.id }} · {{ match.project_name || match.date || '' }} {{ match.investor_name || '' }} {{ match.kind==='investment'?'投入':match.kind==='recovery'?'收回':'' }} {{ match.amount || match.opening_invested || '' }} {{ match.note || '' }} · {{ match.reason }}</p><p v-if="item.similar_match_count>20" class="mt-2">共有 {{ item.similar_match_count }} 条相似候选，显示前 20 条；请逐行核对来源。</p></details></div>
                    <p v-if="invalidCount || activeRows.length>200" role="alert" class="mt-2 text-sm text-red-700">{{ activeRows.length>200 ? '每批最多确认 200 行，请取消部分行或分批导入。' : invalidCount+' 行需要修正，修正或取消勾选后再确认。' }}</p>
                    <label v-if="reviewCurrent && review.similar_count" class="mt-4 flex items-start gap-2 text-sm text-amber-800"><input v-model="similarConfirmed" type="checkbox" class="mt-1" />我已逐项核对相似候选，确认选中行是不同的真实记录，保留这些记录。</label>
                    <label class="mt-4 flex items-start gap-2 text-sm text-gray-700"><input v-model="reviewed" :disabled="!reviewCurrent || !review.can_confirm || checking" type="checkbox" class="mt-1" />我已核对项目、日期、单位和金额，确认导入选中记录。</label>
                  </div>
                </template>
              </fieldset>
              <div v-if="pendingChange" role="alert" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-800"><p class="font-medium">重新生成将替换人工修改</p><p class="mt-1">金额、备注和排除状态会恢复为原表值。保留原预览可继续核对；选择重新生成后才会应用新设置。</p><div class="mt-3 flex flex-wrap gap-3"><button data-testid="payback-import-keep-preview" type="button" :disabled="busy" class="rounded-lg px-3 py-2" style="min-height:44px;background:var(--sx-luxury-green,#143a31);color:white" @click="keepPreview">保留原预览</button><button data-testid="payback-import-regenerate" type="button" :disabled="busy" class="rounded-lg border px-3 py-2 text-red-700" style="min-height:44px" @click="regeneratePreview">放弃修订并重新生成</button></div></div>
              <p v-if="busy" role="status" class="mt-4 text-sm text-gray-600">正在处理，请稍候…</p><p v-if="error" role="alert" class="mt-4 text-sm text-red-700">{{ error }}</p>
              <div v-if="closePrompt" role="alert" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p>本次导入尚未确认，放弃会丢失粘贴内容、已读取预览及人工修改。</p><div class="mt-3 flex flex-wrap gap-3"><button data-testid="payback-import-continue" type="button" :disabled="busy" class="rounded-lg border px-3 py-2" style="min-height:44px" @click="continueImport">继续导入</button><button data-testid="payback-import-discard" type="button" :disabled="busy" class="rounded-lg border px-3 py-2" style="min-height:44px" @click="discardImport">放弃本次导入</button></div></div>
              <footer class="mt-5 flex flex-wrap justify-end gap-3 border-t pt-4"><button type="button" :disabled="busy" class="rounded-lg border px-4 py-2 text-sm" style="min-height:44px" @click="close">取消</button><button type="button" :disabled="!canConfirm" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" style="min-height:44px;background:var(--sx-luxury-green,#143a31);color:white" @click="confirm">{{ busy ? '处理中…' : '确认导入 '+activeRows.length+' 行' }}</button></footer>
            </section>
          </div>
        `,
    };
})();
