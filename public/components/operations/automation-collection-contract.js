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
                    onChange: event => { form.ctrip_source_id = event.target.value; },
                }, sourceOptions(ctx, 'ctrip'))),
                fieldLabel('美团数据源', h('select', {
                    value: form.meituan_source_id || '',
                    class: inputClass,
                    'data-testid': 'automation-plan-meituan-source',
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
                        h('div', { class: 'grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(320px,1fr)]' }, [
                            planForm(ctx, plan),
                            reasonPanel(ctx, binding),
                        ]),
                        runPanel(ctx, plan),
                    ]);
                }

                return h('section', {
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
            };
        },
    };
})(window);
