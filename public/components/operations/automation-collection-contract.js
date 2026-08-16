(function registerAutomationCollectionContract(global) {
    'use strict';

    const h = global.Vue?.h;
    if (typeof h !== 'function') {
        throw new Error('Vue runtime is required for the hotel collection contract panel.');
    }

    const components = global.SUXI_ONLINE_DATA_COMPONENTS
        || (global.SUXI_ONLINE_DATA_COMPONENTS = {});
    const text = (value, fallback = '未取得') => {
        const normalized = String(value ?? '').trim();
        return normalized || fallback;
    };
    const detail = (label, value, options = {}) => h('div', {
        class: 'flex justify-between gap-3',
    }, [
        h('dt', { class: 'text-slate-400' }, label),
        h('dd', {
            class: `${options.mono ? 'font-mono ' : ''}max-w-[155px] truncate text-slate-700`,
            title: text(value, ''),
            ...(options.testid ? { 'data-testid': options.testid } : {}),
        }, text(value, options.fallback || '未取得')),
    ]);
    const pill = (ctx, status) => h('span', {
        class: `rounded-full border px-2 py-0.5 text-[11px] font-medium ${ctx.automationMonitorContractStatusClass(status)}`,
    }, ctx.automationMonitorContractStatusText(status));
    const sourceOptions = (ctx, platform) => [
        h('option', { value: '' }, '请选择'),
        ...ctx.automationMonitorContractSourceOptions(platform).map(sourceId => h('option', {
            value: String(sourceId),
            key: `${platform}-${sourceId}`,
        }, `数据源 #${sourceId}`)),
    ];
    const fieldLabel = (label, control) => h('label', {}, [
        h('span', { class: 'mb-1 block text-xs text-slate-500' }, label),
        control,
    ]);
    const inputClass = 'w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800';

    const scheduledLoopInventoryPanel = (ctx) => {
        const catalog = ctx.automationMonitor?.scheduled_loops || null;
        const items = Array.isArray(catalog?.items) ? catalog.items : [];
        const applicationLoops = Array.isArray(catalog?.application_loops)
            ? catalog.application_loops
            : [];
        const summary = catalog?.summary || {};
        const catalogTone = catalog?.status === 'ready'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
            : catalog?.status === 'partial'
                ? 'border-amber-200 bg-amber-50 text-amber-700'
                : 'border-slate-200 bg-slate-50 text-slate-600';
        const catalogLabel = catalog?.status === 'ready'
            ? '当前回读'
            : catalog?.status === 'partial' ? '部分回读' : '未验证';
        const taskStatusClass = (status) => status === 'running'
            ? 'border-blue-200 bg-blue-50 text-blue-700'
            : status === 'enabled'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-slate-200 bg-slate-100 text-slate-600';
        const resultClass = (status) => status === 'scheduler_completed'
            ? 'border-blue-200 bg-blue-50 text-blue-700'
            : status === 'nonzero'
                ? 'border-amber-200 bg-amber-50 text-amber-700'
                : 'border-slate-200 bg-slate-50 text-slate-500';
        const badge = (label, value, className) => h('span', {
            class: `rounded-full border px-2.5 py-1 text-xs ${className}`,
        }, `${label} ${value ?? 0}`);
        const timestamp = (value) => text(value, '未取得');

        return h('section', {
            class: 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm',
            'data-testid': 'automation-periodic-task-list',
        }, [
            h('header', {
                class: 'flex flex-col gap-3 border-b border-slate-200 bg-[#f7f9f8] px-4 py-4 lg:flex-row lg:items-start lg:justify-between',
            }, [
                h('div', {}, [
                    h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                        h('h3', { class: 'font-semibold text-slate-900' }, '周期任务清单'),
                        h('span', {
                            class: `rounded-full border px-2 py-0.5 text-[11px] font-medium ${catalogTone}`,
                        }, catalogLabel),
                    ]),
                    h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' },
                        '所有明确按日、按周或固定间隔触发的宿析宿主机任务均在这里列出；登录/开机启动项与短期动画不计入。'),
                    h('p', { class: 'mt-1 text-[11px] leading-5 text-slate-400' },
                        `最近核验：${timestamp(catalog?.observed_at)} · 已启用不等于已执行，退出码 0 也不代表采集、推送或经营结果成功。`),
                ]),
                h('div', { class: 'flex flex-wrap gap-1.5' }, [
                    badge('共', summary.total_count, 'border-slate-200 bg-white text-slate-600'),
                    badge('启用', summary.enabled_count, 'border-emerald-200 bg-emerald-50 text-emerald-700'),
                    badge('暂停', summary.disabled_count, 'border-slate-200 bg-slate-100 text-slate-600'),
                    badge('非零结果', summary.nonzero_result_count, 'border-amber-200 bg-amber-50 text-amber-700'),
                ]),
            ]),
            catalog && !['ready', 'partial'].includes(catalog.status) ? h('p', {
                class: 'm-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800',
            }, `${catalog.message || '周期任务当前无法读取；不会用默认任务或旧状态冒充。'} ${catalog.reason_code || ''}`.trim()) : null,
            items.length ? h('div', { class: 'overflow-x-auto' }, [
                h('table', {
                    class: 'min-w-[1420px] w-full table-fixed text-left text-sm',
                    'data-testid': 'automation-periodic-task-table',
                }, [
                    h('thead', { class: 'border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600' }, [
                        h('tr', {}, ['任务名称', '用途', '来源', '频率', '状态', '上次运行', '下次运行', '最近结果'].map(label => (
                            h('th', { class: 'px-3 py-3' }, label)
                        ))),
                    ]),
                    h('tbody', { class: 'divide-y divide-slate-100' }, items.map(item => h('tr', {
                        key: item.key,
                        class: 'align-top hover:bg-slate-50/80',
                    }, [
                        h('td', { class: 'px-4 py-3' }, [
                            h('p', { class: 'font-medium text-slate-900' }, text(item.name)),
                            h('p', { class: 'mt-1 text-[11px] text-slate-400' }, text(item.scope_label)),
                            item.risk_note ? h('p', { class: 'mt-1 text-[11px] leading-4 text-red-600' }, item.risk_note) : null,
                        ]),
                        h('td', { class: 'px-3 py-3 text-xs leading-5 text-slate-600' }, text(item.purpose)),
                        h('td', { class: 'px-3 py-3 text-xs text-slate-600' }, text(item.source_label)),
                        h('td', { class: 'px-3 py-3 text-xs leading-5 text-slate-600' }, text(item.frequency_label)),
                        h('td', { class: 'px-3 py-3' }, [h('span', {
                            class: `inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${taskStatusClass(item.status)}`,
                        }, text(item.status_label))]),
                        h('td', { class: 'px-3 py-3 text-xs tabular-nums text-slate-600' }, timestamp(item.last_run_at)),
                        h('td', { class: 'px-3 py-3 text-xs tabular-nums text-slate-600' }, [
                            timestamp(item.next_run_at),
                            item.next_run_is_theoretical ? h('span', {
                                class: 'mt-1 block text-[11px] text-slate-400',
                            }, '已暂停，仅为理论时间') : null,
                        ]),
                        h('td', { class: 'px-3 py-3' }, [h('span', {
                            class: `inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${resultClass(item.last_result_status)}`,
                        }, text(item.last_result_summary, '结果未取得'))]),
                    ]))),
                ]),
            ]) : catalog ? h('p', { class: 'px-5 py-8 text-center text-sm text-slate-500' },
                '当前权限范围内没有已回读的周期任务。') : null,
            applicationLoops.length ? h('div', {
                class: 'border-t border-slate-200 bg-[#fbfcfb] px-4 py-3',
            }, [
                h('h4', { class: 'text-xs font-semibold text-slate-700' }, '页面与后端的条件式读取'),
                h('div', { class: 'mt-2 grid gap-2 lg:grid-cols-3' }, applicationLoops.map(item => h('article', {
                    key: item.key,
                    class: 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs',
                }, [
                    h('div', { class: 'flex items-start justify-between gap-2' }, [
                        h('strong', { class: 'text-slate-700' }, text(item.name)),
                        h('span', { class: 'rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500' }, text(item.status_label)),
                    ]),
                    h('p', { class: 'mt-1 leading-5 text-slate-500' }, text(item.purpose)),
                    h('p', { class: 'mt-1 font-medium text-[#315d50]' }, `${text(item.frequency_label)} · 不创建 CMD 窗口`),
                ]))),
            ]) : null,
        ]);
    };

    const systemCard = (ctx, binding) => h('article', {
        class: 'rounded-xl border border-slate-200 bg-slate-50/70 p-3',
        'data-testid': 'automation-contract-system-hotel',
    }, [
        h('div', { class: 'flex items-center justify-between gap-2' }, [
            h('h4', { class: 'text-sm font-semibold text-slate-900' }, '系统酒店'),
            pill(ctx, binding.status),
        ]),
        h('p', { class: 'mt-3 text-sm font-medium text-slate-800' },
            text(binding.system_hotel?.hotel_name, '名称未取得')),
        h('p', { class: 'mt-1 text-xs text-slate-500' },
            `酒店 #${text(binding.system_hotel?.system_hotel_id, ctx.automationMonitorContractHotelId)} · 租户 #${text(binding.system_hotel?.tenant_id)}`),
        h('p', { class: 'mt-2 text-[11px] leading-5 text-slate-500' },
            '绑定摘要只识别漂移；敏感登录态不进入服务端计划。'),
    ]);

    const otaCard = (ctx, binding, plan, platform) => {
        const item = binding.bindings?.[platform] || {};
        const source = plan.sources?.[platform] || {};
        const label = platform === 'ctrip' ? '携程门店' : '美团门店';
        return h('article', {
            class: 'rounded-xl border border-slate-200 bg-white p-3',
            'data-testid': `automation-contract-${platform}`,
        }, [
            h('div', { class: 'flex items-center justify-between gap-2' }, [
                h('h4', { class: 'text-sm font-semibold text-slate-900' }, label),
                pill(ctx, item.status),
            ]),
            h('dl', { class: 'mt-3 space-y-1.5 text-xs' }, [
                detail('计划数据源', source.data_source_id ? `#${source.data_source_id}` : '未选择', { mono: true }),
                detail('平台门店 ID', item.platform_hotel_id, { mono: true, fallback: '缺失' }),
                detail('Profile 归属', item.profile_binding?.status),
                detail('原设备映射', item.execution_device_binding?.status),
            ]),
        ]);
    };

    const bindingOnboardingPanel = (ctx, binding) => {
        if (Number(ctx.automationMonitorContractHotelId || 0) !== 80) return null;
        const onboarding = ctx.automationMonitorContractOnboarding || {};
        const reasons = Array.isArray(onboarding.reason_codes) ? onboarding.reason_codes : [];
        const busy = String(ctx.automationMonitorBindingActionBusy || '');
        const confirmed = ctx.automationMonitorBindingConfirmation === true;
        const action = (name) => onboarding.actions?.[name] || {};
        const platformCard = (platform, expectedSourceId) => {
            const item = binding.bindings?.[platform] || {};
            const evidence = item.identity_evidence || {};
            const label = platform === 'ctrip' ? '\u643a\u7a0b' : '\u7f8e\u56e2';
            return h('article', {
                class: 'rounded-xl border border-slate-200 bg-white p-3',
                'data-testid': `automation-binding-scope-${platform}`,
            }, [
                h('div', { class: 'flex items-center justify-between gap-2' }, [
                    h('h5', { class: 'text-sm font-semibold text-slate-900' }, label),
                    pill(ctx, evidence.status || 'unverified'),
                ]),
                h('dl', { class: 'mt-3 space-y-1.5 text-xs' }, [
                    detail('\u56fa\u5b9a\u6570\u636e\u6e90', `#${expectedSourceId}`, {
                        mono: true,
                        testid: `automation-binding-source-${platform}`,
                    }),
                    detail('\u5b9e\u9645\u7ed1\u5b9a\u6e90', item.source_id ? `#${item.source_id}` : '', {
                        mono: true,
                        fallback: '\u7f3a\u5931',
                        testid: `automation-binding-actual-source-${platform}`,
                    }),
                    detail('\u6b63\u5f0f\u5e73\u53f0\u95e8\u5e97 ID', item.platform_hotel_id, {
                        mono: true,
                        fallback: '\u7f3a\u5931',
                        testid: `automation-binding-canonical-${platform}`,
                    }),
                    detail('\u5386\u53f2\u5019\u9009', item.legacy_platform_hotel_id_candidate, {
                        mono: true,
                        fallback: '\u65e0',
                        testid: `automation-binding-legacy-candidate-${platform}`,
                    }),
                    detail('\u8eab\u4efd\u51ed\u636e', evidence.status, {
                        testid: `automation-binding-identity-status-${platform}`,
                    }),
                    detail('\u51ed\u636e\u6765\u6e90', evidence.source, { mono: true }),
                    detail('\u6838\u9a8c\u65f6\u95f4', evidence.checked_at, { mono: true }),
                    detail('\u6267\u884c\u8bbe\u5907', item.execution_device_binding?.status, {
                        testid: `automation-binding-device-${platform}`,
                    }),
                ]),
            ]);
        };
        const actionButton = (name, label, testid) => {
            const item = action(name);
            const isBusy = busy === name;
            return h('button', {
                type: 'button',
                disabled: item.allowed !== true || !confirmed || busy !== '',
                class: 'rounded-lg bg-[#315d50] px-3 py-2 text-sm font-medium text-white disabled:bg-slate-200 disabled:text-slate-400',
                'data-testid': testid,
                onClick: () => ctx.confirmAutomationMonitorOtaBinding(name),
            }, isBusy ? '\u4fdd\u5b58\u5e76\u56de\u8bfb\u4e2d...' : label);
        };

        return h('section', {
            class: 'rounded-xl border border-[#d8c49f] bg-[#fffaf0] p-4',
            'data-testid': 'automation-binding-onboarding-panel',
        }, [
            h('div', { class: 'flex flex-wrap items-start justify-between gap-3' }, [
                h('div', {}, [
                    h('h4', { class: 'text-sm font-semibold text-slate-900' }, '\u9152\u5e97 80 \u00b7 \u56fa\u5b9a OTA \u7ed1\u5b9a\u6062\u590d'),
                    h('p', { class: 'mt-1 text-xs leading-5 text-slate-600' },
                        '\u53ea\u5904\u7406\u643a\u7a0b source #25 \u4e0e\u7f8e\u56e2 source #68\uff1b\u4ec5\u4fdd\u5b58\u7ed1\u5b9a\u5e76\u7cbe\u786e\u56de\u8bfb\uff0c\u4e0d\u542f\u52a8 OTA \u91c7\u96c6\u3001\u4e0d\u521b\u5efa collector task\u3001\u4e0d\u4fee\u6539 Windows \u8ba1\u5212\u4efb\u52a1\u3002'),
                ]),
                pill(ctx, onboarding.status || 'unverified'),
            ]),
            h('div', { class: 'mt-3 grid gap-3 md:grid-cols-2' }, [
                platformCard('ctrip', 25),
                platformCard('meituan', 68),
            ]),
            h('p', {
                class: 'mt-3 rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs leading-5 text-amber-800',
                'data-testid': 'automation-binding-legacy-warning',
            }, '\u5386\u53f2\u5019\u9009 ID \u4ec5\u4f9b\u4eba\u5de5\u6838\u5bf9\uff0c\u9875\u9762\u4e0d\u4f1a\u81ea\u52a8\u586b\u5165\u6216\u65e0\u8bc1\u636e\u63d0\u5347\u3002\u53ea\u6709\u540c\u9152\u5e97\u3001\u540c source\u3001\u540c Profile\u3001\u5f53\u5929\u5f3a\u4f1a\u8bdd\u8bc1\u636e\u9f50\u5168\u65f6\u624d\u5141\u8bb8\u786e\u8ba4\u3002'),
            reasons.length ? h('ul', {
                class: 'mt-3 space-y-1 text-xs text-red-700',
                'data-testid': 'automation-binding-reason-codes',
            }, reasons.map((reason, index) => h('li', {
                key: `${reason.platform || 'hotel'}-${reason.code || index}`,
                class: 'font-mono',
            }, `${reason.platform ? `${reason.platform}:` : ''}${reason.code || 'unknown'}`))) : null,
            h('label', {
                class: 'mt-3 flex items-start gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs leading-5 text-slate-700',
                'data-testid': 'automation-binding-explicit-confirm',
            }, [
                h('input', {
                    type: 'checkbox',
                    checked: confirmed,
                    disabled: busy !== '' || onboarding.action_required == null,
                    class: 'mt-1',
                    onChange: event => { ctx.automationMonitorBindingConfirmation = event.target.checked; },
                }),
                h('span', {}, '\u6211\u786e\u8ba4\u5f53\u524d\u662f\u7cfb\u7edf\u9152\u5e97 #80\uff0c\u4e14\u4e86\u89e3\u672c\u6b21\u53ea\u4fdd\u5b58\u5df2\u6709\u5f3a\u8bc1\u636e\u652f\u6301\u7684\u95e8\u5e97/\u8c03\u5ea6\u7ed1\u5b9a\uff0c\u4e0d\u53d1\u8d77 OTA \u91c7\u96c6\u3002'),
            ]),
            h('div', { class: 'mt-3 flex flex-wrap items-center gap-2' }, [
                actionButton(
                    'claim_meituan_identity',
                    '\u786e\u8ba4\u7f8e\u56e2\u6b63\u5f0f\u95e8\u5e97\u8eab\u4efd',
                    'automation-binding-action-claim'
                ),
                actionButton(
                    'bind_local_profile_scheduler',
                    '\u7ed1\u5b9a\u672c\u673a\u8c03\u5ea6\u5e76\u56de\u8bfb',
                    'automation-binding-action-bind'
                ),
                h('span', {
                    class: `rounded-full border px-2 py-1 text-[11px] ${ctx.automationMonitorContractStatusClass(
                        onboarding.binding_readback_status === 'readback_verified' ? 'verified' : 'unverified'
                    )}`,
                    'data-testid': 'automation-binding-readback-status',
                }, onboarding.binding_readback_status === 'readback_verified'
                    ? '\u9875\u9762\u7cbe\u786e\u56de\u8bfb\u5df2\u8fde\u63a5'
                    : '\u9875\u9762\u7cbe\u786e\u56de\u8bfb\u672a\u9a8c\u8bc1'),
            ]),
        ]);
    };

    const pmsCard = (ctx, binding) => {
        const pms = binding.bindings?.pms || {};
        return h('article', {
            class: 'rounded-xl border border-slate-200 bg-white p-3',
            'data-testid': 'automation-contract-pms',
        }, [
            h('div', { class: 'flex items-center justify-between gap-2' }, [
                h('h4', { class: 'text-sm font-semibold text-slate-900' }, '主 PMS 门店'),
                pill(ctx, pms.status),
            ]),
            h('dl', { class: 'mt-3 space-y-1.5 text-xs' }, [
                detail('提供方', pms.provider, { mono: true, fallback: '未绑定' }),
                detail('PMS 门店 ID', pms.provider_hotel_id, { mono: true, fallback: '缺失' }),
                detail('PMS 门店名', pms.provider_hotel_name),
                detail('最近业务日', pms.last_capture_business_date, { mono: true }),
            ]),
        ]);
    };

    const planForm = (ctx, plan) => {
        const form = ctx.automationMonitorPlanForm || {};
        const busy = ctx.automationMonitorContractSaving === true;
        return h('form', {
            class: 'rounded-xl border border-slate-200 bg-white p-4',
            'data-testid': 'automation-contract-plan-form',
            onSubmit: event => {
                event.preventDefault();
                ctx.saveAutomationMonitorPlan({ activate: false });
            },
        }, [
            h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                h('div', {}, [
                    h('h4', { class: 'text-sm font-semibold text-slate-900' }, '本酒店独立采集计划'),
                    h('p', { class: 'mt-1 text-xs text-slate-500' },
                        '先保存草稿并精确回读；绑定全部就绪后才允许启用。'),
                ]),
                h('div', { class: 'flex flex-wrap gap-1.5 text-[11px]' }, [
                    pill(ctx, plan.status),
                    h('span', {
                        class: `rounded-full border px-2 py-0.5 font-medium ${plan.readback_verified === true
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                            : 'border-red-200 bg-red-50 text-red-700'}`,
                    }, `回读 ${plan.readback_verified === true ? '通过' : '未通过'}`),
                    h('span', { class: 'rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-slate-600' },
                        `版本 ${plan.plan_version || '未保存'}`),
                ]),
            ]),
            h('div', { class: 'mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3' }, [
                fieldLabel('携程数据源', h('select', {
                    value: form.ctrip_source_id || '',
                    class: inputClass,
                    'data-testid': 'automation-plan-ctrip-source',
                    disabled: ctx.automationMonitorContractSourceLocked('ctrip'),
                    onChange: event => { form.ctrip_source_id = event.target.value; },
                }, sourceOptions(ctx, 'ctrip'))),
                fieldLabel('美团数据源', h('select', {
                    value: form.meituan_source_id || '',
                    class: inputClass,
                    'data-testid': 'automation-plan-meituan-source',
                    disabled: ctx.automationMonitorContractSourceLocked('meituan'),
                    onChange: event => { form.meituan_source_id = event.target.value; },
                }, sourceOptions(ctx, 'meituan'))),
                fieldLabel('主 PMS', h('input', {
                    value: form.pms_provider || '',
                    readonly: true,
                    class: `${inputClass} bg-slate-50 font-mono`,
                    'data-testid': 'automation-plan-pms-provider',
                })),
                fieldLabel('每日采集时间', h('input', {
                    value: form.schedule_time || '',
                    type: 'time',
                    class: inputClass,
                    'data-testid': 'automation-plan-schedule-time',
                    onInput: event => { form.schedule_time = event.target.value; },
                })),
                fieldLabel('重试间隔（5–120 分钟）', h('input', {
                    value: form.retry_interval_minutes || 14,
                    type: 'number', min: 5, max: 120,
                    class: inputClass,
                    'data-testid': 'automation-plan-retry-interval',
                    onInput: event => { form.retry_interval_minutes = Number(event.target.value); },
                })),
                fieldLabel('最多尝试（1–12 次）', h('input', {
                    value: form.max_attempts || 7,
                    type: 'number', min: 1, max: 12,
                    class: inputClass,
                    'data-testid': 'automation-plan-max-attempts',
                    onInput: event => { form.max_attempts = Number(event.target.value); },
                })),
            ]),
            h('div', { class: 'mt-4 flex flex-wrap items-center gap-2' }, [
                h('button', {
                    type: 'submit', disabled: busy,
                    class: 'rounded-lg bg-[#315d50] px-3 py-2 text-sm font-medium text-white disabled:opacity-50',
                    'data-testid': 'automation-plan-save-draft',
                }, busy ? '保存并回读中...' : '保存草稿并回读'),
                h('button', {
                    type: 'button',
                    disabled: busy || ctx.automationMonitorContractCanActivate !== true,
                    class: 'rounded-lg border border-[#315d50] bg-white px-3 py-2 text-sm font-medium text-[#315d50] disabled:border-slate-200 disabled:text-slate-400',
                    'data-testid': 'automation-plan-activate',
                    onClick: () => ctx.saveAutomationMonitorPlan({ activate: true }),
                }, '启用精确计划'),
                ctx.automationMonitorContractCanActivate !== true
                    ? h('span', { class: 'text-xs text-slate-400' }, '当前绑定或草稿回读未达启用门槛')
                    : null,
            ]),
        ]);
    };

    const runStatusText = (status) => ({
        success: '\u6210\u529f',
        succeeded: '\u6210\u529f',
        partial: '\u90e8\u5206\u5b8c\u6210',
        failed: '\u5931\u8d25',
        blocked: '\u5df2\u963b\u65ad',
        skipped: '\u5df2\u8df3\u8fc7',
        deferred: '\u5f85\u6062\u590d',
        in_progress: '\u8fd0\u884c\u4e2d',
        started: '\u5df2\u542f\u52a8',
        collected: '\u5df2\u91c7\u96c6',
        authorized: '\u5df2\u6388\u6743',
        declared: '\u5df2\u58f0\u660e',
        queued: '\u6392\u961f\u4e2d',
        leased: '\u5df2\u9886\u53d6',
        running: '\u8fd0\u884c\u4e2d',
        retry_wait: '\u5f85\u91cd\u8bd5',
        waiting_user_login: '\u5f85\u539f\u8bbe\u5907\u767b\u5f55',
        verification_required: '\u5f85\u539f\u8bbe\u5907\u9a8c\u8bc1',
        missing: '\u65e0\u8fd0\u884c\u8bb0\u5f55',
        unavailable: '\u8bb0\u5f55\u4e0d\u53ef\u7528',
    }[String(status || '').toLowerCase()] || '\u72b6\u6001\u672a\u77e5');
    const runStatusClass = (status) => ({
        success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        succeeded: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        partial: 'border-amber-200 bg-amber-50 text-amber-700',
        failed: 'border-red-200 bg-red-50 text-red-700',
        blocked: 'border-red-200 bg-red-50 text-red-700',
        skipped: 'border-slate-200 bg-slate-50 text-slate-600',
        deferred: 'border-amber-200 bg-amber-50 text-amber-700',
        in_progress: 'border-blue-200 bg-blue-50 text-blue-700',
        started: 'border-blue-200 bg-blue-50 text-blue-700',
        collected: 'border-blue-200 bg-blue-50 text-blue-700',
        authorized: 'border-blue-200 bg-blue-50 text-blue-700',
        declared: 'border-blue-200 bg-blue-50 text-blue-700',
        queued: 'border-blue-200 bg-blue-50 text-blue-700',
        leased: 'border-blue-200 bg-blue-50 text-blue-700',
        running: 'border-blue-200 bg-blue-50 text-blue-700',
        retry_wait: 'border-amber-200 bg-amber-50 text-amber-700',
        waiting_user_login: 'border-amber-200 bg-amber-50 text-amber-700',
        verification_required: 'border-amber-200 bg-amber-50 text-amber-700',
        missing: 'border-slate-200 bg-slate-50 text-slate-600',
        unavailable: 'border-red-200 bg-red-50 text-red-700',
    }[String(status || '').toLowerCase()] || 'border-slate-200 bg-slate-50 text-slate-600');
    const runPill = (status) => h('span', {
        class: `rounded-full border px-2 py-0.5 text-[11px] font-medium ${runStatusClass(status)}`,
    }, runStatusText(status));
    const evidenceStatusText = (status) => ({
        verified: '\u5df2\u9a8c\u8bc1',
        not_run: '\u672a\u91c7\u96c6',
        not_evaluated: '\u672a\u9a8c\u6536',
        missing: '\u7f3a\u5931',
        unverified: '\u672a\u9a8c\u8bc1',
    }[String(status || '').toLowerCase()] || String(status || '\u672a\u53d6\u5f97'));
    const runSourceCard = (run, platform) => {
        const source = (Array.isArray(run.source_receipts) ? run.source_receipts : [])
            .find(item => String(item?.platform || '').toLowerCase() === platform) || {};
        const platformLabel = platform === 'ctrip' ? '\u643a\u7a0b' : '\u7f8e\u56e2';
        return h('article', {
            class: 'rounded-xl border border-slate-200 bg-slate-50/70 p-3',
            'data-testid': `automation-run-${platform}`,
        }, [
            h('div', { class: 'flex items-center justify-between gap-2' }, [
                h('h5', { class: 'text-sm font-semibold text-slate-800' }, platformLabel),
                runPill(source.status || 'missing'),
            ]),
            h('dl', { class: 'mt-3 space-y-1.5 text-xs' }, [
                detail('\u6570\u636e\u6e90', source.data_source_id ? `#${source.data_source_id}` : '\u672a\u7ed1\u5b9a', { mono: true, fallback: '\u672a\u7ed1\u5b9a' }),
                detail('\u91c7\u96c6\u65b9\u5f0f', source.ingestion_method, { mono: true, fallback: '\u672a\u53d6\u5f97' }),
                detail('\u540c\u6b65\u4efb\u52a1', source.platform_sync_task_id ? `#${source.platform_sync_task_id}` : '\u672a\u751f\u6210', { mono: true, fallback: '\u672a\u751f\u6210' }),
                detail('\u672c\u673a\u4efb\u52a1', source.local_collector_task_id ? `#${source.local_collector_task_id}` : '\u4e0d\u9002\u7528', { mono: true, fallback: '\u4e0d\u9002\u7528' }),
                detail('\u4fdd\u5b58 / \u56de\u8bfb', `${Number(source.saved_row_count || 0)} / ${Number(source.readback_row_count || 0)}`, { mono: true }),
                detail('\u5931\u8d25\u539f\u56e0', source.failure_code, { mono: true, fallback: '\u65e0' }),
            ]),
        ]);
    };
    const runPanel = (ctx, plan) => {
        const run = plan.latest_run_receipt && typeof plan.latest_run_receipt === 'object'
            ? plan.latest_run_receipt
            : { status: 'missing' };
        const selectedHotelId = String(ctx.automationMonitorContractHotelId || '').trim();
        const selectedBusinessDate = String(ctx.automationMonitorDate || '').trim();
        const runHotelId = String(run.system_hotel_id || '').trim();
        const runBusinessDate = String(run.business_date || '').trim();
        const runScopeMatches = selectedHotelId !== ''
            && selectedBusinessDate !== ''
            && runHotelId === selectedHotelId
            && runBusinessDate === selectedBusinessDate;
        const anchor = String(run.collection_anchor_hash || '').trim();
        const pms = run.pms_receipt && typeof run.pms_receipt === 'object' ? run.pms_receipt : {};
        const page = run.page_acceptance && typeof run.page_acceptance === 'object'
            ? run.page_acceptance
            : {};
        const status = runScopeMatches
            ? String(run.status || 'missing').toLowerCase()
            : 'missing';
        const hasExactRun = runScopeMatches
            && String(run.dispatcher_run_id || '').trim() !== '';
        return h('section', {
            class: 'rounded-xl border border-slate-200 bg-white p-4',
            'data-testid': 'automation-collection-run-receipt',
        }, [
            h('div', { class: 'flex flex-wrap items-start justify-between gap-3' }, [
                h('div', {}, [
                    h('h4', { class: 'text-sm font-semibold text-slate-900' }, '\u6700\u8fd1\u4e00\u6b21\u95e8\u5e97\u8fd0\u884c\u8bb0\u5f55'),
                    h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' },
                        '\u4ec5\u663e\u793a\u5f53\u524d\u7cfb\u7edf\u9152\u5e97\u548c\u5f53\u524d\u4e1a\u52a1\u65e5\u7684\u4fdd\u5b58\u3001\u56de\u8bfb\u3001\u9875\u9762\u9a8c\u6536\u4e0e PMS \u4fa7\u8bc1\u3002'),
                ]),
                runPill(status),
            ]),
            hasExactRun ? h('div', { class: 'mt-4 space-y-3' }, [
                h('dl', { class: 'grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4' }, [
                    detail('\u4e1a\u52a1\u65e5', run.business_date, { mono: true, fallback: '\u672a\u53d6\u5f97' }),
                    detail('\u8fd0\u884c ID', run.dispatcher_run_id, { mono: true, fallback: '\u672a\u53d6\u5f97' }),
                    detail('\u8bc1\u636e\u951a\u70b9', anchor ? `${anchor.slice(0, 12)}\u2026` : '\u672a\u751f\u6210', { mono: true }),
                    detail('\u5931\u8d25\u539f\u56e0', run.failure_code, { mono: true, fallback: '\u65e0' }),
                    detail('\u53f0\u8d26\u56de\u8bfb', run.readback_verified === true ? '\u901a\u8fc7' : '\u672a\u901a\u8fc7'),
                    detail('PMS', `${evidenceStatusText(pms.status || 'not_run')}${pms.capture_id ? ` #${pms.capture_id}` : ''}`),
                    detail('\u9875\u9762\u9a8c\u6536', `${evidenceStatusText(page.status || 'not_evaluated')}${page.receipt_id ? ` #${page.receipt_id}` : ''}`),
                    detail('\u5f00\u59cb\u65f6\u95f4', run.started_at, { mono: true, fallback: '\u672a\u53d6\u5f97' }),
                    detail('\u7ed3\u675f\u65f6\u95f4', run.finished_at, { mono: true, fallback: '\u5c1a\u672a\u7ed3\u675f' }),
                ]),
                h('div', { class: 'grid gap-3 md:grid-cols-2' }, [
                    runSourceCard(run, 'ctrip'),
                    runSourceCard(run, 'meituan'),
                ]),
            ]) : h('p', {
                class: `mt-3 rounded-lg border px-3 py-2 text-xs leading-5 ${runStatusClass(status)}`,
                'data-testid': 'automation-collection-run-empty',
            }, !runScopeMatches && (runHotelId !== '' || runBusinessDate !== '')
                ? '\u8fd0\u884c\u8bb0\u5f55\u4e0e\u5f53\u524d\u9009\u62e9\u7684\u9152\u5e97\u6216\u4e1a\u52a1\u65e5\u4e0d\u4e00\u81f4\uff0c\u5df2\u9690\u85cf\u65e7\u95e8\u5e97\u8fd0\u884c\u8be6\u60c5\u3002'
                : run.failure_code === 'hotel_collection_run_receipt_store_unavailable'
                ? '\u8fd0\u884c\u8bb0\u5f55\u8868\u5c1a\u672a\u5c31\u7eea\uff0c\u672c\u9875\u4e0d\u4f1a\u628a\u65e0\u6cd5\u56de\u8bfb\u8bef\u62a5\u4e3a\u6210\u529f\u3002'
                : '\u5f53\u524d\u95e8\u5e97\u548c\u4e1a\u52a1\u65e5\u5c1a\u65e0\u8fd0\u884c\u8bb0\u5f55\u3002'),
        ]);
    };

    const reasonPanel = (ctx, binding) => {
        const reasons = Array.isArray(ctx.automationMonitorContractReasons)
            ? ctx.automationMonitorContractReasons
            : [];
        return h('aside', {
            class: 'rounded-xl border border-slate-200 bg-slate-50/70 p-4',
            'data-testid': 'automation-contract-reasons',
        }, [
            h('div', { class: 'flex items-center justify-between gap-2' }, [
                h('h4', { class: 'text-sm font-semibold text-slate-900' }, '失败与恢复状态'),
                h('span', { class: 'rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] text-slate-600' },
                    `${reasons.length} 项`),
            ]),
            reasons.length ? h('ul', { class: 'mt-3 space-y-2' }, reasons.map(issue => h('li', {
                key: `${issue.platform || 'hotel'}-${issue.code}`,
                class: 'rounded-lg border border-red-100 bg-white px-3 py-2',
            }, [
                h('div', { class: 'flex flex-wrap items-center gap-1.5' }, [
                    h('span', { class: 'rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] uppercase text-slate-500' },
                        issue.platform || 'hotel'),
                    h('code', { class: 'text-[10px] text-red-600' }, issue.code),
                ]),
                h('p', { class: 'mt-1 text-xs leading-5 text-slate-700' },
                    ctx.automationMonitorContractReasonText(issue)),
            ]))) : h('p', {
                class: 'mt-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-700',
            }, '绑定层暂无阻断；仍需同店同日采集、保存、回读、页面验收和连续运行，才能复制。'),
            h('div', {
                class: 'mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800',
            }, '登录态只在运营人员自己的执行设备。失效或离线时，只在原账号、原设备、原酒店、原平台恢复；不串酒店、不自动换设备代采。'),
            h('button', {
                type: 'button',
                disabled: !ctx.automationMonitorContractHotelId,
                class: 'mt-3 w-full rounded-lg bg-[#315d50] px-3 py-2 text-sm font-medium text-white disabled:bg-slate-200 disabled:text-slate-400',
                'data-testid': 'automation-contract-open-device-onboarding',
                onClick: () => ctx.openHotelCollectionDeviceOnboarding(
                    ctx.automationMonitorContractHotelId
                ),
            }, '前往原设备绑定与恢复'),
            h('p', { class: 'mt-1 text-[11px] leading-5 text-slate-500' },
                '将当前酒店带入“平台采集源”；只连接运营人员正在使用的原电脑，不上传 Cookie、验证码或 Profile。'),
            h('p', { class: 'mt-2 text-[11px] leading-5 text-slate-500' },
                `复制门槛：${binding.replication_gate?.ready === true ? '已通过' : '未通过'}。绑定就绪不等于现场稳定，也不证明已复制。`),
        ]);
    };

    components.AutomationCollectionContractBody = {
        name: 'AutomationCollectionContractBody',
        props: { ctx: { type: Object, required: true } },
        setup(props) {
            return () => {
                const ctx = props.ctx;
                const binding = ctx.automationMonitorContractBinding || {};
                const plan = ctx.automationMonitorContractPlan || {};
                const selectedHotelId = ctx.automationMonitorContractHotelId || '';
                const hotels = Array.isArray(ctx.automationMonitorContractHotelOptions)
                    ? ctx.automationMonitorContractHotelOptions
                    : [];
                let body;
                if (ctx.automationMonitorContractLoading === true) {
                    body = h('div', {
                        class: 'grid animate-pulse gap-3 p-4 md:grid-cols-2 xl:grid-cols-4',
                        'data-testid': 'automation-contract-loading',
                    }, [1, 2, 3, 4].map(index => h('div', {
                        key: index, class: 'h-28 rounded-xl bg-slate-100',
                    })));
                } else if (!selectedHotelId) {
                    body = h('div', { class: 'px-6 py-10 text-center text-sm text-slate-500' },
                        '请先从当前有权限的营业门店中选择一家酒店。');
                } else {
                    body = h('div', { class: 'space-y-4 p-4' }, [
                        ctx.automationMonitorContractError ? h('p', {
                            class: 'rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700',
                            'data-testid': 'automation-contract-error',
                        }, ctx.automationMonitorContractError) : null,
                        h('div', {
                            class: 'grid gap-3 md:grid-cols-2 xl:grid-cols-4',
                            'data-testid': 'automation-contract-bindings',
                        }, [
                            systemCard(ctx, binding),
                            otaCard(ctx, binding, plan, 'ctrip'),
                            otaCard(ctx, binding, plan, 'meituan'),
                            pmsCard(ctx, binding),
                        ]),
                        bindingOnboardingPanel(ctx, binding),
                        h('div', { class: 'grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(320px,1fr)]' }, [
                            planForm(ctx, plan),
                            reasonPanel(ctx, binding),
                        ]),
                        runPanel(ctx, plan),
                    ]);
                }

                const contractPanel = h('section', {
                    class: 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm',
                    'data-testid': 'automation-collection-contract',
                }, [
                    h('header', {
                        class: 'flex flex-col gap-3 border-b border-slate-200 bg-[#f7f9f8] px-4 py-3 lg:flex-row lg:items-end lg:justify-between',
                    }, [
                        h('div', {}, [
                            h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                h('h3', { class: 'font-semibold text-slate-900' }, '门店采集绑定与计划'),
                                h('span', { class: 'rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-700' }, '复制前硬门槛'),
                            ]),
                            h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' },
                                '逐店固定系统酒店、携程、美团、主 PMS 和原执行设备；不保存 Cookie、验证码或 Profile 路径。'),
                        ]),
                        h('div', { class: 'flex flex-wrap items-end gap-2' }, [
                            fieldLabel('核验酒店', h('select', {
                                value: selectedHotelId,
                                class: 'min-w-[220px] rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800',
                                'data-testid': 'automation-contract-hotel',
                                onChange: event => {
                                    ctx.automationMonitorContractHotelId = event.target.value;
                                    ctx.loadAutomationMonitorContract();
                                },
                            }, [
                                h('option', { value: '' }, '请选择酒店'),
                                ...hotels.map(hotel => h('option', {
                                    key: hotel.id, value: String(hotel.id),
                                }, `${hotel.name}（#${hotel.id}）`)),
                            ])),
                            h('button', {
                                type: 'button',
                                disabled: ctx.automationMonitorContractLoading === true || !selectedHotelId,
                                class: 'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 disabled:opacity-50',
                                'data-testid': 'automation-contract-refresh',
                                onClick: () => ctx.loadAutomationMonitorContract(),
                            }, ctx.automationMonitorContractLoading === true ? '回读中...' : '精确回读'),
                        ]),
                    ]),
                    body,
                ]);
                return h('section', { class: 'space-y-3' }, [
                    scheduledLoopInventoryPanel(ctx),
                    contractPanel,
                ]);
            };
        },
    };
})(window);
