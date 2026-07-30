(() => {
    'use strict';

    const { ref, computed, h } = window.Vue || {};
    if (typeof ref !== 'function' || typeof computed !== 'function' || typeof h !== 'function') {
        throw new Error('Missing Vue runtime for Meituan future-flow component.');
    }

    const normalizeRows = (rows = [], days = 30) => {
        const limit = [3, 7, 15, 30].includes(Number(days)) ? Number(days) : 30;
        return (Array.isArray(rows) ? rows : [])
            .filter(row => /^\d{4}-\d{2}-\d{2}$/.test(String(row?.target_date || '')))
            .slice()
            .sort((left, right) => String(left.target_date).localeCompare(String(right.target_date)))
            .slice(0, limit);
    };
    const metricValue = (row, key, peer = false) => {
        const value = row?.metrics?.[`${key}${peer ? '_peer_avg' : ''}`]?.value;
        if (value === null || value === undefined || value === '') return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    };
    const metricText = (value) => {
        if (value === null || value === undefined || !Number.isFinite(Number(value))) {
            return '— / 未返回';
        }
        const number = Number(value);
        return number.toLocaleString('zh-CN', {
            maximumFractionDigits: Number.isInteger(number) ? 0 : 2,
        });
    };
    const sectionStatusText = (status) => ({
        ready: '完整',
        partial: '部分返回',
        pending_source_update: '等待平台更新',
        blocked: '采集受阻',
        unverified: '待核验',
        missing: '未返回',
    }[String(status || '')] || '未返回');
    const barHeight = (value, maximum) => {
        if (value === null) return '0';
        if (Number(value) === 0) return '2px';
        return `${Math.max(3, Math.min(100, (Number(value) / Math.max(1, Number(maximum))) * 100))}%`;
    };
    const metrics = Object.freeze([
        { key: 'pv', label: 'PV' },
        { key: 'uv', label: 'UV' },
        { key: 'advance_orders', label: '提前订订单量' },
    ]);

    window.SUXI_MEITUAN_FUTURE_FLOW = {
        name: 'MeituanFutureFlow',
        props: {
            rows: { type: Array, default: () => [] },
            capturedAt: { type: String, default: '' },
        },
        setup(props) {
            const horizon = ref(30);
            const metricKey = ref('pv');
            const visibleRows = computed(() => normalizeRows(props.rows, horizon.value));
            const selectedMetric = computed(() => (
                metrics.find(metric => metric.key === metricKey.value) || metrics[0]
            ));
            const chartMaximum = computed(() => Math.max(1, ...visibleRows.value.flatMap(row => [
                metricValue(row, metricKey.value),
                metricValue(row, metricKey.value, true),
            ]).filter(value => value !== null)));
            const pairCell = (row, key) => h('div', { class: 'whitespace-nowrap text-center font-medium' }, [
                h('span', { class: 'text-blue-700' }, metricText(metricValue(row, key))),
                h('span', { class: 'mx-1 text-slate-300' }, '/'),
                h('span', { class: 'text-orange-700' }, metricText(metricValue(row, key, true))),
            ]);
            const chart = () => h('div', {
                'data-testid': 'meituan-future-daily-chart',
                class: 'mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-slate-50',
            }, [
                h('div', { class: 'flex h-60 min-w-[760px] items-end gap-1 px-3 pt-3' }, visibleRows.value.map(row => {
                    const selfValue = metricValue(row, metricKey.value);
                    const peerValue = metricValue(row, metricKey.value, true);
                    return h('div', {
                        key: `chart-${row.target_date}`,
                        class: 'group relative flex h-full min-w-[22px] flex-1 flex-col justify-end',
                    }, [
                        h('div', { class: 'pointer-events-none absolute left-1/2 top-1 z-20 w-36 -translate-x-1/2 rounded-lg border border-slate-200 bg-white px-2 py-1 text-center text-[10px] leading-4 text-slate-600 opacity-0 shadow-lg group-hover:opacity-100' }, [
                            h('b', { class: 'block text-slate-800' }, row.target_date),
                            h('span', { class: 'block text-blue-700' }, `本店 ${metricText(selfValue)}`),
                            h('span', { class: 'block text-orange-700' }, `同行均值 ${metricText(peerValue)}`),
                        ]),
                        h('div', { class: 'flex h-44 items-end justify-center gap-px border-b border-slate-200' }, [
                            selfValue === null ? null : h('span', {
                                class: 'block w-2/5 rounded-t bg-blue-500',
                                style: { height: barHeight(selfValue, chartMaximum.value) },
                            }),
                            peerValue === null ? null : h('span', {
                                class: 'block w-2/5 rounded-t bg-orange-400',
                                style: { height: barHeight(peerValue, chartMaximum.value) },
                            }),
                        ]),
                        h('div', { class: 'mt-2 text-center text-[10px] text-slate-500' }, row.target_date.slice(5)),
                    ]);
                })),
            ]);
            const detailTable = () => h('div', { class: 'mt-4 overflow-x-auto' }, [
                h('table', {
                    'data-testid': 'meituan-future-daily-table',
                    class: 'min-w-[760px] w-full text-sm',
                }, [
                    h('thead', { class: 'bg-slate-50 text-slate-700' }, [
                        h('tr', [
                            h('th', { class: 'px-3 py-2 text-left' }, '目标日期'),
                            h('th', { class: 'px-3 py-2 text-center' }, 'PV（本店 / 同行）'),
                            h('th', { class: 'px-3 py-2 text-center' }, 'UV（本店 / 同行）'),
                            h('th', { class: 'px-3 py-2 text-center' }, '提前订（本店 / 同行）'),
                            h('th', { class: 'px-3 py-2 text-center' }, '状态'),
                        ]),
                    ]),
                    h('tbody', { class: 'divide-y divide-slate-100' }, visibleRows.value.map(row => h('tr', {
                        key: `detail-${row.target_date}`,
                        class: 'even:bg-slate-50/70',
                    }, [
                        h('td', { class: 'px-3 py-2 font-semibold text-slate-700' }, row.target_date),
                        h('td', { class: 'px-3 py-2' }, [pairCell(row, 'pv')]),
                        h('td', { class: 'px-3 py-2' }, [pairCell(row, 'uv')]),
                        h('td', { class: 'px-3 py-2' }, [pairCell(row, 'advance_orders')]),
                        h('td', { class: 'px-3 py-2 text-center text-slate-600' }, sectionStatusText(row.status)),
                    ]))),
                ]),
            ]);

            return () => h('div', { 'data-testid': 'meituan-future-flow' }, [
                h('div', { class: 'mt-3 flex flex-wrap items-center justify-between gap-2' }, [
                    h('div', { class: 'inline-flex overflow-hidden rounded-lg border border-slate-200' }, [
                        { days: 3, label: '3天' },
                        { days: 7, label: '7天' },
                        { days: 15, label: '15天' },
                        { days: 30, label: '30天' },
                    ].map(option => h('button', {
                        key: option.days,
                        type: 'button',
                        onClick: () => { horizon.value = option.days; },
                        class: ['border-r px-3 py-2 text-xs last:border-r-0', horizon.value === option.days ? 'bg-slate-900 text-white' : 'bg-white text-slate-600'],
                    }, option.label))),
                    h('div', { class: 'inline-flex overflow-hidden rounded-lg border border-slate-200' }, metrics.map(metric => h('button', {
                        key: metric.key,
                        type: 'button',
                        onClick: () => { metricKey.value = metric.key; },
                        class: ['border-r px-3 py-2 text-xs last:border-r-0', metricKey.value === metric.key ? 'bg-blue-600 text-white' : 'bg-white text-slate-600'],
                    }, metric.label))),
                ]),
                h('div', { class: 'mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500' }, [
                    h('span', `当前范围：${visibleRows.value[0]?.target_date || '—'} ～ ${visibleRows.value.at(-1)?.target_date || '—'}（${visibleRows.value.length}天）`),
                    h('span', `采集时间：${props.capturedAt || '—'}`),
                    h('span', `当前指标：${selectedMetric.value.label}`),
                ]),
                h('div', { class: 'mt-2 text-xs text-slate-500' }, [
                    h('span', { class: 'mr-4 text-blue-700' }, '■ 本店'),
                    h('span', { class: 'mr-4 text-orange-700' }, '■ 同行均值'),
                    h('span', '0 为平台返回零值；— 为未返回。'),
                ]),
                visibleRows.value.length
                    ? h('div', [chart(), detailTable()])
                    : h('div', { class: 'mt-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500' }, '— / 未返回未来30天当前快照'),
            ]);
        },
    };

    window.SUXI_MEITUAN_FUTURE_FLOW_TEST_API = Object.freeze({
        normalizeRows,
        metricValue,
        metricText,
        sectionStatusText,
        barHeight,
    });
})();
