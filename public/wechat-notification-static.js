(function registerWechatNotificationPanel(global) {
    'use strict';

    const h = global.Vue?.h;
    if (typeof h !== 'function') {
        throw new Error('Vue runtime is required for the enterprise WeChat notification panel.');
    }

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
            lastTestText: { type: String, default: '尚未取得送达记录' },
        },
        emits: ['update-webhook', 'save', 'test'],
        setup(props, { emit }) {
            return () => {
                const binding = props.state?.binding || null;
                const selectedHotel = props.hotels.find(
                    hotel => String(hotel?.id) === String(props.hotelId)
                ) || null;
                const busy = props.loading || props.saving || props.testing;
                const inputClass = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm focus:border-[#315d50] focus:outline-none focus:ring-2 focus:ring-[#315d50]/15';

                return h('div', {
                    class: 'mx-auto max-w-7xl',
                    'data-testid': 'wechat-notification-panel',
                }, [
                    h('section', { class: 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm' }, [
                        h('div', { class: 'flex flex-wrap items-start justify-between gap-3' }, [
                            h('div', {}, [
                                h('h2', { class: 'text-lg font-semibold text-slate-900' }, '企业微信群机器人'),
                                h('p', { class: 'mt-1 text-sm text-slate-500' },
                                    selectedHotel?.name || '请选择酒店'),
                            ]),
                            h('span', {
                                class: `inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium ${props.statusClass}`,
                                'data-testid': 'wechat-notification-status',
                            }, props.statusText),
                        ]),
                        h('form', {
                            class: 'mt-4',
                            'data-testid': 'wechat-notification-form',
                            onSubmit: (event) => {
                                event.preventDefault();
                                emit('save');
                            },
                        }, [
                            h('div', { class: 'grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]' }, [
                                h('label', { class: 'block' }, [
                                    h('span', { class: 'mb-2 block text-sm font-medium text-slate-700' },
                                        'Webhook'),
                                    h('input', {
                                        value: props.form?.webhook || '',
                                        type: 'password',
                                        autocomplete: 'new-password',
                                        spellcheck: 'false',
                                        class: `${inputClass} font-mono`,
                                        placeholder: 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...',
                                        'data-testid': 'wechat-notification-webhook',
                                        onInput: event => emit('update-webhook', event.target.value),
                                    }),
                                    h('span', { class: 'mt-2 block text-xs text-slate-500' },
                                        '加密保存，不回显完整地址。'),
                                ]),
                                h('div', { class: 'flex items-end gap-2' }, [
                                    h('button', {
                                        type: 'submit',
                                        disabled: busy || !props.hotelId || !String(props.form?.webhook || '').trim(),
                                        class: 'rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50',
                                        'data-testid': 'wechat-notification-save',
                                    }, props.saving ? '保存中...' : (binding ? '更新' : '保存')),
                                    h('button', {
                                        type: 'button',
                                        disabled: busy || !binding,
                                        class: 'rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50',
                                        'data-testid': 'wechat-notification-test',
                                        onClick: () => emit('test'),
                                    }, props.testing ? '发送中...' : '测试'),
                                ]),
                            ]),
                            h('div', { class: 'mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2' }, [
                                detail('Webhook', binding?.webhook_masked || '未绑定', 'wechat-notification-mask'),
                                detail('最近送达', props.lastTestText, 'wechat-notification-last-test'),
                            ]),
                                props.error ? h('div', {
                                    class: 'mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700',
                                    role: 'alert',
                                    'data-testid': 'wechat-notification-error',
                                }, props.error) : null,
                        ]),
                    ]),
                ]);
            };
        },
    };
})(window);

(function registerManualNotificationDispatchPanel(global) {
    'use strict';

    const h = global.Vue?.h;
    if (typeof h !== 'function') {
        throw new Error('Vue runtime is required for the manual notification dispatch panel.');
    }

    global.SUXI_MANUAL_NOTIFICATION_DISPATCH_PANEL = {
        name: 'ManualNotificationDispatchPanel',
        props: {
            history: { type: Object, default: () => ({ list: [] }) },
            loading: Boolean,
            expandedId: { type: String, default: '' },
            triggerLabel: { type: Function, required: true },
            statusLabel: { type: Function, required: true },
            statusClass: { type: Function, required: true },
            retryClass: { type: Function, required: true },
            canRetry: { type: Function, required: true },
            timeline: { type: Function, required: true },
        },
        emits: ['refresh', 'openPlan', 'retry', 'toggle'],
        setup(props, { emit }) {
            return () => {
                const items = Array.isArray(props.history?.list) ? props.history.list : [];
                return h('section', {
                    class: 'overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm',
                    'data-testid': 'manual-notification-dispatch-history',
                }, [
                    h('header', {
                        class: 'flex items-center justify-between border-b border-slate-100 px-5 py-4',
                    }, [
                        h('h2', { class: 'text-lg font-semibold text-slate-900' }, '发送记录'),
                        h('button', {
                            type: 'button',
                            class: 'rounded-lg px-3 py-2 text-sm text-slate-500 hover:bg-slate-100',
                            onClick: () => emit('refresh'),
                        }, [
                            h('i', {
                                class: `fas fa-sync-alt mr-2 ${props.loading ? 'fa-spin' : ''}`,
                            }),
                            '刷新',
                        ]),
                    ]),
                    h('div', { class: 'divide-y divide-slate-100' }, items.length
                        ? items.map((item) => {
                            const id = String(item?.id || '');
                            const expanded = props.expandedId === id;
                            const steps = expanded ? props.timeline(item) : [];
                            const status = String(item?.status || '');
                            let action = h('span', { class: 'text-xs text-slate-400' }, '待处理');
                            if (status === 'sent') {
                                action = h('span', {
                                    class: 'text-xs text-slate-400',
                                    title: '已送达消息不会重复发送',
                                }, '无需操作');
                            } else if (status === 'blocked' && Number(item?.notification_id || 0) > 0) {
                                action = h('button', {
                                    type: 'button',
                                    class: 'rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700',
                                    onClick: () => emit('openPlan', item),
                                }, '补齐数据');
                            } else if (props.canRetry(item)) {
                                action = h('button', {
                                    type: 'button',
                                    class: `rounded-lg border px-3 py-1.5 text-xs font-medium ${props.retryClass(item)}`,
                                    onClick: () => emit('retry', item),
                                }, status === 'outcome_unknown' ? '确认后重试' : '重试');
                            } else if (['failed', 'outcome_unknown'].includes(status)) {
                                action = h('span', { class: 'text-xs text-slate-500' }, '已达重试上限');
                            }

                            return h('article', { key: `dispatch-${id}`, class: 'p-5' }, [
                                h('div', {
                                    class: 'flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between',
                                }, [
                                    h('div', { class: 'min-w-0' }, [
                                        h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                            h('b', { class: 'text-sm text-slate-900' },
                                                item?.plan_title || `通知 #${item?.notification_id || '未取得'}`),
                                            h('span', {
                                                class: `rounded-full border px-2.5 py-1 text-xs font-medium ${props.statusClass(item)}`,
                                            }, props.statusLabel(item)),
                                        ]),
                                        h('p', { class: 'mt-1 text-xs text-slate-500' },
                                            `${item?.business_date || '日期未取得'} · ${item?.source_scope_label || item?.template_type_label || '来源未取得'} · ${props.triggerLabel(item)} · ${item?.robot_name || '通道待核验'}`),
                                        h('p', {
                                            class: 'mt-2 max-w-3xl truncate text-xs text-slate-500',
                                            title: item?.result_message || item?.result_code || '',
                                        }, item?.result_message || item?.result_code || '无可回查回执'),
                                        h('time', { class: 'mt-1 block text-xs text-slate-400' },
                                            item?.dispatched_at || item?.last_attempt_at || item?.claimed_at || '未取得'),
                                    ]),
                                    h('div', {
                                        class: 'flex shrink-0 flex-wrap items-center gap-2',
                                    }, [
                                        action,
                                        h('button', {
                                            type: 'button',
                                            class: 'rounded-lg px-2 py-1 text-xs font-medium text-[#315d50]',
                                            'aria-expanded': expanded,
                                            onClick: () => emit('toggle', item),
                                        }, expanded ? '收起' : '过程'),
                                    ]),
                                ]),
                                expanded && steps.length
                                    ? h('ol', {
                                        class: 'mt-4 grid gap-2 md:grid-cols-3',
                                        'data-testid': 'manual-notification-dispatch-timeline',
                                    }, steps.map(step => h('li', {
                                        key: step.key,
                                        class: 'rounded-xl bg-slate-50 p-3',
                                    }, [
                                        h('div', {
                                            class: 'flex items-center justify-between gap-2',
                                        }, [
                                            h('b', { class: 'text-xs text-slate-800' }, step.label),
                                            h('time', { class: 'text-xs text-slate-400' }, step.time),
                                        ]),
                                        h('p', { class: 'mt-1 text-xs text-slate-500' }, step.detail),
                                    ])))
                                    : (expanded
                                        ? h('p', { class: 'mt-4 text-xs text-slate-500' }, '未取得过程时间。')
                                        : null),
                            ]);
                        })
                        : [
                            h('p', {
                                class: 'px-6 py-10 text-center text-sm text-slate-500',
                            }, props.loading ? '读取中…' : '暂无发送记录'),
                        ]),
                ]);
            };
        },
    };
})(window);

(function registerManualNotificationSchedulePanel(global) {
    'use strict';

    const h = global.Vue?.h;
    if (typeof h !== 'function') {
        throw new Error('Vue runtime is required for the manual notification schedule panel.');
    }

    const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-[#ad8b52] focus:outline-none focus:ring-2 focus:ring-[#ad8b52]/15';
    const field = (label, control, help = '', error = '') => h('label', { class: 'block' }, [
        h('span', { class: 'mb-2 block text-sm font-medium text-slate-700' }, label),
        control,
        help ? h('span', { class: 'mt-1 block text-xs leading-5 text-slate-500' }, help) : null,
        error ? h('span', {
            class: 'mt-1.5 block text-xs leading-5 text-rose-600',
            role: 'alert',
        }, error) : null,
    ]);
    const summary = (label, value, testid = '') => h('div', {
        class: 'rounded-xl border border-slate-200 bg-white px-3 py-2.5',
        ...(testid ? { 'data-testid': testid } : {}),
    }, [
        h('span', { class: 'block text-xs text-slate-500' }, label),
        h('b', { class: 'mt-1 block text-sm text-slate-900' }, value),
    ]);

    global.SUXI_MANUAL_NOTIFICATION_SCHEDULE_PANEL = {
        name: 'ManualNotificationSchedulePanel',
        props: {
            metadata: { type: Object, default: () => ({}) },
            form: { type: Object, default: () => ({}) },
            robots: { type: Array, default: () => [] },
            dataScopeLabel: { type: String, default: '人工自定义正文' },
            dataStatus: { type: String, default: '仅保存/仅测试' },
            latestDispatch: { type: Object, default: null },
            validationErrors: { type: Object, default: () => ({}) },
            operatingDaily: { type: Boolean, default: false },
            strictThreeSourceInterval: { type: Boolean, default: false },
            strictThreeSourceIntervalAvailable: { type: Boolean, default: false },
            error: { type: String, default: '' },
        },
        emits: ['field-change'],
        setup(props, { emit }) {
            const change = (fieldName, value) => emit('field-change', {
                field: fieldName,
                value,
            });
            const optionLabel = (options, key, fallback) => (
                (Array.isArray(options) ? options : []).find(
                    option => String(option?.key) === String(key)
                )?.label || fallback
            );
            const fieldError = fieldName => String(
                props.validationErrors?.[fieldName] || ''
            ).trim();
            const input = (fieldName, type, testid, attributes = {}) => h('input', {
                value: props.form?.[fieldName] || '',
                type,
                class: `${inputClass} ${fieldError(fieldName)
                    ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-200'
                    : ''}`,
                'data-testid': testid,
                'aria-invalid': fieldError(fieldName) ? 'true' : 'false',
                onInput: event => change(fieldName, event.target.value),
                ...attributes,
            });
            const select = (fieldName, options, testid, placeholder = '') => h('select', {
                value: String(props.form?.[fieldName] ?? ''),
                class: `${inputClass} ${fieldError(fieldName)
                    ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-200'
                    : ''}`,
                'data-testid': testid,
                'aria-invalid': fieldError(fieldName) ? 'true' : 'false',
                onChange: event => change(fieldName, event.target.value),
            }, [
                ...(placeholder ? [h('option', { value: '' }, placeholder)] : []),
                ...(Array.isArray(options) ? options : []).map(option => h('option', {
                    key: String(option?.key ?? option?.id ?? ''),
                    value: String(option?.key ?? option?.id ?? ''),
                    disabled: option?.disabled === true,
                }, option?.label || option?.name || '未命名')),
            ]);

            return () => {
                const metadata = props.metadata || {};
                const form = props.form || {};
                const triggerType = String(form.trigger_type || 'manual_test');
                const conditionType = String(form.condition_type || 'always');
                const operatingDailyTriggerAllowed = (
                    !props.operatingDaily
                    || ['manual_test', 'daily_fixed_time'].includes(triggerType)
                    || props.strictThreeSourceInterval
                );
                const strictIntervalNeedsCorrection = props.operatingDaily
                    && triggerType === 'interval_minutes'
                    && props.strictThreeSourceIntervalAvailable
                    && !props.strictThreeSourceInterval;
                const planActive = form.enabled
                    && form.schedule_status === 'schedule_enabled'
                    && operatingDailyTriggerAllowed;
                const weekdays = (Array.isArray(form.active_weekdays)
                    ? form.active_weekdays
                    : String(form.active_weekdays || '').split(',')
                ).map(Number).filter(day => day >= 1 && day <= 7);
                const weekdayOptions = Array.isArray(metadata.weekdays) && metadata.weekdays.length
                    ? metadata.weekdays
                    : ['周一', '周二', '周三', '周四', '周五', '周六', '周日']
                        .map((label, index) => ({ key: index + 1, label }));
                const dateRuleLabel = optionLabel(
                    metadata.business_date_rules,
                    form.business_date_rule,
                    '发送当天数据'
                );
                const latest = props.latestDispatch || null;
                const scheduleMode = String(form.send_method || '') === 'wecom_formal'
                    ? 'formal'
                    : 'test';
                const modeScheduleRun = metadata.latest_schedule_runs?.[scheduleMode];
                const legacyScheduleRun = metadata.latest_schedule_run || {};
                const scheduleRun = modeScheduleRun
                    || (String(legacyScheduleRun.runner_mode || '') === scheduleMode
                        ? legacyScheduleRun
                        : {});
                const schedulerReady = String(scheduleRun.status || '')
                    === `${scheduleMode}_scope_ready`;
                const lastRun = scheduleRun.observed_at
                    || latest?.claimed_at
                    || latest?.last_attempt_at
                    || '未取得云端运行记录';
                const lastReceipt = latest
                    ? `${latest.status === 'sent' ? '已送达' : latest.status === 'failed' ? '发送失败' : latest.status === 'blocked' ? '门禁阻断' : latest.status === 'outcome_unknown' ? '结果不明确' : '执行记录未取得'} · ${latest.dispatched_at || latest.last_attempt_at || latest.claimed_at || '时间未取得'}`
                    : '未取得发送回执';
                const blocker = props.error
                    || (!operatingDailyTriggerAllowed
                        ? (strictIntervalNeedsCorrection
                            ? '三源严格计划配置不完整，请恢复当日、正式群及指定四项内容'
                            : '旧循环计划已停用，请改为每日固定时间并重新测试')
                        : !form.enabled
                        ? '计划已暂停'
                        : form.schedule_status === 'awaiting_test'
                            ? '等待一次真实测试成功'
                            : !schedulerReady
                                ? (metadata.scheduler_note || '云端调度状态未验证')
                                : latest?.status === 'failed'
                                    ? (latest.result_message || '最近一次发送失败，可人工重试')
                                    : latest?.status === 'outcome_unknown'
                                        ? '最近一次结果不明确，禁止自动重试'
                                        : form.schedule_status === 'schedule_enabled' && !form.next_run_at
                                            ? '当前规则内未找到下次执行时间'
                                             : '无已知阻断');
                const policies = metadata.fixed_policies || {};
                const sourceScopes = Array.isArray(metadata.source_scopes)
                    ? metadata.source_scopes
                    : [];
                const selectedSourceScope = sourceScopes.find(
                    item => String(item?.key || '') === String(form.source_scope || 'combined')
                ) || sourceScopes.find(item => item?.key === 'combined') || {
                    key: 'combined',
                    label: '三源汇总（兼容原计划）',
                    default_sections: [],
                };
                const selectedSections = (Array.isArray(form.content_sections)
                    ? form.content_sections
                    : String(form.content_sections || '').split(',')
                ).map(value => String(value).trim()).filter(Boolean);
                const availableSections = (Array.isArray(metadata.content_sections)
                    ? metadata.content_sections
                    : []
                ).filter(section => (
                    Array.isArray(section?.source_scopes)
                    && section.source_scopes.includes(String(selectedSourceScope.key || 'combined'))
                ));
                const customWholeReport = String(form.content_template_mode || '') === 'custom';
                const visibleSourceScopes = customWholeReport
                    ? sourceScopes.filter(item => item?.key === 'combined')
                    : sourceScopes;
                const allTriggerOptions = Array.isArray(metadata.trigger_types)
                    ? metadata.trigger_types
                    : [];
                const configuredOperatingDailyTriggers = Array.isArray(
                    metadata.operating_daily_trigger_types
                ) && metadata.operating_daily_trigger_types.length
                    ? metadata.operating_daily_trigger_types
                    : allTriggerOptions.filter(option => (
                        ['manual_test', 'daily_fixed_time'].includes(String(option?.key || ''))
                    ));
                const triggerOptions = props.operatingDaily
                    ? [
                        ...configuredOperatingDailyTriggers,
                        ...(props.strictThreeSourceIntervalAvailable ? [{
                            key: 'interval_minutes',
                            label: '每 30 分钟（三源严格计划）',
                        }] : []),
                        ...(!operatingDailyTriggerAllowed
                            && !props.strictThreeSourceIntervalAvailable ? [{
                            key: triggerType,
                            label: `${optionLabel(
                                allTriggerOptions,
                                triggerType,
                                '旧循环计划'
                            )}（已停用，请改为每日固定时间）`,
                            disabled: true,
                        }] : []),
                    ]
                    : allTriggerOptions;
                const templateType = String(form.template_type || form.notification_type || '');
                const hasPmsConditionFacts = !props.operatingDaily || (
                    ['combined', 'dingdandao_pms'].includes(
                        String(selectedSourceScope.key || '')
                    )
                    && selectedSections.includes('pms_efficiency')
                );
                const conditionRules = (Array.isArray(metadata.condition_rules)
                    ? metadata.condition_rules
                    : [{ key: 'always', label: '到点即发送' }]
                ).filter(rule => {
                    const supported = Array.isArray(rule?.supported_template_types)
                        ? rule.supported_template_types
                        : [];
                    const templateSupported = supported.length === 0
                        || supported.includes(templateType);
                    const factsSupported = rule?.requires_pms_facts !== true
                        || hasPmsConditionFacts;
                    return String(rule?.key || '') === 'always'
                        || (templateSupported && factsSupported);
                });
                const selectedCondition = conditionRules.find(
                    rule => String(rule?.key || '') === conditionType
                ) || conditionRules[0] || { key: 'always', label: '到点即发送' };
                const conditionState = form.condition_state || null;

                return h('section', {
                    class: 'rounded-2xl border border-[#eadfc9] bg-[#fffdf8] p-4',
                    'data-testid': 'manual-notification-schedule-settings',
                }, [
                    h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between' }, [
                        h('div', {}, [
                            h('h3', { class: 'font-semibold text-slate-900' }, '自动发送设置'),
                        ]),
                        h('span', {
                            class: `rounded-full border px-2.5 py-1 text-xs font-medium ${planActive ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : !operatingDailyTriggerAllowed ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-slate-200 bg-white text-slate-600'}`,
                            'data-testid': 'manual-notification-plan-toggle-status',
                        }, planActive
                            ? '本计划已开启'
                            : !operatingDailyTriggerAllowed
                                ? (strictIntervalNeedsCorrection
                                    ? '严格计划配置待修正'
                                    : '旧循环计划已阻断')
                                : '本计划已暂停'),
                    ]),
                    h('div', { class: 'mt-4 grid grid-cols-2 gap-2 lg:grid-cols-5' }, [
                        summary('数据范围', props.dataScopeLabel, 'manual-notification-data-scope'),
                        summary('数据日期', dateRuleLabel, 'manual-notification-date-rule-summary'),
                        summary('发送条件', selectedCondition.label || conditionType, 'manual-notification-condition-summary'),
                        summary('计划状态', form.schedule_status_label || props.dataStatus),
                        summary(
                            '下次运行',
                            !operatingDailyTriggerAllowed
                                ? (strictIntervalNeedsCorrection
                                    ? '严格计划配置待修正'
                                    : '循环计划已停用')
                                : (form.next_run_at || '保存并通过测试后计算'),
                            'manual-notification-next-run'
                        ),
                    ]),
                    h('fieldset', {
                        class: 'mt-4',
                        'data-testid': 'manual-notification-source-scope',
                        'aria-invalid': fieldError('source_scope') ? 'true' : 'false',
                    }, [
                        h('legend', { class: 'mb-2 text-sm font-medium text-slate-700' }, '发送来源'),
                        h('div', { class: 'grid gap-2 sm:grid-cols-2' }, visibleSourceScopes.map(source => {
                            const selected = String(source?.key || '') === String(selectedSourceScope.key || '');
                            return h('button', {
                                key: String(source?.key || ''),
                                type: 'button',
                                title: source?.description || '',
                                class: `rounded-xl border p-3 text-left transition ${selected
                                    ? 'border-[#ad8b52] bg-[#fff7e8] text-[#826333] ring-2 ring-[#ad8b52]/10'
                                    : 'border-slate-200 bg-white text-slate-600 hover:border-[#d8c49f]'}`,
                                onClick: () => {
                                    change('source_scope', String(source?.key || 'combined'));
                                    change(
                                        'content_sections',
                                        Array.isArray(source?.default_sections)
                                            ? [...source.default_sections]
                                            : []
                                    );
                                },
                            }, h('b', { class: 'block text-sm' }, source?.label || source?.key || '未命名来源'));
                        })),
                        customWholeReport
                            ? h('p', { class: 'mt-2 text-xs leading-5 text-amber-700' },
                                '自定义全文仅支持三源汇总。')
                            : null,
                        fieldError('source_scope')
                            ? h('p', { class: 'mt-2 text-xs text-rose-600', role: 'alert' }, fieldError('source_scope'))
                            : null,
                    ]),
                    h('fieldset', {
                        class: 'mt-4',
                        'data-testid': 'manual-notification-content-sections',
                        'aria-invalid': fieldError('content_sections') ? 'true' : 'false',
                    }, [
                        h('legend', { class: 'mb-2 text-sm font-medium text-slate-700' }, '发送什么'),
                        h('div', { class: 'grid gap-2 sm:grid-cols-2' }, availableSections.map(section => {
                            const key = String(section?.key || '');
                            const checked = selectedSections.includes(key);
                            return h('label', {
                                key,
                                class: `cursor-pointer rounded-lg border px-3 py-2 text-xs font-medium ${checked
                                    ? 'border-[#ad8b52] bg-[#fff7e8] text-[#826333]'
                                    : 'border-slate-200 bg-white text-slate-500'}`,
                            }, [
                                h('input', {
                                    type: 'checkbox',
                                    checked,
                                    disabled: checked && selectedSections.length === 1,
                                    class: 'mr-2',
                                    onChange: event => {
                                        const next = event.target.checked
                                            ? [...new Set([...selectedSections, key])]
                                            : selectedSections.filter(value => value !== key);
                                        if (next.length) change('content_sections', next);
                                    },
                                }),
                                section?.label || key,
                            ]);
                        })),
                        fieldError('content_sections')
                            ? h('p', { class: 'mt-2 text-xs text-rose-600', role: 'alert' }, fieldError('content_sections'))
                            : null,
                    ]),
                    h('div', { class: 'mt-4 grid gap-4 md:grid-cols-2' }, [
                        field('发送哪天的数据', select(
                            'business_date_rule',
                            metadata.business_date_rules,
                            'manual-notification-business-date-rule'
                        ), '',
                        fieldError('business_date')),
                        field('发送频率', select(
                            'trigger_type',
                            triggerOptions,
                            'manual-notification-trigger'
                        ), '', fieldError('trigger_type')),
                        ...(triggerType === 'daily_fixed_time' ? [
                            field('每日发送时间（北京时间）', input(
                                'planned_send_at',
                                'datetime-local',
                                'manual-notification-planned-time'
                            ), '', fieldError('planned_send_at')),
                        ] : []),
                        ...(!props.operatingDaily && triggerType === 'hourly_on_the_hour' ? [
                            h('div', { class: 'grid grid-cols-2 gap-3' }, [
                                field('小时播报开始', input(
                                    'hourly_start_time',
                                    'time',
                                    'manual-notification-hourly-start',
                                    { step: 3600 }
                                ), '', fieldError('hourly_start_time')),
                                field('小时播报结束', input(
                                    'hourly_end_time',
                                    'time',
                                    'manual-notification-hourly-end',
                                    { step: 3600 }
                                ), '', fieldError('hourly_end_time')),
                            ]),
                        ] : []),
                        ...((!props.operatingDaily || props.strictThreeSourceIntervalAvailable)
                            && triggerType === 'interval_minutes' ? [
                            field('每隔多久发送', input(
                                'interval_minutes',
                                'number',
                                'manual-notification-interval-minutes',
                                props.operatingDaily
                                    ? { min: 30, max: 30, step: 30, readonly: true }
                                    : { min: 5, max: 1440, step: 1 }
                            ), props.operatingDaily ? '酒店 80 三源严格计划固定为每 30 分钟。' : '',
                            fieldError('interval_minutes')),
                            field('首次发送时间', input(
                                'hourly_start_time',
                                'time',
                                'manual-notification-interval-start',
                                { step: 60 }
                            ), '',
                            fieldError('hourly_start_time')),
                        ] : []),
                        ...(!operatingDailyTriggerAllowed ? [
                            h('p', {
                                class: 'md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800',
                                role: 'alert',
                                'data-testid': 'manual-notification-operating-daily-loop-blocked',
                            }, strictIntervalNeedsCorrection
                                ? '该每 30 分钟计划已偏离酒店 80 三源严格合同，请恢复当日、正式群及指定四项内容。'
                                : '该旧计划使用循环发送，现已停止执行。请选择“每日固定时间”并重新保存、测试后再启用。'),
                        ] : []),
                        field('发送条件', select(
                            'condition_type',
                            conditionRules,
                            'manual-notification-condition-type'
                        ), selectedCondition.description || '时间到达后再按经营事实判断是否发送。', fieldError('condition_type')),
                        ...(conditionType === 'occupancy_ladder' ? [
                            field('入住率起始档（%）', input(
                                'condition_threshold',
                                'number',
                                'manual-notification-condition-threshold',
                                { min: 0.01, max: 100, step: 0.01 }
                            ), '首次达到该档位时发送。', fieldError('condition_threshold')),
                            field('跨档步长（百分点）', input(
                                'condition_step',
                                'number',
                                'manual-notification-condition-step',
                                { min: 0.01, max: 100, step: 0.01 }
                            ), '例如起始 20、步长 5：20/25/30…各成功提醒一次。', fieldError('condition_step')),
                        ] : []),
                        ...(conditionType === 'full_house' ? [
                            h('p', {
                                class: 'md:col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-800',
                                'data-testid': 'manual-notification-full-house-rule-help',
                            }, '只读取同酒店、同业务日已保存回读的 PMS 事实；可售归零后首次成功送达才记为已提醒。'),
                        ] : []),
                        ...(conditionType !== 'always' && conditionState ? [
                            h('div', {
                                class: 'md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-700',
                                'data-testid': 'manual-notification-condition-state',
                            }, [
                                h('b', {}, '最近规则状态：'),
                                h('span', {}, `业务日 ${conditionState.business_date || '未取得'}；最近观测 ${conditionState.last_observed_value ?? '未取得'}；已成功档位 ${conditionState.highest_triggered_bucket ?? '尚未触发'}；触发时间 ${conditionState.last_triggered_at || '尚未触发'}`),
                            ]),
                        ] : []),
                        h('fieldset', {
                            class: 'md:col-span-2',
                            'data-testid': 'manual-notification-weekdays',
                            'aria-invalid': fieldError('active_weekdays') ? 'true' : 'false',
                        }, [
                            h('legend', { class: 'mb-2 text-sm font-medium text-slate-700' }, '生效星期'),
                            h('div', { class: 'flex flex-wrap gap-2' }, weekdayOptions.map(option => {
                                const key = Number(option.key);
                                const checked = weekdays.includes(key);
                                return h('label', {
                                    key,
                                    class: `cursor-pointer rounded-lg border px-3 py-2 text-xs font-semibold shadow-sm transition ${checked ? 'border-slate-900 bg-slate-900 text-white ring-2 ring-slate-900/20' : 'border-slate-200 bg-white text-slate-500 hover:border-[#ad8b52]'}`,
                                }, [
                                    h('input', {
                                        type: 'checkbox',
                                        checked,
                                        disabled: checked && weekdays.length === 1,
                                        class: 'sr-only',
                                        onChange: event => {
                                            const next = event.target.checked
                                                ? [...new Set([...weekdays, key])].sort((left, right) => left - right)
                                                : weekdays.filter(day => day !== key);
                                            if (next.length) change('active_weekdays', next);
                                        },
                                    }),
                                    checked ? h('i', {
                                        class: 'fas fa-check mr-1.5 text-[10px]',
                                        'aria-hidden': 'true',
                                    }) : null,
                                    option.label,
                                ]);
                            })),
                            fieldError('active_weekdays')
                                ? h('p', { class: 'mt-2 text-xs text-rose-600', role: 'alert' }, fieldError('active_weekdays'))
                                : null,
                        ]),
                        field('发送方式', select(
                            'send_method',
                            metadata.send_methods,
                            'manual-notification-send-method'
                        ), '', fieldError('send_method')),
                        ...(['wecom_test', 'wecom_formal'].includes(String(form.send_method || '')) ? [
                            field('推送通道', h('div', {
                                class: `rounded-xl border px-3 py-2.5 text-sm ${props.robots.length
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                    : 'border-amber-200 bg-amber-50 text-amber-800'}`,
                                'data-testid': 'manual-notification-formal-robot',
                                'aria-invalid': fieldError('target_robot_id') ? 'true' : 'false',
                            }, props.robots.length
                                ? '当前酒店企业微信群机器人 Webhook 已绑定'
                                : '请先到“推送通道”绑定当前酒店 Webhook'),
                            '',
                            fieldError('target_robot_id')),
                        ] : []),
                    ]),
                    h('label', {
                        class: 'mt-4 flex items-center justify-between rounded-xl border border-slate-200 bg-white p-3',
                    }, [
                        h('b', { class: 'text-sm text-slate-800' }, '启用自动发送'),
                        h('input', {
                            type: 'checkbox',
                            checked: form.enabled === true,
                            class: 'h-5 w-5 accent-[#315d50]',
                            'data-testid': 'manual-notification-enabled',
                            onChange: event => change('enabled', event.target.checked),
                        }),
                    ]),
                    h('details', {
                        class: 'mt-4 rounded-xl border border-amber-200 bg-amber-50',
                        'data-testid': 'manual-notification-fixed-policies',
                    }, [
                        h('summary', { class: 'flex cursor-pointer items-center justify-between px-3 py-2.5' }, [
                            h('b', { class: 'text-sm text-amber-900' }, '安全规则'),
                            h('span', { class: 'text-xs text-amber-700' }, '固定'),
                        ]),
                        h('div', { class: 'grid gap-1 border-t border-amber-200 px-3 py-3 text-xs leading-5 text-amber-800 sm:grid-cols-2' }, [
                            h('span', {}, `缺数据：${policies.missing_data || '可信事实不足时阻断正式消息'}`),
                            h('span', {}, `漏跑：${policies.missed_window || '超过调度窗口不补发'}`),
                            h('span', {}, `结果不明：${policies.unknown_outcome || '不自动重发'}`),
                            h('span', {}, `失败处理：${policies.retry || '明确失败可人工重试；结果不明仅在确认可能重复送达后重试'}`),
                        ]),
                    ]),
                    h('details', {
                        class: 'mt-4 rounded-xl border border-slate-200 bg-white',
                        'data-testid': 'manual-notification-runtime-status',
                    }, [
                        h('summary', { class: 'flex cursor-pointer items-center justify-between gap-3 px-3 py-2.5' }, [
                            h('b', { class: 'text-sm text-slate-800' }, '运行详情'),
                            h('span', {
                                class: `truncate text-xs ${blocker === '无已知阻断' ? 'text-slate-500' : 'text-amber-700'}`,
                                'data-testid': 'manual-notification-current-blocker',
                            }, blocker === '无已知阻断' ? (form.next_run_at || '暂无下次运行') : blocker),
                        ]),
                        h('dl', { class: 'grid gap-2 border-t border-slate-100 p-3 text-xs sm:grid-cols-2' }, [
                            summary('上次运行', lastRun),
                            summary('上次回执', lastReceipt),
                            summary('当前阻断原因', blocker),
                            summary('云端调度', schedulerReady ? '已取得当前作用域运行证据' : (metadata.scheduler_note || '未验证')),
                        ]),
                    ]),
                ]);
            };
        },
    };
})(window);
