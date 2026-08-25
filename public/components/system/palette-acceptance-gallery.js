(() => {
    'use strict';

    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const { h } = Vue;
    const palettes = Object.freeze([
        { id: 'suxios_anchor', name: '宿析品牌基线', source: '当前正式视觉锚点', colors: ['#111418', '#B9965B', '#1F5B63'] },
        { id: 'editorial_coral_cyan', name: '编辑珊瑚青', source: 'PPT 候选 01', colors: ['#FF6438', '#A8EDF0', '#5E4FA2'] },
        { id: 'boardroom_navy_gold', name: '董事会蓝金', source: 'PPT 候选 02', colors: ['#1F5AA6', '#C99A3D', '#347C72'] },
        { id: 'night_signal', name: '夜间信号', source: 'PPT 候选 03', colors: ['#FF6B3D', '#7DD8DE', '#B8A2FF'] },
        { id: 'data_neutral', name: '数据中性色', source: 'PPT 候选 04', colors: ['#2F6FED', '#2A9D8F', '#8E63CE'] },
        { id: 'training_warm', name: '培训暖色', source: 'PPT 候选 05', colors: ['#D95C3A', '#E6B566', '#477C71'] },
    ]);

    const sampleRow = (palette, index, label, text) => h('div', {
        class: 'border-l-4 bg-white rounded px-3 py-2',
        style: { borderLeftColor: palette.colors[index] },
    }, [
        h('div', { class: 'text-xs text-gray-500' }, label),
        h('div', { class: 'text-sm font-medium text-gray-800' }, text),
    ]);

    registry.PaletteAcceptanceGallery = {
        name: 'PaletteAcceptanceGallery',
        props: {
            ctx: { type: Object, required: true },
        },
        render() {
            const form = this.ctx?.systemConfigForm || {};
            const selected = String(form.palette_acceptance_candidate || 'suxios_anchor');
            const selectedPalette = palettes.find(palette => palette.id === selected) || null;
            const choose = (candidate) => {
                form.palette_acceptance_candidate = candidate;
            };

            return h('fieldset', {
                class: 'border border-amber-200 bg-amber-50 rounded-xl p-4',
                'data-testid': 'palette-acceptance-gallery',
            }, [
                h('div', { class: 'flex flex-col gap-2 mb-4 md:flex-row md:items-start md:justify-between' }, [
                    h('div', null, [
                        h('div', { class: 'font-semibold text-gray-800' }, '候选配色验收'),
                        h('p', { class: 'text-xs text-gray-500 mt-1' }, '六张卡片使用同一布局、文案与状态色，只替换三个候选色，便于同口径比较。'),
                    ]),
                    h('span', { class: 'self-start px-2 py-1 rounded-full bg-white text-amber-700 border border-amber-200 text-xs font-medium' }, 'CANDIDATE ONLY'),
                ]),
                h('div', {
                    class: 'mb-4 rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs text-amber-800',
                    'data-testid': 'palette-candidate-boundary',
                }, '只保存候选验收记录；不会切换正式主题、修改登录页、部署、发布或触发经营动作。'),
                selectedPalette
                    ? h('div', { class: 'mb-3 text-xs text-gray-500', 'data-testid': 'palette-current-readback' }, [
                        '当前记录：',
                        h('strong', { class: 'text-gray-800' }, `${selectedPalette.name} · ${selectedPalette.id}`),
                        h('span', { class: 'ml-2 text-amber-700' }, '已记录候选，尚未应用'),
                    ])
                    : h('div', { class: 'mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700', 'data-testid': 'palette-current-invalid' }, `未识别候选：${selected}（未应用）`),
                h('div', { class: 'grid grid-cols-1 md:grid-cols-2 gap-3' }, palettes.map(palette => {
                    const checked = selected === palette.id;
                    return h('label', {
                        key: palette.id,
                        class: 'block bg-white border-2 rounded-xl p-3 cursor-pointer transition-shadow',
                        'data-palette-id': palette.id,
                        style: {
                            borderColor: checked ? palette.colors[1] : '#E5E7EB',
                            boxShadow: checked ? `0 0 0 2px ${palette.colors[1]}33` : 'none',
                        },
                    }, [
                        h('input', {
                            type: 'radio',
                            name: 'palette_acceptance_candidate',
                            value: palette.id,
                            checked,
                            class: 'sr-only',
                            onChange: () => choose(palette.id),
                        }),
                        h('div', { class: 'flex items-start justify-between gap-3 mb-3' }, [
                            h('div', null, [
                                h('div', { class: 'font-semibold text-gray-800' }, palette.name),
                                h('div', { class: 'text-xs text-gray-500' }, `${palette.source} · ${palette.id}`),
                            ]),
                            checked ? h('span', { class: 'font-bold text-green-700', 'aria-label': '已选择' }, '✓') : null,
                        ]),
                        h('div', { class: 'flex gap-2 mb-3', 'aria-label': '候选色板' }, palette.colors.map(color => h('span', {
                            key: color,
                            title: color,
                            class: 'h-6 flex-1 rounded border border-gray-200',
                            style: { backgroundColor: color },
                        }))),
                        h('div', { class: 'rounded-lg border border-gray-200 bg-gray-50 p-3 space-y-2' }, [
                            h('div', { class: 'flex items-center justify-between text-xs text-gray-500' }, [
                                h('span', null, 'MOCK · 样式样例，非经营数据'),
                                h('span', null, '同口径'),
                            ]),
                            sampleRow(palette, 0, '信号', '需求出现回升信号'),
                            sampleRow(palette, 1, '判断', '保持策略，等待更多证据'),
                            sampleRow(palette, 2, '再验证', '次日按同口径复核'),
                            h('div', { class: 'flex gap-2 text-xs' }, [
                                h('span', { class: 'px-2 py-1 rounded', style: { color: '#3E7B5E', backgroundColor: '#E4F0EA' } }, '正常'),
                                h('span', { class: 'px-2 py-1 rounded', style: { color: '#A85252', backgroundColor: '#F2DEDE' } }, '异常'),
                                h('span', { class: 'ml-auto text-gray-400' }, '状态语义色固定'),
                            ]),
                        ]),
                    ]);
                })),
                h('p', { class: 'text-xs text-gray-500 mt-3' }, '旧版主题风格和主题色配置继续保留兼容，但不在此伪装成可生效功能；本候选记录与它们互不联动。'),
                h('section', { class: 'mt-4 rounded-xl border border-gray-200 bg-white p-4', 'data-testid': 'legacy-display-settings' }, [
                    h('div', { class: 'font-medium text-gray-800' }, '兼容显示字段'),
                    h('p', { class: 'mt-1 text-xs text-gray-500' }, '保留原有配置写入与回读；当前运行时代码未接入全局主题切换。'),
                    h('div', { class: 'mt-3 grid grid-cols-1 md:grid-cols-2 gap-4' }, [
                        h('label', { class: 'block' }, [
                            h('span', { class: 'block text-sm font-medium text-gray-700 mb-1' }, '主题风格'),
                            h('select', {
                                value: String(form.theme || 'light'),
                                class: 'w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500',
                                onChange: event => { form.theme = event.target.value; },
                            }, [
                                h('option', { value: 'light' }, '浅色主题'),
                                h('option', { value: 'dark' }, '深色主题'),
                                h('option', { value: 'auto' }, '跟随系统'),
                            ]),
                        ]),
                        h('label', { class: 'block' }, [
                            h('span', { class: 'block text-sm font-medium text-gray-700 mb-1' }, '主题色'),
                            h('div', { class: 'flex items-center gap-2' }, [
                                h('input', {
                                    type: 'color',
                                    value: String(form.primary_color || '#3B82F6'),
                                    class: 'w-10 h-10 border rounded cursor-pointer',
                                    onInput: event => { form.primary_color = event.target.value; },
                                }),
                                h('input', {
                                    type: 'text',
                                    value: String(form.primary_color || '#3B82F6'),
                                    class: 'flex-1 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500',
                                    onInput: event => { form.primary_color = event.target.value; },
                                }),
                            ]),
                        ]),
                    ]),
                ]),
            ]);
        },
    };
})();
