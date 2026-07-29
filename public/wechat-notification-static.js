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
                    class: 'mx-auto max-w-5xl',
                    'data-testid': 'wechat-notification-panel',
                }, [
                    h('section', { class: 'rounded-2xl border border-slate-200 bg-white p-6 shadow-sm' }, [
                        h('div', { class: 'flex flex-col gap-4 border-b border-slate-100 pb-5 lg:flex-row lg:items-start lg:justify-between' }, [
                            h('div', {}, [
                                h('div', { class: 'text-sm font-medium text-emerald-700' }, '当前酒店推送通道'),
                                h('h2', { class: 'mt-1 text-2xl font-bold text-slate-900' },
                                    selectedHotel?.name || '请选择当前酒店'),
                                h('p', { class: 'mt-2 max-w-2xl text-sm leading-6 text-slate-500' },
                                    '当前酒店只绑定一个企业微信群机器人 Webhook；携程、美团和 PMS 的计划统一使用这个通道。'),
                            ]),
                            h('span', {
                                class: `inline-flex items-center rounded-full border px-3 py-1.5 text-sm font-medium ${props.statusClass}`,
                                'data-testid': 'wechat-notification-status',
                            }, props.statusText),
                        ]),
                        h('form', {
                            class: 'mt-5',
                            'data-testid': 'wechat-notification-form',
                            onSubmit: (event) => {
                                event.preventDefault();
                                emit('save');
                            },
                        }, [
                            h('div', { class: 'grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]' }, [
                                h('label', { class: 'block' }, [
                                    h('span', { class: 'mb-2 block text-sm font-medium text-slate-700' },
                                        binding ? '替换企业微信群机器人 Webhook' : '企业微信群机器人 Webhook'),
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
                                    h('span', { class: 'mt-2 block text-xs leading-5 text-slate-500' },
                                        '完整地址会加密保存且不会回显；输入新地址并保存即可替换。'),
                                ]),
                                h('div', { class: 'flex items-end gap-2' }, [
                                    h('button', {
                                        type: 'submit',
                                        disabled: busy || !props.hotelId || !String(props.form?.webhook || '').trim(),
                                        class: 'rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50',
                                        'data-testid': 'wechat-notification-save',
                                    }, props.saving ? '保存中...' : (binding ? '更新 Webhook' : '保存 Webhook')),
                                    h('button', {
                                        type: 'button',
                                        disabled: busy || !binding,
                                        class: 'rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50',
                                        'data-testid': 'wechat-notification-test',
                                        onClick: () => emit('test'),
                                    }, props.testing ? '发送中...' : '测试通道'),
                                ]),
                            ]),
                            h('div', { class: 'mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3' }, [
                                detail('当前酒店', selectedHotel?.name || '未选择'),
                                detail('Webhook', binding?.webhook_masked || '未绑定', 'wechat-notification-mask'),
                                detail('最近送达', props.lastTestText, 'wechat-notification-last-test'),
                            ]),
                            h('div', { class: 'mt-4 text-xs leading-5 text-slate-500' },
                                '酒店由页面顶部统一选择，不会绑定到其他酒店；自动推送计划只读取当前酒店的这个通道。'),
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
                }, option?.label || option?.name || '未命名')),
            ]);

            return () => {
                const metadata = props.metadata || {};
                const form = props.form || {};
                const triggerType = String(form.trigger_type || 'manual_test');
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
                const scheduleRun = metadata.latest_schedule_run || {};
                const schedulerReady = [
                    'test_scope_ready',
                    'formal_scope_ready',
                    'schedule_enabled',
                    'connected',
                ].includes(String(metadata.scheduler_status || ''));
                const lastRun = scheduleRun.observed_at
                    || latest?.claimed_at
                    || latest?.last_attempt_at
                    || '未取得云端运行记录';
                const lastReceipt = latest
                    ? `${latest.status === 'sent' ? '已送达' : latest.status === 'failed' ? '发送失败' : latest.status === 'blocked' ? '门禁阻断' : latest.status === 'outcome_unknown' ? '结果不明确' : '执行记录未取得'} · ${latest.dispatched_at || latest.last_attempt_at || latest.claimed_at || '时间未取得'}`
                    : '未取得发送回执';
                const blocker = props.error
                    || (!form.enabled
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

                return h('section', {
                    class: 'rounded-2xl border border-[#eadfc9] bg-[#fffdf8] p-4',
                    'data-testid': 'manual-notification-schedule-settings',
                }, [
                    h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between' }, [
                        h('div', {}, [
                            h('h3', { class: 'font-semibold text-slate-900' }, '自动发送设置'),
                            h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' },
                                '携程、美团、PMS 分别保存自己的发送内容、时间和频率；计划自动使用当前酒店唯一推送通道。'),
                        ]),
                        h('span', {
                            class: `rounded-full border px-2.5 py-1 text-xs font-medium ${form.enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-white text-slate-600'}`,
                            'data-testid': 'manual-notification-plan-toggle-status',
                        }, form.enabled ? '本计划已开启' : '本计划已暂停'),
                    ]),
                    h('div', { class: 'mt-4 grid grid-cols-2 gap-2 lg:grid-cols-4' }, [
                        summary('数据范围', props.dataScopeLabel, 'manual-notification-data-scope'),
                        summary('数据日期', dateRuleLabel, 'manual-notification-date-rule-summary'),
                        summary('计划状态', form.schedule_status_label || props.dataStatus),
                        summary('下次运行', form.next_run_at || '保存并通过测试后计算', 'manual-notification-next-run'),
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
                            }, [
                                h('b', { class: 'block text-sm' }, source?.label || source?.key || '未命名来源'),
                                h('span', { class: 'mt-1 block text-xs leading-5' }, source?.description || ''),
                            ]);
                        })),
                        customWholeReport
                            ? h('p', { class: 'mt-2 text-xs leading-5 text-amber-700' },
                                '自定义全文模板可能引用全部三源变量，因此保持三源汇总；如需单源计划，请使用通用模板。')
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
                        h('p', { class: 'mt-2 text-xs leading-5 text-slate-500' },
                            '来源证据、同店同日回读状态和 OTA/全酒店范围说明固定附带，不能关闭。'),
                        fieldError('content_sections')
                            ? h('p', { class: 'mt-2 text-xs text-rose-600', role: 'alert' }, fieldError('content_sections'))
                            : null,
                    ]),
                    h('div', { class: 'mt-4 grid gap-4 md:grid-cols-2' }, [
                        field('发送哪天的数据', select(
                            'business_date_rule',
                            metadata.business_date_rules,
                            'manual-notification-business-date-rule'
                        ), '发送当天数据：每次取发送当天；发送前一天数据：每次取发送日期的前一天。消息预览也使用同一规则。',
                        fieldError('business_date')),
                        field('发送频率', select(
                            'trigger_type',
                            metadata.trigger_types,
                            'manual-notification-trigger'
                        ), '', fieldError('trigger_type')),
                        ...(triggerType === 'daily_fixed_time' ? [
                            field('每日发送时间（北京时间）', input(
                                'planned_send_at',
                                'datetime-local',
                                'manual-notification-planned-time'
                            ), '每天复用所选的时、分。', fieldError('planned_send_at')),
                        ] : []),
                        ...(triggerType === 'hourly_on_the_hour' ? [
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
                        ...(triggerType === 'interval_minutes' ? [
                            field('每隔多久发送', input(
                                'interval_minutes',
                                'number',
                                'manual-notification-interval-minutes',
                                { min: 5, max: 1440, step: 1 }
                            ), '允许 5–1440 分钟；每个发送时点只会认领一次。',
                            fieldError('interval_minutes')),
                            field('首次发送时间', input(
                                'hourly_start_time',
                                'time',
                                'manual-notification-interval-start',
                                { step: 60 }
                            ), '当天从这个时间开始按间隔发送，23:59 自动结束，次日重新开始。',
                            fieldError('hourly_start_time')),
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
                            '无需重复选择通知群；保存计划时自动写入当前酒店唯一通道。',
                            fieldError('target_robot_id')),
                        ] : []),
                    ]),
                    h('label', {
                        class: 'mt-4 flex items-center justify-between rounded-xl border border-slate-200 bg-white p-3',
                    }, [
                        h('span', {}, [
                            h('b', { class: 'block text-sm text-slate-800' }, '启用或暂停本计划'),
                            h('span', { class: 'mt-1 block text-xs text-slate-500' },
                                '暂停后不进入调度；重新启用时保留未变更计划的测试凭据。'),
                        ]),
                        h('input', {
                            type: 'checkbox',
                            checked: form.enabled === true,
                            class: 'h-5 w-5 accent-[#315d50]',
                            'data-testid': 'manual-notification-enabled',
                            onChange: event => change('enabled', event.target.checked),
                        }),
                    ]),
                    h('div', {
                        class: 'mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800',
                        'data-testid': 'manual-notification-fixed-policies',
                    }, [
                        h('b', { class: 'block text-sm' }, '系统固定策略（不可关闭）'),
                        h('div', { class: 'mt-2 grid gap-1 sm:grid-cols-2' }, [
                            h('span', {}, `缺数据：${policies.missing_data || '可信事实不足时阻断正式消息'}`),
                            h('span', {}, `漏跑：${policies.missed_window || '超过调度窗口不补发'}`),
                            h('span', {}, `结果不明：${policies.unknown_outcome || '不自动重发'}`),
                            h('span', {}, `失败处理：${policies.retry || '明确失败可人工重试；结果不明仅在确认可能重复送达后重试'}`),
                        ]),
                    ]),
                    h('dl', {
                        class: 'mt-4 grid gap-2 text-xs sm:grid-cols-2',
                        'data-testid': 'manual-notification-runtime-status',
                    }, [
                        summary('上次运行', lastRun),
                        summary('上次回执', lastReceipt),
                        summary('当前阻断原因', blocker, 'manual-notification-current-blocker'),
                        summary('云端调度', schedulerReady ? '已取得当前作用域运行证据' : (metadata.scheduler_note || '未验证')),
                    ]),
                ]);
            };
        },
    };
})(window);
