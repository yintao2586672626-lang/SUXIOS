(() => {
    const components = window.SUXI_ONLINE_DATA_COMPONENTS || (window.SUXI_ONLINE_DATA_COMPONENTS = {});

    components.CompetitorDeviceManagementBody = {
        name: 'CompetitorDeviceManagementBody',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        render() {
            const h = Vue.h;
            const c = this.ctx;
            const devices = Array.isArray(c.competitorDevices) ? c.competitorDevices : [];
            const platforms = Array.isArray(c.competitorDevicePlatforms) ? c.competitorDevicePlatforms : [];
            const stores = Array.isArray(c.competitorStores) ? c.competitorStores : [];
            const users = typeof c.competitorDeviceEligibleUsers === 'function' ? c.competitorDeviceEligibleUsers() : [];
            const activeCount = devices.filter(item => Number(item.status) === 1).length;
            const pagination = c.competitorDevicePagination || { total: devices.length, page: 1, total_page: 1 };

            const actionButton = (label, onClick, className, disabled = false) => h('button', {
                type: 'button',
                onClick,
                disabled,
                class: className,
            }, label);

            const errorState = c.competitorDevicesError ? h('div', {
                class: 'mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700',
                'data-testid': 'competitor-device-error',
            }, [
                h('div', { class: 'font-medium' }, c.competitorDevicesError),
                c.competitorDevicesStale ? h('div', { class: 'mt-1 text-xs' }, '当前显示上次成功结果，不能视为最新状态。') : null,
                actionButton('重新加载', c.loadCompetitorDeviceWorkbench, 'mt-2 underline'),
            ]) : null;

            const refreshingState = c.competitorDevicesLoading && devices.length ? h('div', {
                class: 'mb-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-xs text-blue-700',
                'data-testid': 'competitor-device-refreshing',
            }, '正在刷新，当前显示上次成功结果。') : null;

            const listState = c.competitorDevicesError && !devices.length && !c.competitorDevicesLoading
                ? null
                : (c.competitorDevicesLoading && !devices.length
                ? h('div', {
                    class: 'py-10 text-center text-sm text-gray-500',
                    'data-testid': 'competitor-device-loading',
                }, '正在读取设备绑定')
                : (!devices.length
                    ? h('div', {
                        class: 'rounded-lg border border-amber-200 bg-amber-50 px-4 py-5 text-sm text-amber-800',
                        'data-testid': 'competitor-device-empty',
                    }, [
                        h('div', { class: 'font-medium' }, 'binding_missing：暂无竞对采集设备绑定'),
                        h('p', { class: 'mt-1' }, '任务领取与结果上报接口会保持拒绝。请创建绑定并以最近握手时间验收，不能仅凭“已创建”判断采集可用。'),
                    ])
                    : h('div', { class: 'overflow-x-auto', 'data-testid': 'competitor-device-list' }, [
                        h('table', { class: 'min-w-full text-sm' }, [
                            h('thead', { class: 'bg-gray-50 text-gray-500' }, [h('tr', null,
                                ['设备 / 平台', '门店 / 员工', 'Token', '连接状态', '操作'].map((label, index) => h('th', {
                                    class: `px-3 py-2 ${index === 4 ? 'text-right' : 'text-left'} font-medium`,
                                }, label))
                            )]),
                            h('tbody', { class: 'divide-y' }, devices.map(item => {
                                const enabled = Number(item.status) === 1;
                                const online = enabled && item.is_online === true;
                                const busy = Number(c.competitorDeviceActionId) === Number(item.id);
                                const canEnable = enabled || (Boolean(String(item.token_hint || '').trim()) && !item.revoked_at);
                                return h('tr', { key: item.id }, [
                                    h('td', { class: 'px-3 py-3 align-top' }, [
                                        h('div', { class: 'font-medium text-gray-800' }, item.name || item.device_id),
                                        h('div', { class: 'mt-1 font-mono text-xs text-gray-500' }, item.device_id),
                                        h('div', { class: 'mt-1 text-xs text-gray-500' }, c.competitorDevicePlatformLabel(item.platform)),
                                    ]),
                                    h('td', { class: 'px-3 py-3 align-top' }, [
                                        h('div', null, c.getCompetitorStoreName(item.store_id)),
                                        h('div', { class: 'mt-1 text-xs text-gray-500' }, c.competitorDeviceUserLabel(item.user_id)),
                                    ]),
                                    h('td', { class: 'px-3 py-3 align-top' }, [
                                        h('div', { class: 'font-mono text-xs' }, item.token_hint || '未配置'),
                                        h('div', { class: 'mt-1 text-xs text-gray-500' }, `版本 ${item.token_version || 0}`),
                                    ]),
                                    h('td', { class: 'px-3 py-3 align-top' }, [h('span', {
                                        class: `inline-flex rounded-full px-2 py-1 text-xs ${online ? 'bg-emerald-100 text-emerald-700' : (enabled ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600')}`,
                                    }, c.competitorDeviceLastSeenText(item))]),
                                    h('td', { class: 'whitespace-nowrap px-3 py-3 text-right align-top' }, [
                                        actionButton('轮换 Token', () => c.rotateCompetitorDeviceToken(item), 'rounded px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 disabled:opacity-50', busy),
                                        actionButton('重新绑定', () => c.openCompetitorDeviceModal(item), 'ml-1 rounded px-2 py-1 text-xs text-indigo-700 hover:bg-indigo-50 disabled:opacity-50', busy),
                                        actionButton(enabled ? '停用' : (canEnable ? '启用' : '待轮换后启用'), () => c.updateCompetitorDeviceStatus(item), `ml-1 rounded px-2 py-1 text-xs disabled:opacity-50 ${enabled ? 'text-red-700 hover:bg-red-50' : 'text-emerald-700 hover:bg-emerald-50'}`, busy || (!enabled && !canEnable)),
                                    ]),
                                ]);
                            })),
                        ]),
                    ])));

            const paginationState = devices.length && Number(pagination.total_page || 1) > 1 ? h('div', {
                class: 'mt-4 flex items-center justify-between border-t pt-3 text-xs text-gray-500',
                'data-testid': 'competitor-device-pagination',
            }, [
                h('span', null, `第 ${pagination.page} / ${pagination.total_page} 页 · 共 ${pagination.total} 条`),
                h('div', { class: 'flex gap-2' }, [
                    actionButton('上一页', () => c.changeCompetitorDevicePage(Number(pagination.page) - 1), 'rounded border px-2 py-1 disabled:opacity-50', c.competitorDevicesLoading || Number(pagination.page) <= 1),
                    actionButton('下一页', () => c.changeCompetitorDevicePage(Number(pagination.page) + 1), 'rounded border px-2 py-1 disabled:opacity-50', c.competitorDevicesLoading || Number(pagination.page) >= Number(pagination.total_page)),
                ]),
            ]) : null;

            const panel = h('section', {
                class: 'mt-6 rounded-lg bg-white shadow',
                'data-testid': 'competitor-device-management',
            }, [
                h('div', { class: 'flex flex-col gap-3 border-b p-4 md:flex-row md:items-center md:justify-between' }, [
                    h('div', null, [
                        h('div', { class: 'flex items-center gap-2' }, [
                            h('h3', { class: 'font-medium' }, '竞对采集设备'),
                            h('span', { class: 'rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600' }, `本页有效 ${activeCount} 条 · 共 ${pagination.total} 条`),
                        ]),
                        h('p', { class: 'mt-1 text-sm text-gray-500' }, '只有成功握手后才视为采集可用。'),
                    ]),
                    h('div', { class: 'flex gap-2' }, [
                        actionButton(c.competitorDevicesLoading ? '刷新中' : '刷新', c.loadCompetitorDeviceWorkbench, 'rounded-lg border px-3 py-2 text-sm disabled:opacity-50', c.competitorDevicesLoading),
                        actionButton('新建设备绑定', c.openCompetitorDeviceModal, 'rounded-lg bg-blue-600 px-3 py-2 text-sm text-white'),
                    ]),
                ]),
                h('div', { class: 'p-4' }, [errorState, refreshingState, listState, paginationState]),
            ]);

            const createModal = c.showCompetitorDeviceModal ? h('div', {
                class: 'fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40 p-4',
                'data-testid': 'competitor-device-create-modal',
            }, [h('form', {
                class: 'w-full max-w-2xl rounded-xl bg-white shadow-xl',
                onSubmit: (event) => {
                    event.preventDefault();
                    c.saveCompetitorDevice();
                },
            }, [
                h('div', { class: 'flex items-center justify-between border-b p-5' }, [
                    h('div', null, [
                        h('h3', { class: 'font-semibold text-gray-800' }, c.competitorDeviceEditingId ? '重新绑定竞对采集设备' : '新建竞对采集设备绑定'),
                        h('p', { class: 'mt-1 text-xs text-gray-500' }, 'Token 只显示一次，请立即复制。'),
                    ]),
                    actionButton('关闭', c.closeCompetitorDeviceModal, 'text-sm text-gray-500'),
                ]),
                h('div', { class: 'grid grid-cols-1 gap-4 p-5 md:grid-cols-2' }, [
                    h('label', { class: 'text-sm text-gray-700' }, ['设备标识', h('input', {
                        value: c.competitorDeviceForm.device_id,
                        disabled: Boolean(c.competitorDeviceEditingId),
                        required: true,
                        minLength: 3,
                        maxLength: 120,
                        pattern: '[A-Za-z0-9._:-]{3,120}',
                        placeholder: 'cq-frontdesk-01',
                        class: 'mt-1 w-full rounded-lg border px-3 py-2 font-mono',
                        onInput: event => { c.competitorDeviceForm.device_id = event.target.value; },
                    })]),
                    h('label', { class: 'text-sm text-gray-700' }, ['设备名称', h('input', {
                        value: c.competitorDeviceForm.name,
                        maxLength: 120,
                        class: 'mt-1 w-full rounded-lg border px-3 py-2',
                        onInput: event => { c.competitorDeviceForm.name = event.target.value; },
                    })]),
                    h('label', { class: 'text-sm text-gray-700' }, ['平台', h('select', {
                        value: c.competitorDeviceForm.platform,
                        required: true,
                        class: 'mt-1 w-full rounded-lg border px-3 py-2',
                        onChange: event => { c.competitorDeviceForm.platform = event.target.value; },
                    }, [h('option', { value: '', disabled: true }, '请选择平台'), ...platforms.map(item => h('option', { value: item.value, key: item.value }, item.label))])]),
                    h('label', { class: 'text-sm text-gray-700' }, ['门店', h('select', {
                        value: c.competitorDeviceForm.store_id,
                        required: true,
                        class: 'mt-1 w-full rounded-lg border px-3 py-2',
                        onChange: event => { c.setCompetitorDeviceStoreId(event.target.value); },
                    }, [h('option', { value: '', disabled: true }, '请选择门店'), ...stores.map(item => h('option', { value: item.id, key: item.id }, item.name))])]),
                    h('label', { class: 'text-sm text-gray-700 md:col-span-2' }, ['绑定员工', h('select', {
                        value: c.competitorDeviceForm.user_id,
                        required: true,
                        class: 'mt-1 w-full rounded-lg border px-3 py-2',
                        onChange: event => { c.competitorDeviceForm.user_id = event.target.value; },
                    }, [h('option', { value: '', disabled: true }, '请选择启用中的员工'), ...users.map(item => h('option', { value: item.id, key: item.id }, c.competitorDeviceUserLabel(item.id)))])]),
                ]),
                h('div', { class: 'flex justify-end gap-2 border-t p-5' }, [
                    actionButton('取消', c.closeCompetitorDeviceModal, 'rounded-lg border px-4 py-2 disabled:opacity-50', c.competitorDeviceSaving),
                    h('button', { type: 'submit', disabled: c.competitorDeviceSaving, class: 'rounded-lg bg-blue-600 px-4 py-2 text-white disabled:opacity-50' }, c.competitorDeviceSaving ? '正在保存' : (c.competitorDeviceEditingId ? '重新绑定并生成 Token' : '创建并生成 Token')),
                ]),
            ])]) : null;

            const credential = c.competitorDeviceCredential;
            const credentialModal = credential ? h('div', {
                class: 'fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4',
                'data-testid': 'competitor-device-credential-modal',
            }, [h('div', { class: 'w-full max-w-2xl rounded-xl bg-white shadow-xl' }, [
                h('div', { class: 'border-b p-5' }, [
                    h('h3', { class: 'font-semibold text-gray-800' }, '一次性设备 Token'),
                    h('p', { class: 'mt-1 text-sm text-red-600' }, '关闭后不再显示；轮换后旧 Token 立即失效。'),
                ]),
                h('div', { class: 'space-y-4 p-5' }, [
                    h('div', { class: 'rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800' }, '任务领取使用 X-Task-Token，结果上报使用 X-Report-Token，填写同一个值。'),
                    h('div', { class: 'text-xs text-gray-500' }, `设备 ${credential.device_id} · Token 版本 ${credential.token_version}`),
                    h('div', { class: 'text-xs text-gray-600' }, `平台 ${c.competitorDevicePlatformLabel(credential.platform)} · 门店 ${c.getCompetitorStoreName(credential.store_id)} · 员工 ${c.competitorDeviceUserLabel(credential.user_id)} · ${Number(credential.status) === 1 ? '已启用' : '待启用'}`),
                    h('div', { class: 'flex gap-2' }, [
                        h('input', {
                            value: credential.device_token,
                            readOnly: true,
                            class: 'min-w-0 flex-1 rounded-lg border px-3 py-2 font-mono text-sm',
                            'data-testid': 'competitor-device-one-time-token',
                            onFocus: event => event.target.select(),
                        }),
                        actionButton(c.competitorDeviceTokenCopied ? '已复制' : '复制', c.copyCompetitorDeviceToken, 'rounded-lg bg-blue-600 px-4 py-2 text-white'),
                    ]),
                ]),
                h('div', { class: 'flex justify-end border-t p-5' }, [actionButton('我已保存，关闭', c.clearCompetitorDeviceCredential, 'rounded-lg bg-gray-800 px-4 py-2 text-white')]),
            ])]) : null;

            return h(Vue.Fragment, null, [panel, createModal, credentialModal]);
        },
    };
})();
