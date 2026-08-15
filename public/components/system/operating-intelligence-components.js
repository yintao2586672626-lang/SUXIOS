(() => {
    'use strict';

    const create = ({ ref, computed, inject, h, nextTick, onMounted, onUnmounted }) => {
    const operatingQuestionPanel = {
        setup() {
            const ui = inject('operatingQuestionUi');
            const valueOf = (value, fallback = {}) => (
                value && typeof value === 'object' && 'value' in value
                    ? (value.value ?? fallback)
                    : (value ?? fallback)
            );
            return () => {
                const state = valueOf(ui?.state);
                const form = valueOf(ui?.form);
                const selectedHotel = valueOf(ui?.selectedHotel, null);
                const result = state.result || null;
                const evidence = result?.answer?.evidence_counts || {};
                const children = [
                    h('div', { class: 'flex flex-col gap-3 lg:flex-row lg:items-end' }, [
                        h('div', { class: 'min-w-0 flex-1' }, [
                            h('div', { class: 'text-sm font-semibold text-indigo-900' }, '经营问答 · 统一 Agent 入口'),
                            h('div', { class: 'mt-1 text-xs text-indigo-700' }, `当前范围：${selectedHotel?.name || `酒店 #${form.hotel_id || '未选择'}`} · ${form.platform || '未选择平台'} · ${form.date_start || '未选择日期'} 至 ${form.date_end || '未选择日期'}。只读已保存证据，不写 OTA、不外发。`),
                            h('input', {
                                value: String(state.question || ''),
                                disabled: Boolean(state.loading),
                                class: 'mt-3 w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm disabled:opacity-60',
                                placeholder: '例如：这家店今天最需要复核什么？',
                                onInput: (event) => { state.question = String(event?.target?.value || ''); },
                                onKeydown: (event) => {
                                    if (event?.key !== 'Enter' || event.isComposing || state.loading) return;
                                    event.preventDefault();
                                    ui?.ask?.();
                                },
                            }),
                        ]),
                        h('button', {
                            type: 'button',
                            disabled: Boolean(state.loading),
                            class: 'rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50',
                            onClick: () => ui?.ask?.(),
                        }, state.loading ? '回读中…' : '提交并回读'),
                    ]),
                ];
                if (state.error) {
                    children.push(h('div', {
                        class: 'mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700',
                        'data-testid': 'operating-question-error',
                    }, String(state.error)));
                }
                if (result) {
                    const answerChildren = [
                        h('div', { class: 'flex flex-wrap items-center gap-2 text-xs' }, [
                            h('span', { class: 'font-semibold text-indigo-800' }, String(result.answer_status || '已回读')),
                            h('span', { class: 'text-gray-500' }, `事实 ${Number(evidence.facts || 0)} · 知识 ${Number(evidence.knowledge_chunks || 0)} · 记忆 ${Number(evidence.operating_memories || 0)} · 复盘 ${Number(evidence.execution_reviews || 0)}`),
                        ]),
                        h('p', { class: 'mt-2 text-sm leading-6 text-gray-700' }, String(result.answer_summary || '')),
                    ];
                    if (Array.isArray(result.data_gaps) && result.data_gaps.length) {
                        answerChildren.push(h('ul', { class: 'mt-2 list-disc pl-5 text-xs text-amber-700' }, result.data_gaps.map((gap, index) => (
                            h('li', { key: String(gap?.code || index) }, String(gap?.message || gap?.code || '未说明的数据缺口'))
                        ))));
                    }
                    const actionDrafts = Array.isArray(result.answer?.action_drafts)
                        ? result.answer.action_drafts.slice(0, 1)
                        : [];
                    actionDrafts.forEach((action, actionIndex) => {
                        const actionKey = `${Number(result.id || 0)}:${actionIndex}`;
                        const intent = state.action_intents?.[actionKey] || null;
                        const ready = ui?.isActionReady?.(result, action, form) === true;
                        const steps = Array.isArray(action?.execution_steps) ? action.execution_steps : [];
                        const controls = Array.isArray(action?.risk?.controls) ? action.risk.controls : [];
                        const stops = Array.isArray(action?.stop_conditions) ? action.stop_conditions : [];
                        const evidenceRefs = Array.isArray(action?.evidence_refs) ? action.evidence_refs : [];
                        const actionChildren = [
                            h('div', { class: 'flex flex-wrap items-start justify-between gap-2' }, [
                                h('div', [
                                    h('div', { class: 'text-xs font-semibold uppercase tracking-wide text-[#8c6a2d]' }, 'AI 行动草案 · 待人工确认'),
                                    h('h4', { class: 'mt-1 text-sm font-semibold text-slate-900' }, String(action?.title || '运营复核草案')),
                                ]),
                                h('span', {
                                    class: ['rounded-full px-2 py-1 text-[11px] font-semibold', ready ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'],
                                }, ready ? '证据门已通过' : '需补齐后提交'),
                            ]),
                            h('p', { class: 'mt-2 text-sm leading-6 text-slate-700' }, String(action?.action || '')),
                            h('div', { class: 'mt-2 flex flex-wrap gap-1.5 text-[11px] text-slate-600' }, [
                                h('span', { class: 'rounded bg-slate-100 px-2 py-1' }, `范围锁定：${result.platform} · ${result.date_start}${result.date_end !== result.date_start ? ` 至 ${result.date_end}` : ''}`),
                                h('span', { class: 'rounded bg-slate-100 px-2 py-1' }, `复核指标：${String(action?.expected_metric || '待补齐')}`),
                                h('span', { class: 'rounded bg-slate-100 px-2 py-1' }, `风险：${String(action?.risk?.level || action?.risk_level || '未评估')}`),
                            ]),
                        ];
                        if (steps.length) {
                            actionChildren.push(h('ol', { class: 'mt-3 list-decimal space-y-1 pl-5 text-xs leading-5 text-slate-700' }, steps.map((step, index) => (
                                h('li', { key: `step-${index}-${String(step)}` }, String(step))
                            ))));
                        }
                        actionChildren.push(h('div', { class: 'mt-3 grid gap-2 text-xs text-slate-600 md:grid-cols-2' }, [
                            h('div', { class: 'rounded-lg border border-slate-200 bg-white p-2.5' }, [
                                h('strong', { class: 'text-slate-800' }, '效果复核'),
                                h('p', { class: 'mt-1 leading-5' }, String(action?.expected_effect?.summary || '仅作为复核目标，不承诺经营提升。')),
                                h('p', { class: 'mt-1 leading-5' }, String(action?.review_window || '按同口径前后窗口复核')),
                            ]),
                            h('div', { class: 'rounded-lg border border-slate-200 bg-white p-2.5' }, [
                                h('strong', { class: 'text-slate-800' }, '风险与停止条件'),
                                h('p', { class: 'mt-1 leading-5' }, String(action?.risk?.summary || '执行前仍需人工核对风险。')),
                                controls.length || stops.length
                                    ? h('ul', { class: 'mt-1 list-disc pl-4 leading-5' }, [...controls, ...stops.map(item => `停止：${item}`)].slice(0, 6).map((item, index) => (
                                        h('li', { key: `guard-${index}-${String(item)}` }, String(item))
                                    )))
                                    : null,
                            ]),
                        ]));
                        if (!ready) {
                            actionChildren.push(h('p', {
                                class: 'mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800',
                                'data-testid': 'operating-question-action-blocked',
                            }, String(action?.blocked_reason || '行动草案缺少完整证据、步骤或停止条件，暂不能提交。')));
                        } else {
                            actionChildren.push(h('div', { class: 'mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-3' }, [
                                h('div', { class: 'text-[11px] leading-5 text-slate-500' }, [
                                    h('span', `已绑定 ${evidenceRefs.length} 条严格事实引用。`),
                                    h('br'),
                                    h('span', '点击只提交本地待审批意图；不会自动批准、采集或写 OTA。'),
                                ]),
                                h('button', {
                                    type: 'button',
                                    disabled: Boolean(state.action_loading),
                                    class: 'rounded-lg bg-[#a88a52] px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-[#8f733f] disabled:opacity-50',
                                    'data-testid': intent ? 'operating-question-action-open' : 'operating-question-action-submit',
                                    onClick: () => intent
                                        ? ui?.openActionIntent?.(intent)
                                        : ui?.createActionIntent?.(action, actionIndex),
                                }, state.action_loading === actionKey
                                    ? '提交并回读中…'
                                    : (intent ? `查看${String(intent.status || '待审批')}任务 #${Number(intent.id || 0)}` : '确认并提交待审批')),
                            ]));
                        }
                        answerChildren.push(h('section', {
                            class: 'mt-3 rounded-xl border border-[#d8c7a5] bg-[#fbf8f1] p-3',
                            'data-testid': 'operating-question-action-card',
                        }, actionChildren));
                    });
                    if (state.action_error) {
                        answerChildren.push(h('div', {
                            class: 'mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700',
                            'data-testid': 'operating-question-action-error',
                        }, String(state.action_error)));
                    }
                    children.push(h('div', {
                        class: 'mt-3 rounded-lg border border-indigo-100 bg-white p-3',
                        'data-testid': 'operating-question-readback',
                    }, answerChildren));
                }
                return h('section', {
                    class: 'mb-4 rounded-xl border border-indigo-100 bg-indigo-50/60 p-4',
                    'data-testid': 'operating-question-entry',
                }, children);
            };
        },
    };
    // The evidence consultant remains available in the dedicated Agent surface.
    // The global floating entry is intentionally reassigned below to a system-use
    // guide so navigation help and operating conclusions no longer share one UI.
    const operatingEvidenceConsultant = {
        name: 'OperatingQuestionConsultant',
        props: {
            ctx: { type: Object, required: true },
        },
        setup(props) {
            const icon = (name) => h('i', { class: `fas ${name}`, 'aria-hidden': 'true' });
            const textList = (value) => Array.isArray(value) ? value : [];
            const updateScope = (field, value) => {
                const ctx = props.ctx;
                if (!ctx?.operatingQuestionForm) return;
                if (ctx.operatingQuestionState?.loading) return;
                ctx.operatingQuestionForm[field] = String(value || '');
                if (ctx.operatingQuestionState) {
                    ctx.operatingQuestionState.error = '';
                    ctx.operatingQuestionState.result = null;
                }
            };
            const applyQuestion = (question) => {
                props.ctx?.applyOperatingQuestionSuggestion?.(question);
            };
            const ask = () => props.ctx?.askOperatingQuestion?.();
            const selectField = ({ label, field, options, testId, wide = false }) => h('label', {
                class: ['sx-ai-consultant-field', wide ? 'sx-ai-consultant-field-wide' : ''],
            }, [
                h('span', label),
                h('select', {
                    value: String(props.ctx?.operatingQuestionForm?.[field] || ''),
                    disabled: Boolean(props.ctx?.operatingQuestionState?.loading),
                    'data-testid': testId,
                    onChange: (event) => updateScope(field, event?.target?.value),
                }, options.map((option) => h('option', {
                    key: String(option.value),
                    value: String(option.value),
                }, String(option.label)))),
            ]);
            const dateField = (label, field, testId) => h('label', {
                class: 'sx-ai-consultant-field',
            }, [
                h('span', label),
                h('input', {
                    type: 'date',
                    value: String(props.ctx?.operatingQuestionForm?.[field] || ''),
                    disabled: Boolean(props.ctx?.operatingQuestionState?.loading),
                    'data-testid': testId,
                    onInput: (event) => updateScope(field, event?.target?.value),
                }),
            ]);
            const runRecoveryAction = async (action, recoveryPlan, result) => {
                const ctx = props.ctx || {};
                const state = ctx.operatingQuestionState || {};
                const form = ctx.operatingQuestionForm || {};
                if (state.loading) return false;
                const scope = recoveryPlan?.scope && typeof recoveryPlan.scope === 'object'
                    ? recoveryPlan.scope
                    : {};
                const key = String(action?.key || '').trim();
                const hotelId = Number(scope.hotel_id || 0);
                const scopePlatform = String(scope.platform || '').trim();
                const dateStart = String(scope.date_start || '').trim();
                const dateEnd = String(scope.date_end || '').trim();
                const scopeMatches = hotelId > 0
                    && Number(result?.hotel_id || 0) === hotelId
                    && String(result?.platform || '') === scopePlatform
                    && String(result?.date_start || '') === dateStart
                    && String(result?.date_end || '') === dateEnd
                    && Number(form.hotel_id || 0) === hotelId
                    && String(form.platform || '') === scopePlatform
                    && String(form.date_start || '') === dateStart
                    && String(form.date_end || '') === dateEnd;
                const fail = (message) => {
                    state.error = message;
                    return false;
                };
                if (!scopeMatches || recoveryPlan?.status !== 'waiting_for_verified_fact') {
                    return fail('问答范围已变化，不能使用旧范围的补全动作；请按当前范围重新提问。');
                }
                if (key === 'recheck') {
                    await ctx.askOperatingQuestion?.();
                    return true;
                }
                if (action?.read_only !== true || !['open_data_health', 'open_platform_collection_status'].includes(key)) {
                    return fail('该证据补全动作不在只读白名单内，已停止。');
                }
                const targetPlatform = String(action?.platform || '').trim();
                const businessDate = String(action?.date || '').trim();
                const exactDate = (value) => {
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
                    const parsed = new Date(`${value}T00:00:00Z`);
                    return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
                };
                const businessDateInScope = exactDate(dateStart)
                    && exactDate(dateEnd)
                    && dateStart <= dateEnd
                    && exactDate(businessDate)
                    && businessDate >= dateStart
                    && businessDate <= dateEnd;
                const missingItems = textList(recoveryPlan?.missing_items);
                const isMissingItem = missingItems.some((item) => (
                    String(item?.platform || '') === targetPlatform
                    && String(item?.date || '') === businessDate
                ));
                const platformAllowed = scopePlatform === 'all_ota'
                    ? ['ctrip', 'meituan'].includes(targetPlatform)
                    : targetPlatform === scopePlatform;
                const allowedTargets = key === 'open_data_health'
                    ? { page: 'online-data', tab: 'data-health' }
                    : (targetPlatform === 'ctrip'
                        ? { page: 'ctrip-ebooking', tab: 'data-health' }
                        : { page: 'meituan-ebooking', tab: 'meituan-ranking' });
                if (!platformAllowed
                    || !businessDateInScope
                    || !isMissingItem
                    || String(action?.target_page || '') !== allowedTargets.page
                    || String(action?.target_tab || '') !== allowedTargets.tab
                ) {
                    return fail('证据补全动作与已保存的酒店、平台或日期不一致，已停止跳转。');
                }
                try {
                    return await ctx.openRevenueAiGap?.({
                        target_page: allowedTargets.page,
                        target_tab: allowedTargets.tab,
                        target_platform: targetPlatform,
                        hotel_id: hotelId,
                        business_date: businessDate,
                    });
                } catch (error) {
                    return fail(error?.message || '打开证据补全入口失败');
                }
            };

            return () => {
                const ctx = props.ctx || {};
                const form = ctx.operatingQuestionForm || {};
                const state = ctx.operatingQuestionState || {};
                const result = state.result || null;
                const answer = result?.answer || {};
                const evidence = answer.evidence_counts || {};
                const hotelOptions = [
                    { value: '', label: '请选择单个酒店' },
                    ...textList(ctx.otaDiagnosisHotelOptions).map((hotel) => ({
                        value: hotel?.value || '',
                        label: hotel?.name || `酒店 ${hotel?.value || ''}`,
                    })),
                ];
                const modelOptions = [
                    { value: '', label: 'DeepSeek V4 默认' },
                    ...textList(ctx.availableAiModelOptions).filter((model) => (
                        String(model?.value || '').toLowerCase().includes('deepseek')
                    )).map((model) => ({
                        value: model?.value || '',
                        label: model?.label || model?.value || '模型',
                    })),
                ];
                const conversation = [];

                if (!result && !state.loading && !state.error) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-welcome' }, [
                        h('div', { class: 'sx-ai-consultant-message-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                        h('div', [
                            h('strong', '你好，我是宿析智能咨询。'),
                            h('p', '我用严格回读的经营事实回答，并从你的知识库检索相关SOP作解释；知识不会替代缺失事实。'),
                        ]),
                    ]));
                }
                if (state.loading) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-loading', role: 'status' }, [
                        icon('fa-spinner fa-spin'),
                        h('span', '正在读取证据并生成回答…'),
                    ]));
                }
                if (state.error) {
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-error',
                        role: 'alert',
                        'data-testid': 'operating-question-floating-error',
                    }, [icon('fa-exclamation-circle'), h('span', String(state.error))]));
                }
                if (result) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-user-message' }, [
                        h('p', String(result.question_text || '')),
                    ]));
                    const answerChildren = [
                        h('div', { class: 'sx-ai-consultant-answer-meta' }, [
                            h('span', {
                                class: ['sx-ai-consultant-status', result.answer_status === 'blocked_by_missing_facts' ? 'is-blocked' : ''],
                            }, String(ctx.operatingQuestionAnswerStatusText?.(result.answer_status) || '已严格回读')),
                            answer.confidence
                                ? h('span', String(ctx.operatingQuestionConfidenceText?.(answer.confidence) || ''))
                                : null,
                        ].filter(Boolean)),
                        h('p', { class: 'sx-ai-consultant-answer-summary' }, String(result.answer_summary || '')),
                    ];
                    const keyPoints = textList(answer.key_points);
                    if (keyPoints.length) {
                        answerChildren.push(h('ul', { class: 'sx-ai-consultant-key-points' }, keyPoints.map((point) => (
                            h('li', { key: String(point) }, String(point))
                        ))));
                    }
                    const gaps = textList(result.data_gaps);
                    const missingInformation = textList(answer.missing_information);
                    if (gaps.length || missingInformation.length) {
                        answerChildren.push(h('div', { class: 'sx-ai-consultant-gaps' }, [
                            h('strong', '仍缺少'),
                            h('ul', [
                                ...gaps.map((gap, index) => h('li', {
                                    key: `gap-${String(gap?.code || index)}`,
                                }, String(gap?.message || gap?.code || '未说明的数据缺口'))),
                                ...missingInformation.map((item, index) => h('li', {
                                    key: `missing-${index}-${String(item)}`,
                                }, String(item))),
                            ]),
                        ]));
                    }
                    const recoveryPlan = answer.recovery_plan && typeof answer.recovery_plan === 'object'
                        ? answer.recovery_plan
                        : null;
                    if (recoveryPlan?.status === 'waiting_for_verified_fact') {
                        const missingItems = textList(recoveryPlan.missing_items);
                        const recoveryActions = textList(recoveryPlan.actions);
                        answerChildren.push(h('section', {
                            class: 'sx-ai-consultant-recovery',
                            'data-testid': 'operating-question-recovery-card',
                        }, [
                            h('div', { class: 'sx-ai-consultant-recovery-title' }, [
                                icon('fa-shield-alt'),
                                h('strong', '证据补全卡'),
                            ]),
                            h('p', `${ctx.operatingQuestionSelectedHotel?.name || `酒店 #${Number(result.hotel_id || 0)}`} · ${ctx.operatingQuestionPlatformText?.(result.platform) || result.platform} · ${result.date_start}${result.date_end !== result.date_start ? ` 至 ${result.date_end}` : ''}`),
                            missingItems.length
                                ? h('ul', missingItems.map((item) => h('li', {
                                    key: `${String(item?.platform || '')}-${String(item?.date || '')}`,
                                }, `缺少 ${ctx.operatingQuestionPlatformText?.(item?.platform) || item?.platform} · ${String(item?.date || '')} · 严格保存回读事实`)))
                                : null,
                            h('div', { class: 'sx-ai-consultant-recovery-actions' }, recoveryActions.map((action) => h('button', {
                                key: `${String(action?.key || '')}-${String(action?.platform || 'scope')}`,
                                type: 'button',
                                disabled: Boolean(state.loading),
                                'data-testid': `operating-question-recovery-${String(action?.key || 'unknown')}-${String(action?.platform || 'scope')}`,
                                onClick: () => runRecoveryAction(action, recoveryPlan, result),
                            }, [
                                icon(action?.key === 'recheck' ? 'fa-sync-alt' : 'fa-arrow-right'),
                                h('span', String(action?.label || '查看补全入口')),
                            ]))),
                            h('small', '只读导航与重新核验，不会自动采集、调用模型补事实或写入 OTA。'),
                        ].filter(Boolean)));
                    }
                    answerChildren.push(h('div', { class: 'sx-ai-consultant-evidence' }, [
                        h('span', String(ctx.operatingQuestionAiRuntimeText?.(result) || '严格证据回读模式')),
                        h('span', `事实 ${Number(evidence.facts || 0)} · 知识 ${Number(evidence.knowledge_chunks || 0)} · 记忆 ${Number(evidence.operating_memories || 0)} · 复盘 ${Number(evidence.execution_reviews || 0)}`),
                        h('span', `已保存并严格回读 #${Number(result.id || 0)}`),
                    ]));
                    const knowledgeResources = textList(answer.knowledge_resources);
                    if (knowledgeResources.length) {
                        answerChildren.push(h('details', { class: 'sx-ai-consultant-evidence-refs' }, [
                            h('summary', `查看知识库参考（${knowledgeResources.length}）`),
                            h('ul', knowledgeResources.map((resource, index) => h('li', {
                                key: String(resource?.ref || index),
                            }, `${String(resource?.name || resource?.ref || '知识片段')} · ${String(resource?.usage_policy || 'reference_only')} · ${String(resource?.excerpt || '')}`))),
                        ]));
                    }
                    const evidenceRefs = Array.from(new Set([
                        ...textList(answer.used_evidence_refs),
                        ...textList(result.fact_refs),
                        ...textList(result.memory_refs),
                        ...textList(result.knowledge_refs),
                        ...textList(result.execution_refs),
                        ...textList(answer.diagnosis_refs),
                    ])).slice(0, 20);
                    if (evidenceRefs.length) {
                        answerChildren.push(h('details', {
                            class: 'sx-ai-consultant-evidence-refs',
                            'data-testid': 'operating-question-floating-evidence',
                        }, [
                            h('summary', `查看使用证据（${evidenceRefs.length}）`),
                            h('ul', evidenceRefs.map((ref) => h('li', { key: ref }, String(ref)))),
                        ]));
                    }
                    conversation.push(h('article', {
                        class: 'sx-ai-consultant-answer',
                        'data-testid': 'operating-question-floating-readback',
                    }, answerChildren));

                    const followUps = textList(answer.follow_up_questions);
                    if (followUps.length) {
                        conversation.push(h('div', { class: 'sx-ai-consultant-follow-ups' }, [
                            h('span', '继续追问'),
                            ...followUps.map((question) => h('button', {
                                key: String(question),
                                type: 'button',
                                onClick: () => applyQuestion(question),
                            }, String(question))),
                        ]));
                    }
                }

                const scopeFields = [
                    selectField({
                        label: '酒店',
                        field: 'hotel_id',
                        options: hotelOptions,
                        testId: 'operating-question-hotel',
                        wide: true,
                    }),
                    selectField({
                        label: '平台',
                        field: 'platform',
                        options: [
                            { value: 'ctrip', label: '携程' },
                            { value: 'meituan', label: '美团' },
                            { value: 'all_ota', label: '携程+美团 OTA' },
                        ],
                        testId: 'operating-question-platform',
                    }),
                    selectField({
                        label: '模型',
                        field: 'model_key',
                        options: modelOptions,
                        testId: 'operating-question-model',
                    }),
                    dateField('开始日期', 'date_start', 'operating-question-date-start'),
                    dateField('结束日期', 'date_end', 'operating-question-date-end'),
                ];
                const scopeDetails = h('details', {
                    class: 'sx-ai-consultant-scope',
                    open: !form.hotel_id,
                }, [
                    h('summary', [
                        h('span', [icon('fa-hotel'), ' 问答范围']),
                        h('span', { class: 'sx-ai-consultant-scope-current' }, `${ctx.operatingQuestionSelectedHotel?.name || '请选择酒店'} · ${ctx.operatingQuestionPlatformText?.(form.platform) || '未选择平台'}`),
                    ]),
                    h('div', { class: 'sx-ai-consultant-scope-grid' }, scopeFields),
                    form.platform === 'all_ota'
                        ? h('p', { class: 'sx-ai-consultant-boundary' }, '只合并同酒店、同日期已严格回读的携程与美团 OTA 事实，不包含 PMS，也不代表全酒店经营。')
                        : null,
                ].filter(Boolean));
                const suggestions = h('div', {
                    class: 'sx-ai-consultant-suggestions',
                    'aria-label': '常用问题',
                }, textList(ctx.operatingQuestionSuggestions).map((suggestion) => h('button', {
                    key: String(suggestion),
                    type: 'button',
                    onClick: () => applyQuestion(suggestion),
                }, String(suggestion))));
                const composer = h('form', {
                    class: 'sx-ai-consultant-composer',
                    onSubmit: (event) => {
                        event?.preventDefault?.();
                        ask();
                    },
                }, [
                    h('textarea', {
                        value: String(state.question || ''),
                        maxlength: 1000,
                        rows: 2,
                        placeholder: '输入经营问题，Enter 发送，Shift+Enter 换行',
                        'data-testid': 'operating-question-floating-input',
                        onInput: (event) => { state.question = String(event?.target?.value || ''); },
                        onKeydown: (event) => {
                            if (event?.key !== 'Enter' || event.shiftKey || event.isComposing) return;
                            event.preventDefault();
                            ask();
                        },
                    }),
                    h('button', {
                        type: 'submit',
                        disabled: Boolean(state.loading) || !String(state.question || '').trim(),
                        'data-testid': 'operating-question-floating-submit',
                        'aria-label': '发送问题',
                    }, [icon(state.loading ? 'fa-spinner fa-spin' : 'fa-paper-plane')]),
                    h('p', '只读问答 · 不改价、不改库存、不自动执行'),
                ]);

                return h('details', {
                    class: 'sx-ai-consultant',
                    'data-testid': 'operating-question-floating-entry',
                }, [
                    h('summary', {
                        class: 'sx-ai-consultant-launcher',
                        'data-testid': 'operating-question-floating-launcher',
                        'aria-label': '打开或关闭智能咨询',
                        onClick: () => ctx.ensureOperatingQuestionScope?.(),
                    }, [
                        h('span', { class: 'sx-ai-consultant-avatar', 'aria-hidden': 'true' }, [
                            icon('fa-concierge-bell'),
                            h('span', { class: 'sx-ai-consultant-online-dot' }),
                        ]),
                        h('span', { class: 'sx-ai-consultant-launcher-label' }, '智能咨询'),
                        h('span', { class: 'sx-ai-consultant-close', 'aria-hidden': 'true' }, [icon('fa-times')]),
                    ]),
                    h('section', {
                        class: 'sx-ai-consultant-panel',
                        role: 'dialog',
                        'aria-label': '宿析OS智能咨询',
                        'data-testid': 'operating-question-floating-panel',
                    }, [
                        h('header', { class: 'sx-ai-consultant-header' }, [
                            h('div', { class: 'sx-ai-consultant-header-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                            h('div', [
                                h('div', { class: 'sx-ai-consultant-title' }, '宿析智能咨询'),
                                h('p', '基于当前酒店的已保存事实与知识库回答'),
                            ]),
                        ]),
                        h('div', { class: 'sx-ai-consultant-body' }, [
                            scopeDetails,
                            suggestions,
                            h('div', {
                                class: 'sx-ai-consultant-conversation',
                                'aria-live': 'polite',
                                'aria-atomic': 'false',
                            }, conversation),
                        ]),
                        composer,
                    ]),
                ]);
            };
        },
    };
    // SYSTEM_USAGE_GUIDE_HELPERS_START
    const SYSTEM_USAGE_GUIDE_TOPICS = [
        {
            key: 'daily-workbench',
            title: '从今日经营工作台开始',
            category: '经营总览',
            example: '我是第一次使用，今天应该先做什么？',
            keywords: ['今天先做什么', '今日工作', '经营看板', '工作台', '今日经营', '待办', '从哪里开始', '第一次使用'],
            context_pages: ['compass'],
            target_page: 'compass',
            action_key: 'page',
            action_label: '打开今日经营工作台',
            summary: '先查看当前酒店今天最需要关注的事实状态、阻塞项和下一步入口。',
            steps: ['确认当前酒店和业务日期。', '查看事实状态与优先阻塞项。', '从对应卡片进入数据、收益或运营页面。'],
            boundary: '工作台是总览入口，卡片存在不代表对应数据或任务已经完成。',
        },
        {
            key: 'data-health',
            title: '检查数据为什么不能用',
            category: '数据与采集',
            example: '数据为什么不可用？',
            keywords: ['数据不可用', '数据缺失', '缺数', '数据健康', '采集失败', '未验证', 'partial', 'cookie', '登录过期', '携程数据', '美团数据'],
            context_pages: ['online-data', 'ctrip-ebooking', 'meituan-ebooking', 'compass'],
            target_page: 'online-data',
            action_key: 'data-health',
            action_label: '打开数据健康',
            summary: '先核对酒店、平台、业务日期、来源和质量状态，再决定数据能否进入报告或经营分析。',
            steps: [
                '进入“OTA数据与采集 → 数据健康”。',
                '确认酒店、平台和业务日期与当前任务一致。',
                '查看缺失原因、采集状态以及保存回读结果。',
            ],
            boundary: '数据行存在不等于事实可用；系统不会用历史值、其他酒店或默认值补齐。',
        },
        {
            key: 'auto-collect',
            title: '配置 OTA 数据采集',
            category: '数据与采集',
            example: '怎么配置 OTA 自动采集？',
            keywords: ['自动采集', '采集设置', '携程绑定', '美团绑定', '账号绑定', '平台账号', 'cookie', '定时采集', '采集计划'],
            context_pages: ['online-data', 'ctrip-ebooking', 'meituan-ebooking'],
            target_page: 'online-data',
            action_key: 'auto-collect',
            action_label: '打开自动采集设置',
            summary: '在自动采集设置中完成酒店、平台账号、采集方式和计划配置，然后核对真实运行回执。',
            steps: [
                '选择要采集的系统酒店和 OTA 平台。',
                '完成平台门店身份与授权方式绑定。',
                '运行一次并检查保存数量、失败阶段和精确回读。',
            ],
            boundary: '计划已启用不代表采集成功；登录、验证码和平台授权仍必须在原会话完成。',
        },
        {
            key: 'pms-data',
            title: '查看 PMS 全酒店经营数据',
            category: '经营数据',
            example: '在哪里看 PMS 营收和间夜？',
            keywords: ['pms', '订单来了', '全酒店', '营收', '房费', '间夜', '入住率', '经营数据'],
            context_pages: ['pms-operating-data', 'compass'],
            target_page: 'pms-operating-data',
            action_key: 'page',
            action_label: '打开 PMS 经营数据',
            summary: 'PMS 页面承载全酒店住宿经营事实，可查看房费、间夜、入住率和对应业务日期。',
            steps: [
                '选择系统酒店和要核对的业务日期。',
                '查看 PMS 来源、采集时间和事实状态。',
                '再进入收益或运营页面使用已验证结果。',
            ],
            boundary: 'PMS 是全酒店口径，不能与携程、美团单渠道指标直接混加。',
        },
        {
            key: 'revenue-report',
            title: '查看报告和经营结论',
            category: '收益分析',
            example: '在哪里看报告和经营结论？',
            keywords: ['报告', '结论', '收益', '诊断', '分析', '预测', '调价', 'adr', 'revpar', '经营问题'],
            context_pages: ['compass', 'revenue-ai', 'revenue-research-center', 'agent-center'],
            target_page: 'revenue-research-center',
            action_key: 'page',
            action_label: '打开收益诊断',
            summary: '报告和经营结论由收益诊断页面承载；系统助手只负责带路，并说明生成结论前缺什么证据。',
            steps: [
                '先确认酒店、平台、业务日期和数据口径。',
                '打开收益诊断，查看事实、异常信号和证据缺口。',
                '对建议进行人工判断后，再进入任务执行。',
            ],
            boundary: '报告结论必须引用已验证事实；建议不等于已执行，也不能证明原因或 ROI。',
        },
        {
            key: 'operations',
            title: '安排任务并查看执行复盘',
            category: '运营管理',
            example: '怎么给员工安排任务并复盘？',
            keywords: ['员工', '安排任务', '分配任务', '运营任务', '执行', '回执', '复盘', '负责人', '截止时间'],
            context_pages: ['ops-track', 'operating-targets', 'compass'],
            target_page: 'ops-track',
            action_key: 'page',
            action_label: '打开任务执行与复盘',
            summary: '在运营任务页面指定负责人、截止时间和复盘时间，并用真实执行回执判断结果。',
            steps: [
                '从已保存的诊断或人工判断创建任务。',
                '指定负责人、截止时间和复盘时间。',
                '记录真实执行回执，并按同口径结果完成复盘。',
            ],
            boundary: '创建建议不代表任务已执行；没有真实回执和观察窗口时不能宣称有效。',
        },
        {
            key: 'automation-monitor',
            title: '检查自动化为什么没有运行',
            category: '运行监控',
            example: '自动任务没运行去哪里看？',
            keywords: ['自动化', '定时任务', '没有运行', '运行失败', '监控', '调度', '计划状态', '任务日志'],
            context_pages: ['automation-monitor', 'online-data'],
            target_page: 'automation-monitor',
            action_key: 'page',
            action_label: '打开运行监控',
            summary: '运行监控集中展示计划是否启用、最近运行、真实失败阶段和下一步处理入口。',
            steps: [
                '找到对应酒店、平台和任务计划。',
                '区分“已启用”“已运行”和“已成功”。',
                '根据失败阶段处理登录、绑定、采集或保存问题。',
            ],
            boundary: '端口在线、计划启用或历史成功都不能替代本次运行回执。',
        },
        {
            key: 'hotel-settings',
            title: '设置门店、账号和权限',
            category: '系统设置',
            example: '怎么新增门店或设置账号权限？',
            keywords: ['新增门店', '门店设置', '酒店设置', '账号', '用户', '权限', '角色', '授权酒店', '平台门店id'],
            context_pages: ['hotels', 'users', 'system-config'],
            target_page: 'hotels',
            action_key: 'page',
            action_label: '打开门店管理',
            summary: '先建立系统酒店，再为用户分配可查看、可执行的酒店范围；平台门店身份必须单独绑定。',
            steps: [
                '在门店管理中建立或核对系统酒店。',
                '进入账号权限，为用户分配酒店和操作范围。',
                '回到 OTA 设置核对平台门店身份。',
            ],
            boundary: '系统酒店、平台门店和租户身份不能互相猜测或串用。',
        },
        {
            key: 'operating-targets',
            title: '设置经营目标和保底线',
            category: '目标管理',
            example: '怎么给这家酒店设置本月经营目标和保底线？',
            keywords: ['经营目标', '目标', '保底线', '指标版本', '目标值', '每日目标', '负责人目标'],
            context_pages: ['operating-targets', 'compass'],
            target_page: 'operating-targets',
            action_key: 'page',
            action_label: '打开目标与事实',
            summary: '为指定酒店和业务日期设置目标、指标口径、保底线和负责人。',
            steps: ['选择酒店和目标业务日期。', '确认指标定义、目标值与保底线。', '保存后核对目标版本和来源事实。'],
            boundary: '目标是管理合同，不是已经发生的经营事实，也不能改写采集数据。',
        },
        {
            key: 'ai-daily-report',
            title: '生成和查看 AI 经营日报',
            category: '经营报告',
            example: '怎么生成今天的 AI 经营日报？',
            keywords: ['经营日报', 'ai日报', '生成日报', '日报草稿', '日报预览', '日报发送'],
            context_pages: ['ai-daily-report', 'compass'],
            target_page: 'ai-daily-report',
            action_key: 'page',
            action_label: '打开 AI 经营日报',
            summary: '基于已验证数据生成日报草稿，预览事实、建议和缺口后再决定是否交付。',
            steps: ['选择酒店和报告日期。', '确认数据可用性后生成日报。', '预览内容并人工决定是否发送。'],
            boundary: '生成成功不等于内容已确认或已经发送，外部交付必须另有真实回执。',
        },
        {
            key: 'growth-archive',
            title: '查看经营经验和成长档案',
            category: '复盘与知识',
            example: '以前做过哪些动作，结果和经验去哪里看？',
            keywords: ['成长档案', '经营经验', '历史复盘', '成功经验', '失败经验', '里程碑', '经验沉淀'],
            context_pages: ['operating-growth-archive', 'ops-track'],
            target_page: 'operating-growth-archive',
            action_key: 'page',
            action_label: '打开经营成长档案',
            summary: '查看已保存的动作、执行证据、结果复盘、经验层级和适用边界。',
            steps: ['选择酒店和要回顾的时间范围。', '查看动作、执行和结果证据。', '确认经验是否满足复用条件。'],
            boundary: '一次结果或相关变化不能直接升级为可复制经验，跨店使用还需重新验证。',
        },
        {
            key: 'team-permissions',
            title: '管理员工账号和角色权限',
            category: '团队管理',
            example: '怎么给新员工开账号并分配酒店权限？',
            keywords: ['员工账号', '新增员工', '用户管理', '角色权限', '账号权限', '分配酒店', '登录账号'],
            context_pages: ['users', 'roles', 'hotels'],
            target_page: 'users',
            action_key: 'page',
            action_label: '打开员工管理',
            summary: '新增或维护员工账号，并按角色分配可见酒店和可操作功能。',
            steps: ['建立或选择员工账号。', '分配角色、酒店范围和功能权限。', '用目标账号核对实际可见入口。'],
            boundary: '页面可见范围必须服从服务端权限，不能通过导航或模型绕过授权。',
        },
        {
            key: 'agent-toolbox',
            title: '使用酒店 AI 工具箱',
            category: 'AI 工具',
            example: 'OTA 诊断、需求预测和价格建议在哪里用？',
            keywords: ['ai工具', '智能工具', 'ota诊断', '需求预测', '价格建议', '智能问答', 'agent'],
            context_pages: ['agent-center', 'revenue-research-center'],
            target_page: 'agent-center',
            action_key: 'page',
            action_label: '打开酒店 AI 工具箱',
            summary: '进入专业 AI 页面使用 OTA 诊断、需求预测、价格建议和保存的经营问答。',
            steps: ['选择要使用的专业工具。', '锁定酒店、平台、日期和证据范围。', '保存并回读结果后再进入人工决策。'],
            boundary: 'AI 输出是辅助材料；未经人工确认不会自动改价、改库存或执行任务。',
        },
        {
            key: 'notifications',
            title: '配置企业微信通知',
            category: '通知与交付',
            example: '怎么配置企业微信推送？',
            keywords: ['企业微信', '微信通知', '推送', '通知', '接收人', '发送报告', '消息'],
            context_pages: ['wechat-notification', 'manual-notifications'],
            target_page: 'wechat-notification',
            action_key: 'page',
            action_label: '打开通知中心',
            summary: '通知中心用于绑定接收方、检查发送条件和查看交付回执；报告先本地生成，再决定是否发送。',
            steps: [
                '选择酒店并核对企业微信接收配置。',
                '先在本地生成和预览要发送的内容。',
                '发送后检查接收方、时间和交付回执。',
            ],
            boundary: '预览成功不等于已发送；没有明确接收方和授权时不会自动外发。',
        },
        {
            key: 'task-navigation',
            title: '查找系统功能入口',
            category: '使用帮助',
            example: '我要完成一个任务，但不知道入口',
            keywords: ['怎么用', '在哪里', '哪个页面', '功能入口', '系统帮助', '使用系统', '不会操作', '任务导航'],
            context_pages: ['knowledge-center'],
            target_page: 'knowledge-center',
            action_key: 'task-navigation',
            action_label: '打开任务导航',
            summary: '任务导航按“想完成什么”查找真实页面、使用场景和前置条件，不要求先知道模块名称。',
            steps: [
                '输入要完成的业务任务或页面关键词。',
                '查看对应使用场景和事实边界。',
                '从唯一主操作进入真实功能页面。',
            ],
            boundary: '任务导航只负责带路，不把页面可打开包装成数据已就绪或业务已完成。',
        },
    ];
    const SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS = Object.freeze({
        'daily-workbench': '已确认当前酒店、业务日期和今天最优先处理的阻塞项。',
        'data-health': '已明确数据停在身份、采集、保存还是精确回读阶段；证据不足时仍显示未确定。',
        'auto-collect': '已核对酒店、平台、账号与计划，并取得一次真实运行或明确失败回执。',
        'pms-data': '已确认目标酒店、业务日期、PMS 来源和可用事实状态。',
        'revenue-report': '报告明确区分已验证事实、证据缺口和人工建议，没有把缺数写成结论。',
        operations: '任务已明确负责人、截止时间和复盘口径；未执行时仍保持待执行。',
        'automation-monitor': '已定位本次计划的运行阶段、失败原因和对应恢复入口。',
        'hotel-settings': '系统酒店、平台门店身份和账号可见范围已经逐项核对。',
        'operating-targets': '目标、指标口径、保底线、负责人和版本均已保存并回显。',
        'ai-daily-report': '日报草稿已基于当前可用证据生成并预览；是否外发仍由人工确认。',
        'growth-archive': '已看到动作、执行和结果证据，并明确经验是否具备复用条件。',
        'team-permissions': '目标账号的角色、酒店范围和实际可见入口已经核对。',
        'agent-toolbox': '专业工具已锁定酒店、平台、日期和证据范围，并明确输出边界。',
        notifications: '接收方、内容和发送时间已核对；真实送达仍以发送回执为准。',
        'task-navigation': '已找到与业务目标对应的真实页面和进入前需要满足的条件。',
    });
    const SYSTEM_ASSISTANT_MODE_OPTIONS = Object.freeze([
        { key: 'auto', label: '自动判断', icon: 'fa-wand-magic-sparkles' },
        { key: 'guide', label: '教我使用', icon: 'fa-compass' },
        { key: 'report', label: '给我结论', icon: 'fa-chart-line' },
        { key: 'action', label: '帮我处理', icon: 'fa-list-check' },
    ]);
    const SYSTEM_ASSISTANT_MODE_LABELS = Object.freeze({
        auto: '自动判断',
        guide: '使用指导',
        report: '证据结论',
        action: '行动草案',
    });
    const SYSTEM_USAGE_GUIDE_ANCHORS = Object.freeze({
        'daily-workbench': ['[data-testid="page-compass"]'],
        'data-health': ['[data-testid="phase1-employee-closure-summary"]', '[data-testid="online-data-health-panel"]'],
        'auto-collect': ['[data-testid="canonical-daily-operation-status"]', '[data-testid="platform-auto-settings-panels"]'],
        'pms-data': ['[data-testid="page-pms-operating-data"]'],
        'revenue-report': ['[data-testid="operating-question-entry"]', '[data-testid="page-revenue-research-center"]'],
        operations: ['[data-testid="page-ops-track"]'],
        'automation-monitor': ['[data-testid="page-automation-monitor"]'],
        'hotel-settings': ['[data-testid="page-hotels"]'],
        'operating-targets': ['[data-testid="page-operating-targets"]'],
        'ai-daily-report': ['[data-testid="ai-daily-fact-gate"]', '[data-testid="page-ai-daily-report"]'],
        'growth-archive': ['[data-testid="page-operating-growth-archive"]'],
        'team-permissions': ['[data-testid="page-users"]'],
        'agent-toolbox': ['[data-testid="operating-question-entry"]', '[data-testid="page-agent-center"]'],
        notifications: ['[data-testid="page-wechat-notification"]'],
        'task-navigation': ['[data-testid="page-knowledge-center"]'],
    });
    const normalizeSystemUsageGuideText = (value) => String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[\s，。！？、,.!?：:；;（）()【】\[\]《》<>“”"'`]+/g, '');
    const resolveSystemAssistantMode = (query, requestedMode = 'auto', modelMode = '') => {
        const requested = String(requestedMode || 'auto').trim().toLowerCase();
        if (['guide', 'report', 'action'].includes(requested)) return requested;
        const model = String(modelMode || '').trim().toLowerCase();
        if (['guide', 'report', 'action'].includes(model)) return model;
        const normalized = normalizeSystemUsageGuideText(query);
        const actionKeywords = ['帮我处理', '帮我配置', '替我处理', '创建任务', '安排任务', '生成行动草案', '制定行动方案', '落地执行'];
        if (actionKeywords.some((keyword) => normalized.includes(normalizeSystemUsageGuideText(keyword)))) return 'action';
        const reportKeywords = ['给我报告', '看报告', '查看报告', '报告', '给我结论', '经营结论', '结论', '分析一下', '诊断一下', '经营怎么样', '为什么', '有哪些问题', '复核什么', '数据缺口'];
        if (reportKeywords.some((keyword) => normalized.includes(normalizeSystemUsageGuideText(keyword)))) return 'report';
        return 'guide';
    };
    const resolveSystemUsageGuideTopic = (query, currentPage = '') => {
        const originalQuery = String(query || '').trim();
        const normalizedQuery = normalizeSystemUsageGuideText(originalQuery);
        const normalizedPage = String(currentPage || '').trim();
        const fallback = SYSTEM_USAGE_GUIDE_TOPICS.find((topic) => topic.key === 'task-navigation');
        if (!normalizedQuery) {
            return { ...fallback, match_status: 'empty', original_query: originalQuery };
        }
        let bestTopic = fallback;
        let bestScore = 0;
        for (const topic of SYSTEM_USAGE_GUIDE_TOPICS) {
            if (topic.key === 'task-navigation') continue;
            const candidates = [topic.title, topic.category, topic.example, ...(topic.keywords || [])]
                .map(normalizeSystemUsageGuideText)
                .filter(Boolean);
            let score = 0;
            for (const candidate of candidates) {
                if (normalizedQuery.includes(candidate)) {
                    score += 6 + Math.min(candidate.length, 8);
                } else if (candidate.includes(normalizedQuery) && normalizedQuery.length >= 2) {
                    score += 3;
                }
            }
            if (score > 0 && (topic.context_pages || []).includes(normalizedPage)) score += 1;
            if (score > bestScore) {
                bestTopic = topic;
                bestScore = score;
            }
        }
        return {
            ...(bestScore > 0 ? bestTopic : fallback),
            match_status: bestScore > 0 ? 'matched' : 'fallback',
            original_query: originalQuery,
        };
    };
    // SYSTEM_USAGE_GUIDE_HELPERS_END

    const basicSystemUsageAssistant = {
        name: 'SystemUsageAssistant',
        props: {
            ctx: { type: Object, required: true },
        },
        setup(props) {
            const state = ref({
                query: '',
                result: null,
                error: '',
                opening_key: '',
            });
            const icon = (name) => h('i', { class: `fas ${name}`, 'aria-hidden': 'true' });
            const topicByKey = (key) => SYSTEM_USAGE_GUIDE_TOPICS.find((topic) => topic.key === key) || null;
            const visiblePaths = () => {
                const paths = new Set();
                const visit = (items) => {
                    for (const item of Array.isArray(items) ? items : []) {
                        const path = String(item?.path || '').trim();
                        if (path) paths.add(path);
                        visit(item?.children);
                    }
                };
                visit(props.ctx?.visibleMenuItems);
                return paths;
            };
            const canOpenTopic = (topic) => {
                const paths = visiblePaths();
                if (paths.size === 0) return true;
                return paths.has(String(topic?.target_page || ''));
            };
            const currentPageText = () => String(props.ctx?.pageTitle || props.ctx?.currentPage || '当前页面');
            const suggestionTopics = () => {
                const currentPage = String(props.ctx?.currentPage || '');
                const contextKeys = SYSTEM_USAGE_GUIDE_TOPICS
                    .filter((topic) => topic.key !== 'task-navigation' && (topic.context_pages || []).includes(currentPage))
                    .map((topic) => topic.key);
                const preferred = [...contextKeys, 'data-health', 'revenue-report', 'operations', 'task-navigation'];
                return Array.from(new Set(preferred))
                    .map(topicByKey)
                    .filter(Boolean)
                    .slice(0, 4);
            };
            const applySuggestion = (topic) => {
                state.value.query = String(topic?.example || topic?.title || '');
                state.value.error = '';
                state.value.result = null;
            };
            const ask = () => {
                const query = String(state.value.query || '').trim();
                state.value.error = '';
                if (!query) {
                    state.value.result = null;
                    state.value.error = '请说出你想在系统里完成什么，例如“携程数据缺失去哪里处理”。';
                    return false;
                }
                state.value.result = resolveSystemUsageGuideTopic(query, props.ctx?.currentPage);
                return true;
            };
            const openTopic = async (event, topic) => {
                if (!topic || state.value.opening_key) return false;
                if (!canOpenTopic(topic)) {
                    state.value.error = '当前账号没有显示该功能入口，请联系管理员核对角色与酒店权限。';
                    return false;
                }
                state.value.error = '';
                state.value.opening_key = String(topic.key || 'page');
                try {
                    const ctx = props.ctx || {};
                    if (topic.action_key === 'data-health') {
                        ctx.currentPage = 'online-data';
                        ctx.onlineDataTab = 'data-health';
                        await Promise.resolve(ctx.openOnlineDataTab?.('data-health', { force: true }));
                    } else if (topic.action_key === 'auto-collect') {
                        await Promise.resolve(ctx.openOnlinePlatformAutoTab?.({ force: true }));
                    } else {
                        ctx.currentPage = String(topic.target_page || '');
                        if (topic.action_key === 'task-navigation'
                            && ctx.knowledgeCenterFilter
                            && state.value.result?.match_status === 'fallback'
                        ) {
                            ctx.knowledgeCenterFilter.keyword = String(state.value.query || '').trim();
                        }
                    }
                    const panel = event?.currentTarget?.closest?.('details.sx-ai-consultant');
                    if (panel) panel.open = false;
                    return true;
                } catch (error) {
                    state.value.error = error?.message || '打开功能页面失败，请从左侧导航重试。';
                    return false;
                } finally {
                    state.value.opening_key = '';
                }
            };

            return () => {
                const result = state.value.result;
                const conversation = [];
                if (!result && !state.value.error) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-welcome' }, [
                        h('div', { class: 'sx-ai-consultant-message-avatar', 'aria-hidden': 'true' }, [icon('fa-compass')]),
                        h('div', [
                            h('strong', '告诉我你想在系统里完成什么。'),
                            h('p', '我会说明操作步骤并带你进入真实页面；报告和经营结论由对应专业页面生成。'),
                        ]),
                    ]));
                }
                if (state.value.error) {
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-error',
                        role: 'alert',
                        'data-testid': 'system-guide-error',
                    }, [icon('fa-exclamation-circle'), h('span', String(state.value.error))]));
                }
                if (result) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-user-message' }, [
                        h('p', String(result.original_query || state.value.query || '')),
                    ]));
                    const available = canOpenTopic(result);
                    conversation.push(h('article', {
                        class: 'sx-ai-consultant-answer',
                        'data-testid': 'system-guide-result',
                    }, [
                        h('div', { class: 'sx-ai-consultant-answer-meta' }, [
                            h('span', { class: 'sx-ai-consultant-status' }, result.match_status === 'matched' ? '已找到操作路径' : '进入任务导航'),
                            h('span', String(result.category || '使用帮助')),
                        ]),
                        h('p', { class: 'sx-ai-consultant-answer-summary' }, String(result.title || '系统使用指导')),
                        h('p', String(result.summary || '')),
                        h('ol', { class: 'sx-ai-consultant-key-points' }, (result.steps || []).map((step, index) => (
                            h('li', { key: `${result.key}-${index}` }, `${index + 1}. ${String(step)}`)
                        ))),
                        h('div', { class: 'sx-ai-consultant-gaps' }, [
                            h('strong', '使用边界'),
                            h('p', String(result.boundary || '')),
                        ]),
                        h('section', { class: 'sx-ai-consultant-recovery' }, [
                            h('div', { class: 'sx-ai-consultant-recovery-title' }, [
                                icon('fa-location-arrow'),
                                h('strong', available ? '下一步直接进入' : '当前账号暂无入口'),
                            ]),
                            h('div', { class: 'sx-ai-consultant-recovery-actions' }, [
                                h('button', {
                                    type: 'button',
                                    disabled: !available || Boolean(state.value.opening_key),
                                    'data-testid': `system-guide-open-${String(result.key || 'topic')}`,
                                    onClick: (event) => openTopic(event, result),
                                }, [
                                    icon(state.value.opening_key === result.key ? 'fa-spinner fa-spin' : 'fa-arrow-right'),
                                    h('span', available ? String(result.action_label || '打开页面') : '请联系管理员授权'),
                                ]),
                            ]),
                            h('small', '这里只导航和说明，不生成经营结论，也不会写入业务数据。'),
                        ]),
                        h('div', { class: 'sx-ai-consultant-evidence' }, [
                            h('span', `当前页面：${currentPageText()}`),
                            h('span', '系统使用指导 · 非经营报告'),
                        ]),
                    ]));
                }

                const suggestions = h('div', {
                    class: 'sx-ai-consultant-suggestions',
                    'aria-label': '常用系统任务',
                }, suggestionTopics().map((topic) => h('button', {
                    key: topic.key,
                    type: 'button',
                    onClick: () => applySuggestion(topic),
                }, String(topic.example || topic.title))));
                const composer = h('form', {
                    class: 'sx-ai-consultant-composer',
                    onSubmit: (event) => {
                        event?.preventDefault?.();
                        ask();
                    },
                }, [
                    h('textarea', {
                        value: String(state.value.query || ''),
                        maxlength: 500,
                        rows: 2,
                        placeholder: '例如：携程数据缺失要去哪里处理？',
                        'data-testid': 'system-guide-input',
                        onInput: (event) => {
                            state.value.query = String(event?.target?.value || '');
                            state.value.error = '';
                        },
                        onKeydown: (event) => {
                            if (event?.key !== 'Enter' || event.shiftKey || event.isComposing) return;
                            event.preventDefault();
                            ask();
                        },
                    }),
                    h('button', {
                        type: 'submit',
                        disabled: !String(state.value.query || '').trim(),
                        'data-testid': 'system-guide-submit',
                        'aria-label': '查找系统操作路径',
                    }, [icon('fa-arrow-up')]),
                    h('p', '系统导航与操作指导 · 报告结论请进入专业页面'),
                ]);

                return h('details', {
                    class: 'sx-ai-consultant',
                    'data-testid': 'system-guide-floating-entry',
                }, [
                    h('summary', {
                        class: 'sx-ai-consultant-launcher',
                        'data-testid': 'system-guide-floating-launcher',
                        'aria-label': '打开或关闭系统操作助手',
                    }, [
                        h('span', { class: 'sx-ai-consultant-avatar', 'aria-hidden': 'true' }, [icon('fa-compass')]),
                        h('span', { class: 'sx-ai-consultant-launcher-label' }, '系统助手'),
                        h('span', { class: 'sx-ai-consultant-close', 'aria-hidden': 'true' }, [icon('fa-times')]),
                    ]),
                    h('section', {
                        class: 'sx-ai-consultant-panel',
                        role: 'dialog',
                        'aria-label': '宿析OS系统操作助手',
                        'data-testid': 'system-guide-floating-panel',
                    }, [
                        h('header', { class: 'sx-ai-consultant-header' }, [
                            h('div', { class: 'sx-ai-consultant-header-avatar', 'aria-hidden': 'true' }, [icon('fa-compass')]),
                            h('div', [
                                h('div', { class: 'sx-ai-consultant-title' }, '宿析系统助手'),
                                h('p', '告诉你在哪、怎么做、下一步点什么'),
                            ]),
                        ]),
                        h('div', { class: 'sx-ai-consultant-body' }, [
                            h('p', { class: 'sx-ai-consultant-boundary' }, '当前酒店和页面只用于给出更合适的入口，不限制你查找其他系统功能。'),
                            suggestions,
                            h('div', {
                                class: 'sx-ai-consultant-conversation',
                                'aria-live': 'polite',
                                'aria-atomic': 'false',
                            }, conversation),
                        ]),
                        composer,
                    ]),
                ]);
            };
        },
    };

    const operatingQuestionConsultant = {
        name: 'IntelligentSystemUsageAssistant',
        props: {
            ctx: { type: Object, required: true },
        },
        setup(props) {
            const state = ref({
                query: '',
                turns: [],
                error: '',
                loading: false,
                opening_key: '',
                active_journey: null,
                selected_mode: 'auto',
                coach: null,
            });
            const journeyStorageVersion = 1;
            const widgetStorageVersion = 1;
            const widgetRoot = ref(null);
            const widgetOpen = ref(false);
            const widgetDragging = ref(false);
            const widgetPosition = ref({ right: null, bottom: null });
            const widgetDrag = {
                active: false,
                source: '',
                pointer_id: null,
                start_x: 0,
                start_y: 0,
                start_left: 0,
                start_top: 0,
                width: 0,
                height: 0,
                moved: false,
                capture_target: null,
            };
            let suppressLauncherToggle = false;
            let resizeFrame = 0;
            let coachTarget = null;
            let coachRequestId = 0;
            const icon = (name) => h('i', { class: `fas ${name}`, 'aria-hidden': 'true' });
            const topicByKey = (key) => SYSTEM_USAGE_GUIDE_TOPICS.find((topic) => topic.key === key) || null;
            const visiblePaths = () => {
                const paths = new Set();
                const visit = (items) => {
                    for (const item of Array.isArray(items) ? items : []) {
                        const path = String(item?.path || '').trim();
                        if (path) paths.add(path);
                        visit(item?.children);
                    }
                };
                visit(props.ctx?.visibleMenuItems);
                return paths;
            };
            const canOpenTopic = (topic) => {
                const paths = visiblePaths();
                if (paths.size === 0) return true;
                return paths.has(String(topic?.target_page || ''));
            };
            const visibleTopicKeys = () => SYSTEM_USAGE_GUIDE_TOPICS
                .filter((topic) => canOpenTopic(topic))
                .map((topic) => topic.key);
            const journeyStorageKey = () => {
                const userId = Number(props.ctx?.user?.id || 0);
                return `suxios_system_usage_journey_v1:${userId > 0 ? userId : 'session'}`;
            };
            const widgetStorageKey = () => {
                const userId = Number(props.ctx?.user?.id || 0);
                return `suxios_system_usage_widget_v1:${userId > 0 ? userId : 'session'}`;
            };
            const widgetStyle = computed(() => {
                const position = widgetPosition.value || {};
                const style = {};
                if (Number.isFinite(position.right)) style.right = `${Math.round(position.right)}px`;
                if (Number.isFinite(position.bottom)) style.bottom = `${Math.round(position.bottom)}px`;
                return style;
            });
            const saveWidgetState = () => {
                const position = widgetPosition.value || {};
                try {
                    localStorage.setItem(widgetStorageKey(), JSON.stringify({
                        version: widgetStorageVersion,
                        open: Boolean(widgetOpen.value),
                        right: Number.isFinite(position.right) ? Math.round(position.right) : null,
                        bottom: Number.isFinite(position.bottom) ? Math.round(position.bottom) : null,
                    }));
                } catch (error) {
                    // Widget position is optional; the default corner remains usable.
                }
            };
            const readWidgetState = () => {
                try {
                    const raw = JSON.parse(localStorage.getItem(widgetStorageKey()) || 'null');
                    if (!raw || Number(raw.version || 0) !== widgetStorageVersion) return;
                    widgetOpen.value = raw.open === true;
                    const right = typeof raw.right === 'number' && Number.isFinite(raw.right)
                        ? raw.right
                        : null;
                    const bottom = typeof raw.bottom === 'number' && Number.isFinite(raw.bottom)
                        ? raw.bottom
                        : null;
                    if (right !== null && bottom !== null) {
                        widgetPosition.value = { right, bottom };
                    }
                } catch (error) {
                    // Invalid local UI state must not block the assistant entry.
                }
            };
            const applyWidgetPosition = (right, bottom) => {
                const next = {
                    right: Math.max(0, Math.round(Number(right) || 0)),
                    bottom: Math.max(0, Math.round(Number(bottom) || 0)),
                };
                widgetPosition.value = next;
                if (widgetRoot.value) {
                    widgetRoot.value.style.right = `${next.right}px`;
                    widgetRoot.value.style.bottom = `${next.bottom}px`;
                }
            };
            const clampWidgetPosition = (persist = false) => {
                const root = widgetRoot.value;
                if (!root) return;
                const rect = root.getBoundingClientRect();
                const margin = 8;
                const viewportWidth = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
                const viewportHeight = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
                const maxLeft = Math.max(margin, viewportWidth - rect.width - margin);
                const maxTop = Math.max(margin, viewportHeight - rect.height - margin);
                const left = Math.min(Math.max(rect.left, margin), maxLeft);
                const top = Math.min(Math.max(rect.top, margin), maxTop);
                const hasStoredPosition = Number.isFinite(widgetPosition.value?.right)
                    && Number.isFinite(widgetPosition.value?.bottom);
                const needsClamp = Math.abs(left - rect.left) > 0.5 || Math.abs(top - rect.top) > 0.5;
                if (hasStoredPosition || needsClamp) {
                    applyWidgetPosition(
                        viewportWidth - left - rect.width,
                        viewportHeight - top - rect.height,
                    );
                }
                if (persist) saveWidgetState();
            };
            const startWidgetDrag = (event, source) => {
                if (!event || event.isPrimary === false || (Number.isFinite(event.button) && event.button !== 0)) return;
                if (source === 'launcher' && widgetOpen.value) return;
                const root = widgetRoot.value;
                if (!root) return;
                const rect = root.getBoundingClientRect();
                widgetDrag.active = true;
                widgetDrag.source = source;
                widgetDrag.pointer_id = event.pointerId;
                widgetDrag.start_x = Number(event.clientX || 0);
                widgetDrag.start_y = Number(event.clientY || 0);
                widgetDrag.start_left = rect.left;
                widgetDrag.start_top = rect.top;
                widgetDrag.width = rect.width;
                widgetDrag.height = rect.height;
                widgetDrag.moved = false;
                widgetDrag.capture_target = event.currentTarget || null;
                widgetDragging.value = true;
                try {
                    event.currentTarget?.setPointerCapture?.(event.pointerId);
                } catch (error) {
                    // Pointer capture is an enhancement; movement still uses element events.
                }
            };
            const moveWidgetDrag = (event) => {
                if (!widgetDrag.active || event?.pointerId !== widgetDrag.pointer_id) return;
                const deltaX = Number(event.clientX || 0) - widgetDrag.start_x;
                const deltaY = Number(event.clientY || 0) - widgetDrag.start_y;
                if (!widgetDrag.moved && Math.hypot(deltaX, deltaY) < 5) return;
                widgetDrag.moved = true;
                event.preventDefault?.();
                const margin = 8;
                const viewportWidth = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
                const viewportHeight = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
                const maxLeft = Math.max(margin, viewportWidth - widgetDrag.width - margin);
                const maxTop = Math.max(margin, viewportHeight - widgetDrag.height - margin);
                const left = Math.min(Math.max(widgetDrag.start_left + deltaX, margin), maxLeft);
                const top = Math.min(Math.max(widgetDrag.start_top + deltaY, margin), maxTop);
                applyWidgetPosition(
                    viewportWidth - left - widgetDrag.width,
                    viewportHeight - top - widgetDrag.height,
                );
            };
            const endWidgetDrag = (event) => {
                if (!widgetDrag.active || event?.pointerId !== widgetDrag.pointer_id) return;
                const moved = widgetDrag.moved;
                const source = widgetDrag.source;
                try {
                    widgetDrag.capture_target?.releasePointerCapture?.(widgetDrag.pointer_id);
                } catch (error) {
                    // The browser may release capture automatically on pointerup.
                }
                widgetDrag.active = false;
                widgetDrag.pointer_id = null;
                widgetDrag.capture_target = null;
                widgetDragging.value = false;
                if (moved) {
                    suppressLauncherToggle = source === 'launcher';
                    clampWidgetPosition(true);
                    setTimeout(() => {
                        suppressLauncherToggle = false;
                    }, 0);
                }
            };
            const handleLauncherClick = (event) => {
                if (!suppressLauncherToggle) return;
                event?.preventDefault?.();
                event?.stopPropagation?.();
                suppressLauncherToggle = false;
            };
            const handleWidgetToggle = (event) => {
                widgetOpen.value = Boolean(event?.currentTarget?.open);
                nextTick(() => {
                    clampWidgetPosition(false);
                    saveWidgetState();
                });
            };
            const handleWidgetViewportResize = () => {
                if (resizeFrame) window.cancelAnimationFrame(resizeFrame);
                resizeFrame = window.requestAnimationFrame(() => {
                    resizeFrame = 0;
                    clampWidgetPosition(true);
                });
            };
            const journeyStep = (topic, index) => ({
                index: index + 1,
                key: String(topic.key || ''),
                title: String(topic.title || ''),
                category: String(topic.category || ''),
                summary: String(topic.summary || ''),
                success_marker: String(SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS[topic.key] || '已核对目标页面、当前状态和下一步动作。'),
                action: {
                    key: String(topic.key || ''),
                    label: String(topic.action_label || '打开页面'),
                    target_page: String(topic.target_page || ''),
                    action_key: String(topic.action_key || 'page'),
                },
            });
            const normalizeJourney = (rawJourney, primaryTopic = null) => {
                const keys = [];
                const primaryKey = String(primaryTopic?.key || '');
                if (primaryKey) keys.push(primaryKey);
                for (const row of Array.isArray(rawJourney) ? rawJourney.slice(0, 4) : []) {
                    const key = String(typeof row === 'string' ? row : (row?.key || row?.topic_key || '')).trim();
                    const topic = topicByKey(key);
                    if (topic && canOpenTopic(topic) && !keys.includes(key)) keys.push(key);
                }
                return keys.slice(0, 4)
                    .map(topicByKey)
                    .filter(Boolean)
                    .map(journeyStep);
            };
            const saveActiveJourney = (result, query, activeKey = '') => {
                const journey = normalizeJourney(result?.journey, topicByKey(result?.topic_key));
                if (!journey.length) return false;
                const normalizedActiveKey = journey.some((step) => step.key === activeKey)
                    ? activeKey
                    : journey[0].key;
                const payload = {
                    version: journeyStorageVersion,
                    goal: String(result?.goal || result?.intent_summary || journey[0].title).slice(0, 240),
                    original_query: String(query || result?.original_query || '').slice(0, 500),
                    journey,
                    active_key: normalizedActiveKey,
                    saved_at: Date.now(),
                };
                state.value.active_journey = payload;
                try {
                    localStorage.setItem(journeyStorageKey(), JSON.stringify({
                        version: payload.version,
                        goal: payload.goal,
                        original_query: payload.original_query,
                        journey_keys: payload.journey.map((step) => step.key),
                        active_key: payload.active_key,
                        saved_at: payload.saved_at,
                    }));
                } catch (error) {
                    // Local guidance continuity is optional; navigation remains available.
                }
                return true;
            };
            const readActiveJourney = () => {
                try {
                    const raw = JSON.parse(localStorage.getItem(journeyStorageKey()) || 'null');
                    const savedAt = Number(raw?.saved_at || 0);
                    if (Number(raw?.version || 0) !== journeyStorageVersion
                        || !savedAt
                        || Date.now() - savedAt > 7 * 24 * 60 * 60 * 1000) {
                        localStorage.removeItem(journeyStorageKey());
                        return null;
                    }
                    const journey = normalizeJourney(raw?.journey_keys || [], null);
                    if (!journey.length) {
                        localStorage.removeItem(journeyStorageKey());
                        return null;
                    }
                    return {
                        version: journeyStorageVersion,
                        goal: String(raw?.goal || journey[0].title).slice(0, 240),
                        original_query: String(raw?.original_query || '').slice(0, 500),
                        journey,
                        active_key: journey.some((step) => step.key === raw?.active_key)
                            ? String(raw.active_key)
                            : journey[0].key,
                        saved_at: savedAt,
                    };
                } catch (error) {
                    return null;
                }
            };
            const clearActiveJourney = () => {
                state.value.active_journey = null;
                try {
                    localStorage.removeItem(journeyStorageKey());
                } catch (error) {
                    // Nothing else depends on local guidance persistence.
                }
            };
            const isTopicCurrent = (topic) => {
                const currentPage = String(props.ctx?.currentPage || '');
                const currentTab = String(props.ctx?.onlineDataTab || '');
                if (topic?.action_key === 'data-health') {
                    return currentPage === 'online-data' && currentTab === 'data-health';
                }
                if (topic?.action_key === 'auto-collect') {
                    return currentPage === 'online-data' && currentTab === 'platform-auto';
                }
                return currentPage === String(topic?.target_page || '');
            };
            const guideStepStatus = (topic) => {
                const pending = {
                    key: 'pending', label: '待开始', detail: '还没有进入这一步。', complete: false,
                };
                if (!topic) return pending;
                const current = isTopicCurrent(topic);
                const ctx = props.ctx || {};
                if (topic.key === 'data-health') {
                    const summary = ctx.phase1EmployeeClosureSummary;
                    if (summary?.status === 'complete') {
                        return {
                            key: 'completed', label: '已核验', complete: true,
                            detail: String(summary.summaryText || '数据健康证据已闭合，可进入经营诊断。'),
                        };
                    }
                    if (summary && typeof summary === 'object') {
                        return {
                            key: 'blocked', label: '有阻塞', complete: false,
                            detail: String(summary.summaryText || summary.topActionText || '数据健康仍有未证明项。'),
                        };
                    }
                    return current
                        ? { key: 'checking', label: '待核验', detail: '数据健康证据尚未加载或接口未返回，不能判定完成。', complete: false }
                        : pending;
                }
                if (topic.key === 'auto-collect') {
                    const runState = ctx.autoFetchRunState || {};
                    const canonical = ctx.autoFetchCanonicalOperationStatus || {};
                    if (runState.active === true) {
                        return { key: 'checking', label: '运行中', detail: '采集任务正在运行，等待保存与精确回读回执。', complete: false };
                    }
                    if (canonical.visible === true && canonical.status === 'verified') {
                        return {
                            key: 'completed', label: '已核验', complete: true,
                            detail: String(canonical.status_text || canonical.scope_text || '已取得当前范围的严格回读回执。'),
                        };
                    }
                    if (canonical.visible === true) {
                        return {
                            key: 'blocked', label: '有阻塞', complete: false,
                            detail: String(canonical.reason || canonical.status_text || '采集回执未通过严格核验。'),
                        };
                    }
                    if (ctx.autoFetchEnabled === true) {
                        return { key: 'in_progress', label: '待回执', detail: '计划已启用，但还没有当前范围的严格运行回执。', complete: false };
                    }
                    return current
                        ? { key: 'in_progress', label: '待配置', detail: '请先完成酒店、平台账号与采集计划配置。', complete: false }
                        : pending;
                }
                if (topic.key === 'revenue-report' || topic.key === 'agent-toolbox') {
                    const questionState = ctx.operatingQuestionState || {};
                    const exact = questionState.result || null;
                    if (questionState.loading === true) {
                        return { key: 'checking', label: '读取中', detail: '正在保存问题并严格回读同范围证据。', complete: false };
                    }
                    if (exact?.answer_status === 'blocked_by_missing_facts') {
                        return { key: 'blocked', label: '缺少事实', detail: String(exact.answer_summary || '缺少同酒店、同平台、同日期的可信事实。'), complete: false };
                    }
                    if (Number(exact?.id || 0) > 0 && String(exact?.content_digest || '')) {
                        return { key: 'completed', label: '已回读', detail: String(exact.answer_summary || '经营问题已保存并完成严格回读。'), complete: true };
                    }
                    return current
                        ? { key: 'in_progress', label: '可提问', detail: '已到达专业问答入口，提交问题后以严格回读结果判断。', complete: false }
                        : pending;
                }
                if (topic.key === 'ai-daily-report') {
                    const report = ctx.aiDailyReport || null;
                    const readiness = ctx.aiDailyReportResultReadiness || {};
                    if (Number(report?.id || report?.report_id || 0) > 0 && readiness?.usable === true) {
                        return {
                            key: 'completed', label: '结果可用', complete: true,
                            detail: String(readiness.status_label || '日报已生成，并有可阅读的来源与测得指标。'),
                        };
                    }
                    if (report && typeof report === 'object') {
                        return {
                            key: 'blocked', label: '结果受限', complete: false,
                            detail: String(readiness?.status_label || '日报已有记录，但来源或测得指标仍不足。'),
                        };
                    }
                    return current
                        ? { key: 'in_progress', label: '待生成', detail: '请先通过事实门，再生成并预览日报。', complete: false }
                        : pending;
                }
                if (topic.key === 'operations') {
                    const drafts = ctx.operatingQuestionState?.result?.answer?.action_drafts;
                    if (Array.isArray(drafts) && drafts.length) {
                        return { key: 'in_progress', label: '待人工确认', detail: '行动草案已生成，但尚未提交、审批或执行。', complete: false };
                    }
                }
                return current
                    ? { key: 'in_progress', label: '进行中', detail: '已到达目标页面；完成操作并核对页面回显后才能继续。', complete: false }
                    : pending;
            };
            const clearTopicCoach = () => {
                coachRequestId += 1;
                if (coachTarget) coachTarget.classList.remove('sx-system-guide-anchor-active');
                coachTarget = null;
                state.value.coach = null;
            };
            const topicAnchorSelectors = (topic) => {
                const configured = SYSTEM_USAGE_GUIDE_ANCHORS[String(topic?.key || '')];
                return Array.isArray(configured) ? configured : [];
            };
            const focusTopicAnchor = async (topic) => {
                if (!topic) return false;
                const requestId = ++coachRequestId;
                if (coachTarget) coachTarget.classList.remove('sx-system-guide-anchor-active');
                coachTarget = null;
                state.value.coach = {
                    topic_key: String(topic.key || ''),
                    target_found: false,
                    checking: true,
                };
                widgetOpen.value = false;
                if (widgetRoot.value) widgetRoot.value.open = false;
                saveWidgetState();
                const delays = [0, 120, 320, 650];
                let target = null;
                for (const delay of delays) {
                    if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
                    if (requestId !== coachRequestId) return false;
                    await nextTick();
                    target = topicAnchorSelectors(topic)
                        .map((selector) => document.querySelector(selector))
                        .find(Boolean) || null;
                    if (target) break;
                }
                if (requestId !== coachRequestId) return false;
                if (!target) {
                    state.value.coach = {
                        topic_key: String(topic.key || ''),
                        target_found: false,
                        checking: false,
                    };
                    return false;
                }
                coachTarget = target;
                coachTarget.classList.add('sx-system-guide-anchor-active');
                coachTarget.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                state.value.coach = {
                    topic_key: String(topic.key || ''),
                    target_found: true,
                    checking: false,
                };
                return true;
            };
            const currentPageText = () => String(props.ctx?.pageTitle || props.ctx?.currentPage || '当前页面');
            const suggestionTopics = () => {
                const currentPage = String(props.ctx?.currentPage || '');
                const contextKeys = SYSTEM_USAGE_GUIDE_TOPICS
                    .filter((topic) => topic.key !== 'task-navigation'
                        && canOpenTopic(topic)
                        && (topic.context_pages || []).includes(currentPage))
                    .map((topic) => topic.key);
                const preferred = [...contextKeys, 'data-health', 'revenue-report', 'operations', 'task-navigation'];
                return Array.from(new Set(preferred))
                    .map(topicByKey)
                    .filter((topic) => topic && canOpenTopic(topic))
                    .slice(0, 4);
            };
            const conversationHistory = () => state.value.turns
                .slice(-4)
                .flatMap((turn) => [
                    { role: 'user', content: String(turn.query || '') },
                    { role: 'assistant', content: String(turn.result?.assistant_message || '') },
                ])
                .filter((message) => message.content);
            const setAssistantMode = (mode) => {
                const normalized = String(mode || 'auto').trim().toLowerCase();
                state.value.selected_mode = ['auto', 'guide', 'report', 'action'].includes(normalized)
                    ? normalized
                    : 'auto';
                state.value.error = '';
            };
            const operatingPlatformFromQuery = (query) => {
                const normalized = normalizeSystemUsageGuideText(query);
                if (['携程和美团', '携程美团', '全部ota', '全ota', '所有ota'].some((key) => normalized.includes(normalizeSystemUsageGuideText(key)))) {
                    return 'all_ota';
                }
                if (normalized.includes('美团')) return 'meituan';
                if (normalized.includes('携程')) return 'ctrip';
                return '';
            };
            const runOperatingWorkflow = async (guideResult, query) => {
                const assistantMode = String(guideResult?.assistant_mode || 'guide');
                if (!['report', 'action'].includes(assistantMode) || guideResult?.topic_key === 'clarify') {
                    return guideResult;
                }
                const ctx = props.ctx || {};
                const form = ctx.operatingQuestionForm;
                const questionState = ctx.operatingQuestionState;
                if (!form || !questionState || typeof ctx.askOperatingQuestion !== 'function') {
                    return {
                        ...guideResult,
                        operating_result: null,
                        operating_error: '专业经营问答入口尚未加载，请进入酒店 AI 工具箱后重试。',
                    };
                }
                ctx.ensureOperatingQuestionScope?.();
                const inferredPlatform = operatingPlatformFromQuery(query);
                if (inferredPlatform) form.platform = inferredPlatform;
                questionState.question = query;
                const exact = await ctx.askOperatingQuestion();
                const operatingResult = exact || questionState.result || null;
                return {
                    ...guideResult,
                    operating_result: operatingResult,
                    operating_error: operatingResult ? '' : String(questionState.error || '当前范围没有取得可严格回读的经营回答。'),
                };
            };
            const localFallbackResult = (query, reason = 'client_request_failed', requestedMode = state.value.selected_mode) => {
                const topic = resolveSystemUsageGuideTopic(query, props.ctx?.currentPage);
                return {
                    status: 'ready',
                    mode: 'fallback',
                    assistant_mode: resolveSystemAssistantMode(query, requestedMode),
                    assistant_message: `智能理解暂时不可用，我先按“${String(topic.title || '任务导航')}”带你进入最接近的功能。`,
                    intent_summary: String(topic.title || ''),
                    goal: String(topic.title || ''),
                    topic_key: String(topic.key || 'task-navigation'),
                    topic: {
                        key: String(topic.key || 'task-navigation'),
                        title: String(topic.title || '查找项目功能入口'),
                        category: String(topic.category || '使用帮助'),
                    },
                    journey: normalizeJourney([topic.key], topic),
                    steps: Array.isArray(topic.steps) ? topic.steps.slice(0, 4) : [],
                    clarifying_question: '',
                    follow_up_questions: [],
                    confidence: 'low',
                    boundary: String(topic.boundary || ''),
                    runtime: {
                        status: 'fallback',
                        fallback_used: true,
                        degraded: true,
                        reason,
                    },
                };
            };
            const normalizeResult = (raw, query) => {
                if (!raw || typeof raw !== 'object') {
                    throw new Error('智能引导结果为空');
                }
                const mode = raw.mode === 'intelligent' ? 'intelligent' : 'fallback';
                const assistantMode = resolveSystemAssistantMode(query, state.value.selected_mode, raw.assistant_mode);
                const topicKey = String(raw.topic_key || '').trim();
                if (topicKey === 'clarify') {
                    const question = String(raw.clarifying_question || '').trim();
                    if (!question) throw new Error('智能引导缺少澄清问题');
                    return {
                        ...raw,
                        mode,
                        assistant_mode: assistantMode,
                        goal: String(raw.goal || raw.intent_summary || ''),
                        topic_key: 'clarify',
                        topic: null,
                        journey: [],
                        steps: [],
                        action: null,
                        original_query: query,
                    };
                }
                const topic = topicByKey(topicKey);
                if (!topic) throw new Error('智能引导返回了未知功能');
                const journey = normalizeJourney(raw.journey, topic);
                return {
                    ...raw,
                    mode,
                    assistant_mode: assistantMode,
                    topic_key: topic.key,
                    topic: {
                        key: topic.key,
                        title: topic.title,
                        category: topic.category,
                    },
                    goal: String(raw.goal || raw.intent_summary || topic.title).slice(0, 240),
                    journey,
                    steps: Array.isArray(raw.steps) && raw.steps.length
                        ? raw.steps.slice(0, 4).map((step) => String(step || '')).filter(Boolean)
                        : (topic.steps || []).slice(0, 4),
                    boundary: String(raw.boundary || topic.boundary || ''),
                    original_query: query,
                };
            };
            const applySuggestion = (topic) => {
                state.value.query = String(topic?.example || topic?.title || '');
                state.value.error = '';
            };
            const applyFollowUp = (question) => {
                state.value.query = String(question || '');
                state.value.error = '';
            };
            const ask = async () => {
                if (state.value.loading) return false;
                const query = String(state.value.query || '').trim();
                state.value.error = '';
                if (!query) {
                    state.value.error = '直接说你想完成什么，例如“我刚接手这家店，携程数据没进来，应该先做什么？”';
                    return false;
                }
                state.value.loading = true;
                state.value.query = '';
                const requestedMode = String(state.value.selected_mode || 'auto');
                let result;
                try {
                    result = normalizeResult(await props.ctx?.askSystemUsageGuide?.({
                        query,
                        requested_mode: requestedMode,
                        current_page: String(props.ctx?.currentPage || ''),
                        page_title: currentPageText(),
                        visible_topic_keys: visibleTopicKeys(),
                        history: conversationHistory(),
                    }), query);
                    result = await runOperatingWorkflow(result, query);
                } catch (error) {
                    result = localFallbackResult(query, 'request_failed', requestedMode);
                    result = await runOperatingWorkflow(result, query);
                } finally {
                    state.value.loading = false;
                }
                state.value.turns.push({
                    id: `${Date.now()}-${state.value.turns.length}`,
                    query,
                    result,
                });
                if (result.topic_key !== 'clarify') {
                    saveActiveJourney(result, query, result.topic_key);
                }
                if (state.value.turns.length > 6) {
                    state.value.turns.splice(0, state.value.turns.length - 6);
                }
                return true;
            };
            const openTopic = async (event, topic, turn) => {
                if (!topic || state.value.opening_key) return false;
                if (!canOpenTopic(topic)) {
                    state.value.error = '当前账号没有显示该功能入口，请联系管理员核对角色与酒店权限。';
                    return false;
                }
                state.value.error = '';
                state.value.opening_key = String(topic.key || 'page');
                try {
                    const ctx = props.ctx || {};
                    if (topic.action_key === 'data-health') {
                        ctx.currentPage = 'online-data';
                        ctx.onlineDataTab = 'data-health';
                        await Promise.resolve(ctx.openOnlineDataTab?.('data-health', { force: true }));
                    } else if (topic.action_key === 'auto-collect') {
                        await Promise.resolve(ctx.openOnlinePlatformAutoTab?.({ force: true }));
                    } else {
                        ctx.currentPage = String(topic.target_page || '');
                        if (topic.action_key === 'task-navigation' && ctx.knowledgeCenterFilter) {
                            ctx.knowledgeCenterFilter.keyword = String(turn?.query || '').trim();
                        }
                    }
                    if (turn?.result?.journey?.length) {
                        saveActiveJourney(turn.result, turn.query, String(topic.key || ''));
                    } else if (state.value.active_journey?.journey?.length) {
                        saveActiveJourney(state.value.active_journey, state.value.active_journey.original_query, String(topic.key || ''));
                    }
                    await focusTopicAnchor(topic);
                    return true;
                } catch (error) {
                    state.value.error = error?.message || '打开功能页面失败，请从左侧导航重试。';
                    return false;
                } finally {
                    state.value.opening_key = '';
                }
            };
            const runtimeText = (result) => {
                if (result?.mode !== 'intelligent') return '基础导航模式';
                if (result?.status === 'clarification_required') return '智能追问';
                const model = String(result?.runtime?.model || '').trim();
                return model ? `智能引导 · ${model}` : '智能引导';
            };
            const openOperatingWorkspace = async () => {
                const ctx = props.ctx || {};
                ctx.currentPage = 'agent-center';
                await nextTick();
                return focusTopicAnchor(topicByKey('agent-toolbox'));
            };
            const operatingScopeText = (result) => {
                const hotelId = Number(result?.hotel_id || 0);
                const hotel = (Array.isArray(props.ctx?.otaDiagnosisHotelOptions) ? props.ctx.otaDiagnosisHotelOptions : [])
                    .find((item) => Number(item?.value || 0) === hotelId);
                const hotelText = String(hotel?.name || (hotelId > 0 ? `酒店 #${hotelId}` : '未锁定酒店'));
                const platformText = String(props.ctx?.operatingQuestionPlatformText?.(result?.platform) || result?.platform || '未锁定平台');
                const dateStart = String(result?.date_start || '');
                const dateEnd = String(result?.date_end || '');
                const dateText = dateStart ? `${dateStart}${dateEnd && dateEnd !== dateStart ? ` 至 ${dateEnd}` : ''}` : '未锁定日期';
                return `${hotelText} · ${platformText} · ${dateText}`;
            };
            const renderOperatingResult = (guideResult, turn, isLatest = false) => {
                const assistantMode = String(guideResult?.assistant_mode || 'guide');
                if (!['report', 'action'].includes(assistantMode)) return null;
                const exact = guideResult?.operating_result || null;
                if (!exact) {
                    return h('section', {
                        class: 'sx-ai-consultant-operating-result is-blocked',
                        'data-testid': isLatest ? 'system-guide-operating-blocked' : undefined,
                    }, [
                        h('div', { class: 'sx-ai-consultant-operating-result-title' }, [
                            icon('fa-circle-exclamation'),
                            h('strong', assistantMode === 'action' ? '行动草案暂时不能生成' : '当前不能给出可信结论'),
                        ]),
                        h('p', String(guideResult?.operating_error || '当前范围没有取得可严格回读的经营事实。')),
                        h('div', { class: 'sx-ai-consultant-recovery-actions' }, [
                            h('button', {
                                type: 'button',
                                onClick: (event) => openTopic(event, topicByKey('data-health'), turn),
                            }, [icon('fa-shield-alt'), h('span', '去数据健康查阻塞')]),
                            h('button', { type: 'button', onClick: openOperatingWorkspace }, [
                                icon('fa-arrow-right'), h('span', '打开专业问答'),
                            ]),
                        ]),
                    ]);
                }
                const answer = exact.answer && typeof exact.answer === 'object' ? exact.answer : {};
                const keyPoints = Array.isArray(answer.key_points) ? answer.key_points.slice(0, 4).filter(Boolean) : [];
                const gaps = [
                    ...(Array.isArray(exact.data_gaps) ? exact.data_gaps.map((gap) => gap?.message || gap?.code || '').filter(Boolean) : []),
                    ...(Array.isArray(answer.missing_information) ? answer.missing_information.filter(Boolean) : []),
                ].slice(0, 5);
                const evidence = answer.evidence_counts && typeof answer.evidence_counts === 'object'
                    ? answer.evidence_counts
                    : {};
                const blocked = String(exact.answer_status || '') === 'blocked_by_missing_facts';
                const children = [
                    h('div', { class: 'sx-ai-consultant-operating-result-head' }, [
                        h('span', {
                            class: ['sx-ai-consultant-status', blocked ? 'is-blocked' : ''],
                        }, String(props.ctx?.operatingQuestionAnswerStatusText?.(exact.answer_status) || (blocked ? '缺少可信事实' : '已严格回读'))),
                        h('span', assistantMode === 'action' ? '行动草案模式' : '证据结论模式'),
                    ]),
                    h('p', { class: 'sx-ai-consultant-operating-scope' }, operatingScopeText(exact)),
                    h('p', { class: 'sx-ai-consultant-answer-summary' }, String(exact.answer_summary || '当前严格回读没有返回摘要。')),
                ];
                if (keyPoints.length) {
                    children.push(h('ul', { class: 'sx-ai-consultant-key-points' }, keyPoints.map((point, index) => (
                        h('li', { key: `operating-point-${index}` }, String(point))
                    ))));
                }
                if (gaps.length) {
                    children.push(h('div', { class: 'sx-ai-consultant-gaps' }, [
                        h('strong', '当前阻塞'),
                        h('ul', gaps.map((gap, index) => h('li', { key: `operating-gap-${index}` }, String(gap)))),
                    ]));
                }
                if (assistantMode === 'action') {
                    const action = Array.isArray(answer.action_drafts) ? answer.action_drafts[0] : null;
                    if (action) {
                        const ready = props.ctx?.operatingQuestionActionIsCurrent?.(
                            exact,
                            action,
                            props.ctx?.operatingQuestionForm || {}
                        ) === true;
                        const steps = Array.isArray(action.execution_steps) ? action.execution_steps.slice(0, 4) : [];
                        children.push(h('section', {
                            class: ['sx-ai-consultant-action-draft', ready ? 'is-ready' : 'is-blocked'],
                            'data-testid': isLatest ? 'system-guide-action-draft' : undefined,
                        }, [
                            h('div', { class: 'sx-ai-consultant-operating-result-title' }, [
                                icon(ready ? 'fa-clipboard-check' : 'fa-shield-alt'),
                                h('strong', String(action.title || '待人工确认的行动草案')),
                                h('span', ready ? '可复核' : '需补证据'),
                            ]),
                            h('p', String(action.action || action.blocked_reason || '当前回答没有形成可复核动作。')),
                            steps.length
                                ? h('ol', steps.map((step, index) => h('li', { key: `action-step-${index}` }, String(step))))
                                : null,
                            h('small', '这里只展示草案；不会自动采集、改价、写 OTA、创建任务或发送消息。'),
                        ].filter(Boolean)));
                    } else {
                        children.push(h('div', { class: 'sx-ai-consultant-gaps' }, [
                            h('strong', '没有可执行草案'),
                            h('p', '当前证据只够形成结论或缺口，尚不足以生成行动草案。'),
                        ]));
                    }
                }
                children.push(h('div', { class: 'sx-ai-consultant-evidence' }, [
                    h('span', `事实 ${Number(evidence.facts || 0)} · 知识 ${Number(evidence.knowledge_chunks || 0)} · 记忆 ${Number(evidence.operating_memories || 0)} · 复盘 ${Number(evidence.execution_reviews || 0)}`),
                    h('span', `已保存并严格回读 #${Number(exact.id || 0)}`),
                ]));
                children.push(h('div', { class: 'sx-ai-consultant-recovery-actions' }, [
                    blocked
                        ? h('button', {
                            type: 'button',
                            onClick: (event) => openTopic(event, topicByKey('data-health'), turn),
                        }, [icon('fa-shield-alt'), h('span', '补齐可信事实')])
                        : null,
                    h('button', {
                        type: 'button',
                        'data-testid': isLatest ? 'system-guide-open-operating-workspace' : undefined,
                        onClick: openOperatingWorkspace,
                    }, [icon('fa-arrow-right'), h('span', assistantMode === 'action' ? '到专业页面复核草案' : '查看完整证据与引用')]),
                ].filter(Boolean)));
                return h('section', {
                    class: ['sx-ai-consultant-operating-result', blocked ? 'is-blocked' : 'is-ready'],
                    'data-testid': isLatest ? 'system-guide-operating-result' : undefined,
                }, children);
            };
            const renderJourney = (result, turn = null, persistent = false) => {
                const journey = normalizeJourney(result?.journey, topicByKey(result?.topic_key));
                if (!journey.length) return null;
                const liveSteps = journey.map((step) => ({
                    ...step,
                    status: guideStepStatus(topicByKey(step.key)),
                }));
                const completedCount = liveSteps.filter((step) => step.status.complete).length;
                const firstIncomplete = liveSteps.find((step) => !step.status.complete);
                const activeKey = String(firstIncomplete?.key || result?.active_key || result?.topic_key || journey[journey.length - 1].key);
                const sourceTurn = turn || {
                    query: String(result?.original_query || ''),
                    result: {
                        ...result,
                        journey,
                    },
                };
                return h('section', {
                    class: ['sx-ai-consultant-journey', persistent ? 'is-persistent' : ''],
                    'data-testid': persistent ? 'system-guide-active-journey' : 'system-guide-journey',
                }, [
                    h('div', { class: 'sx-ai-consultant-journey-header' }, [
                        h('div', [
                            h('span', persistent ? '继续上次任务' : '你的任务路线'),
                            h('strong', {
                                'data-testid': persistent ? undefined : 'system-guide-journey-goal',
                            }, String(result?.goal || result?.intent_summary || journey[0].title)),
                            h('small', { class: 'sx-ai-consultant-journey-progress' }, `已核验 ${completedCount} / ${liveSteps.length}；未核验步骤不会被标成完成。`),
                        ]),
                        persistent
                            ? h('button', {
                                type: 'button',
                                class: 'sx-ai-consultant-journey-clear',
                                'data-testid': 'system-guide-journey-clear',
                                onClick: clearActiveJourney,
                            }, '结束引导')
                            : null,
                    ]),
                    h('ol', { class: 'sx-ai-consultant-journey-list' }, liveSteps.map((step, index) => {
                        const topic = topicByKey(step.key);
                        const current = isTopicCurrent(topic);
                        const active = step.key === activeKey;
                        const status = step.status;
                        return h('li', {
                            key: `journey-${step.key}`,
                            class: [active ? 'is-active' : '', current ? 'is-current' : '', `is-${status.key}`],
                            'data-testid': `system-guide-journey-step-${step.key}`,
                        }, [
                            h('span', { class: 'sx-ai-consultant-journey-index', 'aria-hidden': 'true' }, String(index + 1)),
                            h('div', { class: 'sx-ai-consultant-journey-content' }, [
                                h('div', { class: 'sx-ai-consultant-journey-title' }, [
                                    h('strong', String(step.title || topic?.title || '系统步骤')),
                                    h('span', { class: `is-${status.key}` }, String(status.label)),
                                ]),
                                h('p', String(step.summary || topic?.summary || '')),
                                h('p', { class: 'sx-ai-consultant-journey-live-status' }, [
                                    h('b', '当前状态：'),
                                    String(status.detail || '尚未核验。'),
                                ]),
                                h('small', [
                                    h('b', '确认标准：'),
                                    String(step.success_marker || SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS[step.key] || ''),
                                ]),
                                h('button', {
                                    type: 'button',
                                    disabled: !canOpenTopic(topic) || Boolean(state.value.opening_key),
                                    'data-testid': `system-guide-journey-open-${step.key}`,
                                    onClick: (event) => openTopic(event, topic, sourceTurn),
                                }, [
                                    icon(state.value.opening_key === step.key
                                        ? 'fa-spinner fa-spin'
                                        : (status.complete ? 'fa-check' : (current ? 'fa-crosshairs' : 'fa-arrow-right'))),
                                    h('span', current
                                        ? '在页面中指给我看'
                                        : (status.complete ? '重新核对' : String(topic?.action_label || '打开页面'))),
                                ]),
                            ]),
                        ]);
                    })),
                    h('p', { class: 'sx-ai-consultant-journey-note' }, '进度来自当前页面的可读状态与严格回读；仅到达页面不会被算作完成。模型不能创造页面或在这里写入业务数据。'),
                ]);
            };

            onMounted(() => {
                state.value.active_journey = readActiveJourney();
                readWidgetState();
                window.addEventListener('resize', handleWidgetViewportResize, { passive: true });
                nextTick(() => clampWidgetPosition(false));
            });
            onUnmounted(() => {
                window.removeEventListener('resize', handleWidgetViewportResize);
                if (resizeFrame) window.cancelAnimationFrame(resizeFrame);
                clearTopicCoach();
            });

            return () => {
                const conversation = [];
                if (state.value.turns.length === 0
                    && !state.value.active_journey
                    && !state.value.error
                    && !state.value.loading) {
                    conversation.push(h('div', { class: 'sx-ai-consultant-welcome' }, [
                        h('div', { class: 'sx-ai-consultant-message-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                        h('div', [
                            h('strong', '直接说你想完成什么，不用记模块名。'),
                            h('p', '我会结合当前页面和前面的对话理解你的目标；不确定时先问清楚，再带你进入真实功能。'),
                        ]),
                    ]));
                }
                if (state.value.error) {
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-error',
                        role: 'alert',
                        'data-testid': 'system-guide-error',
                    }, [icon('fa-exclamation-circle'), h('span', String(state.value.error))]));
                }
                state.value.turns.forEach((turn, index) => {
                    const result = turn.result || {};
                    const topic = topicByKey(result.topic_key);
                    const available = topic ? canOpenTopic(topic) : false;
                    const isLatest = index === state.value.turns.length - 1;
                    const assistantMode = String(result.assistant_mode || 'guide');
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-user-message',
                        key: `${turn.id}-user`,
                    }, [h('p', String(turn.query || ''))]));
                    const answerChildren = [
                        h('div', { class: 'sx-ai-consultant-answer-meta' }, [
                            h('span', {
                                class: ['sx-ai-consultant-status', result.mode === 'fallback' ? 'is-blocked' : ''],
                            }, runtimeText(result)),
                            h('span', {
                                class: `sx-ai-consultant-mode-badge is-${assistantMode}`,
                                'data-testid': isLatest ? 'system-guide-mode' : undefined,
                            }, String(SYSTEM_ASSISTANT_MODE_LABELS[assistantMode] || '使用指导')),
                        ]),
                        h('p', {
                            class: 'sx-ai-consultant-answer-summary',
                        }, String(result.topic?.title || (result.topic_key === 'clarify' ? '先确认你的目标' : '系统使用引导'))),
                        h('p', String(result.assistant_message || '')),
                    ];
                    const operatingResult = renderOperatingResult(result, turn, isLatest);
                    if (operatingResult) answerChildren.push(operatingResult);
                    const journeyCard = renderJourney(result, turn);
                    if (journeyCard) answerChildren.push(journeyCard);
                    if (result.clarifying_question) {
                        answerChildren.push(h('div', {
                            class: 'sx-ai-consultant-gaps',
                            'data-testid': isLatest ? 'system-guide-clarifying-question' : undefined,
                        }, [
                            h('strong', '我需要确认一件事'),
                            h('p', String(result.clarifying_question)),
                        ]));
                    }
                    if (Array.isArray(result.steps) && result.steps.length) {
                        answerChildren.push(h('div', { class: 'sx-ai-consultant-step-guide' }, [
                            h('strong', '当前这一步怎么做'),
                            h('ol', { class: 'sx-ai-consultant-key-points' }, result.steps.map((step, stepIndex) => (
                                h('li', { key: `${turn.id}-step-${stepIndex}` }, `${stepIndex + 1}. ${String(step)}`)
                            ))),
                        ]));
                    }
                    if (result.boundary) {
                        answerChildren.push(h('div', { class: 'sx-ai-consultant-gaps' }, [
                            h('strong', '操作边界'),
                            h('p', String(result.boundary)),
                        ]));
                    }
                    if (topic && !journeyCard) {
                        answerChildren.push(h('section', { class: 'sx-ai-consultant-recovery' }, [
                            h('div', { class: 'sx-ai-consultant-recovery-title' }, [
                                icon('fa-location-arrow'),
                                h('strong', available ? '下一步直接进入' : '当前账号暂无入口'),
                            ]),
                            h('div', { class: 'sx-ai-consultant-recovery-actions' }, [
                                h('button', {
                                    type: 'button',
                                    disabled: !available || Boolean(state.value.opening_key),
                                    'data-testid': isLatest
                                        ? `system-guide-open-${String(topic.key)}`
                                        : `system-guide-open-history-${index}-${String(topic.key)}`,
                                    onClick: (event) => openTopic(event, topic, turn),
                                }, [
                                    icon(state.value.opening_key === topic.key ? 'fa-spinner fa-spin' : 'fa-arrow-right'),
                                    h('span', available ? String(topic.action_label || '打开页面') : '请联系管理员授权'),
                                ]),
                            ]),
                            h('small', '模型只负责理解和说明；导航目标来自系统白名单，不会在这里写入业务数据。'),
                        ]));
                    }
                    const followUps = Array.isArray(result.follow_up_questions)
                        ? result.follow_up_questions.slice(0, 3).filter(Boolean)
                        : [];
                    if (followUps.length) {
                        answerChildren.push(h('div', { class: 'sx-ai-consultant-follow-ups' }, [
                            h('span', '可以接着问'),
                            ...followUps.map((question) => h('button', {
                                key: `${turn.id}-${String(question)}`,
                                type: 'button',
                                onClick: () => applyFollowUp(question),
                            }, String(question))),
                        ]));
                    }
                    answerChildren.push(h('div', { class: 'sx-ai-consultant-evidence' }, [
                        h('span', `当前页面：${currentPageText()}`),
                        h('span', result.mode === 'intelligent'
                            ? (assistantMode === 'guide' ? 'DeepSeek直接生成 · 真实入口约束' : 'DeepSeek识别意图 · 结论由严格证据回读生成')
                            : '智能模型不可用 · 基础规则兜底'),
                    ]));
                    conversation.push(h('article', {
                        key: `${turn.id}-answer`,
                        class: 'sx-ai-consultant-answer',
                        'data-testid': isLatest ? 'system-guide-result' : `system-guide-result-history-${index}`,
                    }, answerChildren));
                });
                if (state.value.loading) {
                    conversation.push(h('div', {
                        class: 'sx-ai-consultant-loading',
                        role: 'status',
                        'data-testid': 'system-guide-loading',
                    }, [
                        icon('fa-spinner fa-spin'),
                        h('span', '正在理解你的目标并核对可用入口…'),
                    ]));
                }

                const modeSwitcher = h('div', {
                    class: 'sx-ai-consultant-mode-switcher',
                    role: 'group',
                    'aria-label': '助手工作方式',
                    'data-testid': 'system-guide-mode-switcher',
                }, SYSTEM_ASSISTANT_MODE_OPTIONS.map((option) => h('button', {
                    key: option.key,
                    type: 'button',
                    class: state.value.selected_mode === option.key ? 'is-active' : '',
                    disabled: state.value.loading,
                    'aria-pressed': state.value.selected_mode === option.key ? 'true' : 'false',
                    'data-testid': `system-guide-mode-${option.key}`,
                    onClick: () => setAssistantMode(option.key),
                }, [icon(option.icon), h('span', option.label)])));
                const suggestions = h('div', {
                    class: 'sx-ai-consultant-suggestions',
                    'aria-label': '常用系统任务',
                }, suggestionTopics().map((topic) => h('button', {
                    key: topic.key,
                    type: 'button',
                    disabled: state.value.loading,
                    onClick: () => applySuggestion(topic),
                }, String(topic.example || topic.title))));
                const composer = h('form', {
                    class: 'sx-ai-consultant-composer',
                    onSubmit: (event) => {
                        event?.preventDefault?.();
                        ask();
                    },
                }, [
                    h('textarea', {
                        value: String(state.value.query || ''),
                        maxlength: 500,
                        rows: 2,
                        disabled: state.value.loading,
                        placeholder: state.value.selected_mode === 'report'
                            ? '例如：给我这家酒店今天携程经营情况的结论和证据缺口'
                            : (state.value.selected_mode === 'action'
                                ? '例如：根据今天的可信事实，帮我生成待人工确认的行动草案'
                                : '例如：我刚接手这家店，携程数据没进来，应该先做什么？'),
                        'data-testid': 'system-guide-input',
                        onInput: (event) => {
                            state.value.query = String(event?.target?.value || '');
                            state.value.error = '';
                        },
                        onKeydown: (event) => {
                            if (event?.key !== 'Enter' || event.shiftKey || event.isComposing) return;
                            event.preventDefault();
                            ask();
                        },
                    }),
                    h('button', {
                        type: 'submit',
                        disabled: state.value.loading || !String(state.value.query || '').trim(),
                        'data-testid': 'system-guide-submit',
                        'aria-label': '让智能助手理解并引导',
                    }, [icon(state.value.loading ? 'fa-spinner fa-spin' : 'fa-arrow-up')]),
                    h('p', state.value.selected_mode === 'guide'
                        ? '页面内教用 + 真实状态核验'
                        : '结论与行动草案基于同酒店、同平台、同日期的严格保存回读事实'),
                ]);

                const coach = state.value.coach;
                const coachTopic = topicByKey(coach?.topic_key);
                const coachStatus = guideStepStatus(coachTopic);
                const coachCard = coach
                    ? h('aside', {
                        class: ['sx-system-guide-coach', coach.target_found ? 'is-found' : 'is-blocked'],
                        role: 'status',
                        'data-testid': 'system-guide-page-coach',
                    }, [
                        h('div', { class: 'sx-system-guide-coach-head' }, [
                            h('span', { class: 'sx-system-guide-coach-icon', 'aria-hidden': 'true' }, [icon(coach.checking ? 'fa-spinner fa-spin' : (coach.target_found ? 'fa-crosshairs' : 'fa-circle-exclamation'))]),
                            h('div', [
                                h('small', coach.target_found ? '我在页面里指给你看' : '页面区域尚未就绪'),
                                h('strong', String(coachTopic?.title || '当前操作区域')),
                            ]),
                            h('button', { type: 'button', 'aria-label': '关闭页面指引', onClick: clearTopicCoach }, [icon('fa-xmark')]),
                        ]),
                        h('p', coach.checking
                            ? '正在等待目标区域加载…'
                            : (coach.target_found
                                ? String(coachStatus.detail || '已高亮当前需要核对的区域。')
                                : '目标区域没有加载出来。请先确认当前账号权限、酒店范围和页面接口状态。')),
                        h('div', { class: 'sx-system-guide-coach-actions' }, [
                            h('span', { class: `is-${coachStatus.key}` }, String(coachStatus.label)),
                            h('button', { type: 'button', onClick: () => focusTopicAnchor(coachTopic) }, [
                                icon('fa-rotate'), h('span', '重新检查'),
                            ]),
                            h('button', { type: 'button', onClick: () => { widgetOpen.value = true; if (widgetRoot.value) widgetRoot.value.open = true; clearTopicCoach(); } }, [
                                icon('fa-comment-dots'), h('span', '打开助手'),
                            ]),
                        ]),
                    ])
                    : null;

                return h('div', { class: 'sx-ai-consultant-shell' }, [h('details', {
                    ref: widgetRoot,
                    class: ['sx-ai-consultant', widgetDragging.value ? 'is-dragging' : ''],
                    open: widgetOpen.value,
                    style: widgetStyle.value,
                    'data-testid': 'system-guide-floating-entry',
                    onToggle: handleWidgetToggle,
                }, [
                    h('summary', {
                        class: 'sx-ai-consultant-launcher',
                        'data-testid': 'system-guide-floating-launcher',
                        'aria-label': widgetOpen.value ? '收起宿析智能使用助手' : '打开宿析智能使用助手',
                        title: widgetOpen.value ? '收起为悬浮按钮' : '打开助手；按住可移动',
                        onPointerdown: (event) => startWidgetDrag(event, 'launcher'),
                        onPointermove: moveWidgetDrag,
                        onPointerup: endWidgetDrag,
                        onPointercancel: endWidgetDrag,
                        onClick: handleLauncherClick,
                    }, [
                        h('span', { class: 'sx-ai-consultant-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                        h('span', { class: 'sx-ai-consultant-launcher-label' }, '打开助手'),
                        h('span', { class: 'sx-ai-consultant-close' }, [
                            icon('fa-chevron-down'),
                            h('span', '收起'),
                        ]),
                    ]),
                    h('section', {
                        class: 'sx-ai-consultant-panel',
                        role: 'dialog',
                        'aria-label': '宿析OS智能使用助手',
                        'data-testid': 'system-guide-floating-panel',
                    }, [
                        h('header', {
                            class: 'sx-ai-consultant-header',
                            'data-testid': 'system-guide-drag-handle',
                            title: '按住并拖动移动助手',
                            onPointerdown: (event) => startWidgetDrag(event, 'panel'),
                            onPointermove: moveWidgetDrag,
                            onPointerup: endWidgetDrag,
                            onPointercancel: endWidgetDrag,
                        }, [
                            h('div', { class: 'sx-ai-consultant-header-avatar', 'aria-hidden': 'true' }, [icon('fa-sparkles')]),
                            h('div', { class: 'sx-ai-consultant-header-copy' }, [
                                h('div', { class: 'sx-ai-consultant-title' }, '宿析智能使用助手'),
                                h('p', '教你使用 · 给出证据结论 · 生成行动草案'),
                            ]),
                            h('span', { class: 'sx-ai-consultant-drag-hint', 'aria-hidden': 'true' }, [
                                icon('fa-grip-lines'),
                                h('span', '拖动'),
                            ]),
                        ]),
                        h('div', { class: 'sx-ai-consultant-body' }, [
                            h('p', { class: 'sx-ai-consultant-boundary' }, '只使用当前账号可见的真实功能。报告提问会保存并严格回读；行动草案不会自动执行，执行或外发仍需到专业页面人工确认。'),
                            modeSwitcher,
                            state.value.turns.length === 0 && state.value.active_journey
                                ? renderJourney(state.value.active_journey, null, true)
                                : null,
                            suggestions,
                            h('div', {
                                class: 'sx-ai-consultant-conversation',
                                'aria-live': 'polite',
                                'aria-atomic': 'false',
                            }, conversation),
                        ]),
                        composer,
                    ]),
                ]), coachCard]);
            };
        },
    };

        return Object.freeze({ operatingQuestionPanel, operatingQuestionConsultant });
    };

    const exportedFactory = Object.freeze({ create });
    window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL = exportedFactory;
    if (!window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS) {
        window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS = exportedFactory;
    }
})();
