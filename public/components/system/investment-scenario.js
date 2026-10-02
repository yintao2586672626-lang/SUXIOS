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
        currency: 'CNY', source_sha256: '', reference_example: false, rent_escalations: [], cash_adjustments: [], consumables_cost: null,
        cash_plan: null, decision_constraints: null, cost_evidence_snapshot_id: null, cost_evidence_digest: '', cost_evidence_confirmed: false,
    });
    const numeric = value => value === '' || value === null || value === undefined ? null : Number.isFinite(Number(value)) ? Number(value) : value;
    const displayNumeric = (value, percent = false) => value === null || value === undefined || value === '' ? '' : percent ? String(Number((Number(value) * 100).toFixed(8))) : String(value);
    const copyReference = value => value ? JSON.parse(JSON.stringify(value)) : value;
    const toForm = input => {
        const form = blankForm();
        if (!input) return form;
        for (const field of fields) form[field.key] = field.type === 'number' ? displayNumeric(input[field.key], field.percent) : (input[field.key] ?? '');
        form.currency = input.currency || 'CNY';
        form.source_sha256 = input.source_sha256 || '';
        form.reference_example = input.reference_example === true;
        form.cash_plan = copyReference(input.cash_plan || null);
        if (form.cash_plan) for (const loan of form.cash_plan.loans) loan.annual_rate = displayNumeric(loan.annual_rate, true);
        form.decision_constraints = copyReference(input.decision_constraints || null);
        form.cost_evidence_snapshot_id = input.cost_evidence_snapshot_id ?? null;
        form.cost_evidence_digest = input.cost_evidence_digest || '';
        form.cost_evidence_confirmed = input.cost_evidence_confirmed === true;
        form.rent_escalations = (input.rent_escalations || []).map(row => ({ year: displayNumeric(row.year), rate: displayNumeric(row.rate, true) }));
        form.cash_adjustments = (input.cash_adjustments || []).map(row => Object.assign({ year: displayNumeric(row.year) }, Object.fromEntries(adjustmentFields.map(field => [field.key, displayNumeric(row[field.key])]))));
        if (input.consumables_cost) form.consumables_cost = {
            schema_version: 'consumables-v1', mode: input.consumables_cost.mode,
            other_variable_cost_per_night: displayNumeric(input.consumables_cost.other_variable_cost_per_night),
            items: input.consumables_cost.items.map(row => ({ ...row, name: row.name ?? '', unit: row.unit ?? '', source_label: row.source_label ?? '', as_of: row.as_of ?? '',
                ...(row.procurement_reference ? { procurement_reference: copyReference(row.procurement_reference) } : {}),
                ...Object.fromEntries(['package_price', 'package_quantity', 'usage_quantity', 'occurrences_per_occupied_night'].map(key => [key, displayNumeric(row[key])])) })),
        };
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
        input.cash_plan = copyReference(form.cash_plan);
        if (input.cash_plan) {
            input.cash_plan.months = numeric(input.cash_plan.months);
            input.cash_plan.opening_liquidity = numeric(input.cash_plan.opening_liquidity);
            for (const loan of input.cash_plan.loans) { loan.principal = numeric(loan.principal); loan.term_months = numeric(loan.term_months); loan.annual_rate = numeric(loan.annual_rate) === null ? null : Number((Number(loan.annual_rate) / 100).toFixed(10)); }
            for (const row of input.cash_plan.monthly_inputs) for (const key of ['operating_net_cash', 'capex_cash', 'other_net_cash']) row[key] = numeric(row[key]);
        }
        input.decision_constraints = copyReference(form.decision_constraints);
        if (input.decision_constraints) input.decision_constraints.target_payback_months = numeric(input.decision_constraints.target_payback_months);
        input.cost_evidence_snapshot_id = form.cost_evidence_snapshot_id;
        input.cost_evidence_digest = form.cost_evidence_digest;
        input.cost_evidence_confirmed = form.cost_evidence_confirmed === true;
        input.rent_escalations = form.rent_escalations.map(row => ({ year: numeric(row.year), rate: numeric(row.rate) === null ? null : Number((Number(row.rate) / 100).toFixed(10)) }));
        input.cash_adjustments = form.cash_adjustments.map(row => Object.assign({ year: numeric(row.year) }, Object.fromEntries(adjustmentFields.map(field => [field.key, numeric(row[field.key])]))));
        input.consumables_cost = form.consumables_cost === null ? null : {
            schema_version: 'consumables-v1', mode: form.consumables_cost.mode,
            other_variable_cost_per_night: numeric(form.consumables_cost.other_variable_cost_per_night),
            items: form.consumables_cost.items.map(row => ({ ...row,
                ...(row.procurement_reference ? { procurement_reference: copyReference(row.procurement_reference) } : {}),
                ...Object.fromEntries(['package_price', 'package_quantity', 'usage_quantity', 'occurrences_per_occupied_night'].map(key => [key, numeric(row[key])])),
                occurrences_per_occupied_night: row.usage_basis === 'occupied_room_night' ? 1 : numeric(row.occurrences_per_occupied_night),
            })),
        };
        return input;
    };
    const canonical = value => JSON.stringify(value, (_, item) => item && typeof item === 'object' && !Array.isArray(item) ? Object.fromEntries(Object.keys(item).sort().map(key => [key, item[key]])) : item);
    const money = value => value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? '未明确' : Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const percent = value => value === null || value === undefined || value === '' ? '未明确' : `${Number((Number(value) * 100).toFixed(2))}%`;
    const fieldLabel = key => {
        const label = fields.find(field => field.key === key)?.label || adjustmentFields.find(field => field.key === key)?.label || ({ rent_escalations: '租金涨幅安排', cash_adjustments: '年度现金调整', source_label: '假设来源说明', consumables_cost: '易耗品明细与采用方式', 'consumables_cost.occupied_room_night_basis': '明细采用须选择按已售间夜或固定加变动口径' }[key]);
        if (label) return label;
        if (key.startsWith('consumables_cost.')) {
            const parts = key.split('.');
            const costLabels = { items: '至少一项启用且完整的用品', package_price: '每包价格', package_quantity: '包净含量', unit: '净含量与用量单位', usage_quantity: '每基准用量', occurrences_per_occupied_night: '客夜或清洁次数 / 已售间夜', other_variable_cost_per_night: '其他变动成本', source_label: '本方案参数来源', as_of: '本方案价格基准日', procurement_reference: '本方案引用确认', confirmed_for_scenario: '本方案价格、单位和用量确认' };
            return '易耗品' + (parts[1] === 'items' && parts.length > 2 ? '第 ' + (Number(parts[2]) + 1) + ' 项：' : '：') + (costLabels[parts.at(-1)] || parts.at(-1));
        }
        return key;
    };
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
            const scenarioKey = ref('base');
            const historyViewing = ref(false);
            const viewedEventId = ref(null);
            const library = ref(null), history = ref(null), projectOptions = ref(null), panelOpen = ref(false), panelError = ref('');
            const compareSelections = ref([]), compareResult = ref(null), compareProjectId = ref(''), compareKey = ref('base'), copyTarget = ref('base');
            const projectSearch = ref('');
            const actualCostReference = ref(null);
            const pendingReplacement = ref(null);
            const exportContent = ref('');
            const exportFilename = ref('');
            const exportMessage = ref('');
            const exportTextarea = ref(null);
            let activeProjectId = null;
            let generation = 0;
            let procurementGeneration = 0;
            const procurementCatalog = ref(null);
            const procurementLoading = ref(false);
            const procurementReferenceAvailable = ref(false);
            const actualReferenceAvailable = ref(false);
            const procurementError = ref('');
            const procurementOpen = ref(false);
            const procurementItemId = ref('');
            const procurementTierId = ref('');
            const readOnly = computed(() => !!props.project?.archived_at || historyViewing.value);
            const busy = computed(() => loading.value || working.value || procurementLoading.value || props.ledgerBusy);
            const selectedProcurementItem = computed(() => procurementCatalog.value?.items.find(item => String(item.id) === String(procurementItemId.value)) || null);
            const selectedProcurementTier = computed(() => procurementCatalog.value?.tiers.find(tier => tier.id === procurementTierId.value) || null);
            const selectedProcurementQuote = computed(() => selectedProcurementItem.value?.quotes.find(quote => quote.tier_id === procurementTierId.value) || null);
            const selectedProcurementRecommendations = computed(() => (selectedProcurementItem.value?.recommendations || []).filter(row => row.tier_id === procurementTierId.value));
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
                const label = { blank: '新建空白测算', reference: '载入清远参考样例', reload: '重新读取已保存测算', switch: '切换保存方案', history: '查看历史版本' }[kind];
                pendingReplacement.value = { kind, label };
                return true;
            };
            const emitBusy = () => emit('busy-change', loading.value || working.value || procurementLoading.value);
            const setWorking = value => { working.value = value; emitBusy(); };
            const setLoading = value => { loading.value = value; emitBusy(); };
            const setProcurementLoading = value => { procurementLoading.value = value; emitBusy(); };
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
                procurementReferenceAvailable.value = data.capabilities?.procurement_reference === true;
                actualReferenceAvailable.value = data.capabilities?.actual_consumables_reference === true;
                form.value = toForm(data.input);
                result.value = data.result || null;
                historicalModel.value = data.model_status === 'historical_snapshot';
                loadedFingerprint.value = fingerprint.value;
                resultFingerprint.value = fingerprint.value;
                scenarioVersion.value = Number(data.scenario_version) > 0 ? data.scenario_version : null;
                projectVersion.value = data.project_version ?? props.project?.version ?? null;
                contentDigest.value = data.content_digest || '';
                readback.value = data.readback || '';
                historyViewing.value = data.historical_version === true;
                viewedEventId.value = data.scenario_event_id || null;
            };
            const scenarioPath = (projectId, key = scenarioKey.value) => `/projects/${projectId}/scenario${key === 'base' ? '' : '?scenario_key=' + key}`;
            const loadScenario = async ({ discardDraft = false, projectChanged = false, key = scenarioKey.value } = {}) => {
                const projectId = props.project?.id;
                if (!projectChanged && busy.value && String(activeProjectId) === String(projectId)) return;
                if (!projectChanged && !discardDraft && requestReplacement('reload')) return;
                const stamp = ++generation;
                working.value = false;
                pendingReplacement.value = null;
                if (String(activeProjectId) !== String(projectId)) {
                    ++procurementGeneration;
                    procurementCatalog.value = null; procurementError.value = ''; procurementLoading.value = false;
                    procurementOpen.value = false; procurementItemId.value = ''; procurementTierId.value = '';
                    scenarioKey.value = 'base'; key = 'base'; historyViewing.value = false; viewedEventId.value = null;
                    library.value = null; history.value = null; panelOpen.value = false; panelError.value = ''; compareResult.value = null; compareSelections.value = []; actualCostReference.value = null;
                    form.value = blankForm(); result.value = null; readback.value = ''; contentDigest.value = ''; scenarioVersion.value = null;
                    loadedFingerprint.value = fingerprint.value; resultFingerprint.value = ''; clearExport();
                }
                activeProjectId = projectId;
                error.value = ''; notice.value = ''; writeBlocked.value = true;
                if (!projectId) return;
                setLoading(true);
                try {
                    const data = await api(scenarioPath(projectId, key));
                    if (!applies(stamp, projectId)) return;
                    checkProject(data, projectId);
                    if ((data.scenario_key || 'base') !== key) throw new Error('返回的保存方案不一致，当前输入已保留。');
                    scenarioKey.value = key;
                    applyRead(data);
                    const pendingActual = window.SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE;
                    if (pendingActual && receiveActualCost({ detail: pendingActual })) window.SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE = null;
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
                else if (kind === 'switch') await switchScenario(pendingActionKey, true);
                else if (kind === 'history') await viewVersion(pendingActionKey, true);
            };
            let pendingActionKey = null;
            const switchScenario = async (key, discardDraft = false) => {
                if (busy.value || !['base', 'conservative', 'optimistic'].includes(key)) return;
                if (!discardDraft && requestReplacement('switch')) { pendingActionKey = key; return; }
                await loadScenario({ discardDraft: true, key });
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
                    const saved = await api(`/projects/${projectId}/scenario`, { expected_version: expectedVersion, scenario_key: scenarioKey.value, scenario: toInput(form.value) });
                    if (!applies(stamp, projectId)) return;
                    postCompleted = true;
                    checkProject(saved, projectId);
                    if ((saved.scenario_key || 'base') !== scenarioKey.value) throw new Error('保存响应方案不一致，请重读核对。');
                    const reread = await api(scenarioPath(projectId));
                    if (!applies(stamp, projectId)) return;
                    checkProject(reread, projectId);
                    if ((reread.scenario_key || 'base') !== scenarioKey.value) throw new Error('回读方案不一致，当前输入保留。');
                    if (!saved.content_digest || saved.content_digest !== reread.content_digest || saved.scenario_version !== reread.scenario_version || canonical(saved.input) !== canonical(reread.input) || canonical(saved.result) !== canonical(reread.result) || reread.readback !== 'exact') throw new Error('保存后的精确回读未通过，当前表单已保留。');
                    applyRead(reread); writeBlocked.value = false; verified = true;
                    library.value = null; history.value = null; compareResult.value = null;
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
            const scenarioLabel = key => ({ conservative: '保守', base: '基准', optimistic: '乐观' }[key] || key);
            const statusLabel = status => ({ within_limit: '在目标期内', beyond_limit: '超过目标期', not_reached_in_horizon: '测算期内未达成', trial_only: '仅税前代理试算，完整现金待补', inputs_missing: '条件未补齐', forecast_missing: '经营假设未补齐', expired: '合同已到期', user_confirmed: '用户已核对来源', unverified: '合同资料待核对' }[status] || status || '待补齐');
            const loadScenarioPanel = async () => {
                if (busy.value) return;
                const projectId = props.project.id, stamp = generation;
                panelOpen.value = true; panelError.value = ''; setWorking(true);
                try {
                    const data = await api(`/projects/${projectId}/scenario/library`);
                    const versions = await api(`/projects/${projectId}/scenario/history`);
                    const projects = await api('/projects?page_size=100');
                    if (!applies(stamp, projectId)) return;
                    checkProject(data, projectId); checkProject(versions, projectId);
                    if (!Array.isArray(data.items) || !Array.isArray(versions.items) || !Array.isArray(projects.list)) throw new Error('方案或项目列表响应不完整');
                    library.value = data; history.value = versions; projectOptions.value = projects;
                } catch (failure) { if (applies(stamp, projectId)) panelError.value = `方案历史与比较读取失败：${errorText(failure)}；当前输入保留，可重试。`; }
                finally { if (applies(stamp, projectId)) setWorking(false); }
            };
            const viewVersion = async (eventId, discardDraft = false) => {
                if (busy.value) return;
                if (!discardDraft && requestReplacement('history')) { pendingActionKey = eventId; return; }
                const projectId = props.project.id, stamp = ++generation;
                setWorking(true); error.value = '';
                try {
                    const data = await api(`/projects/${projectId}/scenario/history/${eventId}`);
                    if (!applies(stamp, projectId)) return;
                    checkProject(data, projectId);
                    if (Number(data.scenario_event_id) !== Number(eventId) || data.readback !== 'exact') throw new Error('历史版本精确回读不一致');
                    scenarioKey.value = data.scenario_key || scenarioKey.value; applyRead(data); historyViewing.value = true; viewedEventId.value = eventId; writeBlocked.value = false;
                    notice.value = '正在只读查看历史快照。复制到指定方案会用当前模型创建新版本，历史原文保留。';
                } catch (failure) { if (applies(stamp, projectId)) error.value = errorText(failure); }
                finally { if (applies(stamp, projectId)) setWorking(false); }
            };
            const loadMoreHistory = async () => {
                if (busy.value || !history.value?.has_more) return;
                const projectId = props.project.id, stamp = generation, previous = history.value;
                setWorking(true); panelError.value = '';
                try { const data = await api(`/projects/${projectId}/scenario/history?before_event_id=${previous.next_before_event_id}`); if (applies(stamp, projectId)) { checkProject(data, projectId); if (!Array.isArray(data.items)) throw new Error('历史分页响应不完整'); history.value = { ...data, items: [...previous.items, ...data.items] }; } }
                catch (failure) { if (applies(stamp, projectId)) panelError.value = errorText(failure); }
                finally { if (applies(stamp, projectId)) setWorking(false); }
            };
            const loadComparisonProjects = async (page = 1) => {
                if (busy.value) return;
                const projectId = props.project.id, stamp = generation; setWorking(true); panelError.value = '';
                try { const data = await api(`/projects?page_size=100&page=${page}&search=${encodeURIComponent(projectSearch.value)}`); if (applies(stamp, projectId)) { if (!Array.isArray(data.list)) throw new Error('项目分页响应不完整'); projectOptions.value = data; compareProjectId.value = ''; } }
                catch (failure) { if (applies(stamp, projectId)) panelError.value = errorText(failure); }
                finally { if (applies(stamp, projectId)) setWorking(false); }
            };
            const copyVersion = async () => {
                if (busy.value || props.project?.archived_at || !viewedEventId.value || !historyViewing.value || writeBlocked.value) return;
                const projectId = props.project.id, stamp = ++generation, target = copyTarget.value;
                setWorking(true); error.value = ''; let postCompleted = false;
                try {
                    const saved = await api(`/projects/${projectId}/scenario/history/${viewedEventId.value}/copy`, { expected_version: projectVersion.value, scenario_key: target });
                    postCompleted = true;
                    const reread = await api(scenarioPath(projectId, target));
                    if (!applies(stamp, projectId)) return;
                    checkProject(saved, projectId); checkProject(reread, projectId);
                    if (reread.scenario_key !== target || reread.readback !== 'exact' || saved.content_digest !== reread.content_digest || canonical(saved.input) !== canonical(reread.input) || canonical(saved.result) !== canonical(reread.result) || saved.scenario_version !== reread.scenario_version) throw new Error('复制后的保存回读未通过');
                    scenarioKey.value = target; applyRead(reread); writeBlocked.value = false;
                    notice.value = `已复制为${scenarioLabel(target)}方案新版本并精确回读。`; emit('saved', { project_id: projectId, scenario_version: scenarioVersion.value });
                    library.value = null; history.value = null; compareResult.value = null;
                } catch (failure) { if (applies(stamp, projectId)) { writeBlocked.value = postCompleted || failure?.code === 409; error.value = `${errorText(failure)}；请重读确认后再继续。`; } }
                finally { if (applies(stamp, projectId)) setWorking(false); }
            };
            const addComparison = () => {
                const id = Number(compareProjectId.value);
                if (!id || compareSelections.value.length >= 12 || compareSelections.value.some(row => row.project_id === id && row.scenario_key === compareKey.value)) return;
                const project = projectOptions.value?.list.find(row => Number(row.id) === id);
                if (!project) return;
                compareSelections.value.push({ project_id: id, scenario_key: compareKey.value, project_name: project.project_name }); compareResult.value = null;
            };
            const compareScenarios = async () => {
                if (busy.value || compareSelections.value.length < 2) return;
                const projectId = props.project.id, stamp = generation; setWorking(true); panelError.value = ''; compareResult.value = null;
                try { const data = await api('/scenario/compare', { selections: compareSelections.value.map(({ project_id, scenario_key }) => ({ project_id, scenario_key })) }); if (applies(stamp, projectId)) compareResult.value = data; }
                catch (failure) { if (applies(stamp, projectId)) panelError.value = errorText(failure); }
                finally { if (applies(stamp, projectId)) setWorking(false); }
            };
            const addConstraints = () => { if (!busy.value && !readOnly.value && !writeBlocked.value && !form.value.decision_constraints) form.value.decision_constraints = { target_payback_months: '', contract_start_on: '', contract_end_on: '', contract_source: '', contract_confirmed: false }; };
            const addCashPlan = () => { if (!busy.value && !readOnly.value && !writeBlocked.value && !form.value.cash_plan) form.value.cash_plan = { start_month: form.value.as_of?.slice(0, 7) || '', months: '12', opening_liquidity: '', source_label: '', loans: [], monthly_inputs: [] }; };
            const generateMonths = () => {
                const plan = form.value.cash_plan;
                if (busy.value || readOnly.value || writeBlocked.value || !plan || !/^\d{4}-(0[1-9]|1[0-2])$/.test(plan.start_month) || Number(plan.months) < 1 || Number(plan.months) > 360 || !Number.isInteger(Number(plan.months))) return;
                const start = new Date(plan.start_month + '-01T00:00:00Z');
                const existing = new Map(plan.monthly_inputs.map(row => [row.month, row]));
                plan.monthly_inputs = Array.from({ length: Number(plan.months) }, (_, n) => { const date = new Date(start); date.setUTCMonth(date.getUTCMonth() + n); const month = date.toISOString().slice(0, 7); return existing.get(month) || { month, operating_net_cash: '', capex_cash: '', other_net_cash: '' }; });
            };
            const addLoan = () => { if (!busy.value && !readOnly.value && !writeBlocked.value && form.value.cash_plan && form.value.cash_plan.loans.length < 20) form.value.cash_plan.loans.push({ id: 'loan-' + Date.now() + '-' + form.value.cash_plan.loans.length, name: '', funding: 'new', principal: '', annual_rate: '', start_month: form.value.cash_plan.start_month, term_months: '', method: 'equal_principal' }); };
            const receiveActualCost = event => {
                const data = event?.detail;
                if (!data || !props.project?.hotel_id || String(data.hotel_id) !== String(props.project.hotel_id) || !data.snapshot_id || !/^[a-f0-9]{64}$/i.test(data.content_digest || '') || data.actual_consumables_cost_per_room_night == null || !Number.isFinite(Number(data.actual_consumables_cost_per_room_night)) || Number(data.actual_consumables_cost_per_room_night) < 0) return false;
                actualCostReference.value = copyReference(data);
                return true;
            };
            const adoptActualCost = () => {
                if (busy.value || readOnly.value || writeBlocked.value || !actualCostReference.value) return;
                const data = actualCostReference.value, other = form.value.consumables_cost?.other_variable_cost_per_night ?? '';
                form.value.consumables_cost = { schema_version: 'consumables-v1', mode: 'derived', other_variable_cost_per_night: other, items: [{ id: 'actual-evidence-' + data.snapshot_id, name: '人工月度耗材成本参考', enabled: true, package_price: String(data.actual_consumables_cost_per_room_night), package_quantity: '1', unit: 'piece', usage_quantity: '1', usage_basis: 'occupied_room_night', occurrences_per_occupied_night: '1', source_label: `${data.source_label || '人工月度资料'}；${data.business_month || ''}；证据${data.snapshot_id}`, as_of: data.as_of || '' }] };
                form.value.cost_evidence_snapshot_id = data.snapshot_id; form.value.cost_evidence_digest = data.content_digest; form.value.cost_evidence_confirmed = true;
                if (form.value.operating_cost_basis !== 'fixed_variable') form.value.operating_cost_basis = 'occupied_room_night';
                notice.value = '已明确采用人工月度耗材参考，原耗材明细已替换；其他变动成本保持原输入或未知。请核对重复成本并重算，测算仍为假设。'; actualCostReference.value = null;
            };
            const addEscalation = () => { if (!busy.value && !readOnly.value && !writeBlocked.value) form.value.rent_escalations.push({ year: '', rate: '' }); };
            const addAdjustment = () => { if (!busy.value && !readOnly.value && !writeBlocked.value) form.value.cash_adjustments.push(Object.assign({ year: '' }, Object.fromEntries(adjustmentFields.map(field => [field.key, ''])))); };
            const addConsumable = () => {
                if (busy.value || readOnly.value || writeBlocked.value) return;
                if (!form.value.consumables_cost) form.value.consumables_cost = { schema_version: 'consumables-v1', mode: 'manual', other_variable_cost_per_night: '', items: [] };
                const rows = form.value.consumables_cost.items;
                if (rows.length >= 100) return;
                let id = rows.length + 1;
                while (rows.some(row => row.id === 'item-' + id)) id++;
                rows.push({ id: 'item-' + id, name: '', enabled: true, package_price: '', package_quantity: '', unit: 'piece', usage_quantity: '', usage_basis: 'occupied_room_night', occurrences_per_occupied_night: '1', source_label: '', as_of: '' });
            };
            const loadProcurementReference = async ({ force = false } = {}) => {
                if (busy.value || readOnly.value || writeBlocked.value || !props.project?.id || (procurementCatalog.value && !force)) return;
                const projectId = props.project.id, stamp = ++procurementGeneration, scenarioStamp = generation;
                const current = () => stamp === procurementGeneration && applies(scenarioStamp, projectId);
                procurementError.value = ''; procurementCatalog.value = null;
                procurementItemId.value = ''; procurementTierId.value = '';
                setProcurementLoading(true);
                try {
                    const data = await api(`/projects/${projectId}/scenario/consumables-reference`);
                    if (!current()) return;
                    if (data.project_id !== undefined) checkProject(data, projectId);
                    if (data.schema_version !== 'consumables-procurement-reference-v1'
                        || data.catalog_id !== 'consumables-price-list-20261001'
                        || String(data.source_sha256).toUpperCase() !== 'BF8AA1E0290BCF7F6464D6FFA63BDB653C1E11745A816EA53F517E2FC3EBA4F0'
                        || data.usage_policy !== 'reference_only' || !Array.isArray(data.items) || !data.items.length
                        || !Array.isArray(data.tiers) || !data.tiers.length
                        || data.items.some(item => !item.id || !item.name || !Array.isArray(item.quotes) || !Array.isArray(item.recommendations))) {
                        throw new Error('采购参考响应缺少有效的来源身份或品类档位，未采用。');
                    }
                    procurementCatalog.value = data;
                } catch (failure) {
                    if (current()) procurementError.value = `采购参考读取失败：${errorText(failure)} 请重试；当前测算输入已保留。`;
                } finally { if (current()) setProcurementLoading(false); }
            };
            const onProcurementToggle = event => {
                procurementOpen.value = event.target.open;
                if (procurementOpen.value && !procurementCatalog.value && !procurementError.value) loadProcurementReference();
            };
            const procurementDetails = row => {
                const reference = row?.procurement_reference;
                if (!reference) return null;
                if (reference.item_name && reference.raw_text !== undefined) return reference;
                const catalog = procurementCatalog.value;
                if (!catalog || catalog.catalog_id !== reference.catalog_id || catalog.source_sha256 !== reference.source_sha256) return reference;
                const item = catalog.items.find(value => value.id === reference.item_id);
                const tier = catalog.tiers.find(value => value.id === reference.tier_id);
                const quote = item?.quotes.find(value => value.tier_id === reference.tier_id);
                return { ...reference, item_name: item?.name, tier_label: tier?.label, source_cell: quote?.source_cell,
                    raw_text: quote?.raw_text, recommendations: (item?.recommendations || []).filter(value => value.tier_id === reference.tier_id),
                    interpretation_limits: item?.interpretation_limits || [], source_filename: catalog.source_filename,
                    quote_effective_date: catalog.quote_effective_date, supplier: catalog.supplier, currency: catalog.currency, usage_policy: catalog.usage_policy };
            };
            const addProcurementConsumable = () => {
                const item = selectedProcurementItem.value, tier = selectedProcurementTier.value;
                if (busy.value || readOnly.value || writeBlocked.value || !item || !tier || !selectedProcurementQuote.value
                    || (form.value.consumables_cost?.items.length || 0) >= 100) return;
                addConsumable();
                const row = form.value.consumables_cost.items.at(-1);
                Object.assign(row, { name: item.name, unit: '', source_label: `用户测算假设；参考${item.name} / ${tier.label}`, as_of: '',
                    procurement_reference: { catalog_id: procurementCatalog.value.catalog_id, source_sha256: procurementCatalog.value.source_sha256,
                        item_id: item.id, tier_id: tier.id, confirmed_for_scenario: false } });
                notice.value = `已添加${item.name}待确认明细。请填写本方案实际采用的价格、包装单位、用量与日期，并主动确认；参考原价未自动填入。`;
            };
            const clearProcurementConfirmation = (row = {}) => {
                if (row.procurement_reference) row.procurement_reference.confirmed_for_scenario = false;
                if (form.value.cost_evidence_snapshot_id) {
                    form.value.cost_evidence_snapshot_id = null; form.value.cost_evidence_digest = ''; form.value.cost_evidence_confirmed = false;
                    notice.value = '耗材参数已改动，解除实际成本版本引用；当前值为独立测算假设。';
                }
            };
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
                if (result.value.consumables_cost || input.consumables_cost) {
                    const cost = result.value.consumables_cost || { status: '历史快照未提供明细结果', items: input.consumables_cost.items };
                    rows.push(['易耗品采用方式', input.consumables_cost?.mode], ['易耗品完整性', cost.status], ['已明确部分小计 / 元每已售间夜', cost.known_subtotal_per_night], ['易耗品合计 / 元每已售间夜', cost.consumables_per_night], ['当前采用经营成本 / 元每间夜', result.value.effective_operating_cost_per_night],
                        ['用品名称', '启用', '每包价格 / 元', '包净含量', '单位', '每基准用量', '用量基准', '次数每已售间夜', '元每已售间夜', '来源', '日期', '参考品类', '参考档位', '原档位报价', '来源单元格', '来源SHA256', '已确认本方案参数', '布局上下文建议', '引用限制']);
                    rows.push(...cost.items.map(row => {
                        const reference = row.procurement_reference || input.consumables_cost?.items.find(value => value.id === row.id)?.procurement_reference;
                        return [row.name, row.enabled, row.package_price, row.package_quantity, row.unit, row.usage_quantity, row.usage_basis,
                            row.occurrences_per_occupied_night, row.cost_per_occupied_night, row.source_label, row.as_of,
                            reference?.item_name, reference?.tier_label, reference?.raw_text, reference?.source_cell, reference?.source_sha256,
                            reference ? reference.confirmed_for_scenario : null, reference?.recommendations, reference?.interpretation_limits];
                    }), []);
                }
                const labels = ['经营年', '天数', 'ADR（元）', '入住率（比例0..1）', 'RevPAR（元）', '营业额（元）', '经营成本（元）', '租金（元）', '折旧（元）', '管理费（元）', '税前利润（元）', '税前现金代理（元）', '累计税前现金代理（元）', '完整调整后现金流（元）', '累计完整调整后现金流（元）'];
                rows.push(labels, ...annualRows.value.map(row => columns.map(key => row[key])));
                if (result.value.decision_constraints) rows.push([], ['合同与目标约束', result.value.decision_constraints]);
                if (result.value.cash_pressure) {
                    const pressure = result.value.cash_pressure;
                    rows.push([], ['月度现金假设口径', pressure.basis], ['完整性', pressure.status], ['现金低谷 / 元', pressure.minimum_liquidity], ['低谷月份', pressure.minimum_month], ['资金缺口 / 元', pressure.funding_gap], ['缺项', pressure.missing_fields],
                        ['月份', '经营净现金', '资本支出', '其他净现金', '新增放款', '还本', '利息', '净现金', '期末可用现金', '偿债覆盖倍数']);
                    rows.push(...pressure.monthly_rows.map(row => ['month', 'operating_net_cash', 'capex_cash', 'other_net_cash', 'loan_draw', 'principal_payment', 'interest_payment', 'net_cash', 'ending_liquidity', 'dscr'].map(key => row[key])));
                    rows.push([], ['已明确贷款偿债计划（包括计划期之外，缺项见完整性提示）'], ['贷款编号', '月份', '还本', '利息', '债务偿还', '剩余本金', '在计划范围内']);
                    rows.push(...pressure.debt_schedule.map(row => ['loan_id', 'month', 'principal_payment', 'interest_payment', 'debt_service', 'remaining_principal', 'within_plan'].map(key => row[key])));
                }
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
            window.addEventListener?.('suxi:actual-consumables-reference', receiveActualCost);
            if (onUnmounted) onUnmounted(() => { generation++; procurementGeneration++; window.removeEventListener?.('suxi:actual-consumables-reference', receiveActualCost); emit('busy-change', false); });
            return { procurementReferenceAvailable, actualReferenceAvailable, form, result, groups, adjustmentFields, loading, working, busy, error, notice, scenarioVersion, contentDigest, readback, historicalModel, readOnly, writeBlocked, dirty, stale, annualRows, endingUnrecovered, adjustedEndingUnrecovered, cashAdjustmentGaps, visibleFields, groupMissing, pendingReplacement, confirmReplacement, cancelReplacement, exportContent, exportFilename, exportMessage, exportTextarea, copyCsv, clearExport, money, percent, fieldLabel, issueLabel, paybackText, loadScenario, newBlank, loadReference, preview, save, addEscalation, addAdjustment, addConsumable, procurementCatalog, procurementLoading, procurementError, procurementOpen, procurementItemId, procurementTierId, selectedProcurementItem, selectedProcurementTier, selectedProcurementQuote, selectedProcurementRecommendations, loadProcurementReference, onProcurementToggle, procurementDetails, addProcurementConsumable, clearProcurementConfirmation, exportCsv, buildCsv, toInput,
                scenarioKey, historyViewing, viewedEventId, library, history, projectOptions, panelOpen, panelError, compareSelections, compareResult, compareProjectId, compareKey, copyTarget, scenarioLabel, statusLabel, switchScenario, loadScenarioPanel, viewVersion, copyVersion, addComparison, compareScenarios, addConstraints, addCashPlan, generateMonths, addLoan, actualCostReference, receiveActualCost, adoptActualCost, loadMoreHistory, loadComparisonProjects, projectSearch };
        },
        template: `
          <section class="border-t pt-5 space-y-4" data-testid="investment-scenario-workbench">
            <div class="flex flex-wrap justify-between items-start gap-3"><div><h4 class="text-lg font-semibold">投资经营测算 · 假设区</h4><p class="mt-1 text-sm text-gray-600 break-words">{{ project.project_name }} · {{ project.hotel_name || (project.hotel_id ? '酒店编号 ' + project.hotel_id : '未关联酒店') }} · 测算基准 {{ form.as_of || '待填写' }}</p></div><span class="text-xs rounded-full px-3 py-1 bg-amber-50 text-amber-800">经营假设</span></div>
            <p class="text-sm text-gray-600">这里的营业额、利润和回本均为输入假设推算。上方人工资金台账记录实际投入与收回；测算保存不会改变台账金额。所有金额以人民币元填写，空值表示未明确。</p>
            <div class="flex flex-wrap items-center gap-3"><label class="text-sm">当前保存方案<select :value="scenarioKey" @change="switchScenario($event.target.value)" :disabled="busy" class="ml-2 border rounded-lg px-3 py-3" data-testid="scenario-key"><option value="conservative">保守</option><option value="base">基准</option><option value="optimistic">乐观</option></select></label><button type="button" @click="loadScenarioPanel" :disabled="busy" class="border rounded-lg px-3 py-3" data-testid="scenario-library-open">方案历史与项目比较</button><span class="text-xs text-gray-500">三种方案各自保存；旧测算保留为基准方案</span></div>
            <div v-if="panelOpen" class="border rounded-lg p-3 space-y-3 min-w-0" data-testid="scenario-library"><p v-if="panelError" class="text-red-800 break-words" role="alert">{{ panelError }}</p><p v-if="library" class="text-sm">已保存方案：<span v-for="item in library.items" :key="item.scenario_key" class="mr-3">{{ scenarioLabel(item.scenario_key) }} {{ item.scenario_version ? 'v' + item.scenario_version : '未保存' }}</span></p>
              <details v-if="history"><summary class="cursor-pointer py-2 text-sm">历史快照 · 已载入 {{ history.items.length }} 条（更早原文保留）</summary><p v-if="!history.items.length" class="text-sm text-gray-500">尚未保存方案。</p><div v-for="item in history.items" :key="item.scenario_event_id" class="flex flex-wrap justify-between items-center gap-2 py-2 border-t text-sm"><span class="break-words">{{ scenarioLabel(item.scenario_key) }} v{{ item.scenario_version }} · {{ item.scenario_name || '草稿' }} · {{ item.saved_at }} · {{ item.model_version }}</span><button type="button" @click="viewVersion(item.scenario_event_id)" :disabled="busy" class="border rounded-lg px-3 py-3" :data-testid="'scenario-history-' + item.scenario_event_id">查看原快照</button></div><button v-if="history.has_more" type="button" @click="loadMoreHistory" :disabled="busy" class="border rounded-lg px-3 py-3">读取更早版本</button></details>
              <div v-if="historyViewing" class="border border-amber-200 bg-amber-50 rounded-lg p-3 space-y-2 text-sm"><p>正在只读查看历史版本；复制后按当前模型新建版本，原历史保留。</p><label>复制到<select v-model="copyTarget" class="ml-2 border rounded-lg px-3 py-3"><option value="conservative">保守</option><option value="base">基准</option><option value="optimistic">乐观</option></select></label><button type="button" @click="copyVersion" :disabled="busy || !!project.archived_at || writeBlocked" class="ml-2 border rounded-lg px-3 py-3" data-testid="scenario-history-copy">复制为新版本并回读</button></div>
              <details v-if="projectOptions"><summary class="cursor-pointer py-2 text-sm">同口径项目与方案比较</summary><p class="text-xs text-gray-600 mt-2">选择已保存方案。日期、模型、年数和成本口径须一致；资料不足时显示缺口，不形成排序。实际实收单独列示。</p><div class="flex flex-wrap gap-2 mt-3"><input v-model="projectSearch" placeholder="按项目名查找" class="border rounded-lg px-3 py-3 max-w-full" /><button type="button" @click="loadComparisonProjects(1)" :disabled="busy" class="border rounded-lg px-3 py-3">查找项目</button></div><div class="flex flex-wrap gap-2 mt-3"><select v-model="compareProjectId" class="border rounded-lg px-3 py-3 max-w-full" data-testid="scenario-compare-project"><option value="">选择项目</option><option v-for="item in projectOptions.list" :key="item.id" :value="String(item.id)">{{ item.project_name }}</option></select><select v-model="compareKey" class="border rounded-lg px-3 py-3"><option value="conservative">保守</option><option value="base">基准</option><option value="optimistic">乐观</option></select><button type="button" @click="addComparison" :disabled="busy" class="border rounded-lg px-3 py-3">加入比较</button></div><div v-if="projectOptions.pagination.total_page > 1" class="flex gap-2 items-center mt-2 text-sm"><button type="button" @click="loadComparisonProjects(projectOptions.pagination.page - 1)" :disabled="busy || projectOptions.pagination.page <= 1" class="border rounded-lg px-3 py-3">上一页</button><span>{{ projectOptions.pagination.page }} / {{ projectOptions.pagination.total_page }}</span><button type="button" @click="loadComparisonProjects(projectOptions.pagination.page + 1)" :disabled="busy || projectOptions.pagination.page >= projectOptions.pagination.total_page" class="border rounded-lg px-3 py-3">下一页</button></div><div v-for="(item,index) in compareSelections" :key="item.project_id + ':' + item.scenario_key" class="text-sm mt-2">{{ item.project_name }} · {{ scenarioLabel(item.scenario_key) }} <button type="button" @click="compareSelections.splice(index,1);compareResult=null" :disabled="busy" class="px-3 py-2">移除</button></div><button type="button" @click="compareScenarios" :disabled="busy || compareSelections.length < 2" class="border rounded-lg px-3 py-3 mt-3" data-testid="scenario-compare-run">比较已保存结果</button>
                <div v-if="compareResult" class="mt-3 space-y-2"><p>{{ compareResult.comparable ? '同口径经营假设比较' : '比较条件不齐，请先核对' }}</p><p v-for="reason in compareResult.reasons" :key="reason" class="text-sm text-amber-800 break-words">{{ reason }}</p><div class="overflow-x-auto max-w-full"><table class="text-sm min-w-[760px]"><thead><tr><th class="p-2">项目 / 方案</th><th class="p-2">日期 / 模型</th><th class="p-2">完整现金假设回本</th><th class="p-2">期末情景现金（元）</th><th class="p-2">实际投入 / 实收（元）</th></tr></thead><tbody><tr v-for="item in compareResult.items" :key="item.project_id + ':' + item.scenario_key"><td class="p-2">{{ item.project_name }} / {{ scenarioLabel(item.scenario_key) }}</td><td class="p-2">{{ item.as_of || '待填' }} / {{ item.model_version || '未保存' }}</td><td class="p-2">{{ paybackText(item.scenario_payback) }}</td><td class="p-2">{{ money(item.ending_cumulative_scenario_cashflow) }}</td><td class="p-2">{{ money(item.actual_cash?.invested_amount) }} / {{ money(item.actual_cash?.net_recovered_amount) }}<p class="text-xs text-gray-500">人工实收，来源未独立核验</p></td></tr></tbody></table></div></div>
              </details>
            </div>
            <div v-if="actualReferenceAvailable && actualCostReference" class="border border-amber-200 bg-amber-50 rounded-lg p-3 space-y-2 text-sm" data-testid="scenario-actual-cost-reference"><p>收到同酒店 {{ actualCostReference.business_month }} 人工月度耗材参考：{{ money(actualCostReference.actual_consumables_cost_per_room_night) }} 元 / 已售间夜。采用会替换当前耗材明细，保留其他变动成本；请先核对重复项和全酒店分母。测算仍为假设。</p><button type="button" @click="adoptActualCost" :disabled="busy || readOnly || writeBlocked" class="border rounded-lg px-3 py-3" data-testid="scenario-actual-cost-adopt">已核对，采用为本方案耗材参考</button><button type="button" @click="actualCostReference=null" class="px-3 py-3">取消</button></div>
            <p v-if="readOnly" class="rounded-lg border p-3 text-sm text-gray-600" data-testid="scenario-readonly">本项目已归档，经营测算仅供读取与导出。</p>
            <div class="flex flex-wrap gap-2"><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy || readOnly || writeBlocked" @click="newBlank" data-testid="scenario-new-blank">新建空白测算</button><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy || readOnly || writeBlocked" @click="loadReference" data-testid="scenario-reference-example">载入清远参考样例</button><button type="button" class="text-green-800 px-3 py-3" :disabled="busy" @click="loadScenario" data-testid="scenario-reload">重新读取已保存测算</button></div>
            <div v-if="pendingReplacement" class="border border-amber-200 bg-amber-50 rounded-lg p-3 space-y-3 text-sm text-amber-900" role="alert" data-testid="scenario-draft-replacement"><p>当前有未保存的修改。继续“{{ pendingReplacement.label }}”会替换当前输入；已保存版本不会改变。</p><div class="flex flex-wrap gap-2"><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy" @click="cancelReplacement" data-testid="scenario-keep-draft">保留当前输入</button><button type="button" class="border rounded-lg px-3 py-3" :disabled="busy" @click="confirmReplacement" data-testid="scenario-confirm-replacement">放弃修改并继续</button></div></div>
            <p v-if="loading" class="text-sm text-gray-500" role="status">正在读取本项目经营测算…</p>
            <p v-if="error" class="border border-red-200 bg-red-50 rounded-lg p-3 text-sm text-red-800 break-words" role="alert" data-testid="scenario-error">{{ error }}</p>
            <p v-if="notice" class="border border-green-200 rounded-lg p-3 text-sm text-green-800" role="status" data-testid="scenario-notice">{{ notice }}</p>
            <div v-if="form.reference_example" class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900" data-testid="scenario-reference-source"><p>清远参考样例：2022年原表元数据，仅引用假设，非本酒店事实。改动后的数字仍需独立核对。</p><p class="mt-1 break-words">来源：{{ form.source_label || '待填写' }} · {{ form.source_ref || '来源编号未提供' }}</p><p v-if="form.source_sha256" class="mt-1 text-xs break-all">来源 SHA256：{{ form.source_sha256 }}</p></div>
            <form autocomplete="off" class="space-y-3" @submit.prevent="save" data-testid="scenario-form">
              <fieldset :disabled="busy || readOnly || writeBlocked" class="space-y-3 min-w-0">
                <details open class="border rounded-lg p-3 sm:p-4" data-testid="scenario-consumables">
                  <summary class="font-semibold cursor-pointer py-1">易耗品成本明细 · {{ form.consumables_cost?.items.length || 0 }} 项</summary>
                  <p class="mt-3 text-sm text-gray-600">每包价格 ÷ 包净含量 × 每基准用量 × 基准次数 / 已售间夜。净含量与用量须用同一单位；客夜、清洁次数需独立提供换算依据。采购额不等于当期耗用，以下为测算假设。</p>
                  <details v-if="procurementReferenceAvailable" :open="procurementOpen" @toggle="onProcurementToggle" class="mt-3 border-t pt-3 min-w-0" data-testid="scenario-consumables-reference">
                    <summary @click="(busy || readOnly || writeBlocked) && $event.preventDefault()" :aria-disabled="busy || readOnly || writeBlocked" class="font-semibold cursor-pointer py-2 min-h-[44px]" data-testid="scenario-procurement-reference-toggle">采购价目参考 · 按品类与档位查看</summary>
                    <p class="mt-2 text-sm text-gray-600 break-words">品类与档位须自行选择。原价区间、配置建议只作参考；添加后价格、单位和用量仍待确认，采用的参数属于本方案测算假设。</p>
                    <p v-if="procurementLoading" class="mt-3 text-sm text-gray-500" role="status" data-testid="scenario-procurement-reference-loading">正在读取本项目采购参考…</p>
                    <div v-if="procurementError" class="mt-3 space-y-2" role="alert" data-testid="scenario-procurement-reference-error"><p class="text-sm text-red-800 break-words">{{ procurementError }}</p><button type="button" @click="loadProcurementReference" :disabled="busy || readOnly || writeBlocked" class="border rounded-lg px-3 py-3" data-testid="scenario-procurement-reference-retry">重试读取采购参考</button></div>
                    <div v-if="procurementCatalog" class="mt-3 space-y-3 min-w-0">
                      <p class="text-xs text-gray-600 break-words">来源：{{ procurementCatalog.source_filename || procurementCatalog.source_label }} · 原表报价生效日：{{ procurementCatalog.quote_effective_date || '未给出' }} · 供应商：{{ procurementCatalog.supplier || '未给出' }} · 原表币种及计价单位：待确认</p>
                      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 min-w-0">
                        <label class="text-sm min-w-0">参考品类<select v-model="procurementItemId" class="mt-1 w-full max-w-full border rounded-lg px-3 py-3" data-testid="scenario-procurement-item"><option value="">请选择品类</option><option v-for="item in procurementCatalog.items" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label>
                        <label class="text-sm min-w-0">参考档位<select v-model="procurementTierId" class="mt-1 w-full max-w-full border rounded-lg px-3 py-3" data-testid="scenario-procurement-tier"><option value="">请选择档位</option><option v-for="tier in procurementCatalog.tiers" :key="tier.id" :value="tier.id">{{ tier.label }}</option></select></label>
                      </div>
                      <div v-if="selectedProcurementQuote" class="space-y-2 min-w-0">
                        <p class="text-sm break-words" data-testid="scenario-procurement-quote">{{ selectedProcurementItem.name }} · {{ selectedProcurementTier.label }} · 原档位内容：<strong>{{ selectedProcurementQuote.raw_text }}</strong> · 单元格 {{ selectedProcurementQuote.source_cell }}</p>
                        <div class="text-sm text-gray-600 space-y-1 break-words" data-testid="scenario-procurement-recommendations"><p v-if="!selectedProcurementRecommendations.length">本品本档没有单独给出的布局建议。</p><p v-for="row in selectedProcurementRecommendations" :key="row.source_cell">布局上下文建议（非本SKU确认报价）· {{ row.source_cell }}：{{ row.raw_text }}</p></div>
                        <ul class="text-xs text-gray-600 space-y-1 break-words"><li v-for="line in selectedProcurementItem.interpretation_limits" :key="line">{{ line }}</li></ul>
                      </div>
                      <p v-else class="text-sm text-gray-500">选择品类和档位后显示原表内容。</p>
                      <button type="button" @click="addProcurementConsumable" :disabled="!selectedProcurementQuote || busy || readOnly || writeBlocked || (form.consumables_cost?.items.length || 0) >= 100" class="border rounded-lg px-3 py-3 min-h-[44px]" data-testid="scenario-procurement-add">添加待确认明细</button>
                      <p class="text-xs text-gray-500 break-all">参考来源 SHA256：{{ procurementCatalog.source_sha256 }}</p>
                    </div>
                  </details>
                  <button type="button" @click="addConsumable" class="mt-3 border rounded-lg px-3 py-3" :disabled="(form.consumables_cost?.items.length || 0) >= 100" data-testid="scenario-consumable-add">添加用品</button>
                  <div v-if="form.consumables_cost" class="mt-3 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <label class="text-sm">成本采用方式<select v-model="form.consumables_cost.mode" @change="clearProcurementConfirmation()" class="mt-1 w-full border rounded-lg px-3 py-3" data-testid="scenario-consumables-mode"><option value="manual">仍采用手填单位经营成本</option><option value="derived">采用易耗品明细 + 其他变动成本</option></select></label>
                      <label class="text-sm">其他变动成本（元 / 已售间夜）<input v-model="form.consumables_cost.other_variable_cost_per_night" type="number" min="0" step="any" placeholder="无其他成本也须明确填0" class="mt-1 w-full border rounded-lg px-3 py-3" data-testid="scenario-consumables-other" /></label>
                    </div>
                    <p class="text-xs text-gray-600">采用明细时，经营成本口径须为“按已售间夜”或“固定 + 变动”。其他变动成本应排除本明细、租金、固定年成本、管理费与折旧。原手填汇总会保留供比较。</p>
                    <div v-for="(row,index) in form.consumables_cost.items" :key="row.id" class="border-t pt-3 space-y-3" :data-testid="'scenario-consumable-row-' + index">
                      <label class="flex items-center gap-2 text-sm"><input v-model="row.enabled" @change="clearProcurementConfirmation(row)" type="checkbox" class="h-5 w-5" :data-testid="'consumable-enabled-' + index" />计入第 {{ index + 1 }} 项（停用仍保留资料）</label>
                      <div v-if="row.procurement_reference" class="space-y-2 text-sm min-w-0" :data-testid="'consumable-procurement-reference-' + index">
                        <p class="break-words">采购参考：{{ procurementDetails(row).item_name || row.name }} · {{ procurementDetails(row).tier_label || row.procurement_reference.tier_id }} · 原档位内容：{{ procurementDetails(row).raw_text ?? '待服务回读确认' }} · 单元格 {{ procurementDetails(row).source_cell || '待回读' }}</p>
                        <p class="text-xs text-gray-600 break-words">原表报价生效日：{{ procurementDetails(row).quote_effective_date || '未给出' }} · 供应商及原表计价单位：待确认。以下手填值是本方案假设，不代表原表已成交价。</p>
                        <p v-for="advice in procurementDetails(row).recommendations" :key="advice.source_cell" class="text-xs text-gray-600 break-words">布局上下文建议（非本SKU确认报价）· {{ advice.source_cell }}：{{ advice.raw_text }}</p>
                        <ul class="text-xs text-gray-600 space-y-1 break-words"><li v-for="line in procurementDetails(row).interpretation_limits" :key="line">{{ line }}</li></ul>
                        <label class="flex items-start gap-2 text-sm"><input v-model="row.procurement_reference.confirmed_for_scenario" type="checkbox" class="h-5 w-5 shrink-0 mt-1" :data-testid="'consumable-reference-confirmed-' + index" /><span>已确认本方案采用的价格、包装单位和用量<span class="block text-xs text-gray-600">仅确认本方案参数；原价目资料继续为参考。改动参数后须重新确认。</span></span></label>
                        <p v-if="!row.procurement_reference.confirmed_for_scenario" class="text-xs text-amber-800">{{ row.enabled ? '本方案尚未确认采用；完整用品合计保持待确认。' : '本行未确认采用且已停用，保留参考记录，不参与成本合计。' }}</p>
                        <p class="text-xs text-gray-500 break-all">引用来源 SHA256：{{ row.procurement_reference.source_sha256 }}</p>
                      </div>
                      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <label class="text-sm">用品名称<input v-model="row.name" maxlength="160" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-name-' + index" /></label>
                        <label class="text-sm">每包价格（元）<input v-model="row.package_price" @input="clearProcurementConfirmation(row)" type="number" min="0" step="any" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-price-' + index" /></label>
                        <label class="text-sm">包净含量<input v-model="row.package_quantity" @input="clearProcurementConfirmation(row)" type="number" min="0" step="any" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-package-' + index" /></label>
                        <label class="text-sm">净含量与用量单位<select v-model="row.unit" @change="clearProcurementConfirmation(row)" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-unit-' + index"><option value="">请选择本方案单位</option><option value="piece">件 / 个</option><option value="ml">毫升 mL</option><option value="g">克 g</option></select></label>
                        <label class="text-sm">每基准用量<input v-model="row.usage_quantity" @input="clearProcurementConfirmation(row)" type="number" min="0" step="any" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-usage-' + index" /></label>
                        <label class="text-sm">用量基准<select v-model="row.usage_basis" @change="row.occurrences_per_occupied_night = row.usage_basis === 'occupied_room_night' ? '1' : ''; clearProcurementConfirmation(row)" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-basis-' + index"><option value="occupied_room_night">每已售间夜</option><option value="guest_night">每客夜</option><option value="cleaning">每次清洁</option></select></label>
                        <label v-if="row.usage_basis !== 'occupied_room_night'" class="text-sm">客夜或清洁次数 / 已售间夜<input v-model="row.occurrences_per_occupied_night" @input="clearProcurementConfirmation(row)" type="number" min="0" step="any" placeholder="未提供时无法换算" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-occurrences-' + index" /></label>
                        <label class="text-sm">价格、用量与换算来源<input v-model="row.source_label" @input="clearProcurementConfirmation(row)" maxlength="300" placeholder="采购规格、实测样本或人工假设" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-source-' + index" /></label>
                        <label class="text-sm">{{ row.procurement_reference ? '本方案价格基准日' : '明细基准日期' }}<input v-model="row.as_of" @input="clearProcurementConfirmation(row)" type="date" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'consumable-date-' + index" /></label>
                      </div>
                    </div>
                  </div>
                  <div v-if="result?.consumables_cost && !stale" class="mt-4 border-t pt-3 text-sm space-y-2" data-testid="scenario-consumables-result">
                    <p>已明确部分小计：{{ money(result.consumables_cost.known_subtotal_per_night) }} 元 / 已售间夜 · 完整易耗品合计：{{ money(result.consumables_cost.consumables_per_night) }} 元 / 已售间夜</p>
                    <p>当前采用经营成本：{{ money(result.effective_operating_cost_per_night) }} 元 / 间夜 · {{ form.consumables_cost?.mode === 'derived' ? '明细 + 其他变动成本' : '手填汇总' }}</p>
                    <p v-if="result.consumables_cost.status !== 'ready'" class="text-amber-800">明细尚不完整；已知小计不能代表全部用品成本。</p>
                    <ul class="space-y-1"><li v-for="row in result.consumables_cost.items" :key="row.id" class="break-words">{{ row.name || row.id }}：{{ row.enabled ? money(row.cost_per_occupied_night) + ' 元 / 已售间夜' : '已停用，未计入' }}<span v-if="!row.source_label || !row.as_of"> · 来源或日期待补</span></li></ul>
                  </div>
                  <p v-else-if="stale && form.consumables_cost" class="mt-3 text-sm text-amber-800">输入已变更，请重算后核对用品合计与利润。</p>
                </details>
                <details v-for="group in groups" :key="group.name" :open="group.open" class="border rounded-lg p-3 sm:p-4"><summary class="font-semibold cursor-pointer py-1">{{ group.name }}<span v-if="groupMissing(group)" class="ml-2 text-xs text-amber-800">待补 {{ groupMissing(group) }} 项</span></summary><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-4"><label v-for="field in visibleFields(group)" :key="field.key" class="text-sm min-w-0">{{ field.label }}<span v-if="!stale && result?.missing_fields?.includes(field.key)" class="ml-1 text-xs text-amber-800">待补</span><select v-if="field.type === 'select'" v-model="form[field.key]" class="mt-1 w-full border rounded-lg px-3 py-3" :data-testid="'scenario-' + field.key"><option value="">请选择计价口径</option><option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option></select><input v-else :type="field.type" :step="field.type === 'number' ? 'any' : undefined" :value="form[field.key]" @input="form[field.key] = $event.target.value" :name="'scenario-' + project.id + '-' + field.key" autocomplete="off" :maxlength="field.type === 'text' ? 500 : undefined" :aria-invalid="!stale && result?.missing_fields?.includes(field.key) ? 'true' : undefined" class="mt-1 w-full border rounded-lg px-3 py-3" placeholder="未明确可留空" :data-testid="'scenario-' + field.key" /></label></div><p v-if="group.name === '经营假设'" class="mt-3 text-xs text-gray-500">比例按百分比填写；成熟期采用指定入住率，房价与成本按各自年度增长假设递增。固定年度成本仅在“固定 + 变动”口径下计入。</p><p v-if="group.name === '初始投资与折旧'" class="mt-3 text-xs text-gray-500">初始现金与可折旧金额分开填写；折旧是会计费用，押金可退不等于已收回现金。不涉及的现金项请明确填0。</p></details>
                <details class="border rounded-lg p-3 sm:p-4"><summary class="font-semibold cursor-pointer py-1">租金递增安排 · {{ form.rent_escalations.length }} 段</summary><p class="mt-3 text-xs text-gray-500">按经营年填写生效年度与涨幅；未添加表示没有额外租金递增安排。</p><div v-for="(row,index) in form.rent_escalations" :key="index" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3"><label class="text-sm">生效经营年<input v-model="row.year" type="number" step="1" class="mt-1 w-full border rounded-lg px-3 py-2" :data-testid="'scenario-rent-year-' + index" /></label><label class="text-sm">涨幅（%）<input v-model="row.rate" type="number" step="any" class="mt-1 w-full border rounded-lg px-3 py-2" :data-testid="'scenario-rent-rate-' + index" /></label><button type="button" class="text-red-700 px-3 py-3 self-end" @click="form.rent_escalations.splice(index,1)">移除本段</button></div><button type="button" class="border rounded-lg px-3 py-2 mt-3 min-h-[44px]" @click="addEscalation" data-testid="scenario-add-rent-escalation">添加租金递增</button></details>
                <details class="border rounded-lg p-3 sm:p-4"><summary class="font-semibold cursor-pointer py-1">可选年度现金调整 · {{ form.cash_adjustments.length }} 年</summary><p class="mt-3 text-xs text-gray-500">完整现金回收需每个经营年的六项现金明确填写。不涉及某项请人工填0；未填写时保留未知，仍只给税前现金代理。</p><div v-for="(row,index) in form.cash_adjustments" :key="index" class="border-t mt-3 pt-3"><div class="flex justify-between gap-3"><label class="text-sm">经营年<input v-model="row.year" type="number" step="1" class="mt-1 w-full border rounded-lg px-3 py-2" :data-testid="'scenario-cash-year-' + index" /></label><button type="button" class="text-red-700 px-3 py-3" @click="form.cash_adjustments.splice(index,1)">移除此年</button></div><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-3"><label v-for="field in adjustmentFields" :key="field.key" class="text-sm">{{ field.label }}<input v-model="row[field.key]" type="number" step="any" class="mt-1 w-full border rounded-lg px-3 py-3" placeholder="未明确" :data-testid="'scenario-cash-' + field.key + '-' + index" /></label></div></div><button type="button" class="border rounded-lg px-3 py-2 mt-3 min-h-[44px]" @click="addAdjustment" data-testid="scenario-add-cash-adjustment">添加年度现金调整</button></details>
                <details class="border rounded-lg p-3 space-y-3" data-testid="scenario-decision-constraints"><summary class="font-semibold cursor-pointer py-2">目标回本与合同剩余期（选填）</summary><button v-if="!form.decision_constraints" type="button" @click="addConstraints" class="border rounded-lg px-3 py-3">填写合同与目标</button><div v-if="form.decision_constraints" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3"><label class="text-sm">目标回本期（月）<input v-model="form.decision_constraints.target_payback_months" type="number" min="1" max="360" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">合同开始日<input v-model="form.decision_constraints.contract_start_on" @input="form.decision_constraints.contract_confirmed=false" type="date" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">合同结束日<input v-model="form.decision_constraints.contract_end_on" @input="form.decision_constraints.contract_confirmed=false" type="date" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">合同来源 / 凭证编号<input v-model="form.decision_constraints.contract_source" @input="form.decision_constraints.contract_confirmed=false" maxlength="300" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm flex items-center gap-2"><input v-model="form.decision_constraints.contract_confirmed" type="checkbox" class="h-5 w-5" />已核对实际合同期限与来源</label></div><p class="text-xs text-gray-600">合同来源由用户核对；系统不独立鉴真。情景从基准日计时并包含营建期，完整现金缺项时仅显示税前代理试算。</p></details>
                <details class="border rounded-lg p-3 space-y-3 min-w-0" data-testid="scenario-cash-plan"><summary class="font-semibold cursor-pointer py-2">月度偿债计划与现金压力（选填）</summary><button v-if="!form.cash_plan" type="button" @click="addCashPlan" class="border rounded-lg px-3 py-3">建立月度假设</button><div v-if="form.cash_plan" class="space-y-3 mt-3"><p class="text-xs text-gray-600">独立月度假设：经营净现金已扣经营成本、租金及税费，排除本表贷款和资本支出；不自动摊分年度利润。显式0与空值分别处理。存量贷款填剩余本金/期限，不增加放款；新增贷款在起始月放款，月末开始还款。</p><div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><label class="text-sm">计划起始月<input v-model="form.cash_plan.start_month" type="month" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">计划月数<input v-model="form.cash_plan.months" type="number" min="1" max="360" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">期初可用现金（元）<input v-model="form.cash_plan.opening_liquidity" type="number" min="0" step="0.01" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">月度假设来源<input v-model="form.cash_plan.source_label" maxlength="300" class="mt-1 w-full border rounded-lg px-3 py-3" /></label></div><button type="button" @click="generateMonths" class="border rounded-lg px-3 py-3">生成月份，保留同月已填值</button><button type="button" @click="addLoan" class="ml-2 border rounded-lg px-3 py-3">添加贷款假设</button>
                  <div v-for="(loan,index) in form.cash_plan.loans" :key="loan.id" class="border-t pt-3 space-y-2"><div class="flex justify-between text-sm"><span>贷款 {{ index + 1 }}</span><button type="button" @click="form.cash_plan.loans.splice(index,1)" class="px-3 py-2">移除此贷款假设</button></div><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3"><label class="text-sm">名称<input v-model="loan.name" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">资金口径<select v-model="loan.funding" class="mt-1 w-full border rounded-lg px-3 py-3"><option value="new">新增放款</option><option value="existing">存量贷款余额</option></select></label><label class="text-sm">本金 / 剩余本金（元）<input v-model="loan.principal" type="number" min="0.01" step="0.01" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">年利率（%）<input v-model="loan.annual_rate" type="number" min="0" max="100" step="any" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">放款 / 剩余还款起始月<input v-model="loan.start_month" type="month" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">还款 / 剩余期限（月）<input v-model="loan.term_months" type="number" min="1" max="360" class="mt-1 w-full border rounded-lg px-3 py-3" /></label><label class="text-sm">还款方式<select v-model="loan.method" class="mt-1 w-full border rounded-lg px-3 py-3"><option value="equal_principal">等额本金</option><option value="annuity">等额本息</option><option value="interest_only">先息后本</option></select></label></div></div>
                  <div class="overflow-x-auto max-w-full"><table class="text-sm min-w-[630px]"><thead><tr><th class="p-2">月份</th><th class="p-2">经营净现金 / 元</th><th class="p-2">资本支出 / 元</th><th class="p-2">其他净现金 / 元</th></tr></thead><tbody><tr v-for="row in form.cash_plan.monthly_inputs" :key="row.month"><td class="p-2">{{ row.month }}</td><td class="p-2"><input v-model="row.operating_net_cash" type="number" step="0.01" class="w-40 border rounded-lg px-3 py-3" placeholder="空值未知" /></td><td class="p-2"><input v-model="row.capex_cash" type="number" min="0" step="0.01" class="w-40 border rounded-lg px-3 py-3" placeholder="无支出须填0" /></td><td class="p-2"><input v-model="row.other_net_cash" type="number" step="0.01" class="w-40 border rounded-lg px-3 py-3" placeholder="无其他须填0" /></td></tr></tbody></table></div>
                </div></details>
              </fieldset>
              <div class="flex flex-wrap justify-between items-center gap-3"><p class="text-sm text-gray-500">{{ dirty ? '表单有未保存的修改' : '当前为已读取的表单' }} · {{ scenarioVersion == null ? '尚无测算版本' : '已存版本 ' + scenarioVersion }}<span class="block mt-1">可先保存未填完的草稿，保存后自动核对读取结果。</span></p><div class="flex flex-wrap gap-2"><button type="button" class="border rounded-lg px-4 py-3" :disabled="busy || readOnly || writeBlocked" @click="preview" data-testid="scenario-preview">{{ working ? '处理中…' : '预览重算' }}</button><button class="btn-primary px-4 py-3" :disabled="busy || readOnly || writeBlocked" data-testid="scenario-save">{{ working ? '处理中…' : '保存测算' }}</button></div></div>
            </form>
            <div v-if="result" class="space-y-4 border-t pt-4" data-testid="scenario-results">
              <div v-if="result.decision_constraints && !stale" class="border rounded-lg p-3 space-y-2 text-sm" data-testid="scenario-contract-result"><p>目标期：{{ result.decision_constraints.target_payback_months ?? '未填写' }} 月 · {{ statusLabel(result.decision_constraints.target_status) }}</p><p>合同状态：{{ statusLabel(result.decision_constraints.contract_status) }} · 剩余 {{ result.decision_constraints.contract_remaining_months ?? '未知' }} 月 · {{ statusLabel(result.decision_constraints.contract_payback_status) }}</p><p v-if="result.decision_constraints.contract_missing_fields.length" class="text-amber-800">合同缺项：{{ result.decision_constraints.contract_missing_fields.join('、') }}</p><p class="text-xs text-gray-600">{{ result.decision_constraints.basis_note }}</p></div>
              <details v-if="result.cash_pressure && !stale" class="border rounded-lg p-3 space-y-2 text-sm" data-testid="scenario-cash-pressure"><summary class="font-semibold cursor-pointer py-2">月度现金压力 · {{ result.cash_pressure.status === 'ready_assumption' ? '完整假设' : '部分资料' }}</summary><p>现金低谷 {{ money(result.cash_pressure.minimum_liquidity) }} 元 · {{ result.cash_pressure.minimum_month || '待补齐' }}；资金缺口 {{ money(result.cash_pressure.funding_gap) }} 元</p><p>最低偿债覆盖倍数 {{ result.cash_pressure.minimum_dscr == null ? '未知 / 无到期偿债' : Number(result.cash_pressure.minimum_dscr).toFixed(2) }}；首次已观察现金不足 {{ result.cash_pressure.observed_first_shortage_month || '未观察到 / 资料未全' }}</p><p class="text-xs text-gray-600">{{ result.cash_pressure.basis }}</p><p v-if="result.cash_pressure.missing_fields.length" class="text-amber-800 break-words">缺项 {{ result.cash_pressure.missing_fields.length }} 处，完整低谷与资金缺口保持未知。</p><div class="overflow-x-auto max-w-full"><table class="text-sm min-w-[800px]"><thead><tr><th class="p-2">月份</th><th class="p-2">放款</th><th class="p-2">还本</th><th class="p-2">利息</th><th class="p-2">净现金</th><th class="p-2">期末可用现金</th><th class="p-2">偿债覆盖</th></tr></thead><tbody><tr v-for="row in result.cash_pressure.monthly_rows" :key="row.month"><td class="p-2">{{ row.month }}</td><td class="p-2">{{ money(row.loan_draw) }}</td><td class="p-2">{{ money(row.principal_payment) }}</td><td class="p-2">{{ money(row.interest_payment) }}</td><td class="p-2">{{ money(row.net_cash) }}</td><td class="p-2">{{ money(row.ending_liquidity) }}</td><td class="p-2">{{ row.dscr == null ? '未知 / 无偿债' : Number(row.dscr).toFixed(2) }}</td></tr></tbody></table></div></details>
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
