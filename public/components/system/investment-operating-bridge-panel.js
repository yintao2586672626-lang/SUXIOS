(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const money = value => value == null || value === '' ? '未取得' : `¥${Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const statusText = value => ({ ready: '人工账目已核对完整', partial: '账目或覆盖不完整', blocked: '口径不可汇总', missing: '未关联投资项目', read_failed: '资金台账读取失败', error: '资金台账读取失败', not_started: '未来账期尚未开始' })[value] || '尚未取得资金台账';
    const amountFields = [['actual_invested', '累计实际投入'], ['net_actual_recovered', '累计净实收'], ['unrecovered', '逐项目未回本金'], ['excess_return', '超额收回']];
    components.InvestmentOperatingBridgePanel = {
        name: 'InvestmentOperatingBridgePanel',
        props: { bridge: { type: Object, default: () => ({}) }, loading: { type: Boolean, default: false } },
        emits: ['open-ledger'],
        render() {
            const h = Vue.h;
            const bridge = this.bridge || {};
            const totals = bridge.totals || bridge.recorded_totals;
            const projects = Array.isArray(bridge.projects) ? bridge.projects : [];
            const valid = bridge.contract_version === 'investment_operating_bridge.v1';
            return h('section', { class: 'rounded-2xl border border-slate-200 bg-white p-5', 'data-testid': 'investment-operating-bridge' }, [
                h('div', { class: 'flex flex-wrap items-center justify-between gap-3' }, [
                    h('div', [h('h3', { class: 'font-bold text-slate-900' }, '自投项目 · 投资人实收回本'), h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, '关联当前酒店的投资资金台账。累计实际收回与本月利润分别核对。')]),
                    h('button', { type: 'button', disabled: bridge.reason_code === 'investment_view_permission_required', class: 'operating-finance-link rounded-lg border px-3 py-2 text-sm disabled:opacity-50', onClick: () => this.$emit('open-ledger') }, '打开投资回本台账'),
                ]),
                this.loading ? h('p', { class: 'mt-4 text-sm text-slate-500', role: 'status' }, '正在读取当前酒店、账期的资金台账…') : h('div', [
                    h('p', { class: 'mt-3 text-xs leading-5 text-slate-600', 'data-testid': 'investment-bridge-scope' }, valid ? `酒店 #${bridge.hotel_id} · 账期 ${bridge.period_month} · 累计截至 ${bridge.effective_as_of} · ${statusText(bridge.status)}` : statusText(bridge.status)),
                    valid && bridge.cutoff_status === 'current_month_to_date' ? h('p', { class: 'mt-2 text-xs text-amber-700' }, `本月只取截至 ${bridge.effective_as_of} 的已发生记录；账期末 ${bridge.requested_period_end} 尚未发生。`) : null,
                    valid && totals ? h('div', { class: 'mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4' }, amountFields.map(([key, label]) => h('div', { class: 'rounded-xl bg-slate-50 p-3', 'data-metric': key }, [h('div', { class: 'text-xs text-slate-500' }, label), h('strong', { class: 'mt-1 block' }, money(totals[key]))]))) : h('p', { class: 'mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800', 'data-testid': 'investment-bridge-no-total' }, bridge.reason_code === 'investment_view_permission_required' ? '当前账号没有该酒店的投资查看权限，资金台账保持隐藏。' : '尚未形成完整资金合计。请核对项目关联、投资人、账目和读取状态。'),
                    valid && !bridge.totals && bridge.recorded_totals ? h('p', { class: 'mt-2 text-xs text-amber-700' }, '以上仅为已录入记录的部分合计，不能认定真实累计回款完整。') : null,
                    valid && projects.length ? h('div', { class: 'mt-4 overflow-x-auto' }, [h('table', { class: 'min-w-full text-left text-sm' }, [
                        h('thead', { class: 'text-xs text-slate-500' }, [h('tr', ['项目 / 投资人', '实际投入', '净实收', '未回本金', '核对状态'].map(label => h('th', { class: 'px-3 py-2' }, label)))]),
                        h('tbody', projects.map(project => h('tr', { key: project.project_id, class: 'border-t border-slate-100', 'data-project-id': project.project_id }, [
                            h('td', { class: 'px-3 py-3' }, `${project.project_name} / ${project.investor_name || '主体待补'}${project.archived_at ? '（已归档）' : ''}`),
                            ...['actual_invested', 'net_actual_recovered', 'unrecovered'].map(key => h('td', { class: 'whitespace-nowrap px-3 py-3' }, money(project.amounts?.[key]))),
                            h('td', { class: 'px-3 py-3' }, project.history_complete ? '人工核对截至当前日期' : '账目核对不完整'),
                        ]))),
                    ])]) : null,
                    h('p', { class: 'mt-3 text-xs leading-5 text-slate-500' }, '资金来源为人工台账，未独立核验。全店 GOP、业主现金代理和未来测算不会自动记作已回本；不同投资人不合并，一个项目的超额回收不抵消另一项目的未回本金。'),
                ]),
            ]);
        },
    };
})();
