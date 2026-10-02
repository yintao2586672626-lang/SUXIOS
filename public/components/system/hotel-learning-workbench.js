(function () {
    'use strict';
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const clone = value => JSON.parse(JSON.stringify(value));
    const f = (path, label, type = 'text', options = null, percent = false, initial = '') => ({ path, label, type, options, percent, initial });
    const option = (value, label) => ({ value, label });
    const quality = [option('unverified', '未核对'), option('manual_reference', '人工参考'), option('operator_attested', '人工已核对')];
    const units = [option('piece', '件'), option('ml', '毫升'), option('g', '克')];
    const rateUnits = [option('percentage_point', '百分数：1表示1%'), option('fraction', '比例：0.01表示1%')];
    const modes = [
        { id: 'profile', label: '基础资料', module: 'knowledge', note: '逐字段保留来源、日期与核对状态。缺项保持缺项。' },
        { id: 'consumables_reconciliation', label: '耗材领用与盘点', module: 'operations', note: '库存消耗、领用、盘点差异和报损分别核算；差异不能直接认定员工浪费。数量使用同一基础单位，明确未发生的流转请填0。' },
        { id: 'investment_target', label: '回本目标反推', module: 'investment', note: '沿用年度正向模型反求房价或入住率。所有金额均为手填假设；融资净现金、税费等逐年明确，不等于投资人实际回款。' },
        { id: 'contract_review', label: '合同安全垫', module: 'investment', note: '按自然日期与保守插值比较回本期限；手填预测不表示合同已独立核验。' },
        { id: 'ota_scene', label: 'OTA搜索与价格', module: 'ota', note: '固定关键词、位置、入住离店日期和价格资格。范围内未见不表示全平台没有曝光。' },
        { id: 'market_sample', label: '商圈样本评分', module: 'ota', note: '人工核对同口径引用键，权重自行明确；样本评分是方法参考，不能作为官方平台评分。' },
        { id: 'operating_review', label: '计划与实际复盘', module: 'operations', note: '按同一期间和口径比较七项指标。项目净现金与投资人实收分别记录。' },
        { id: 'geo_observation', label: 'AI问答观测', module: 'knowledge', note: '记录问题、模型、环境和引用。被提及或引用不能直接证明订单和获客效果。' },
    ];
    const kinds = { profile: 'jhira_profile', consumables_reconciliation: 'consumables_actual', investment_target: 'jhira_target', contract_review: 'jhira_contract', ota_scene: 'jhira_ota_scene', market_sample: 'jhira_market', operating_review: 'jhira_review', geo_observation: 'jhira_geo' };
    const reviewMetrics = [['available_room_nights', '可售间夜（间夜）'], ['sold_room_nights', '已售间夜（间夜）'], ['revenue', '收入（元）'], ['operating_cost', '经营成本（元）'], ['debt_service', '偿债本息（元）'], ['project_net_cash', '项目净现金（元）'], ['investor_received_cash', '投资人实收（元）']];
    function get(object, path) { return path.split('.').reduce((value, key) => value == null ? undefined : value[key], object); }
    function set(object, path, value) { const keys = path.split('.'); let current = object; keys.slice(0, -1).forEach(key => { if (!current[key] || typeof current[key] !== 'object') current[key] = {}; current = current[key]; }); current[keys.at(-1)] = value; }
    function schema(mode, form = {}) {
        if (mode === 'profile') return [];
        if (mode === 'consumables_reconciliation') return [f('occupied_room_nights', '全酒店本期已售间夜（间夜）', 'number'), f('occupied_room_nights_source_ref', '全酒店已售间夜来源'), f('cleaning_count', '本期清洁次数（次）', 'number'), f('cleaning_count_source_ref', '清洁次数来源'), f('operator_attested', '我已核对当前酒店、账期、数量、单价与来源', 'checkbox')];
        if (mode === 'investment_target') {
            const occ = get(form, 'request.solve_for') === 'occupancy';
            return [f('scenario.scenario_name', '方案名称'), f('scenario.as_of', '测算基准日', 'date'), f('scenario.source_label', '假设来源说明'),
                ...[['rooms', '物理房数（间）'], ['leased_rooms', '计租房数（间）'], ['years', '预测运营年数（年）'], ['days_per_year', '每年可售天数（天）'], ['mature_from_year', '成熟入住率起算年'], ['adr_growth_from_year', '房价增长起算年'], ['depreciation_years', '折旧年限（年）'], ['construction_months', '营建期（月）'], ['rent_free_months', '免租期（月）']].map(([key, label]) => f('scenario.' + key, label, 'number')),
                ...[['adr_first_year', '首年净房价（元/间夜）'], ['operating_cost_per_night', '经营单位成本（元/间夜）'], ['fixed_annual_operating_cost', '固定年经营成本（元，固定+变动模式填写）'], ['monthly_rent_per_room', '单房月租金（元）'], ['renovation_cash', '装修现金投入（元）'], ['franchise_cash', '加盟现金投入（元）'], ['refundable_deposit_cash', '可退押金投入（元）'], ['other_initial_cash', '其他初始投入（元）'], ['working_capital_cash', '初始营运资金（元）'], ['depreciable_amount', '可折旧金额（元）']].map(([key, label]) => f('scenario.' + key, label, 'number')),
                ...[['occupancy_first_year', '首年入住率（%）'], ['occupancy_mature', '成熟入住率（%）'], ['management_fee_rate', '管理费率（%）'], ['adr_growth_rate', '年房价增长率（%）'], ['operating_cost_growth_rate', '年经营成本增长率（%）']].map(([key, label]) => f('scenario.' + key, label, 'number', null, true)),
                f('scenario.operating_cost_basis', '经营成本基准', 'select', [option('available_room_night', '按可售间夜'), option('occupied_room_night', '按已售间夜'), option('fixed_variable', '固定成本+已售间夜变动成本')]),
                f('request.solve_for', '反求变量', 'select', [option('adr', '首年净房价'), option('occupancy', '成熟入住率')], false, 'adr'),
                f('request.target_payback_months', '目标回本月数（含营建期）', 'number'), f('request.bounds.lower', occ ? '入住率求解下界（%）' : '房价求解下界（元/间夜）', 'number', null, occ), f('request.bounds.upper', occ ? '入住率求解上界（%，最多100）' : '房价求解上界（元/间夜）', 'number', null, occ),
                f('request.occupancy_shape', '入住率形状（反求入住率时适用）', 'select', [option('preserve_ratio', '保留首年/成熟比例'), option('constant_all_years', '各年同一入住率')])];
        }
        if (mode === 'contract_review') return [f('as_of', '测算基准日', 'date'), f('payback_months', '预测回本月数（含营建期；未回本留空）', 'number'), f('constraints.contract_start_on', '合同开始日期', 'date'), f('constraints.contract_end_on', '合同结束日期', 'date'), f('constraints.contract_source', '合同来源'), f('constraints.contract_confirmed', '我已核对合同起止日期与来源', 'checkbox'), f('constraints.target_payback_months', '目标回本月数', 'number')];
        if (mode === 'ota_scene') return [...[['keyword', '搜索关键词'], ['location', '定位位置'], ['device', '设备环境'], ['login_state', '登录状态（只记录状态）'], ['sort', '排序规则'], ['filters', '筛选条件（无筛选填无）'], ['platform_store_id', '平台酒店ID'], ['source_ref', '截图或观测来源']].map(([key, label]) => f('scene.' + key, label)), f('scene.observed_at', '观测时间（北京时间）', 'datetime-local'),
            f('scene.check_in', '入住日期', 'date'), f('scene.check_out', '离店日期', 'date'), f('scene.page_capacity', '每页条数（条）', 'number'),
            f('visibility', '可见状态', 'select', [option('unknown', '未取得'), option('observed', '已观察到'), option('not_seen_in_range', '本次范围内未见')], false, 'unknown'),
            f('rank_min', '排名区间下界（名）', 'number'), f('rank_max', '排名区间上界（名）', 'number'), f('observed_through_rank', '已观察至排名（名）', 'number'), f('conversion_rate', '转化率（依下方单位填写）', 'number'), f('rate_unit', '转化率单位', 'select', rateUnits), f('price', '本次资格可成交价（元）', 'number'),
            ...[['room_type', '可比房型'], ['cancellation', '取消规则'], ['breakfast', '早餐权益'], ['guest_count', '入住人数'], ['membership', '会员资格'], ['tax_basis', '含税口径'], ['payment', '付款条件']].map(([key, label]) => f('price_terms.' + key, label))];
        if (mode === 'market_sample') return [f('sample_ref', '样本来源'), f('model_version', '评分算法版本'), f('comparison_key', '同口径搜索/价格引用键'), f('rate_unit', '样本转化率单位', 'select', rateUnits), ...[['traffic', '流量权重（%）'], ['conversion', '转化权重（%）'], ['revenue', '营收权重（%）']].map(([key, label]) => f('weights.' + key, label, 'number', null, true)), f('comparison_attested', '我已人工核对每家样本使用同一搜索及价格口径引用键', 'checkbox')];
        if (mode === 'operating_review') return [f('period_start', '复盘起日', 'date'), f('period_end', '复盘止日', 'date'), ...['plan', 'actual'].flatMap(side => [f(side + '.source_ref', (side === 'plan' ? '计划' : '实际') + '来源'), f(side + '.basis', (side === 'plan' ? '计划' : '实际') + '口径（两侧须一致）'), ...reviewMetrics.map(([key, label]) => f(side + '.' + key, (side === 'plan' ? '计划 · ' : '实际 · ') + label, 'number'))])];
        return [f('question', '固定观测问题'), f('model', '模型名称'), f('model_version', '模型版本'), f('region', '地区'), f('network', '网络环境'), f('observed_at', '观测时间（北京时间）', 'datetime-local'), f('source_ref', '回答截图或保存来源'), f('response_summary', '回答摘要', 'textarea')];
    }
    function repeaters(mode) {
        if (mode === 'profile') return [{ path: 'fields', label: '基础资料字段', fields: [f('key', '字段标识（如room_count）'), f('value', '字段值'), f('unit', '单位'), f('source_ref', '来源'), f('as_of', '资料日期', 'date'), f('quality', '核对状态', 'select', quality, false, 'unverified')] }];
        if (mode === 'consumables_reconciliation') return [{ path: 'items', label: '耗材计量项', fields: [f('enabled', '纳入本期核算', 'checkbox', null, false, true), f('name', '耗材名称'), f('unit', '基础单位', 'select', units, false, 'piece'), f('source_ref', '库存/计价资料来源'), f('source_date', '资料日期', 'date'),
            ...[['opening_quantity', '期初数量'], ['purchased_quantity', '采购入库数量'], ['transfer_in_quantity', '调入数量'], ['closing_quantity', '实盘期末数量'], ['transfer_out_quantity', '调出数量'], ['returned_quantity', '退货数量'], ['written_off_quantity', '单列报损数量'], ['issued_quantity', '领用数量'], ['book_closing_quantity', '账面期末数量']].map(([key, label]) => f(key, label + '（基础单位）', 'number')),
            f('issued_quantity_source_ref', '领用单来源'), f('book_closing_quantity_source_ref', '账面期末来源'), f('unit_price', '已确认基础单位成本（元/单位）', 'number'), f('budget_unit_price', '预算基础单位成本（元/单位，可选）', 'number'), f('budget_usage_per_room_night', '预算每已售间夜用量（单位/间夜，可选）', 'number')] }];
        if (mode === 'investment_target') return [{ path: 'scenario.rent_escalations', label: '租金递增', fields: [f('year', '递增运营年', 'number'), f('rate', '本次递增比例（%）', 'number', null, true)] }, { path: 'scenario.cash_adjustments', label: '逐年现金调整（每个预测年均需填写，明确没有填0）', fields: [f('year', '运营年序号', 'number'), ...[['tax_cash', '税费支出'], ['financing_net_cash', '融资净现金（放款减偿债）'], ['maintenance_capex', '维护资本支出'], ['working_capital_change', '营运资金增加'], ['deposit_refund', '押金退还'], ['salvage_cash', '残值净现金']].map(([key, label]) => f(key, label + '（元）', 'number'))] }];
        if (mode === 'market_sample') return [{ path: 'hotels', label: '商圈酒店样本', fields: [f('platform_store_id', '平台酒店ID（唯一）'), f('name', '酒店名称'), f('traffic', '同期间流量（次）', 'number'), f('conversion', '同期间转化率（依全局单位）', 'number'), f('revenue', '同期间营收（元）', 'number')] }];
        if (mode === 'geo_observation') return [{ path: 'citations', label: '回答引用', fields: [f('url', 'HTTP/HTTPS来源链接', 'url'), f('fact_consistency', '与酒店事实一致性', 'select', [option('unverified', '未核对'), option('consistent', '人工核对一致'), option('inconsistent', '人工核对不一致')], false, 'unverified')] }];
        return [];
    }
    function initial(mode, month) {
        const out = {}; schema(mode).forEach(field => set(out, field.path, field.type === 'checkbox' ? false : field.initial)); repeaters(mode).forEach(group => set(out, group.path, []));
        if (mode === 'consumables_reconciliation') out.denominator_scope = 'whole_hotel';
        if (mode === 'investment_target') { out.scenario.currency = 'CNY'; out.scenario.reference_example = false; }
        if (mode === 'operating_review' && /^\d{4}-\d{2}$/.test(month)) { out.period_start = month + '-01'; const [year, m] = month.split('-').map(Number); out.period_end = month + '-' + new Date(year, m, 0).getDate(); }
        return out;
    }
    const resultLabels = { status: '计算状态', source_quality: '来源质量', solved_value: '达到目标所需值', target_reached: '目标是否达成', reason: '判断原因', minimum_liquidity: '最低现金（元）', funding_gap: '资金缺口（元）', actual_consumed_cost: '库存平衡消耗成本（元）', known_consumed_cost: '已知消耗成本（元）', actual_consumables_cost_per_room_night: '全酒店每已售间夜成本（元）', issued_cost: '领用成本（元）', known_issued_cost: '已知领用成本（元）', inventory_balance_minus_issued_cost: '库存消耗与领用成本差异（元）', counted_minus_book_closing_cost: '盘点与账面成本差异（元）', inventory_balance_cost_per_cleaning: '库存消耗成本/有来源清洁次数（元/次）', issued_cost_per_room_night: '领用成本/全酒店已售间夜（元）', missing_items: '缺项', missing_fields: '缺失字段', forecast_payback_on: '预测回本日', target_payback_on: '目标回本日', forecast_payback_months: '预测回本月数', target_payback_months: '目标回本月数', contract_safety_days: '合同安全垫（天）', target_safety_days: '目标安全垫（天）', contract_remaining_days: '合同剩余天数', contract_payback_status: '合同内回本判断', contract_status: '合同状态', target_status: '目标判断', total_years: '含营建回本年数', operating_years: '运营回本年数', actual_cost_ratio: '实际经营成本/收入（比例）', reference_score: '样本参考评分', comparison_ready: '是否具备可比条件', comparison_key: '同口径引用键', scene_fingerprint: '本次观测摘要', sample_fingerprint: '本次样本摘要', experiment_key: '固定问题环境摘要', conversion_percentage_point: '转化率（%）', visibility: '观察可见状态', rank_min: '排名下界', rank_max: '排名上界', price: '可成交价（元）', difference: '实际减计划', actual: '实际', plan: '计划', value: '字段值', key: '字段标识', source_ref: '来源', as_of: '资料日期', name: '名称', platform_store_id: '平台酒店ID', basis_note: '口径说明', basis: '计算口径', contract_remaining_months: '自然月剩余期限（仅展示）', decision_safe: '是否可直接用于决策', external_write_authorized: '是否允许外部操作', actual_cash_written: '是否写入实际台账' };
    const states = { missing: '未取得', inputs_missing: '缺少输入', partial: '部分资料', recorded: '已记录', calculated: '已计算', calculated_reference: '参考评分已计算', solved: '反求完成', unreachable: '本次边界/期限内不可达', no_solution: '无法取得有效解', unsupported_non_monotonic: '现金响应不单调，需另行分析', ready: '假设参数完整', unverified: '未核对', operator_attested: '人工已核对', manual_reference: '人工参考', scenario_assumption: '测算假设', within_limit: '在期限内', beyond_limit: '超过期限', trial_only: '仅供试算', not_reached_in_horizon: '预测期内未回本', user_confirmed: '用户核对来源', expired: '合同已到期', not_started: '合同尚未开始', before_contract_start: '早于合同起日', forecast_missing: '预测缺失', observed: '已观察到', unknown: '未取得', not_seen_in_range: '本次范围内未见' };
    function flatten(value, path = '', out = []) { if (value === null || typeof value !== 'object') out.push([path, value]); else Object.entries(value).forEach(([key, item]) => flatten(item, path ? path + '.' + key : key, out)); return out; }
    Object.assign(resultLabels, { field_count: '资料字段数', scope_aligned: '计划与实际口径一致', reconciliation_status: '领用与盘点核对状态', contract_missing_fields: '合同待补资料', enabled_items_missing: '尚未选择核算耗材', cleaning_count_missing_or_zero: '清洁次数缺失或为零', cleaning_count_source_missing: '清洁次数来源缺失', inventory_source_evidence: '库存来源与资料日期', confirmed_unit_price: '已确认基础单位成本', counted_closing_quantity: '实盘期末数量', inventory_balance_consumption: '库存平衡消耗数量', scope_mismatch: '计划与实际口径不一致', period_mismatch: '期间与本次复盘不一致', source_missing: '来源缺失', room_night_denominator_missing: '全酒店已售间夜及来源缺失', observed_through_rank: '已观察至排名', fact_consistency: '引用事实核对', traffic: '流量', conversion: '转化率', revenue: '收入', unit: '单位' });
    Object.assign(states, { evaluated_assumption: '已按假设比较', compared: '已完成同口径比较', excluded: '未纳入本期', consistent: '人工核对一致', inconsistent: '人工核对不一致', required_inputs_missing: '必要输入不完整', occupancy_shape_exceeds_capacity: '入住率上下界与形状超出客房容量', lower_bound_satisfies: '所填下界已达到目标', bounded_minimum_feasible: '已求得本次范围内达到目标的最低值', non_positive_cash_response: '提高该变量未改善年度现金', no_positive_cash_response: '该变量未带来正向现金变化', mixed_annual_cash_response: '各年现金变化方向不一致', upper_bound_or_horizon_insufficient: '本次上界或预测期限不足以达到目标' });
    Object.assign(resultLabels, { source_evidence: '库存来源与有效资料日期', inventory_balance_negative: '库存平衡消耗为负，请复核流转数量', whole_hotel_room_nights_missing_or_zero: '全酒店已售间夜缺失或为零', whole_hotel_room_nights_source_missing: '全酒店已售间夜来源缺失', positive_profile: '成熟入住率必须大于零' });
    if (typeof document !== 'undefined' && !document.getElementById('hotel-learning-workbench-style')) {
        const style = document.createElement('style'); style.id = 'hotel-learning-workbench-style'; style.textContent = '.hotel-learning{color:var(--sx-text,#334155);background:var(--sx-card,#fff);border:1px solid var(--sx-border,#d8e2ee);border-radius:16px;padding:20px;min-width:0}.hotel-learning h3,.hotel-learning h4{color:var(--sx-title,#102033);margin:0 0 8px;font-weight:700}.hotel-learning p{margin:8px 0;line-height:1.6}.hl-scope,.hl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:16px 0}.hl-field{display:flex;flex-direction:column;gap:6px;font-size:13px;min-width:0}.hl-field input,.hl-field select,.hl-field textarea{width:100%;box-sizing:border-box;min-width:0;padding:10px;border:1px solid var(--sx-border,#d8e2ee);border-radius:8px;background:#fff;color:inherit;font:inherit}.hl-field textarea{min-height:88px;resize:vertical}.hl-check{flex-direction:row;align-items:center}.hl-check input{width:auto}.hl-actions,.hl-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.hotel-learning button{padding:9px 12px;border:1px solid var(--sx-border,#d8e2ee);border-radius:8px;background:#fff;cursor:pointer;white-space:normal;color:inherit;min-height:44px}.hotel-learning button:disabled{opacity:.55;cursor:default}.hotel-learning .hl-primary,.hotel-learning .hl-selected{background:var(--sx-luxury-green,var(--sx-brand,#143a31));color:#fff;border-color:transparent}.hl-muted{font-size:12px;color:var(--sx-muted,#5b6d63)}.hl-alert{padding:12px;background:#fff1f2;color:#9f1239;border-radius:8px;overflow-wrap:anywhere}.hl-notice{padding:12px;background:#edf7f0;color:#176b42;border-radius:8px}.hl-row,.hl-result{border:1px solid var(--sx-border,#d8e2ee);padding:14px;border-radius:10px;margin:12px 0;min-width:0}.hl-results{display:grid;grid-template-columns:minmax(120px,1fr) minmax(0,2fr);gap:8px 12px;margin:12px 0}.hl-results dt,.hl-results dd{margin:0;overflow-wrap:anywhere;font-size:13px}.hl-results dt{color:var(--sx-muted,#5b6d63)}.hl-results dd{white-space:pre-wrap}.hl-history{display:flex;flex-wrap:wrap;gap:8px}.hotel-learning fieldset{min-width:0}.hotel-learning input:focus,.hotel-learning select:focus,.hotel-learning textarea:focus,.hotel-learning button:focus-visible{outline:2px solid var(--sx-luxury-gold,#dcc591);outline-offset:2px}@media(max-width:480px){.hotel-learning{padding:12px}.hl-scope,.hl-grid,.hl-results{grid-template-columns:minmax(0,1fr)}.hl-actions button,.hl-tabs button{flex:1 1 120px}.hl-results dd{margin-bottom:8px}}'; document.head.appendChild(style);
    }
    registry.HotelLearningWorkbench = {
        name: 'HotelLearningWorkbench',
        props: { request: { type: Function, required: true }, hotels: { type: Array, default: () => [] }, selectedHotelId: { type: [String, Number], default: '' }, canExecute: { type: Boolean, default: false }, module: { type: String, default: 'all' } },
        data() { const mode = (modes.find(item => this.module === 'all' || item.module === this.module) || modes[0]).id; const periodMonth = new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Shanghai' }).slice(0, 7); return { mode, hotelId: this.selectedHotelId || '', periodMonth, platform: 'ctrip', form: initial(mode, periodMonth), busy: false, error: '', notice: '', overview: null, record: null, saved: null, dirty: false, seq: 0, restoreRequested: null, saveKey: null }; },
        computed: {
            availableModes() { return modes.filter(item => this.module === 'all' || item.module === this.module); },
            currentMode() { return modes.find(item => item.id === this.mode); },
            isOta() { return ['ota_scene', 'market_sample'].includes(this.mode); },
            scope() { return { hotel_id: Number(this.hotelId), period_month: this.periodMonth, platform: this.isOta ? this.platform : 'whole_hotel', mode: this.mode }; },
            query() { return new URLSearchParams(this.scope).toString(); },
            scopeReady() { return Number.isInteger(Number(this.hotelId)) && Number(this.hotelId) > 0 && /^\d{4}-(0[1-9]|1[0-2])$/.test(this.periodMonth) && (!this.isOta || ['ctrip', 'meituan'].includes(this.platform)); },
            fields() { return schema(this.mode, this.form); }, groups() { return repeaters(this.mode); },
            history() { return this.overview?.history || []; },
            effectiveCanExecute() { return this.overview?.can_execute ?? this.canExecute; },
            resultCurrent() { return !!this.record && !this.dirty; },
            resultRows() {
                if (!this.resultCurrent) return [];
                const result = this.record.result || {}; const rows = [];
                const add = (path, label = this.label(path)) => rows.push([label, get(result, path)]);
                const gaps = (items, label = '待补或待核对资料') => { if (Array.isArray(items) && items.length) rows.push([label, items.map(item => this.gapLabel(item)).join('；')]); };
                if (this.mode === 'profile') {
                    add('field_count');
                    (result.fields || []).forEach((row, i) => { rows.push([row.key || ('第' + (i + 1) + '项资料'), this.display(row.value) + (row.unit ? ' ' + row.unit : '')]); rows.push(['来源与核对 · ' + (row.key || i + 1), [row.source_ref, row.as_of, row.status].map(this.display).join(' · ')]); });
                } else if (this.mode === 'consumables_reconciliation') {
                    ['actual_consumed_cost', 'known_consumed_cost', 'actual_consumables_cost_per_room_night', 'reconciliation.status', 'reconciliation.issued_cost', 'reconciliation.inventory_balance_minus_issued_cost', 'reconciliation.counted_minus_book_closing_cost', 'reconciliation.inventory_balance_cost_per_cleaning', 'reconciliation.issued_cost_per_room_night'].forEach(path => add(path, path === 'reconciliation.status' ? '领用与盘点核对状态' : this.label(path)));
                    (result.items || []).forEach((row, i) => { rows.push([row.name || ('耗材' + (i + 1)), '库存消耗 ' + this.display(row.consumed_quantity) + '；领用 ' + this.display(row.issued_quantity) + '；盘点减账面 ' + this.display(row.counted_minus_book_closing_quantity) + '（' + ({ piece: '件', ml: '毫升', g: '克' }[row.unit] || this.display(row.unit)) + '）']); });
                    gaps(result.reconciliation?.missing_items, '领用与盘点待补资料');
                } else if (this.mode === 'investment_target') {
                    const occupancy = (result.request?.solve_for || this.record.inputs?.request?.solve_for) === 'occupancy';
                    const value = result.solved_value;
                    rows.push([occupancy ? '目标所需成熟入住率（%）' : '目标所需首年净房价（元/间夜）', value == null ? null : this.display(occupancy ? value * 100 : value) + (occupancy ? '%' : ' 元/间夜')]);
                    ['target_reached', 'reason', 'request.target_payback_months', 'forward_result.scenario_payback.total_years', 'forward_result.scenario_payback.operating_years', 'basis_note'].forEach(path => add(path));
                } else if (this.mode === 'contract_review') {
                    ['contract_status', 'contract_remaining_days', 'target_payback_months', 'target_payback_on', 'forecast_payback_months', 'forecast_payback_on', 'contract_payback_status', 'contract_safety_days', 'target_status', 'target_safety_days'].forEach(key => add('calendar.' + key));
                    gaps(result.calendar?.contract_missing_fields, '合同待补资料');
                } else if (this.mode === 'ota_scene') {
                    ['scene.keyword', 'scene.location', 'scene.check_in', 'scene.check_out', 'scene.observed_at', 'scene.source_ref', 'visibility', 'rank_min', 'rank_max', 'observed_through_rank', 'conversion_percentage_point', 'price', 'comparison_ready', 'comparison_key'].forEach(path => add(path));
                    rows.push(['本次价格资格', Object.entries(result.price_terms || {}).map(([key, value]) => this.label('price_terms.' + key) + '：' + this.display(value)).join('；') || null]);
                } else if (this.mode === 'market_sample') {
                    rows.push(['样本来源', this.record.inputs?.sample_ref]); rows.push(['评分算法版本', result.model_version || this.record.inputs?.model_version]);
                    (result.items || []).forEach((row, i) => { rows.push([(row.name || ('酒店' + (i + 1))) + ' · 平台酒店 ' + row.platform_store_id, '参考评分 ' + this.display(row.reference_score) + '；流量 ' + this.display(row.traffic) + ' 次；转化率 ' + this.display(row.conversion) + '%；营收 ' + this.display(row.revenue) + ' 元']); });
                } else if (this.mode === 'operating_review') {
                    ['period_start', 'period_end', 'scope_aligned'].forEach(path => add(path));
                    (result.rows || []).forEach(row => rows.push([reviewMetrics.find(([key]) => key === row.metric)?.[1] || row.metric, '计划 ' + this.display(row.plan) + '；实际 ' + this.display(row.actual) + '；实际减计划 ' + this.display(row.difference)]));
                    rows.push(['实际经营成本占收入（%）', result.actual_cost_ratio == null ? null : this.display(result.actual_cost_ratio * 100) + '%']);
                } else if (this.mode === 'geo_observation') {
                    ['question', 'model', 'model_version', 'region', 'network', 'observed_at', 'source_ref', 'response_summary'].forEach(key => add('record.' + key));
                    (result.record?.citations || []).forEach((row, i) => rows.push(['引用 ' + (i + 1), this.display(row.url) + ' · ' + this.display(row.fact_consistency)]));
                }
                gaps(result.missing_items); gaps(result.missing_fields);
                return rows;
            },
        },
        watch: {
            scope: { deep: true, handler(next, previous) { if (!previous || JSON.stringify(next) !== JSON.stringify(previous)) this.resetScope(); } },
            selectedHotelId(next) { this.hotelId = next || ''; },
            module() { if (!this.availableModes.some(item => item.id === this.mode)) this.mode = this.availableModes[0]?.id || 'profile'; },
        },
        mounted() { this.load(); },
        methods: {
            value(path) { return get(this.form, path) ?? ''; },
            rows(path) { return get(this.form, path) || []; },
            label(path) { const key = path.split('.').at(-1); const field = [...this.fields, ...this.groups.flatMap(group => group.fields)].find(item => item.path === path || item.path.split('.').at(-1) === key); const numbered = path.match(/\.(\d+)\./); return (numbered ? '第' + (Number(numbered[1]) + 1) + '项 · ' : '') + (resultLabels[key] || field?.label || key); },
            gapLabel(value) { const path = String(value); const parts = path.split(':'); const item = this.record?.result?.items?.find(row => row.id === parts[0]); if (item) return (item.name || '耗材') + ' · ' + this.label(parts.slice(1).join(':')); const year = path.match(/(?:^|\.)cash_adjustments\.(\d+)\.(.+)$/); if (year) return '第' + year[1] + '运营年 · ' + this.label(year[2]); const side = path.startsWith('actual.') ? '实际 · ' : path.startsWith('plan.') ? '计划 · ' : ''; return side + this.label(path); },
            display(value) { if (value === null || value === undefined || value === '') return '未取得'; if (typeof value === 'boolean') return value ? '是' : '否'; if (typeof value === 'number') return Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 6 }); return states[value] || String(value); },
            fieldValue(field) { return this.value(field.path); },
            setField(field, value) { set(this.form, field.path, value); this.edit(); },
            edit() { this.seq++; this.busy = false; this.saved = null; this.saveKey = null; this.dirty = true; this.notice = ''; this.error = ''; },
            resetScope() { this.seq++; this.busy = false; this.record = null; this.saved = null; this.overview = null; this.restoreRequested = null; this.saveKey = null; this.dirty = false; this.error = ''; this.notice = ''; this.form = initial(this.mode, this.periodMonth); this.load(); },
            addRow(group) { const row = {}; group.fields.forEach(field => set(row, field.path, field.type === 'checkbox' ? field.initial === true : field.initial)); if (this.mode === 'consumables_reconciliation') { row.id = crypto.randomUUID(); row.valuation_method = 'confirmed_unit_cost'; } this.rows(group.path).push(row); this.edit(); },
            removeRow(group, index) { this.rows(group.path).splice(index, 1); this.edit(); },
            number(value, field) { if (value === '' || value === null || value === undefined) return null; const number = Number(value); if (!Number.isFinite(number)) throw new Error(field.label + '需填写有效数字'); return field.percent ? number / 100 : number; },
            inputs() {
                const out = {}; const assign = (target, raw, field) => set(target, field.path, field.type === 'number' ? this.number(get(raw, field.path), field) : field.type === 'checkbox' ? get(raw, field.path) === true : get(raw, field.path) ?? '');
                this.fields.forEach(field => assign(out, this.form, field));
                this.groups.forEach(group => set(out, group.path, this.rows(group.path).map(raw => { const row = {}; group.fields.forEach(field => assign(row, raw, field)); if (this.mode === 'consumables_reconciliation') { row.id = raw.id || crypto.randomUUID(); row.valuation_method = 'confirmed_unit_cost'; } if (this.mode === 'market_sample') { row.comparison_key = out.comparison_key; row.rate_unit = out.rate_unit; } return row; })));
                if (this.mode === 'consumables_reconciliation') out.denominator_scope = 'whole_hotel';
                if (this.mode === 'investment_target') { out.scenario.currency = 'CNY'; out.scenario.reference_example = false; out.scenario.decision_constraints = { target_payback_months: out.request.target_payback_months }; }
                if (this.mode === 'market_sample' && out.comparison_attested !== true) throw new Error('请核对各酒店使用同口径引用键，并勾选人工核对确认');
                if (this.mode === 'operating_review') ['plan', 'actual'].forEach(side => { out[side].period_start = out.period_start; out[side].period_end = out.period_end; });
                return out;
            },
            hydrate(inputs) {
                let value = clone(inputs || {});
                if (this.mode === 'market_sample') { value.rate_unit = value.rate_unit || value.hotels?.[0]?.rate_unit || ''; value.comparison_attested = !!value.comparison_key && (value.hotels || []).length > 0 && value.hotels.every(row => row.comparison_key === value.comparison_key); }
                const form = Object.assign(initial(this.mode, this.periodMonth), value);
                schema(this.mode, form).forEach(field => { const raw = get(form, field.path); if (field.percent && raw !== null && raw !== undefined && raw !== '') set(form, field.path, Number(raw) * 100); if (field.type === 'datetime-local' && typeof raw === 'string') set(form, field.path, raw.replace(' ', 'T').replace(/\+08:00$/, '')); });
                repeaters(this.mode).forEach(group => { if (!Array.isArray(get(form, group.path))) set(form, group.path, []); get(form, group.path).forEach(row => group.fields.forEach(field => { const raw = get(row, field.path); if (field.percent && raw !== null && raw !== undefined && raw !== '') set(row, field.path, Number(raw) * 100); })); });
                this.form = form;
            },
            async call(path, options = {}) { const response = await this.request(path, options); if (![0, 200].includes(response?.code)) throw new Error(response?.message || response?.msg || '请求失败，请重试'); return response.data; },
            verifyScope(actual, scope, mode = actual?.mode) { if (['hotel_id', 'period_month', 'platform'].some(key => String(actual?.[key]) !== String(scope[key]))) throw new Error('回读酒店、账期或平台范围不一致'); if (mode ? mode !== scope.mode : actual?.kind !== kinds[scope.mode]) throw new Error('回读业务模式不一致'); if (actual?.kind && actual.kind !== kinds[scope.mode]) throw new Error('回读业务类型不一致'); },
            verify(data, scope, id = null) { if (data?.readback_verified !== true || !Number.isInteger(Number(data?.snapshot_id)) || Number(data.snapshot_id) <= 0 || !/^[a-f0-9]{64}$/i.test(data?.content_digest || '')) throw new Error('版本ID或保存摘要未核对，请重试读取'); if (id !== null && Number(data.snapshot_id) !== Number(id)) throw new Error('回读版本ID不一致'); this.verifyScope(data.scope, scope, data.mode || data.result?.mode || data.scope?.mode); if (!data.inputs || !data.result) throw new Error('保存版本内容不完整'); },
            acceptOverview(data, scope) { this.verifyScope(data?.scope, scope); if (!Array.isArray(data?.history) || (data.can_execute != null && typeof data.can_execute !== 'boolean')) throw new Error('版本列表或操作权限未取得，请重试'); return data; },
            async load() { if (!this.scopeReady) return; const sequence = ++this.seq; const scope = { ...this.scope }; this.busy = true; this.error = ''; try { const data = await this.call('/hotel-learning/overview?' + this.query); if (sequence === this.seq) this.overview = this.acceptOverview(data, scope); } catch (error) { if (sequence === this.seq) this.error = error.message; } finally { if (sequence === this.seq) this.busy = false; } },
            async calculate(save) {
                if (this.busy || !this.effectiveCanExecute) return; if (!this.scopeReady) { this.error = '请先选择酒店、有效账期和平台'; return; }
                const sequence = ++this.seq; const scope = { ...this.scope }; const query = this.query; this.busy = true; this.error = ''; this.notice = ''; this.saved = null; this.dirty = true;
                try {
                    const inputs = this.inputs(); if (save && !this.saveKey) this.saveKey = crypto.randomUUID();
                    const posted = await this.call('/hotel-learning/' + (save ? 'snapshots' : 'preview'), { method: 'POST', body: JSON.stringify({ ...scope, inputs, idempotency_key: save ? this.saveKey : crypto.randomUUID() }) });
                    if (sequence !== this.seq) return;
                    let data = posted;
                    if (save) { this.verify(posted, scope); data = await this.call('/hotel-learning/snapshots/' + posted.snapshot_id + '?' + query); if (sequence !== this.seq) return; this.verify(data, scope, posted.snapshot_id); if (data.content_digest !== posted.content_digest) throw new Error('保存与精确回读摘要不一致'); }
                    else { this.verifyScope(data?.scope, scope, data?.result?.mode); if (!data?.inputs || !data?.result) throw new Error('计算结果不完整，请重试'); }
                    this.record = data; this.saved = save ? data : null; this.dirty = false; this.notice = save ? '版本 #' + data.snapshot_id + ' 已保存，ID、范围与摘要精确回读一致' : '当前预览未保存';
                    if (save) { this.hydrate(data.inputs); this.saveKey = null; try { const overview = await this.call('/hotel-learning/overview?' + query); if (sequence === this.seq) this.overview = this.acceptOverview(overview, scope); } catch (error) { if (sequence === this.seq) this.notice += '；历史列表刷新失败，可重读版本列表'; } }
                } catch (error) { if (sequence === this.seq) this.error = error.message; } finally { if (sequence === this.seq) this.busy = false; }
            },
            async restore(id, confirmed = false) {
                if (this.dirty && !confirmed) { this.restoreRequested = id; return; } if (this.busy || !this.scopeReady) return;
                this.restoreRequested = null; const sequence = ++this.seq; const scope = { ...this.scope }; this.busy = true; this.error = '';
                try { const data = await this.call('/hotel-learning/snapshots/' + id + '?' + this.query); if (sequence !== this.seq) return; this.verify(data, scope, id); this.hydrate(data.inputs); this.record = data; this.saved = data; this.saveKey = null; this.dirty = false; this.notice = '已载入版本 #' + data.snapshot_id + ' 的输入和结果，继续编辑将形成新版本'; } catch (error) { if (sequence === this.seq) this.error = error.message; } finally { if (sequence === this.seq) this.busy = false; }
            },
            csv() { if (!this.resultCurrent) return ''; const escape = value => { let text = value === null || value === undefined ? '未取得' : String(value); if (typeof value === 'string' && /^[=+\-@\t\r]/.test(text)) text = "'" + text; return '"' + text.replaceAll('"', '""') + '"'; }; return '\ufeff' + [['字段', '值'], ...flatten(this.record)].map(row => row.map(escape).join(',')).join('\r\n'); },
            exportCsv() { const csv = this.csv(); if (!csv) return; const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); const anchor = document.createElement('a'); anchor.href = url; anchor.download = '酒店业务-' + this.mode + '-' + this.periodMonth + '-' + (this.saved?.snapshot_id || '预览') + '.csv'; document.body.appendChild(anchor); try { anchor.click(); } finally { anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000); } },
        },
        template: `
            <section class="hotel-learning" data-testid="hotel-learning-workbench">
                <h3>酒店业务工作台</h3><p class="hl-muted">按酒店与账期核对经营资料、耗材和投资假设，记录搜索观测并比较计划与实际。先填写来源，再预览结果或保存可追溯版本。</p>
                <div class="hl-scope"><label class="hl-field">当前酒店<select v-model="hotelId" aria-label="当前酒店"><option value="">请选择酒店</option><option v-for="hotel in hotels" :key="hotel.id" :value="hotel.id">{{ hotel.hotel_name || hotel.name || ('酒店 #' + hotel.id) }}</option></select></label><label class="hl-field">业务账期<input v-model="periodMonth" type="month" aria-label="业务账期"></label><label v-if="isOta" class="hl-field">OTA平台<select v-model="platform" aria-label="OTA平台"><option value="ctrip">携程</option><option value="meituan">美团</option></select></label><p v-else class="hl-muted">本模式范围：全酒店；与 OTA 渠道事实分别记录。</p></div>
                <nav class="hl-tabs" aria-label="业务方法"><button v-for="item in availableModes" :key="item.id" type="button" :class="{'hl-selected':mode===item.id}" :aria-pressed="mode===item.id" @click="mode=item.id">{{ item.label }}</button></nav>
                <h4>{{ currentMode.label }}</h4><p class="hl-muted">{{ currentMode.note }}</p><p v-if="!effectiveCanExecute" class="hl-muted">当前账号只可查看已保存版本。</p>
                <p v-if="error" class="hl-alert" role="alert">{{ error }} <button type="button" @click="load" :disabled="busy">重读版本</button></p><p v-if="notice" class="hl-notice" role="status">{{ notice }}</p><p v-if="busy" role="status" class="hl-muted">正在核对当前范围的请求…</p>
                <div v-if="restoreRequested" class="hl-row"><p>载入历史版本将替换当前未保存输入。</p><div class="hl-actions"><button type="button" @click="restore(restoreRequested,true)">确认载入该版本</button><button type="button" @click="restoreRequested=null">保留当前输入</button></div></div>
                <form @submit.prevent="calculate(false)">
                    <fieldset :disabled="!effectiveCanExecute" style="border:0;padding:0;margin:0"><div class="hl-grid"><label v-for="field in fields" :key="field.path" class="hl-field" :class="{'hl-check':field.type==='checkbox'}">
                        <input v-if="field.type==='checkbox'" type="checkbox" :checked="value(field.path)===true" @change="setField(field,$event.target.checked)"><span>{{ field.label }}</span>
                        <select v-if="field.type==='select'" :value="fieldValue(field)" @change="setField(field,$event.target.value)"><option value="">请选择</option><option v-for="choice in field.options" :key="choice.value" :value="choice.value">{{ choice.label }}</option></select>
                        <textarea v-else-if="field.type==='textarea'" :value="fieldValue(field)" @input="setField(field,$event.target.value)"></textarea>
                        <input v-else-if="field.type!=='checkbox'" :type="field.type" :value="fieldValue(field)" :step="field.type==='number'?'any':undefined" :inputmode="field.type==='number'?'decimal':undefined" @input="setField(field,$event.target.value)">
                    </label></div>
                    <div v-for="group in groups" :key="group.path"><h4>{{ group.label }}</h4><fieldset v-for="(row,index) in rows(group.path)" :key="row.id || index" class="hl-row"><legend>第 {{ index+1 }} 项</legend><div class="hl-grid"><label v-for="field in group.fields" :key="field.path" class="hl-field" :class="{'hl-check':field.type==='checkbox'}">
                        <input v-if="field.type==='checkbox'" v-model="row[field.path]" type="checkbox" @change="edit"><span>{{ field.label }}</span>
                        <select v-if="field.type==='select'" v-model="row[field.path]" @change="edit"><option value="">请选择</option><option v-for="choice in field.options" :key="choice.value" :value="choice.value">{{ choice.label }}</option></select>
                        <input v-else-if="field.type!=='checkbox'" v-model="row[field.path]" :type="field.type" :step="field.type==='number'?'any':undefined" @input="edit">
                    </label></div><button type="button" @click="removeRow(group,index)">移除第 {{ index+1 }} 项</button></fieldset><button type="button" @click="addRow(group)">添加{{ group.label.split('（')[0] }}</button></div></fieldset>
                    <div class="hl-actions"><button type="submit" :disabled="busy || !effectiveCanExecute || !scopeReady">预览计算</button><button type="button" class="hl-primary" :disabled="busy || !effectiveCanExecute || !scopeReady" @click="calculate(true)">保存新版本并核对</button><button type="button" :disabled="busy || !scopeReady" @click="load">重读版本列表</button><button type="button" :disabled="!resultCurrent" @click="exportCsv">导出当前结果CSV</button></div>
                </form>
                <p v-if="dirty && record" class="hl-muted">输入已编辑，上一结果与保存标记已失效，请重新预览或保存。</p>
                <div v-if="resultCurrent" class="hl-result" data-testid="hotel-learning-result"><h4>{{ saved ? ('已核对版本 #' + saved.snapshot_id) : '未保存预览' }} · {{ display(record.result.status) }}</h4><p class="hl-muted">来源状态：{{ display(record.source_quality || record.result.source_quality) }}；保存保留本次填写与计算结果，人工记录仍需按来源核对。</p><dl class="hl-results"><template v-for="(entry,index) in resultRows" :key="index"><dt>{{ entry[0] }}</dt><dd>{{ display(entry[1]) }}</dd></template></dl></div>
                <div class="hl-row"><h4>最近30个同范围版本</h4><p v-if="!overview" class="hl-muted">尚未取得版本列表；失败时请重读。</p><p v-else-if="!history.length" class="hl-muted">当前酒店、账期、平台与业务模式尚无已保存版本。</p><div class="hl-history"><button v-for="item in history" :key="item.snapshot_id || item.id" type="button" :disabled="busy" @click="restore(item.snapshot_id || item.id)">版本 #{{ item.snapshot_id || item.id }} · {{ item.created_at || item.saved_at || '时间未取得' }}</button></div></div>
            </section>
        `,
    };
})();
