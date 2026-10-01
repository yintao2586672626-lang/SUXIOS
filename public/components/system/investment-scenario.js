(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const numberField = (key, label, percent = false) => ({ key, label, type: 'number', percent });
    const groups = [
        { name: '基本信息与来源', open: true, fields: [
            { key: 'scenario_name', label: '测算名称', type: 'text' }, { key: 'as_of', label: '测算基准日期', type: 'date' },
            { key: 'source_label', label: '假设来源说明', type: 'text' }, { key: 'source_ref', label: '参考文件 / 来源编号', type: 'text' },
        ] },
        { name: '经营假设', open: true, fields: [
            numberField('rooms', '经营客房数（间）'), numberField('years', '测算经营年数（年）'),
            numberField('adr_first_year', '首年平均房价 ADR（元 / 间夜）'), numberField('occupancy_first_year', '首年入住率（%）', true),
            numberField('occupancy_mature', '成熟期入住率（%）', true), numberField('mature_from_year', '成熟期开始经营年'),
            numberField('adr_growth_rate', '房价年增长率（%）', true), numberField('adr_growth_from_year', '房价增长开始经营年'),
            { key: 'operating_cost_basis', label: '经营成本计价口径', type: 'select', options: [
                { value: 'available_room_night', label: '按可售间夜（空房也计成本）' },
                { value: 'occupied_room_night', label: '按已售间夜' }, { value: 'fixed_variable', label: '固定年成本 + 已售间夜变动成本' },
            ] },
            numberField('operating_cost_per_night', '单位经营成本（元 / 间夜）'), numberField('fixed_annual_operating_cost', '固定年度经营成本（元）'),
            numberField('operating_cost_growth_rate', '经营成本年增长率（%）', true), numberField('management_fee_rate', '营业额管理费率（%）', true),
            numberField('days_per_year', '每年经营天数（天）'),
        ] },
        { name: '租赁及营建', open: false, fields: [
            numberField('leased_rooms', '租赁计价房间数（间）'), numberField('monthly_rent_per_room', '每间月租金（元 / 间月）'),
            numberField('rent_free_months', '免租月数（月）'), numberField('construction_months', '营建期（月）'),
        ] },
        { name: '初始投资与折旧', open: false, fields: [
            numberField('renovation_cash', '装修投入现金（元）'), numberField('franchise_cash', '加盟投入现金（元）'),
            numberField('refundable_deposit_cash', '可退押金现金（元）'), numberField('other_initial_cash', '其他初始投入（元）'),
            numberField('working_capital_cash', '初始周转金（元）'), numberField('depreciable_amount', '可折旧金额（元）'),
            numberField('depreciation_years', '折旧年限（年）'),
        ] },
    ];
    const fields = groups.flatMap(group => group.fields);
    const adjustmentFields = [
        numberField('tax_cash', '税费现金（元）'), numberField('financing_net_cash', '融资净流入（元，可为负）'),
        numberField('maintenance_capex', '维护资本开支（元）'), numberField('working_capital_change', '周转金增加（元，可为负）'),
        numberField('deposit_refund', '押金退回（元）'), numberField('salvage_cash', '残值回收（元）'),
    ];
    const blankForm = () => Object.assign(Object.fromEntries(fields.map(field => [field.key, ''])), {
        currency: 'CNY', source_sha256: '', reference_example: false, rent_escalations: [], cash_adjustments: [],
    });
    const numeric = value => value === '' || value === null || value === undefined ? null : Number.isFinite(Number(value)) ? Number(value) : value;
    const displayNumeric = (value, percent = false) => value === null || value === undefined || value === '' ? '' : percent ? String(Number((Number(value) * 100).toFixed(8))) : String(value);
    const toForm = input => {
        const form = blankForm();
        if (!input) return form;
        for (const field of fields) form[field.key] = field.type === 'number' ? displayNumeric(input[field.key], field.percent) : (input[field.key] ?? '');
        form.currency = input.currency || 'CNY';
        form.source_sha256 = input.source_sha256 || '';
        form.reference_example = input.reference_example === true;
        form.rent_escalations = (input.rent_escalations || []).map(row => ({ year: displayNumeric(row.year), rate: displayNumeric(row.rate, true) }));
        form.cash_adjustments = (input.cash_adjustments || []).map(row => Object.assign({ year: displayNumeric(row.year) }, Object.fromEntries(adjustmentFields.map(field => [field.key, displayNumeric(row[field.key])]))));
        return form;
    };
    const toInput = form => {
        const input = {};
        for (const field of fields) {
            const value = field.type === 'number' ? numeric(form[field.key]) : form[field.key];
            input[field.key] = field.percent && typeof value === 'number' ? Number((value / 100).toFixed(10)) : value;
        }
        input.currency = form.currency;
        input.source_sha256 = form.source_sha256;
        input.reference_example = form.reference_example === true;
        input.rent_escalations = form.rent_escalations.map(row => ({ year: numeric(row.year), rate: numeric(row.rate) === null ? null : Number((Number(row.rate) / 100).toFixed(10)) }));
        input.cash_adjustments = form.cash_adjustments.map(row => Object.assign({ year: numeric(row.year) }, Object.fromEntries(adjustmentFields.map(field => [field.key, numeric(row[field.key])]))));
        return input;
    };
    const canonical = value => JSON.stringify(value, (_, item) => item && typeof item === 'object' && !Array.isArray(item) ? Object.fromEntries(Object.keys(item).sort().map(key => [key, item[key]])) : item);
    const money = value => value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? '未明确' : Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const percent = value => value === null || value === undefined || value === '' ? '未明确' : `${Number((Number(value) * 100).toFixed(2))}%`;
    const fieldLabel = key => fields.find(field => field.key === key)?.label || adjustmentFields.find(field => field.key === key)?.label || ({ rent_escalations: '租金涨幅安排', cash_adjustments: '年度现金调整', source_label: '假设来源说明' }[key]) || key;
    const issueLabel = issue => typeof issue === 'string' ? issue : issue?.message || issue?.label || issue?.code || '资料尚未明确';
    const paybackText = payback => {
        if (!payback || payback.status === 'inputs_missing') return '资料不足，待补齐假设';
        if (payback.operating_years !== null && payback.operating_years !== undefined && Number.isFinite(Number(payback.operating_years))) return `经营期约 ${Number(payback.operating_years).toFixed(2)} 年；含营建期约 ${payback.total_years == null ? '未明确' : Number(payback.total_years).toFixed(2)} 年`;
        return '测算期内尚未回本';
    };
    components.InvestmentScenarioWorkbench = {
        name: 'InvestmentScenarioWorkbench',
        props: { request: { type: Function, required: true }, project: { type: Object, required: true }, ledgerBusy: { type: Boolean, default: false } },
        emits: ['saved', 'busy-change'],
        setup(props, { emit }) {
            const { ref, computed, watch, onUnmounted } = Vue;
            const form = ref(blankForm());
            const result = ref(null);
            const loading = ref(false);
            const working = ref(false);
            const error = ref('');
            const notice = ref('');
            const scenarioVersion = ref(null);
            const projectVersion = ref(props.project?.version ?? null);
            const contentDigest = ref('');
            const readback = ref('');
            const writeBlocked = ref(false);
            const loadedFingerprint = ref('');
            const resultFingerprint = ref('');
            const historicalModel = ref(false);
            const pendingReplacement = ref(null);
            const exportContent = ref('');
            const exportFilename = ref('');
            const exportMessage = ref('');
            const exportTextarea = ref(null);
            let activeProjectId = null;
            let generation = 0;
            const readOnly = computed(() => !!props.project?.archived_at);
            const busy = computed(() => loading.value || working.value || props.ledgerBusy);
            const fingerprint = computed(() => canonical(toInput(form.value)));
            const dirty = computed(() => loadedFingerprint.value !== fingerprint.value);
            const stale = computed(() => !!result.value && resultFingerprint.value !== fingerprint.value);
            const annualRows = computed(() => Array.isArray(result.value?.annual_rows) ? result.value.annual_rows : []);
            const endingUnrecovered = computed(() => {
                const ending = result.value?.totals?.ending_cumulative_cash_proxy;
                return ending == null ? null : Math.max(0, -Number(ending));
            });
            const adjustedEndingUnrecovered = computed(() => {
                const ending = result.value?.totals?.ending_cumulative_scenario_cashflow;
                return ending == null ? null : Math.max(0, -Number(ending));
            });
            const cashAdjustmentGaps = computed(() => annualRows.value.filter(row => row.cash_adjustments_missing?.length).map(row => ({ year: row.year, count: row.cash_adjustments_missing.length })));
            const visibleFields = group => group.fields.filter(field => field.key !== 'fixed_annual_operating_cost' || form.value.operating_cost_basis === 'fixed_variable');
            const groupMissing = group => !stale.value ? (result.value?.missing_fields || []).filter(key => group.fields.some(field => field.key === key)).length : 0;
            const clearExport = () => { exportContent.value = ''; exportFilename.value = ''; exportMessage.value = ''; };
            const requestReplacement = kind => {
                if (!dirty.value || String(activeProjectId) !== String(props.project?.id)) return false;
                const label = { blank: '新建空白测算', reference: '载入清远参考样例', reload: '重新读取已保存测算' }[kind];
                pendingReplacement.value = { kind, label };
                return true;
            };
            const setWorking = value => { working.value = value; emit('busy-change', loading.value || working.value); };
            const setLoading = value => { loading.value = value; emit('busy-change', loading.value || working.value); };
            const api = async (path, payload) => {
                const response = await props.request(`/investment-payback${path}`, payload === undefined ? { withBusinessContext: false } : { withBusinessContext: false, method: 'POST', body: JSON.stringify(payload) });
                if (Number(response?.code) !== 200 || !response?.data) {
                    const code = response?.code;
                    const failure = new Error(`${code ? `请求失败（${code}）：` : ''}${response?.message || response?.msg || '经营测算请求未完成'}`);
                    failure.code = Number(code);
                    throw failure;
                }
                return response.data;
            };
            const errorText = failure => `${failure?.status && !String(failure.message).includes(String(failure.status)) ? `请求失败（${failure.status}）：` : ''}${failure?.message || '经营测算请求未完成'}`;
            const applies = (stamp, projectId) => stamp === generation && String(projectId) === String(props.project?.id);
            const checkProject = (data, projectId) => {
                if (String(data?.project_id) !== String(projectId)) throw new Error('测算响应项目不一致，未载入或覆盖当前项目。');
            };
            const applyRead = data => {
                clearExport();
                form.value = toForm(data.input);
                result.value = data.result || null;
                historicalModel.value = data.model_status === 'historical_snapshot';
                loadedFingerprint.value = fingerprint.value;
                resultFingerprint.value = fingerprint.value;
                scenarioVersion.value = Number(data.scenario_version) > 0 ? data.scenario_version : null;
                projectVersion.value = data.project_version ?? props.project?.version ?? null;
                contentDigest.value = data.content_digest || '';
                readback.value = data.readback || '';
            };
            const loadScenario = async ({ discardDraft = false, projectChanged = false } = {}) => {
                const projectId = props.project?.id;
                if (!projectChanged && busy.value && String(activeProjectId) === String(projectId)) return;
                if (!projectChanged && !discardDraft && requestReplacement('reload')) return;
                const stamp = ++generation;
                working.value = false;
                pendingReplacement.value = null;
                if (String(activeProjectId) !== String(projectId)) {
                    form.value = blankForm(); result.value = null; readback.value = ''; contentDigest.value = ''; scenarioVersion.value = null;
                    loadedFingerprint.value = fingerprint.value; resultFingerprint.value = ''; clearExport();
                }
                activeProjectId = projectId;
                error.value = ''; notice.value = ''; writeBlocked.value = true;
                if (!projectId) return;
                setLoading(true);
                try {
                    const data = await api(`/projects/${projectId}/scenario`);
                    if (!applies(stamp, projectId)) return;
                    checkProject(data, projectId);
                    applyRead(data);
                    writeBlocked.value = false;
                    if (!data.input) notice.value = '尚未保存经营测算。请填写假设或主动载入参考样例；金额保持空白。';
                } catch (failure) { if (applies(stamp, projectId)) error.value = `测算读取失败：${errorText(failure)} 当前输入已保留；读取成功后才会替换，请重试读取。`; }
                finally { if (applies(stamp, projectId)) setLoading(false); }
            };
            const newBlank = (discardDraft = false) => {
                if (busy.value || readOnly.value || writeBlocked.value) return;
                if (discardDraft !== true && requestReplacement('blank')) return;
                pendingReplacement.value = null; clearExport();
                form.value = blankForm(); result.value = null; resultFingerprint.value = ''; error.value = ''; readback.value = ''; contentDigest.value = '';
                notice.value = '已新建空白测算，参考样例和当前结果已清除；保存后才替换本项目已保存的测算。';
            };
            const loadReference = async (discardDraft = false) => {
                if (busy.value || readOnly.value || writeBlocked.value) return;
                if (discardDraft !== true && requestReplacement('reference')) return;
                pendingReplacement.value = null;
                const projectId = props.project.id, stamp = ++generation;
                setWorking(true); error.value = ''; notice.value = '';
                try {
                    const data = await api('/scenario/reference-example');
                    if (!applies(stamp, projectId)) return;
                    if (!data.input || typeof data.input !== 'object') throw new Error('参考样例响应缺少假设输入。');
                    clearExport();
                    form.value = toForm({ ...data.input, reference_example: true, source_label: data.input.source_label || data.source_label || '清远参考样例（2022年原表元数据，仅作假设）', source_ref: data.input.source_ref || data.source_ref || '', source_sha256: data.input.source_sha256 || data.source_sha256 || '' });
                    result.value = null; resultFingerprint.value = ''; readback.value = ''; contentDigest.value = '';
                    notice.value = '已载入清远参考样例：2022年原表元数据，仅为引用假设，非本酒店事实。请核对项目、日期与每项金额后重算或保存。';
                } catch (failure) { if (applies(stamp, projectId)) error.value = errorText(failure); }
                finally { if (applies(stamp, projectId)) setWorking(false); }
            };
            const cancelReplacement = () => { pendingReplacement.value = null; };
            const confirmReplacement = async () => {
                if (!pendingReplacement.value || busy.value) return;
                const kind = pendingReplacement.value.kind;
                pendingReplacement.value = null;
                if (kind === 'blank') newBlank(true);
                else if (kind === 'reference') await loadReference(true);
                else if (kind === 'reload') await loadScenario({ discardDraft: true });
            };
            const preview = async () => {
                if (busy.value || readOnly.value || writeBlocked.value) return;
                pendingReplacement.value = null;
                const projectId = props.project.id, stamp = ++generation, scenario = toInput(form.value), snapshot = fingerprint.value;
                setWorking(true); error.value = ''; notice.value = '';
                try {
                    const data = await api(`/projects/${projectId}/scenario/preview`, { scenario });
                    if (!applies(stamp, projectId)) return;
                    checkProject(data, projectId);
                    if (!data.result) throw new Error('预览响应缺少测算结果，未显示成功。');
                    clearExport();
                    result.value = data.result; resultFingerprint.value = snapshot;
                    historicalModel.value = false;
                    readback.value = ''; contentDigest.value = '';
                    notice.value = '预览已重算，尚未保存。测算属于经营假设，不计入实际资金台账。';
                } catch (failure) { if (applies(stamp, projectId)) error.value = errorText(failure); }
                finally { if (applies(stamp, projectId)) setWorking(false); }
            };
            const save = async () => {
                if (busy.value || readOnly.value || writeBlocked.value) return;
                pendingReplacement.value = null;
                const projectId = props.project.id, stamp = ++generation, expectedVersion = projectVersion.value;
                setWorking(true); error.value = ''; notice.value = '';
                let postCompleted = false, verified = false;
                try {
                    const saved = await api(`/projects/${projectId}/scenario`, { expected_version: expectedVersion, scenario: toInput(form.value) });
                    if (!applies(stamp, projectId)) return;
                    postCompleted = true;
                    checkProject(saved, projectId);
                    const reread = await api(`/projects/${projectId}/scenario`);
                    if (!applies(stamp, projectId)) return;
                    checkProject(reread, projectId);
                    if (!saved.content_digest || saved.content_digest !== reread.content_digest || saved.scenario_version !== reread.scenario_version || canonical(saved.input) !== canonical(reread.input) || canonical(saved.result) !== canonical(reread.result) || reread.readback !== 'exact') throw new Error('保存后的精确回读未通过，当前表单已保留。');
                    applyRead(reread); writeBlocked.value = false; verified = true;
                    notice.value = `已保存并精确回读经营测算，版本 ${reread.scenario_version}。`;
                } catch (failure) {
                    if (applies(stamp, projectId)) {
                        const conflict = failure?.code === 409 || failure?.status === 409;
                        writeBlocked.value = postCompleted || conflict;
                        error.value = `${errorText(failure)}${postCompleted ? ' 保存请求已响应，请重新读取已保存测算后再继续，勿重复覆盖。' : conflict ? ' 项目版本已变更，请重新读取当前项目后再编辑；未自动重试覆盖。' : ''}`;
                    }
                } finally {
                    if (applies(stamp, projectId)) {
                        setWorking(false);
                        if (verified) emit('saved', { project_id: projectId, scenario_version: scenarioVersion.value });
                    }
                }
            };
            const addEscalation = () => { if (!busy.value && !readOnly.value && !writeBlocked.value) form.value.rent_escalations.push({ year: '', rate: '' }); };
            const addAdjustment = () => { if (!busy.value && !readOnly.value && !writeBlocked.value) form.value.cash_adjustments.push(Object.assign({ year: '' }, Object.fromEntries(adjustmentFields.map(field => [field.key, ''])))); };
            const csvCell = value => {
                let text = value === null || value === undefined ? '' : typeof value === 'object' ? JSON.stringify(value) : String(value);
                if (typeof value === 'string' && /^[\s]*[=+\-@]/.test(text)) text = `'${text}`;
                return `"${text.replace(/"/g, '""')}"`;
            };
            const buildCsv = () => {
                if (!result.value || stale.value || !annualRows.value.length) return '';
                const input = result.value.input || toInput(form.value);
                const rows = [
                    ['经营测算导出', '仅经营假设，非实际资金收回'], ['项目', props.project.project_name], ['关联酒店', props.project.hotel_name || props.project.hotel_id || '未关联'],
                    ['测算日期', input.as_of], ['模型版本', result.value.model_version], ['数据质量', 'scenario_assumption'],
                    ['模型状态', historicalModel.value ? '历史快照' : '当前模型'],
                    ['来源', input.source_label], ['参考来源', input.source_ref], ['来源SHA256', input.source_sha256],
                    ['结果状态', readback.value === 'exact' ? '已保存并精确回读' : '预览，未保存'],
                    ['本结果保存版本', readback.value === 'exact' ? scenarioVersion.value : null], ['项目已存测算版本', scenarioVersion.value], ['精确回读', readback.value], ['内容摘要', contentDigest.value],
                    ['税前现金代理回本', paybackText(result.value.payback)], ['完整调整后回本', result.value.scenario_payback ? paybackText(result.value.scenario_payback) : '现金调整未完整明确'],
                    ['期末税前现金代理未收回（元）', endingUnrecovered.value], ['期末完整调整后未收回（元）', adjustedEndingUnrecovered.value],
                    ['警示', (result.value.warnings || []).map(issueLabel).join('；')], ['未覆盖项', (result.value.exclusions || []).map(issueLabel).join('；')], [],
                    ['输入字段', '值'], ...Object.entries(input).map(([key, value]) => [fieldLabel(key), value]), [],
                ];
                const columns = ['year', 'days', 'adr', 'occupancy', 'revpar', 'revenue', 'operating_cost', 'rent', 'depreciation', 'management_fee', 'pretax_profit', 'pretax_cash_proxy', 'cumulative_cash_proxy', 'scenario_cashflow', 'cumulative_scenario_cashflow'];
                const labels = ['经营年', '天数', 'ADR（元）', '入住率（比例0..1）', 'RevPAR（元）', '营业额（元）', '经营成本（元）', '租金（元）', '折旧（元）', '管理费（元）', '税前利润（元）', '税前现金代理（元）', '累计税前现金代理（元）', '完整调整后现金流（元）', '累计完整调整后现金流（元）'];
                rows.push(labels, ...annualRows.value.map(row => columns.map(key => row[key])));
                return '\uFEFF' + rows.map(row => row.map(csvCell).join(',')).join('\r\n');
            };
            const exportCsv = () => {
                if (busy.value) return;
                const csv = buildCsv(); if (!csv) return;
                exportContent.value = csv;
                exportFilename.value = `经营测算-项目${props.project.id}-${form.value.as_of || '日期未填'}.csv`;
                exportMessage.value = '已生成当前结果，已请求浏览器下载。请确认文件是否出现；也可复制下方 CSV 内容。';
                let url = '', anchor;
                try {
                    url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                    anchor = document.createElement('a'); anchor.href = url; anchor.download = exportFilename.value;
                    document.body.appendChild(anchor); anchor.click();
                } catch (_) { exportMessage.value = '浏览器未能启动下载，CSV 内容已保留，可复制并保存为下方文件名。'; }
                finally {
                    anchor?.remove();
                    if (url) window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                }
            };
            const copyCsv = async () => {
                if (busy.value || stale.value || !exportContent.value) return;
                const stamp = generation, content = exportContent.value;
                const appliesToExport = () => stamp === generation && !stale.value && content === exportContent.value;
                try {
                    let copied = false;
                    if (exportTextarea.value && typeof document !== 'undefined' && typeof document.execCommand === 'function') {
                        exportTextarea.value.focus(); exportTextarea.value.select();
                        try { copied = document.execCommand('copy'); } catch (_) { /* Fall through to the Clipboard API. */ }
                    }
                    if (!copied) {
                        if (!navigator.clipboard?.writeText) throw new Error('clipboard_unavailable');
                        await navigator.clipboard.writeText(content);
                    }
                    if (appliesToExport()) exportMessage.value = '复制请求已发出，请粘贴检查。内容未更新时，可在下方选区按 Ctrl+C（Mac 用 ⌘C），手机可长按选择复制，再以 .csv 保存。';
                } catch (_) { if (appliesToExport()) exportMessage.value = '未能自动复制。可点击下方内容框，全选后手动复制，并以 .csv 保存。'; }
            };
            watch(() => props.project?.id, () => loadScenario({ projectChanged: true }), { immediate: true });
            if (onUnmounted) onUnmounted(() => { generation++; emit('busy-change', false); });
            return { form, result, groups, adjustmentFields, loading, working, busy, error, notice, scenarioVersion, contentDigest, readback, historicalModel, readOnly, writeBlocked, dirty, stale, annualRows, endingUnrecovered, adjustedEndingUnrecovered, cashAdjustmentGaps, visibleFields, groupMissing, pendingReplacement, confirmReplacement, cancelReplacement, exportContent, exportFilename, exportMessage, exportTextarea, copyCsv, clearExport, money, percent, fieldLabel, issueLabel, paybackText, loadScenario, newBlank, loadReference, preview, save, addEscalation, addAdjustment, exportCsv, buildCsv, toInput };
        },
        template: `
          <section class="border-t pt-5 space-y-4" data-testid="investment-scenario-workbench">
            <div class="flex flex-wrap justify-between items-start gap-3"><div><h4 class="text-lg font-semibold">投资经营测算 · 假设区</h4><p class="mt-1 text-sm text-gray-600 break-words">{{ project.project_name }} · {{ project.hotel_name || (project.hotel_id ? '酒店编号 ' + project.hotel_id : '未关联酒店') }} · 测算基准 {{ form.as_of || '待填写' }}</p></div><span class="text-xs rounded-full px-3 py-1 bg-amber-50 text-amber-800">经营假设</span></div>
            <p class="text-sm text-gray-600">这里的营业额、利润和回本均为输入假设推算。上方人工资金台账记录实际投入与收回；测算保存不会改变台账金额。所有金额以人民币元填写，空值表示未明确。</p>
            <p v-if="readOnly" class="rounded-lg border p-3 text-sm text-gray-600" data-testid="scenario-readonly">本项目已归档，经营测算仅供读取与导出。</p>
            <div class="flex flex-wrap gap-2"><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy || readOnly || writeBlocked" @click="newBlank" data-testid="scenario-new-blank">新建空白测算</button><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy || readOnly || writeBlocked" @click="loadReference" data-testid="scenario-reference-example">载入清远参考样例</button><button type="button" class="text-green-800 px-3 py-3" :disabled="busy" @click="loadScenario" data-testid="scenario-reload">重新读取已保存测算</button></div>
            <div v-if="pendingReplacement" class="border border-amber-200 bg-amber-50 rounded-lg p-3 space-y-3 text-sm text-amber-900" role="alert" data-testid="scenario-draft-replacement"><p>当前有未保存的修改。继续“{{ pendingReplacement.label }}”会替换当前输入；已保存版本不会改变。</p><div class="flex flex-wrap gap-2"><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy" @click="cancelReplacement" data-testid="scenario-keep-draft">保留当前输入</button><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy" @click="confirmReplacement" data-testid="scenario-confirm-replacement">放弃修改并继续</button></div></div>
            <p v-if="loading" class="text-sm text-gray-500" role="status">正在读取本项目经营测算…</p>
            <p v-if="error" class="border border-red-200 bg-red-50 rounded-lg p-3 text-sm text-red-800 break-words" role="alert" data-testid="scenario-error">{{ error }}</p>
            <p v-if="notice" class="border border-green-200 rounded-lg p-3 text-sm text-green-800" role="status" data-testid="scenario-notice">{{ notice }}</p>
            <div v-if="form.reference_example" class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900" data-testid="scenario-reference-source"><p>清远参考样例：2022年原表元数据，仅引用假设，非本酒店事实。改动后的数字仍需独立核对。</p><p class="mt-1 break-words">来源：{{ form.source_label || '待填写' }} · {{ form.source_ref || '来源编号未提供' }}</p><p v-if="form.source_sha256" class="mt-1 text-xs break-all">来源 SHA256：{{ form.source_sha256 }}</p></div>
            <form autocomplete="off" class="space-y-3" @submit.prevent="save" data-testid="scenario-form">
              <fieldset :disabled="busy || readOnly || writeBlocked" class="space-y-3 min-w-0">
                <details v-for="group in groups" :key="group.name" :open="group.open" class="border rounded-lg p-3 sm:p-4"><summary class="font-semibold cursor-pointer py-1">{{ group.name }}<span v-if="groupMissing(group)" class="ml-2 text-xs text-amber-800">待补 {{ groupMissing(group) }} 项</span></summary><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-4"><label v-for="field in visibleFields(group)" :key="field.key" class="text-sm min-w-0">{{ field.label }}<span v-if="!stale && result?.missing_fields?.includes(field.key)" class="ml-1 text-xs text-amber-800">待补</span><select v-if="field.type === 'select'" v-model="form[field.key]" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'scenario-' + field.key"><option value="">请选择计价口径</option><option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option></select><input v-else :type="field.type" :step="field.type === 'number' ? 'any' : undefined" :value="form[field.key]" @input="form[field.key] = $event.target.value" :name="'scenario-' + project.id + '-' + field.key" autocomplete="off" :maxlength="field.type === 'text' ? 500 : undefined" :aria-invalid="!stale && result?.missing_fields?.includes(field.key) ? 'true' : undefined" class="mt-1 w-full border rounded-lg px-3 py-3" placeholder="未明确可留空" :data-testid="'scenario-' + field.key" /></label></div><p v-if="group.name === '经营假设'" class="mt-3 text-xs text-gray-500">比例按百分比填写；成熟期采用指定入住率，房价与成本按各自年度增长假设递增。固定年度成本仅在“固定 + 变动”口径下计入。</p><p v-if="group.name === '初始投资与折旧'" class="mt-3 text-xs text-gray-500">初始现金与可折旧金额分开填写；折旧是会计费用，押金可退不等于已收回现金。不涉及的现金项请明确填0。</p></details>
                <details class="border rounded-lg p-3 sm:p-4"><summary class="font-semibold cursor-pointer py-1">租金递增安排 · {{ form.rent_escalations.length }} 段</summary><p class="mt-3 text-xs text-gray-500">按经营年填写生效年度与涨幅；未添加表示没有额外租金递增安排。</p><div v-for="(row,index) in form.rent_escalations" :key="index" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3"><label class="text-sm">生效经营年<input v-model="row.year" type="number" step="1" class="mt-1 w-full border rounded-lg px-3 py-2" :data-testid="'scenario-rent-year-' + index" /></label><label class="text-sm">涨幅（%）<input v-model="row.rate" type="number" step="any" class="mt-1 w-full border rounded-lg px-3 py-2" :data-testid="'scenario-rent-rate-' + index" /></label><button type="button" class="text-red-700 px-3 py-3 self-end" @click="form.rent_escalations.splice(index,1)">移除本段</button></div><button type="button" class="border rounded-lg px-3 py-2 mt-3 min-h-[44px]" @click="addEscalation" data-testid="scenario-add-rent-escalation">添加租金递增</button></details>
                <details class="border rounded-lg p-3 sm:p-4"><summary class="font-semibold cursor-pointer py-1">可选年度现金调整 · {{ form.cash_adjustments.length }} 年</summary><p class="mt-3 text-xs text-gray-500">完整现金回收需每个经营年的六项现金明确填写。不涉及某项请人工填0；未填写时保留未知，仍只给税前现金代理。</p><div v-for="(row,index) in form.cash_adjustments" :key="index" class="border-t mt-3 pt-3"><div class="flex justify-between gap-3"><label class="text-sm">经营年<input v-model="row.year" type="number" step="1" class="mt-1 w-full border rounded-lg px-3 py-2" :data-testid="'scenario-cash-year-' + index" /></label><button type="button" class="text-red-700 px-3 py-3" @click="form.cash_adjustments.splice(index,1)">移除此年</button></div><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-3"><label v-for="field in adjustmentFields" :key="field.key" class="text-sm">{{ field.label }}<input v-model="row[field.key]" type="number" step="any" class="mt-1 w-full border rounded-lg px-3 py-3" placeholder="未明确" :data-testid="'scenario-cash-' + field.key + '-' + index" /></label></div></div><button type="button" class="border rounded-lg px-3 py-2 mt-3 min-h-[44px]" @click="addAdjustment" data-testid="scenario-add-cash-adjustment">添加年度现金调整</button></details>
              </fieldset>
              <div class="flex flex-wrap justify-between items-center gap-3"><p class="text-sm text-gray-500">{{ dirty ? '表单有未保存的修改' : '当前为已读取的表单' }} · {{ scenarioVersion == null ? '尚无测算版本' : '已存版本 ' + scenarioVersion }}<span class="block mt-1">可先保存未填完的草稿，保存后自动核对读取结果。</span></p><div class="flex flex-wrap gap-2"><button type="button" class="border rounded-lg px-4 py-3" :disabled="busy || readOnly || writeBlocked" @click="preview" data-testid="scenario-preview">{{ working ? '处理中…' : '预览重算' }}</button><button class="btn-primary px-4 py-3" :disabled="busy || readOnly || writeBlocked" data-testid="scenario-save">{{ working ? '处理中…' : '保存测算' }}</button></div></div>
            </form>
            <div v-if="result" class="space-y-4 border-t pt-4" data-testid="scenario-results">
              <div class="flex flex-wrap justify-between gap-3 items-center"><div><h5 class="font-semibold">年度经营测算结果</h5><p class="mt-1 text-xs text-gray-500" data-testid="scenario-result-status">{{ stale ? '上次结果 · 当前输入待重算' : readback === 'exact' ? '已保存并精确回读 · 版本 ' + scenarioVersion : '预览结果 · 尚未保存' }}</p></div><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy || stale || !annualRows.length" @click="exportCsv" data-testid="scenario-export-csv">导出当前结果 CSV</button></div>
              <div v-if="exportContent" class="border rounded-lg p-3 space-y-3 min-w-0" data-testid="scenario-export-panel"><div class="flex flex-wrap justify-between items-center gap-2"><p class="text-sm font-semibold break-all">{{ exportFilename }}</p><button type="button" class="px-3 py-3" @click="clearExport">关闭导出内容</button></div><p class="text-sm text-gray-600" role="status" data-testid="scenario-export-message">{{ stale ? '假设已修改，导出内容已过期。请重算并重新导出。' : exportMessage }}</p><template v-if="!stale"><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy" @click="copyCsv" data-testid="scenario-copy-csv">复制 CSV 内容</button><label class="block text-sm text-gray-600">CSV 内容（只读，可全选复制）<textarea ref="exportTextarea" :value="exportContent" readonly rows="6" class="mt-1 w-full max-w-full border rounded-lg px-3 py-2 text-xs" data-testid="scenario-export-content"></textarea></label></template></div>
              <p v-if="stale" class="border border-amber-200 bg-amber-50 rounded-lg p-3 text-sm text-amber-900" role="status" data-testid="scenario-result-stale">假设已修改，以下为上次结果，需重算；导出已停用。</p>
              <p v-if="historicalModel" class="border border-amber-200 bg-amber-50 rounded-lg p-3 text-sm text-amber-900" data-testid="scenario-historical-model">当前为旧模型 {{ result.model_version }} 的已保存快照，原结果保持不变。可“预览重算”查看当前模型结果；保存后才新增测算版本。</p>
              <p v-if="result.status === 'inputs_missing'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900" data-testid="scenario-missing-inputs">输入尚未齐全，不能计算回本。缺失项：{{ (result.missing_fields || []).map(fieldLabel).join('、') || '请核对输入资料' }}</p>
              <div v-else-if="result.status === 'partial'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 space-y-1" data-testid="scenario-completion"><p v-if="result.missing_fields?.length">待补资料：{{ result.missing_fields.map(fieldLabel).join('、') }}。</p><p v-if="cashAdjustmentGaps.length">年度现金调整尚未完整：{{ cashAdjustmentGaps.map(row => '第' + row.year + '年缺' + row.count + '项').join('；') }}。请在“可选年度现金调整”中补齐；不涉及的项目需明确填0。</p><p>税前现金代理与调整后现金均为假设测算，不代表实际收回。</p></div>
              <div v-if="annualRows.length" class="grid grid-cols-1 sm:grid-cols-2 gap-3"><div class="border rounded-lg p-3"><p class="text-sm text-gray-500">初始投入现金（元）</p><p class="mt-1 font-semibold">{{ money(result.initial_cash_total) }}</p><p class="mt-1 text-xs text-gray-500">其中营建期租金 {{ money(result.construction_rent_cash) }} 元</p></div><div class="border rounded-lg p-3" data-testid="scenario-payback"><p class="text-sm text-gray-500">税前现金代理首次回本</p><p class="mt-1 font-semibold">{{ paybackText(result.payback) }}</p><p v-if="endingUnrecovered != null" class="mt-1 text-xs text-gray-500">测算期末未收回 {{ money(endingUnrecovered) }} 元</p><p v-if="endingUnrecovered > 0 && result.payback?.operating_years != null" class="mt-2 text-sm text-amber-800">曾经回本，但期末累计再次转负，仍有资金缺口。</p></div><div v-if="result.scenario_payback" class="border rounded-lg p-3" data-testid="scenario-adjusted-payback"><p class="text-sm text-gray-500">现金调整后的情景首次回本</p><p class="mt-1 font-semibold">{{ paybackText(result.scenario_payback) }}</p><p v-if="adjustedEndingUnrecovered != null" class="mt-1 text-xs text-gray-500">测算期末未收回 {{ money(adjustedEndingUnrecovered) }} 元</p><p v-if="adjustedEndingUnrecovered > 0 && result.scenario_payback.operating_years != null" class="mt-2 text-sm text-amber-800">曾经回本，但调整后期末累计再次转负。</p><p class="mt-1 text-xs text-gray-500">包含已明确年度现金调整，仍非实际到账记录。</p></div><div v-if="result.totals" class="border rounded-lg p-3"><p class="text-sm text-gray-500">测算期合计（元）</p><p class="mt-1 text-sm">营业额 {{ money(result.totals.revenue) }} · 税前利润 {{ money(result.totals.pretax_profit) }}</p><p class="mt-1 text-sm">税前现金代理 {{ money(result.totals.pretax_cash_proxy) }}</p></div></div>
              <div v-if="annualRows.length" class="overflow-x-auto"><table class="w-full text-sm whitespace-nowrap" data-testid="scenario-annual-table"><thead class="text-gray-500 text-right"><tr class="border-b"><th class="py-3 pr-3 text-left">经营年</th><th class="pr-3">ADR / 元</th><th class="pr-3">入住率</th><th class="pr-3">RevPAR / 元</th><th class="pr-3">营业额 / 元</th><th class="pr-3">经营成本 / 元</th><th class="pr-3">租金 / 元</th><th class="pr-3">折旧 / 元</th><th class="pr-3">管理费 / 元</th><th class="pr-3">税前利润 / 元</th><th class="pr-3">税前现金代理 / 元</th><th class="pr-3">累计代理 / 元</th><th v-if="result.scenario_payback" class="pr-3">调整后现金流 / 元</th></tr></thead><tbody><tr v-for="row in annualRows" :key="row.year" class="border-b text-right"><td class="py-3 pr-3 text-left">{{ row.year }}</td><td class="pr-3">{{ money(row.adr) }}</td><td class="pr-3">{{ percent(row.occupancy) }}</td><td class="pr-3">{{ money(row.revpar) }}</td><td class="pr-3">{{ money(row.revenue) }}</td><td class="pr-3">{{ money(row.operating_cost) }}</td><td class="pr-3">{{ money(row.rent) }}</td><td class="pr-3">{{ money(row.depreciation) }}</td><td class="pr-3">{{ money(row.management_fee) }}</td><td class="pr-3">{{ money(row.pretax_profit) }}</td><td class="pr-3">{{ money(row.pretax_cash_proxy) }}</td><td class="pr-3">{{ money(row.cumulative_cash_proxy) }}</td><td v-if="result.scenario_payback" class="pr-3">{{ money(row.scenario_cashflow) }}</td></tr></tbody></table></div>
              <details v-if="result.break_even" class="border rounded-lg p-3"><summary class="font-semibold cursor-pointer py-1">首年盈亏平衡线</summary><dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3 text-sm"><div><dt class="text-gray-500">会计利润保本入住率</dt><dd>{{ percent(result.break_even.profit_occupancy) }}</dd></div><div><dt class="text-gray-500">税前现金代理保本入住率</dt><dd>{{ percent(result.break_even.cash_proxy_occupancy) }}</dd></div><div><dt class="text-gray-500">输入入住率下利润保本ADR（元）</dt><dd>{{ money(result.break_even.profit_adr_at_input_occupancy) }}</dd></div><div><dt class="text-gray-500">输入入住率下现金代理保本ADR（元）</dt><dd>{{ money(result.break_even.cash_proxy_adr_at_input_occupancy) }}</dd></div></dl></details>
              <details v-if="result.sensitivity_rows?.length" class="border rounded-lg p-3"><summary class="font-semibold cursor-pointer py-1">房价 × 持续入住率敏感性（九格）</summary><p class="mt-3 text-xs text-gray-500">房价首年±10%，持续入住率采用首年±10个百分点；每格使用持续入住率，未沿用原成熟期爬坡。</p><div class="overflow-x-auto mt-3"><table class="w-full text-sm whitespace-nowrap" data-testid="scenario-sensitivity-table"><thead><tr class="text-gray-500 border-b text-left"><th class="py-2 pr-3">首年ADR（元）</th><th class="pr-3">持续入住率</th><th class="pr-3">首年税前利润（元）</th><th class="pr-3">税前现金代理回本</th></tr></thead><tbody><tr v-for="(row,index) in result.sensitivity_rows" :key="index" class="border-b"><td class="py-3 pr-3">{{ money(row.adr_first_year) }}</td><td class="pr-3">{{ percent(row.occupancy_all_years) }}</td><td class="pr-3">{{ money(row.first_year_pretax_profit) }}</td><td class="pr-3">{{ paybackText(row.payback) }}</td></tr></tbody></table></div></details>
              <details class="border rounded-lg p-3 text-sm text-gray-600"><summary class="font-semibold cursor-pointer py-1">测算边界与需核对项 · {{ (result.warnings || []).length + (result.exclusions || []).length }} 项</summary><div class="mt-3 space-y-2"><p v-for="(warning,index) in result.warnings || []" :key="'warning-' + index">需核对：{{ issueLabel(warning) }}</p><p v-for="(exclusion,index) in result.exclusions || []" :key="'exclusion-' + index">未覆盖 / 边界：{{ issueLabel(exclusion) }}</p><p class="text-xs text-gray-500">模型 {{ result.model_version }} · {{ readback === 'exact' ? '已保存并精确回读' : '预览或未核对保存结果' }} · scenario_assumption</p><p v-if="contentDigest" class="text-xs text-gray-500 break-all" data-testid="scenario-digest">内容摘要：{{ contentDigest }}</p></div></details>
            </div>
          </section>
        `,
    };
})();
