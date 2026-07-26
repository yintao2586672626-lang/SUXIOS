(function registerWechatNotificationPanel(global) {
    'use strict';

    const h = global.Vue?.h;
    if (typeof h !== 'function') {
        throw new Error('Vue runtime is required for the enterprise WeChat notification panel.');
    }

    const field = (label, control, help = '') => h('label', { class: 'block' }, [
        h('span', { class: 'mb-2 block text-sm font-medium text-slate-700' }, label),
        control,
        help ? h('span', { class: 'mt-2 block text-xs leading-5 text-slate-500' }, help) : null,
    ]);
    const detail = (label, value, testid = '') => h('div', {}, [
        h('dt', { class: 'text-slate-500' }, label),
        h('dd', {
            class: 'mt-1 break-all font-medium text-slate-900',
            ...(testid ? { 'data-testid': testid } : {}),
        }, value),
    ]);

    global.SUXI_WECHAT_NOTIFICATION_PANEL = {
        name: 'WechatNotificationPanel',
        props: {
            hotels: { type: Array, default: () => [] },
            hotelId: { type: [String, Number], default: '' },
            state: { type: Object, default: () => ({}) },
            form: { type: Object, default: () => ({ name: '', webhook: '' }) },
            loading: Boolean,
            saving: Boolean,
            testing: Boolean,
            error: { type: String, default: '' },
            statusText: { type: String, default: '尚未绑定' },
            statusClass: { type: String, default: '' },
            lastTestText: { type: String, default: '尚未发送测试消息' },
        },
        emits: ['hotel-change', 'update-name', 'update-webhook', 'save', 'test'],
        setup(props, { emit }) {
            return () => {
                const binding = props.state?.binding || null;
                const selectedHotel = props.hotels.find(
                    hotel => String(hotel?.id) === String(props.hotelId)
                ) || null;
                const busy = props.loading || props.saving || props.testing;
                const inputClass = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100';

                return h('div', {
                    class: 'mx-auto max-w-5xl space-y-5',
                    'data-testid': 'wechat-notification-panel',
                }, [
                    h('section', { class: 'rounded-2xl border border-slate-200 bg-white p-6 shadow-sm' }, [
                        h('div', { class: 'flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between' }, [
                            h('div', {}, [
                                h('div', { class: 'text-sm font-medium text-emerald-700' }, '当前账户 · 当前门店'),
                                h('h2', { class: 'mt-1 text-2xl font-bold text-slate-900' }, '企业微信通知'),
                                h('p', { class: 'mt-2 max-w-2xl text-sm leading-6 text-slate-500' },
                                    '绑定只对当前账户和所选门店生效，不会修改管理员已有机器人。'),
                            ]),
                            h('span', {
                                class: `inline-flex items-center rounded-full border px-3 py-1.5 text-sm font-medium ${props.statusClass}`,
                                'data-testid': 'wechat-notification-status',
                            }, props.statusText),
                        ]),
                    ]),
                    h('section', { class: 'grid gap-5 lg:grid-cols-2' }, [
                        h('form', {
                            class: 'rounded-2xl border border-slate-200 bg-white p-6 shadow-sm',
                            'data-testid': 'wechat-notification-form',
                            onSubmit: (event) => {
                                event.preventDefault();
                                emit('save');
                            },
                        }, [
                            h('div', { class: 'space-y-5' }, [
                                field('绑定门店', h('select', {
                                    value: String(props.hotelId || ''),
                                    class: inputClass,
                                    disabled: busy,
                                    'data-testid': 'wechat-notification-hotel',
                                    onChange: event => emit('hotel-change', event.target.value),
                                }, [
                                    h('option', { value: '', disabled: true }, '请选择有权限的门店'),
                                    ...props.hotels.map(hotel => h('option', {
                                        key: hotel.id,
                                        value: String(hotel.id),
                                    }, hotel.name || `门店 ${hotel.id}`)),
                                ])),
                                field('通知群名称', h('input', {
                                    value: props.form?.name || '',
                                    type: 'text',
                                    maxlength: 120,
                                    class: inputClass,
                                    placeholder: '例如：西安店经营通知群',
                                    'data-testid': 'wechat-notification-name',
                                    onInput: event => emit('update-name', event.target.value),
                                })),
                                field('企业微信群机器人 Webhook', h('input', {
                                    value: props.form?.webhook || '',
                                    type: 'password',
                                    autocomplete: 'new-password',
                                    spellcheck: 'false',
                                    class: `${inputClass} font-mono`,
                                    placeholder: 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...',
                                    'data-testid': 'wechat-notification-webhook',
                                    onInput: event => emit('update-webhook', event.target.value),
                                }), '系统加密保存完整地址；保存或切换门店后输入框立即清空，页面只显示掩码。'),
                                props.error ? h('div', {
                                    class: 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700',
                                    role: 'alert',
                                    'data-testid': 'wechat-notification-error',
                                }, props.error) : null,
                                h('div', { class: 'flex flex-col gap-3 sm:flex-row' }, [
                                    h('button', {
                                        type: 'submit',
                                        disabled: props.saving || props.loading || !props.hotelId,
                                        class: 'rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60',
                                        'data-testid': 'wechat-notification-save',
                                    }, props.saving ? '安全保存中...' : (binding ? '更新绑定' : '保存绑定')),
                                    h('button', {
                                        type: 'button',
                                        disabled: props.testing || !binding,
                                        class: 'rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-800 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60',
                                        'data-testid': 'wechat-notification-test',
                                        onClick: () => emit('test'),
                                    }, props.testing ? '发送中...' : '发送测试消息'),
                                ]),
                            ]),
                        ]),
                        h('aside', { class: 'rounded-2xl border border-slate-200 bg-slate-50 p-6' }, [
                            h('h3', { class: 'text-base font-semibold text-slate-900' }, '当前绑定状态'),
                            h('dl', { class: 'mt-4 space-y-4 text-sm' }, [
                                detail('门店', selectedHotel?.name || '未选择'),
                                detail('通知群', binding?.name || '尚未绑定'),
                                detail('Webhook', binding?.webhook_masked || '未保存', 'wechat-notification-mask'),
                                detail('最后测试', props.lastTestText, 'wechat-notification-last-test'),
                            ]),
                            h('div', { class: 'mt-5 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-xs leading-5 text-blue-800' },
                                '切换门店后会重新读取该门店下当前账户自己的绑定，其他账户和门店的配置不会显示。'),
                        ]),
                    ]),
                ]);
            };
        },
    };
})(window);
