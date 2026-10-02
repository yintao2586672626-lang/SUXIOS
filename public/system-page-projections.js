window.SUXI_SYSTEM_PAGE_PROJECTIONS = (() => {
    const buildInvestmentDecisionActionQueueRows = ({ investmentDecisionOverview, investmentDecisionBusinessChainRows, investmentDecisionGapTitle, investmentDecisionGapAction, investmentDecisionRiskRows }) => {
    const backendRows = Array.isArray(investmentDecisionOverview.value?.action_queue?.items)
        ? investmentDecisionOverview.value.action_queue.items
        : [];
    if (backendRows.length) {
        return backendRows.map((row, index) => ({
            key: row.key || `backend-action:${index}`,
            priority: row.priority || 4,
            priority_label: row.priority_label || '',
            severity: row.severity || (row.blocking ? 'high' : 'medium'),
            sourceLabel: row.section_title || row.source || '动作队列',
            stageTitle: row.stage_title || row.stage_key || '-',
            title: row.title || row.evidence_code || '证据缺口',
            nextAction: row.next_action || '',
            next_action: row.next_action || '',
            status: row.blocking ? 'blocked' : (row.status || 'has_action'),
            blocking: row.blocking === true,
            evidence_code: row.evidence_code || '',
        }));
    }

    const rows = [];
    const addRow = (row) => {
        const title = String(row.title || '').trim();
        const nextAction = String(row.nextAction || '').trim();
        if (!title && !nextAction) return;
        rows.push({
            key: row.key || `${row.sourceLabel || 'gap'}:${title}:${nextAction}`,
            severity: row.severity || 'medium',
            sourceLabel: row.sourceLabel || '业务闭环',
            stageTitle: row.stageTitle || '-',
            title: title || '证据缺口',
            nextAction,
            next_action: nextAction,
            status: row.status || 'data_gap',
            blocking: row.blocking === true,
            priority: row.severity === 'high' ? 1 : (row.severity === 'medium' ? 3 : 4),
            priority_label: row.severity === 'high' ? '先处理' : '补证',
            evidence_code: row.evidenceCode || '',
        });
    };

    investmentDecisionBusinessChainRows.value.forEach((stage) => {
        const gaps = Array.isArray(stage?.missing_evidence) ? stage.missing_evidence : [];
        gaps.forEach((gap, index) => addRow({
            key: `chain:${stage.key || 'stage'}:${gap?.code || index}`,
            severity: stage?.blocking ? 'high' : 'medium',
            sourceLabel: '业务闭环',
            stageTitle: stage?.title || stage?.key || '-',
            title: investmentDecisionGapTitle(gap),
            nextAction: investmentDecisionGapAction(gap),
            status: stage?.status || 'data_gap',
            blocking: stage?.blocking === true,
        }));
    });

    (investmentDecisionOverview.value?.operating_data_gate?.missing_evidence || []).forEach((gap, index) => addRow({
        key: `gate:${gap?.code || index}`,
        severity: 'high',
        sourceLabel: '经营准入',
        stageTitle: '运营管理',
        title: investmentDecisionGapTitle(gap),
        nextAction: investmentDecisionGapAction(gap),
        status: investmentDecisionOverview.value?.operating_data_gate?.status || 'not_ready',
        blocking: true,
    }));

    investmentDecisionRiskRows.value.forEach((risk, index) => addRow({
        key: `risk:${risk?.code || index}:${risk?.title || ''}`,
        severity: risk?.severity || (risk?.blocking ? 'high' : 'medium'),
        sourceLabel: '风险提示',
        stageTitle: risk?.blocking ? '投决阻断' : '补证任务',
        title: risk?.title || risk?.code || '风险提示',
        nextAction: risk?.next_action || '',
        status: risk?.blocking ? 'blocked' : 'has_risk',
        blocking: risk?.blocking === true,
    }));

    const unique = new Map();
    rows.forEach((row) => {
        const key = `${row.title}|${row.nextAction}|${row.stageTitle}`;
        if (!unique.has(key)) unique.set(key, row);
    });
    return Array.from(unique.values()).sort((left, right) => {
        const priority = { high: 0, medium: 1, low: 2 };
        return (priority[left.severity] ?? 3) - (priority[right.severity] ?? 3);
    }).slice(0, 10);
};
    const buildManualNotificationThreeSourceSummary = ({ manualNotificationOperatingDailyPlans, manualNotificationForm, operationToday, manualNotificationDispatchHistory, manualNotificationMetadata, manualNotificationPlanIsActive }) => {
    const plans = manualNotificationOperatingDailyPlans.value;
    const selectedBusinessDate = String(
        manualNotificationForm.value?.business_date || operationToday || ''
    ).trim();
    const dispatches = (manualNotificationDispatchHistory.value?.list || []).filter(
        item => String(item?.status || '').toLowerCase() === 'sent'
    );
    const definitions = [
        { key: 'ctrip', label: '携程', scopeLabel: 'OTA 渠道' },
        { key: 'meituan', label: '美团', scopeLabel: 'OTA 渠道' },
        { key: 'dingdandao_pms', label: 'PMS', scopeLabel: '订单来了' },
    ];
    const normalizeSourceKey = (value) => (
        ['pms', 'dingdandao'].includes(String(value || ''))
            ? 'dingdandao_pms'
            : String(value || '')
    );
    const planSourceKeys = (item) => {
        const sourceScope = normalizeSourceKey(item?.source_scope || 'combined');
        if (sourceScope !== 'combined') {
            return [sourceScope];
        }
        const sections = Array.isArray(item?.content_sections)
            ? item.content_sections
            : String(item?.content_sections || '')
                .split(',')
                .map(value => value.trim())
                .filter(Boolean);
        if (!sections.length) {
            return definitions.map(definition => definition.key);
        }
        const metadataSections = manualNotificationMetadata.value?.content_sections || [];
        return [...new Set(sections.flatMap((section) => {
            const definition = metadataSections.find(item => item?.key === section);
            const scoped = (definition?.source_scopes || [])
                .filter(scope => scope !== 'combined')
                .map(normalizeSourceKey);
            if (scoped.length) return scoped;
            if (String(section).startsWith('pms_')) return ['dingdandao_pms'];
            if (/^(ctrip|qunar)_/.test(String(section))) return ['ctrip'];
            if (String(section).startsWith('meituan_')) return ['meituan'];
            return [];
        }))].filter(key => definitions.some(definition => definition.key === key));
    };
    const cards = definitions.map((definition) => {
        const relatedPlans = plans.filter(
            item => planSourceKeys(item).includes(definition.key)
        );
        const relatedPlanIds = new Set(
            relatedPlans.map(item => Number(item?.id || 0)).filter(id => id > 0)
        );
        const relatedDispatches = dispatches.filter(
            item => relatedPlanIds.has(Number(item?.notification_id || 0))
        );
        const currentDelivery = relatedDispatches.find(
            item => String(item?.business_date || '') === selectedBusinessDate
        ) || null;
        const latestDelivery = relatedDispatches[0] || null;
        const activePlans = relatedPlans.filter(
            manualNotificationPlanIsActive
        );
        const active = activePlans.length > 0;
        const activeModes = [...new Set(activePlans.map(item => (
            String(item?.send_method || '') === 'wecom_formal'
                ? 'formal'
                : 'test'
        )))];
        return {
            ...definition,
            integrated: relatedPlans.length > 0,
            active,
            activeModes,
            selectedBusinessDate,
            currentDelivery,
            latestDelivery,
            currentDeliveredAt: currentDelivery
                ? String(
                    currentDelivery.dispatched_at
                    || currentDelivery.last_attempt_at
                    || currentDelivery.claimed_at
                    || ''
                )
                : '',
            latestDeliveryBusinessDate: String(latestDelivery?.business_date || ''),
            latestDeliveredAt: latestDelivery
                ? String(
                    latestDelivery.dispatched_at
                    || latestDelivery.last_attempt_at
                    || latestDelivery.claimed_at
                    || ''
                )
                : '',
        };
    });
    return {
        cards,
        selectedBusinessDate,
        integratedCount: cards.filter(item => item.integrated).length,
        activeCount: cards.filter(item => item.active).length,
        activeModes: [...new Set(cards.flatMap(item => item.activeModes || []))],
        deliveredCount: cards.filter(item => item.currentDelivery !== null).length,
    };
};
    const buildManualNotificationAutomaticTaskRows = ({ manualNotificationMetadata, manualNotificationThreeSourceSummary, manualNotificationSchedulerDisplay, escapeManualNotificationTaskText, manualNotificationLoading }) => {
    const overview = manualNotificationMetadata.value?.automatic_tasks || {};
    const tasks = Array.isArray(overview.tasks) ? overview.tasks : [];
    const sourceSummary = manualNotificationThreeSourceSummary.value;
    const schedulerDisplay = manualNotificationSchedulerDisplay.value;
    const header = `<header class="border-b border-slate-100 bg-[#f7f9f8] p-5 pr-24">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-slate-900">${escapeManualNotificationTaskText(overview?.hotel?.name, '当前酒店')}自动推送</h2>
            <span class="rounded-full border px-2.5 py-1 text-xs font-medium ${schedulerDisplay.className}" title="${escapeManualNotificationTaskText(schedulerDisplay.note)}">${escapeManualNotificationTaskText(schedulerDisplay.label)}</span>
        </div>
        <span class="sr-only" data-testid="manual-notification-automatic-source">${escapeManualNotificationTaskText(overview.source_label, '待核验')}</span>
    </header>`;
    const sourceCards = `<section class="border-b border-slate-100 px-5 py-4" data-testid="manual-notification-three-source-status">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-slate-800">三源推送回执</h3>
            <p class="text-xs text-slate-500">核对业务日 ${escapeManualNotificationTaskText(sourceSummary.selectedBusinessDate, '未取得')}；历史回执不计作本日送达</p>
        </div>
        <div class="mt-3 grid gap-3 md:grid-cols-3">
            ${sourceSummary.cards.map((source) => {
                const badgeClass = source.currentDelivery
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : source.active
                        ? 'border-sky-200 bg-sky-50 text-sky-700'
                    : source.latestDelivery
                        ? 'border-slate-200 bg-slate-50 text-slate-600'
                    : source.integrated
                        ? 'border-amber-200 bg-amber-50 text-amber-700'
                        : 'border-slate-200 bg-slate-50 text-slate-500';
                const badge = source.currentDelivery
                    ? '本业务日已送达'
                    : source.active
                        ? '自动发送'
                    : source.latestDelivery
                        ? '历史送达'
                    : source.integrated
                        ? '已配置'
                        : '未配置';
                const evidence = source.currentDelivery
                    ? `业务日 ${escapeManualNotificationTaskText(source.selectedBusinessDate, '未取得')} · 回执 ${escapeManualNotificationTaskText(source.currentDeliveredAt, '时间未取得')}`
                    : source.latestDelivery
                        ? `最近业务日 ${escapeManualNotificationTaskText(source.latestDeliveryBusinessDate, '未取得')} · 回执 ${escapeManualNotificationTaskText(source.latestDeliveredAt, '时间未取得')}`
                    : source.integrated
                        ? (source.active ? '等待送达回执' : '自动发送未启用')
                        : '待配置';
                return `<article class="rounded-xl border border-slate-200 bg-white p-3" data-testid="manual-notification-source-${source.key}" title="${escapeManualNotificationTaskText(source.scopeLabel)}">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-slate-900">${source.label}</p>
                        <span class="rounded-full border px-2 py-0.5 text-[11px] font-medium ${badgeClass}">${badge}</span>
                    </div>
                    <p class="mt-2 truncate text-xs text-slate-500" title="${evidence}">${evidence}</p>
                </article>`;
            }).join('')}
        </div>
    </section>`;
    const renderTask = (task) => {
        const mode = task?.delivery_mode === 'scheduled_send'
            ? '固定发送'
            : task?.delivery_mode === 'manual_schedule'
                ? '计划配置'
                : '条件触发';
        const notificationId = Number(task?.notification_id || 0);
        const editable = task?.editable === true && notificationId > 0;
        const action = editable
            ? `<button type="button" class="rounded-lg border border-[#d8c49f] bg-[#fffaf0] px-3 py-1.5 text-xs font-semibold text-[#826333] hover:border-[#ad8b52]" data-manual-notification-edit-id="${notificationId}">${escapeManualNotificationTaskText(task?.edit_label, '编辑计划')}</button>`
            : '<span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-500">云端固定任务</span>';
        const planStatus = task?.plan_status_label
            ? `<span class="rounded-full border border-[#d8c49f] bg-[#fffaf0] px-2 py-0.5 text-[11px] text-[#826333]">${escapeManualNotificationTaskText(task.plan_status_label)}</span>`
            : '';
        const sourceScope = editable && task?.source_scope_label
            ? `<span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] text-slate-600">${escapeManualNotificationTaskText(task.source_scope_label)}</span>`
            : '';
        const nextRunLabel = task?.next_run_at
            || (String(task?.trigger_type || '') === 'manual_test'
                ? '无定时运行'
                : String(task?.plan_status || '') === 'blocked'
                    ? '计划已阻断'
                    : ['paused', 'attention'].includes(String(task?.status || ''))
                        ? '计划未启用'
                        : '下次时间未取得');
        return `<article class="p-5 text-sm" data-testid="manual-notification-automatic-task-${escapeManualNotificationTaskText(task?.key, 'unknown')}">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium text-slate-900">${escapeManualNotificationTaskText(task?.name)}</p>
                        ${sourceScope}
                        ${planStatus}
                    </div>
                    <p class="mt-1 text-slate-600" title="${escapeManualNotificationTaskText(task?.delivery_rule, '发送条件未取得')}">${escapeManualNotificationTaskText(task?.schedule, '时间未取得')} · ${mode}</p>
                    <p class="mt-1 truncate text-xs text-slate-400" title="${escapeManualNotificationTaskText(task?.last_result)}">最近 ${escapeManualNotificationTaskText(task?.last_result)} · 下次 ${escapeManualNotificationTaskText(nextRunLabel)}</p>
                </div>
                <div class="shrink-0">${action}</div>
            </div>
        </article>`;
    };
    const hotelPlans = tasks.filter(
        task => task?.editable === true && Number(task?.notification_id || 0) > 0
    );
    const safeguards = tasks.filter(
        task => task?.editable !== true || Number(task?.notification_id || 0) <= 0
    );
    const planSection = `<section data-testid="manual-notification-hotel-plans">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
            <h3 class="text-sm font-semibold text-slate-800">推送计划 <span class="font-normal text-slate-400">${hotelPlans.length}</span></h3>
            <button type="button" class="rounded-lg border border-[#ad8b52] bg-[#fff7e8] px-3 py-1.5 text-xs font-semibold text-[#826333] hover:bg-[#fffaf0]" data-manual-notification-create-hourly="1">一键三源整点推送</button>
        </div>
        ${hotelPlans.length
            ? `<div class="divide-y divide-slate-100">${hotelPlans.map(renderTask).join('')}</div>`
            : `<p class="px-5 py-6 text-sm text-slate-500">${manualNotificationLoading.value.metadata
                ? '读取中…'
                : '暂无计划，可在下方新建。'}</p>`}
    </section>`;
    const safeguardStatus = overview.is_stale
        ? {
            label: '核验记录已过期',
            className: 'border-amber-200 bg-amber-50 text-amber-700',
            evidence: `历史核验 ${escapeManualNotificationTaskText(overview.observed_at, '时间未取得')} · 仅作参考`,
        }
        : {
            label: overview.source_status === 'live' ? '云端已核验' : '状态待核验',
            className: overview.source_status === 'live'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-slate-200 bg-slate-50 text-slate-600',
            evidence: overview.observed_at
                ? `最近核验 ${escapeManualNotificationTaskText(overview.observed_at)}`
                : '尚未取得云端核验时间',
        };
    const safeguardSection = safeguards.length
        ? `<details class="border-t border-slate-100" data-testid="manual-notification-system-safeguards">
            <summary class="cursor-pointer px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pr-2">
                    <p class="text-sm font-semibold text-slate-700">系统保障 <span class="font-normal text-slate-400">${safeguards.length}</span></p>
                    <span class="rounded-full border px-2.5 py-1 text-xs font-medium ${safeguardStatus.className}">${safeguardStatus.label}</span>
                </div>
            </summary>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500" title="${safeguardStatus.evidence}">只读系统任务，不代表酒店计划已启用。</p>
            <div class="divide-y divide-slate-100 border-t border-slate-100">${safeguards.map(renderTask).join('')}</div>
        </details>`
        : '';
    return `${header}${sourceCards}${planSection}${safeguardSection}`;
};
    const buildEmployeeOtaChecklistRows = ({ dataHealthPriorityText, employeeOtaChecklistCategoryClass, employeeOtaChecklistCategoryText, dataHealthPriorityClass, otaTodayCollectionReminderRows, dataAcquisitionWorkbenchRows, dailyWorkbenchRows, dailyWorkbenchStatusText, dailyWorkbenchStatusClass, dailyWorkbenchPatrolVisibleActions, dailyWorkbenchPatrolTaskId, dailyWorkbenchPatrolTrackedStatusText, dailyWorkbenchPatrolExecutionText, dailyWorkbenchPatrolLatest, dataHealthTodayWorkOrders, employeeOtaChecklistPriorityRank }) => {
    const rows = [];
    const pushRow = (row) => {
        if (!row || !row.key) return;
        rows.push({
            ...row,
            priorityText: dataHealthPriorityText(row.priority || 'medium'),
            categoryClass: employeeOtaChecklistCategoryClass(row.category),
            categoryText: employeeOtaChecklistCategoryText(row.category),
            statusClass: row.statusClass || dataHealthPriorityClass(row.priority || 'medium'),
            boundaryText: row.boundaryText || 'OTA渠道口径；缺失证据保持待证明。',
            verificationText: row.verificationText || '复核完成后刷新数据健康和每日工作台。',
        });
    };

    otaTodayCollectionReminderRows.value.forEach((item, index) => {
        const platform = String(item.platform || '').toLowerCase();
        const canManualFetch = ['ctrip', 'meituan'].includes(platform);
        const needsAction = String(item.status || '') !== 'ready';
        pushRow({
            key: `health-${item.key || index}`,
            category: 'health',
            priority: item.priority || (item.status === 'ready' ? 'ok' : 'high'),
            objectText: item.platformLabel || 'OTA平台',
            objectRawText: item.platform || '',
            sourceText: item.sourceLabel || '当日采集',
            statusText: item.statusText || (item.status === 'ready' ? '当日已采到' : '当日未采到'),
            statusClass: item.className,
            evidenceText: item.detail || item.targetText || '未返回目标日采集证据',
            evidenceRawText: [item.targetText, item.latestText].filter(Boolean).join('；'),
            actionText: item.nextActionText || '核对采集配置和目标日入库证据',
            actionRawText: item.nextActionText || '',
            entryText: item.entryText || '',
            entryRawText: item.entryRawText || '',
            verificationText: item.proofText || '完成判定：目标日有 OTA 入库数据。',
            boundaryText: item.boundaryText || '只按 OTA 渠道目标日证据判断。',
            actionType: needsAction ? (canManualFetch ? 'manual_fetch_platform' : 'config') : '',
            actionPlatform: platform,
            primaryButtonText: needsAction ? (canManualFetch ? `补抓${item.platformLabel || '平台'}` : '查看配置') : '',
        });
    });

    dataAcquisitionWorkbenchRows.value
        .filter(item => item.showFetchButton || item.fieldHasGap)
        .forEach((item, index) => {
            const blocked = item.acquisitionStatusKind === 'blocked' || !item.canCookieFetch;
            pushRow({
                key: `gap-${item.hotelId || index}-${item.acquisitionStatusKind}`,
                category: 'gap',
                priority: blocked ? 'high' : 'medium',
                objectText: item.hotelName || `门店 ${item.hotelId || '-'}`,
                objectRawText: String(item.hotelId || ''),
                sourceText: `${item.targetDate || '目标日'} · ${item.sourceRowsText}`,
                statusText: item.acquisitionStatusText || '待确认',
                statusClass: item.acquisitionStatusClass,
                evidenceText: [item.issueText, item.fieldBriefText].filter(Boolean).join('；') || '缺口待复核',
                evidenceRawText: item.issueRawText || item.fieldIssueRawText || '',
                actionText: item.actionRawText || item.actionText || '按数据健康缺口处理',
                actionRawText: item.nextActionRawText || item.actionRawText || '',
                entryText: item.canCookieFetch ? '手动一键获取' : '配置：平台账号/自动采集',
                verificationText: '完成判定：目标日源数据行、字段可信状态和缺口状态同步转为已证明。',
                boundaryText: '仅整理采集/字段缺口；不改携程或美团采集口径。',
                actionType: item.canCookieFetch ? 'manual_fetch_all' : 'config',
                primaryButtonText: item.canCookieFetch ? '手动补抓' : '查看配置',
            });
        });

    dailyWorkbenchRows.value
        .filter(item => String(item.status) !== 'complete')
        .forEach((item, index) => {
            const priority = String(item.nextAction?.priority || '').toLowerCase() || (String(item.status) === 'request_failed' ? 'high' : 'medium');
            pushRow({
                key: `anomaly-${item.hotelId || index}-${item.status}`,
                category: 'anomaly',
                priority,
                objectText: item.hotelName || `门店 ${item.hotelId || '-'}`,
                objectRawText: String(item.hotelId || ''),
                sourceText: item.provedText || '员工六问',
                statusText: dailyWorkbenchStatusText(item.status),
                statusClass: dailyWorkbenchStatusClass(item.status),
                evidenceText: item.workflowChainText || item.metricGapText || '链路证据待复核',
                evidenceRawText: item.workflowChainRawText || item.aiEvidenceRawText || '',
                actionText: item.nextActionText || '复核异常链路并补齐证据',
                actionRawText: item.nextActionRawText || '',
                entryText: item.nextAction?.entry || item.nextActionMetaText || '',
                entryRawText: item.nextActionMetaRawText || '',
                verificationText: item.aiEvidenceNextStepText || '完成判定：异常对应阶段不再阻断，动作进入执行或复盘。',
                boundaryText: item.aiEvidenceBoundaryText || '不把缺失证据转成 AI 可执行结论。',
                actionType: 'diagnosis',
                primaryButtonText: '完整诊断',
            });
        });

    const actionItems = dailyWorkbenchPatrolVisibleActions.value;
    actionItems.forEach((item, index) => {
        const priority = String(item.priority || '').toLowerCase() || 'medium';
        const taskId = dailyWorkbenchPatrolTaskId(item);
        pushRow({
            key: `action-${item.hotelId || index}-${item.actionCode || item.questionKey || index}`,
            category: 'action',
            priority,
            objectText: item.hotelName || `门店 ${item.hotelId || '-'}`,
            objectRawText: String(item.hotelId || ''),
            sourceText: [item.platform, item.questionKey].filter(Boolean).join(' · ') || '每日巡检动作',
            statusText: dailyWorkbenchPatrolTrackedStatusText(item),
            statusClass: dataHealthPriorityClass(priority),
            evidenceText: item.actionCode || item.questionKey || '动作编码未返回',
            evidenceRawText: item.actionRawText || '',
            actionText: item.actionText || '处理并留痕',
            actionRawText: item.actionRawText || '',
            entryText: item.entry || '',
            verificationText: dailyWorkbenchPatrolExecutionText(item) || (taskId ? `任务 #${taskId}` : '生成巡检快照后可标记执行和复查。'),
            boundaryText: '动作来自每日工作台/巡检快照；只记录员工处理状态。',
            canTrackAction: !!dailyWorkbenchPatrolLatest.value && Number(item.hotelId || 0) > 0 && !!(item.actionCode || item.questionKey),
            actionSource: item,
            actionType: dailyWorkbenchPatrolLatest.value ? '' : 'patrol',
            primaryButtonText: dailyWorkbenchPatrolLatest.value ? '' : '生成巡检',
        });
    });

    dataHealthTodayWorkOrders.value
        .filter(item => String(item?.action_type || '') !== 'today_collection')
        .slice(0, 6)
        .forEach((item, index) => {
        const workActionType = item.action_type === 'fetch' && item.action_tab
            ? 'fetch_tab'
            : (item.action_type === 'log' ? 'log' : (item.action_type === 'cookie' ? 'config' : 'history'));
        pushRow({
            key: `workorder-${item.key || index}`,
            category: 'action',
            priority: item.priority || 'medium',
            objectText: item.platform_label || 'OTA',
            objectRawText: item.platform_label || '',
            sourceText: item.source_label || '数据健康',
            statusText: dataHealthPriorityText(item.priority || 'medium'),
            statusClass: dataHealthPriorityClass(item.priority || 'medium'),
            evidenceText: item.title || '数据健康工单',
            evidenceRawText: item.detail || '',
            actionText: item.detail || item.button_text || '处理数据健康工单',
            actionRawText: item.detail || '',
            entryText: item.button_text || '',
            verificationText: '处理后刷新数据健康；仍缺证据则继续保留待处理。',
            boundaryText: '来自数据健康信号；不代表全酒店经营结论。',
            actionType: workActionType,
            actionTab: item.action_tab || '',
            primaryButtonText: item.button_text || (workActionType === 'config' ? '查看配置' : (workActionType === 'log' ? '查看日志' : '查看历史')),
        });
    });

    const seen = new Set();
    return rows
        .filter(row => {
            const key = `${row.category}|${row.objectText}|${row.evidenceText}|${row.actionText}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        })
        .sort((left, right) => {
            const priorityDiff = employeeOtaChecklistPriorityRank(left.priority) - employeeOtaChecklistPriorityRank(right.priority);
            if (priorityDiff !== 0) return priorityDiff;
            const categoryRank = { health: 0, gap: 1, anomaly: 2, action: 3 };
            return (categoryRank[left.category] ?? 9) - (categoryRank[right.category] ?? 9);
        })
        .slice(0, 16);
};
    const buildPlatformProfileFlowRows = ({ platformCollectionFailureReasonText, platformCollectionStatusRows, platformFailureReasonSourceText, platformProfileStatusLabel, platformProfileStatusRows }) => {
        const map = new Map();
        const ensureItem = (platform) => {
            const key = String(platform || '').trim().toLowerCase();
            if (!key) return null;
            if (!map.has(key)) map.set(key, { platform: key, collectionRow: null, profileItem: null });
            return map.get(key);
        };
        platformCollectionStatusRows.value.forEach(row => {
            const item = ensureItem(row?.platform);
            if (item) item.collectionRow = row;
        });
        platformProfileStatusRows.value.forEach(row => {
            const item = ensureItem(row?.platform);
            if (item) item.profileItem = row;
        });
        return Array.from(map.values()).map(({ platform, collectionRow, profileItem }) => {
            const profile = collectionRow?.profile || {};
            const bindingContract = (profileItem?.binding_contract && typeof profileItem.binding_contract === 'object') ? profileItem.binding_contract : {};
            const hasBindingContract = Object.keys(bindingContract).length > 0;
            const currentStatus = String(profileItem?.current_status || profile.currentStatus || collectionRow?.platformLoginStatus || '').trim();
            const statusCode = String(bindingContract.profile_status || profileItem?.status_code || profile.statusCode || collectionRow?.platformLoginStatus || '').trim().toLowerCase();
            const dataSourceId = bindingContract.data_source_id || profileItem?.data_source_id || profile.dataSourceId || profile.data_source_id || '';
            const contractProfileId = String(bindingContract.profile_id || '').trim();
            const contractOtaStoreId = String(bindingContract.ota_store_id || '').trim();
            const profileExists = bindingContract.profile_exists === true || profileItem?.profile_exists === true || profile.profileExists === true;
            const hasConfiguredProfile = hasBindingContract
                ? !!(dataSourceId && contractProfileId && contractOtaStoreId)
                : (profileExists || !!dataSourceId || !['', 'unconfigured', 'missing_profile', 'needs_profile'].includes(statusCode));
            const currentSessionVerified = hasBindingContract
                ? bindingContract.current_session_verified === true
                : profile.current_session_verified === true;
            const profileReusable = hasBindingContract
                ? bindingContract.profile_reusable === true
                : profile.profile_reusable === true;
            const profileReuseWarning = hasBindingContract
                ? bindingContract.profile_reuse_warning === true
                : profile.profile_reuse_warning === true;
            const historicalLoginMetadataPresent = hasBindingContract
                ? bindingContract.historical_login_metadata_present === true
                : (profile.manual_login_state_verified === true || profile.manualLoginStateVerified === true || !!String(profile.last_login_verified_at || profile.lastLoginVerifiedAt || '').trim());
            const loginVerified = currentSessionVerified && statusCode === 'logged_in';
            const loginBlocked = ['session_expired', 'login_expired', 'anti_bot', 'resource_busy_login', 'cookies_incomplete', 'capture_failed', 'permission_denied', 'hotel_mismatch'].includes(statusCode);
            const collectionStatus = String(collectionRow?.collectionStatus || '').trim().toLowerCase();
            const storedRows = Number(collectionRow?.storedRowCount || 0);
            const targetDateRows = Number(collectionRow?.targetDateRows || collectionRow?.target_date_rows || 0);
            const targetTrafficRows = Number(collectionRow?.targetDateTrafficRows || collectionRow?.target_date_traffic_rows || 0);
            const fieldFactStatus = String(collectionRow?.fieldFactStatus || collectionRow?.field_fact_status || '').trim().toLowerCase();
            const fieldFactsReady = Number(collectionRow?.fieldFactsReady || collectionRow?.field_facts_ready || 0);
            const collectionDone = collectionStatus === 'collected' && targetTrafficRows > 0 && fieldFactStatus === 'ready';
            const collectionPartial = collectionStatus === 'partial' || (targetDateRows > 0 && (targetTrafficRows <= 0 || fieldFactStatus !== 'ready'));
            const collectionBlocked = ['failed', 'stale', 'stale_running', 'permission_denied', 'hotel_mismatch', 'login_expired'].includes(collectionStatus);
            const failureRaw = platformFailureReasonSourceText(collectionRow?.failureReason, collectionRow);
            const failureText = platformCollectionFailureReasonText(collectionRow?.failureReason, collectionRow);
            const normalizedProfileItem = profileItem || {
                platform,
                status_code: statusCode,
                current_status: currentStatus,
                profile_key: profile.profileKey || '',
            };
            const profileCanAttempt = hasConfiguredProfile && !loginBlocked;
            const syncStepStatus = collectionDone
                ? 'done'
                : (collectionPartial ? 'warning' : (collectionStatus === 'collecting' ? 'active' : (collectionBlocked ? 'blocked' : (profileCanAttempt ? 'active' : 'pending'))));
            const dataStepStatus = collectionDone
                ? 'done'
                : (collectionPartial ? 'warning' : (collectionBlocked ? 'blocked' : (profileCanAttempt ? 'warning' : 'pending')));
            return {
                platform,
                platformName: collectionRow?.platformName || profileItem?.platform_name || platform,
                statusCode: statusCode || 'unconfigured',
                statusText: platformProfileStatusLabel(normalizedProfileItem),
                bindingContract,
                profileConfigured: hasConfiguredProfile,
                currentSessionVerified,
                profileReusable,
                profileReuseWarning,
                loginBlocked,
                collectionStatus,
                collectionDone,
                collectionPartial,
                historicalLoginMetadataPresent,
                failureRaw,
                failureText,
                collectionRow,
                targetDateText: collectionRow?.targetDate || collectionRow?.latestTask?.targetDate || collectionRow?.latestTask?.target_date || collectionRow?.latestTask?.syncDiagnostics?.target_date || collectionRow?.dataRange || collectionRow?.latestDataDate || '-',
                steps: [
                    {
                        key: 'open-login',
                        label: '打开平台登录',
                        status: hasConfiguredProfile ? 'done' : 'active',
                        description: hasConfiguredProfile ? '已存在平台数据源；授权仍以账号使用者本机验证为准。' : '账号使用者先在自己的电脑完成平台授权，再导入本机采集证据。',
                    },
                    {
                        key: 'user-verification',
                        label: '等待用户验证',
                        status: loginVerified ? 'done' : (hasConfiguredProfile ? (loginBlocked ? 'blocked' : 'active') : 'pending'),
                        description: loginVerified ? '用户已在自己的授权浏览器内完成当天验证。' : (hasConfiguredProfile ? '已有 Profile，可直接尝试采集；失败时再处理登录。' : '在账号使用者自己的浏览器内完成人工登录、短信或人机验证。'),
                    },
                    {
                        key: 'confirm-login-state',
                        label: '确认登录态',
                        status: loginVerified ? 'done' : (loginBlocked ? 'blocked' : 'pending'),
                        description: loginVerified ? '当天登录态已验证，可进入采集。' : (hasConfiguredProfile ? '当天证明不是采集前置条件；以平台实际响应为准。' : '尚未配置可执行的浏览器 Profile。'),
                    },
                    {
                        key: 'sync-target-date',
                        label: '同步目标日期数据',
                        status: syncStepStatus,
                        description: collectionDone ? `目标日期 traffic 已入库 ${targetTrafficRows} 条，字段事实 ${fieldFactsReady} 条。` : (profileCanAttempt ? `直接运行现有 Profile 采集；当前目标日 ${targetDateRows} 行，traffic ${targetTrafficRows} 行。` : '配置 Profile 后运行目标日期同步。'),
                    },
                    {
                        key: 'verify-data-completeness',
                        label: '验证数据完整性',
                        status: dataStepStatus,
                        description: collectionDone ? '目标日期 traffic rows 与 field_facts 已闭合。' : failureText,
                    },
                ],
            };
        });
    };
    const buildCtripTrafficBusinessQuality = ({ ctripTrafficCoreMetricKeys, ctripTrafficForm, ctripTrafficHistoryResult, ctripTrafficRowHasNonzeroCoreMetric, ctripTrafficRowRole, ctripTrafficRows, formatDate, onlineDataResult }) => {
        const rows = Array.isArray(ctripTrafficRows.value) ? ctripTrafficRows.value : [];
        const selfRows = rows.filter(row => ctripTrafficRowRole(row) === 'self');
        const scopedRows = selfRows;
        const nonzeroRows = scopedRows.filter(ctripTrafficRowHasNonzeroCoreMetric).length;
        const hasMissing = row => ctripTrafficCoreMetricKeys.some(key => row?.[key] == null || row[key] === '');
        const missingRows = scopedRows.filter(hasMissing).length;
        const zeroRows = scopedRows.filter(row => !hasMissing(row) && !ctripTrafficRowHasNonzeroCoreMetric(row)).length;
        const historyResult = ctripTrafficHistoryResult.value || {};
        const savedCount = Number(historyResult.saved_count || 0);
        const rowDates = scopedRows
            .map(row => String(row?.date || row?.data_date || '').slice(0, 10))
            .filter(value => /^\d{4}-\d{2}-\d{2}$/.test(value));
        const latestDataDate = rowDates.length ? [...rowDates].sort().at(-1) : '';
        const settledDate = new Date();
        settledDate.setDate(settledDate.getDate() - 1);
        const targetDataDate = String(
            historyResult.request_end_date
            || (ctripTrafficForm.value?.dateRange === 'custom' ? ctripTrafficForm.value?.endDate : '')
            || formatDate(settledDate)
        ).slice(0, 10);
        const targetDatePresent = targetDataDate !== '' && rowDates.includes(targetDataDate);
        const flowStatus = String(historyResult.ui_flow_status || '').trim();
        const latestTrafficSnapshot = historyResult.source === 'latest'
            ? historyResult.traffic
            : (onlineDataResult.value?.source === 'latest' ? onlineDataResult.value?.traffic : null);
        const readbackVerified = latestTrafficSnapshot
            ? latestTrafficSnapshot.readback_verified === true
                && String(latestTrafficSnapshot.persistence_status || '').toLowerCase() === 'readback_verified'
            : historyResult.persisted === true && historyResult.readback_verified === true;
        const currentRowsText = `${scopedRows.length} 行本店数据（${nonzeroRows} 行核心指标非 0）`;
        const persistenceText = latestTrafficSnapshot
            ? `已数据库精确回读快照 ${scopedRows.length} 行`
            : (savedCount > 0
                ? `本次保存并精确回读 ${savedCount} 条`
                : `数据库精确回读 ${scopedRows.length} 行`);
        const httpCode = historyResult.http_code || 200;

        if (['business_failed', 'failed', 'exception'].includes(flowStatus)) {
            return {
                status: 'failed',
                className: 'bg-red-50 border-red-200 text-red-800',
                title: '本次携程采集或保存失败',
                detail: `本次操作状态为 ${flowStatus}；${currentRowsText}仅保留原页面记录，未作为本次已核验结果。`,
                nonzeroRows,
                zeroRows,
            };
        }

        if (historyResult.status === 'running' || historyResult.status === 'accepted') {
            return {
                status: 'pending',
                className: 'bg-blue-50 border-blue-200 text-blue-800',
                title: '携程采集仍在后台执行',
                detail: `后台任务尚未完成；${currentRowsText}不代表本次任务已保存并精确回读。`,
                nonzeroRows,
                zeroRows,
            };
        }

        if (rows.length > 0 && scopedRows.length <= 0) {
            return {
                status: 'self_data_missing',
                className: 'bg-amber-50 border-amber-200 text-amber-800',
                title: '仅返回竞争圈，本店流量未返回',
                detail: '当前记录不能作为本店携程流量事实；未用竞争圈数值或 0 替代本店缺失值。',
                nonzeroRows,
                zeroRows,
            };
        }

        if (scopedRows.length <= 0) {
            return {
                status: 'no_data',
                className: 'bg-yellow-50 border-yellow-200 text-yellow-800',
                title: '当前日期范围暂无流量数据',
                detail: '未形成可用于收益分析和 AI 决策的携程 OTA 流量指标。',
                nonzeroRows,
                zeroRows,
            };
        }

        if (flowStatus === 'display_only') {
            return {
                status: 'display_only',
                className: 'bg-amber-50 border-amber-200 text-amber-800',
                title: '本次流量仅供查看，保存未确认',
                detail: `${currentRowsText}；未确认入库和数据库精确回读，不作为已保存历史证据。`,
                nonzeroRows,
                zeroRows,
            };
        }

        if (flowStatus === 'readback_unverified'
            || (flowStatus === 'success' && !readbackVerified)
            || !readbackVerified
        ) {
            return {
                status: 'readback_unverified',
                className: 'bg-amber-50 border-amber-200 text-amber-800',
                title: '携程数据尚未通过数据库精确回读',
                detail: `${historyResult.persisted === true && savedCount > 0 ? `接口报告保存 ${savedCount} 条；` : ''}${currentRowsText}；回读未核验，不作为已保存历史证据。`,
                nonzeroRows,
                zeroRows,
            };
        }

        if (missingRows > 0) {
            return {
                status: 'partial',
                className: 'bg-amber-50 border-amber-200 text-amber-800',
                title: '本店指标缺失',
                detail: `缺 ${missingRows} 行指标；不作 0。`,
                nonzeroRows,
                zeroRows,
            };
        }

        if (nonzeroRows <= 0) {
            return {
                status: 'zero_value_unverified',
                className: 'bg-yellow-50 border-yellow-200 text-yellow-800',
                title: '核心流量全 0，未闭环',
                detail: `目标日 ${scopedRows.length} 行核心流量指标均为 0；可信闭环未成立。`,
                nonzeroRows,
                zeroRows,
            };
        }

        if (targetDataDate && !targetDatePresent) {
            return {
                status: 'partial',
                className: 'bg-amber-50 border-amber-200 text-amber-800',
                title: '历史流量部分可用',
                detail: `最新仅到 ${latestDataDate || '未知'}，目标 ${targetDataDate} 未返回；HTTP ${httpCode}，${persistenceText}，${nonzeroRows} 行指标非 0。`,
                nonzeroRows,
                zeroRows,
                latestDataDate,
                targetDataDate,
            };
        }

        return {
            status: 'ready',
            className: 'bg-green-50 border-green-200 text-green-800',
            title: '携程 OTA 流量指标可用',
            detail: `HTTP ${httpCode}，${persistenceText}；${nonzeroRows} 行本店核心指标非 0。`,
            nonzeroRows,
            zeroRows,
        };
    };
    const buildLocalCollectorLoginTaskRows = ({ LOCAL_COLLECTOR_LOGIN_ATTENTION_STATUSES, LOCAL_COLLECTOR_LOGIN_TASK_TYPES, LOCAL_COLLECTOR_POLLABLE_STATUSES, getHotelNameById, localCollectorPlatformText, localCollectorStatus, localCollectorStatusClass, localCollectorStatusText }) => {
        const tasks = Array.isArray(localCollectorStatus.value?.tasks)
            ? localCollectorStatus.value.tasks
            : [];
        const accounts = Array.isArray(localCollectorStatus.value?.accounts)
            ? localCollectorStatus.value.accounts
            : [];
        const devices = Array.isArray(localCollectorStatus.value?.devices)
            ? localCollectorStatus.value.devices
            : [];
        const accountMap = new Map(accounts.map(account => [Number(account?.id || 0), account]));
        const deviceMap = new Map(devices.map(device => [Number(device?.id || 0), device]));

        return tasks
            .filter(task => {
                const taskType = String(task?.task_type || '').toLowerCase();
                const status = String(task?.status || '').toLowerCase();
                return LOCAL_COLLECTOR_LOGIN_TASK_TYPES.has(taskType)
                    || LOCAL_COLLECTOR_LOGIN_ATTENTION_STATUSES.has(status);
            })
            .map(task => {
                const taskId = Number(task?.id || 0);
                const accountId = Number(task?.account_id || 0);
                const account = accountMap.get(accountId) || {};
                const deviceId = Number(task?.device_id || account?.device_id || 0);
                const device = deviceMap.get(deviceId) || {};
                const systemHotelId = Number(task?.system_hotel_id || 0);
                const platform = String(task?.platform || account?.platform || '').toLowerCase();
                const platformText = localCollectorPlatformText(platform);
                const taskType = String(task?.task_type || '').toLowerCase();
                const status = String(task?.status || '').toLowerCase();
                const mappings = Array.isArray(account?.hotels) ? account.hotels : [];
                const mapping = mappings.find(item => (
                    Number(item?.system_hotel_id || 0) === systemHotelId
                    && String(item?.platform || platform).toLowerCase() === platform
                )) || {};
                const systemHotelName = String(getHotelNameById(systemHotelId) || '').trim()
                    || `门店 #${systemHotelId || '-'}`;
                const platformHotelId = String(
                    task?.platform_hotel_id || mapping?.platform_hotel_id || ''
                ).trim();
                const platformHotelName = String(mapping?.platform_hotel_name || '').trim();
                const deviceName = String(
                    device?.device_name || account?.device_name || '原绑定设备'
                ).trim();
                const accountAlias = String(
                    task?.account_alias || account?.account_alias || '原绑定账户'
                ).trim();
                const taskLabel = taskType === 'session_probe' ? '会话检查' : '登录/验证';
                let handoffText = `请在原设备“${deviceName}”上继续当前${taskLabel}。`;
                if (status === 'queued') {
                    handoffText = `等待原设备“${deviceName}”领取${taskLabel}；请保持该设备上的本机采集器在线。`;
                } else if (status === 'leased') {
                    handoffText = `原设备“${deviceName}”已领取${taskLabel}；请留意该设备上的${platformText}专用 Profile 窗口。`;
                } else if (status === 'running') {
                    handoffText = `原设备“${deviceName}”已领取${taskLabel}并正在检查登录态；如平台要求登录，请查看该设备上的${platformText}专用 Profile 窗口。`;
                } else if (status === 'waiting_user_login') {
                    handoffText = `原设备“${deviceName}”已领取登录任务；请查看该设备上的${platformText}专用 Profile 窗口并完成登录。`;
                } else if (status === 'verification_required') {
                    handoffText = `请在原设备“${deviceName}”上的${platformText}专用 Profile 窗口完成人工验证；系统不会绕过验证。`;
                } else if (status === 'login_required') {
                    handoffText = `本次登录未完成；请在原设备“${deviceName}”上通过当前酒店的“登录/验证”重新发起。`;
                } else if (status === 'success') {
                    handoffText = `原设备“${deviceName}”已完成登录验证，可继续当前酒店的采集任务。`;
                }
                const recovery = task?.recovery && typeof task.recovery === 'object'
                    ? task.recovery
                    : {};
                const taskMessage = String(task?.error_summary || '').trim();
                const recoveryMessage = String(recovery?.message || '').trim();
                const recoveryAction = String(recovery?.next_action || '').trim();

                return {
                    taskId,
                    accountId,
                    accountAlias,
                    deviceId,
                    deviceName,
                    systemHotelId,
                    systemHotelName,
                    platform,
                    platformText,
                    platformHotelId,
                    platformHotelName,
                    taskType,
                    taskLabel,
                    status,
                    statusText: localCollectorStatusText(status),
                    statusClass: localCollectorStatusClass(status),
                    hotelText: [
                        `${systemHotelName}（系统酒店 #${systemHotelId || '-'}）`,
                        platformHotelName ? `OTA 门店 ${platformHotelName}` : '',
                        platformHotelId ? `ID ${platformHotelId}` : '',
                    ].filter(Boolean).join(' · '),
                    progressText: taskMessage || recoveryMessage || handoffText,
                    recoveryAction: recoveryAction || handoffText,
                    handoffText,
                    isPollable: LOCAL_COLLECTOR_POLLABLE_STATUSES.has(status),
                };
            })
            .sort((left, right) => {
                if (left.isPollable !== right.isPollable) return left.isPollable ? -1 : 1;
                return right.taskId - left.taskId;
            })
            .slice(0, 6);
    };
    const buildManualNotificationFieldErrors = ({ isOperationHotelPermitted, manualNotificationCanConfigureStrictThreeSourceHourly, manualNotificationCanConfigureStrictThreeSourceInterval, manualNotificationForm, manualNotificationIsOperatingDaily, manualNotificationOperatingDailyTriggerAllowed, manualNotificationValidationActive }) => {
                if (!manualNotificationValidationActive.value) return {};
                const form = manualNotificationForm.value || {};
                const errors = {};
                const hotelId = String(form.hotel_id || '').trim();
                const title = String(form.title || '').trim();
                const body = String(form.body || '').trim();
                const triggerType = String(form.trigger_type || 'manual_test');
                const sendMethod = String(form.send_method || 'manual_preview');
                const weekdays = (Array.isArray(form.active_weekdays)
                    ? form.active_weekdays
                    : String(form.active_weekdays || '').split(',')
                ).map(Number).filter(day => day >= 1 && day <= 7);

                if (!hotelId || !isOperationHotelPermitted(hotelId)) {
                    errors.hotel_id = '请选择当前账号有权限的酒店。';
                }
                if (!String(form.business_date || '').trim()) {
                    errors.business_date = '请选择消息对应的业务日期。';
                }
                if (!title) errors.title = '请填写模板名称。';
                if (!body) errors.body = '请填写消息内容。';
                if (manualNotificationIsOperatingDaily.value) {
                    if (!String(form.source_scope || '').trim()) {
                        errors.source_scope = '请选择发送来源。';
                    }
                    const sections = Array.isArray(form.content_sections)
                        ? form.content_sections
                        : String(form.content_sections || '').split(',').filter(Boolean);
                    if (!sections.length) errors.content_sections = '至少选择一项发送内容。';
                    if (!manualNotificationOperatingDailyTriggerAllowed.value) {
                        errors.trigger_type = triggerType === 'interval_minutes'
                            && manualNotificationCanConfigureStrictThreeSourceInterval.value
                            ? '每 30 分钟计划必须保持酒店 80、当日、正式群及指定 PMS＋携程＋美团四项内容。'
                            : triggerType === 'hourly_on_the_hour'
                                && manualNotificationCanConfigureStrictThreeSourceHourly.value
                                ? '三源整点推送必须保持当日、正式群及指定 PMS＋携程＋美团四项内容。'
                                : '普通经营日报不支持循环发送，请选择每日固定时间。';
                    }
                }
                if (!weekdays.length && triggerType !== 'manual_test') {
                    errors.active_weekdays = '至少选择一个生效星期。';
                }
                if (triggerType === 'daily_fixed_time' && !String(form.planned_send_at || '').trim()) {
                    errors.planned_send_at = '请设置每日发送时间。';
                }
                if (triggerType === 'hourly_on_the_hour'
                    && (!manualNotificationIsOperatingDaily.value
                        || manualNotificationCanConfigureStrictThreeSourceHourly.value)
                ) {
                    if (!String(form.hourly_start_time || '').trim()) {
                        errors.hourly_start_time = '请设置小时播报开始时间。';
                    }
                    if (!String(form.hourly_end_time || '').trim()) {
                        errors.hourly_end_time = '请设置小时播报结束时间。';
                    }
                }
                if (triggerType === 'interval_minutes'
                    && (
                        !manualNotificationIsOperatingDaily.value
                        || manualNotificationCanConfigureStrictThreeSourceInterval.value
                    )
                ) {
                    const interval = Number(form.interval_minutes);
                    if (!Number.isFinite(interval) || interval < 5 || interval > 1440) {
                        errors.interval_minutes = '发送间隔应为 5–1440 分钟。';
                    }
                    if (!String(form.hourly_start_time || '').trim()) {
                        errors.hourly_start_time = '请设置首次发送时间。';
                    }
                }
                const conditionType = String(form.condition_type || 'always');
                if (conditionType === 'occupancy_ladder') {
                    const threshold = Number(form.condition_threshold);
                    const step = Number(form.condition_step);
                    if (!Number.isFinite(threshold) || threshold <= 0 || threshold > 100) {
                        errors.condition_threshold = '入住率起始档必须大于 0 且不超过 100。';
                    }
                    if (!Number.isFinite(step) || step <= 0 || step > 100) {
                        errors.condition_step = '入住率跨档步长必须大于 0 且不超过 100。';
                    }
                }
                if (['wecom_test', 'wecom_formal'].includes(sendMethod)
                    && !String(form.target_robot_id || '').trim()) {
                    errors.target_robot_id = '请先为当前酒店绑定企业微信推送通道。';
                }
                return errors;
            };
    const buildCoreOperationsPlatformCards = ({ coreOperationsEvidenceStatusClass, coreOperationsEvidenceStatusText, coreOperationsMetricCard, coreOperationsMetrics, coreOperationsPlatformTruthStatus, coreOperationsSelectedWorkbenchRow, coreOperationsSourceEvidenceStatusText, onlineTruthStatusClass, onlineTruthStatusText }) => {
                const workbenchRow = coreOperationsSelectedWorkbenchRow.value;
                return ['ctrip', 'meituan'].map(platform => {
                    const result = coreOperationsMetrics.value?.[platform] || {};
                    const platformEvidence = Array.isArray(workbenchRow?.platformRows)
                        ? workbenchRow.platformRows.find(item => item.platform === platform)
                        : null;
                    const rawSourceRows = platformEvidence?.target_date_rows;
                    const parsedSourceRows = Number(rawSourceRows);
                    const sourceRows = rawSourceRows !== null
                        && rawSourceRows !== undefined
                        && rawSourceRows !== ''
                        && Number.isInteger(parsedSourceRows)
                        && parsedSourceRows >= 0
                        ? parsedSourceRows
                        : null;
                    const status = String(result.status || 'not_loaded');
                    const metrics = [
                        {
                            key: 'revenue',
                            label: 'OTA房费收入',
                            candidates: [
                                { totalKey: 'room_revenue', trustKey: 'totals.room_revenue' },
                                { totalKey: 'revenue', trustKey: 'totals.revenue' },
                            ],
                            format: { currency: true, decimals: 2 },
                        },
                        {
                            key: 'room_nights',
                            label: '间夜',
                            candidates: [{ totalKey: 'room_nights', trustKey: 'totals.room_nights' }],
                            format: { decimals: 2 },
                        },
                        {
                            key: 'adr',
                            label: 'ADR',
                            candidates: [{ totalKey: 'adr', trustKey: 'totals.adr' }],
                            format: { currency: true, decimals: 2 },
                        },
                        {
                            key: 'revpar',
                            label: 'RevPAR',
                            candidates: [{ totalKey: 'revpar', trustKey: 'totals.revpar' }],
                            format: { currency: true, decimals: 2 },
                            requiredForLoop: false,
                        },
                    ].map(definition => coreOperationsMetricCard(result.data, definition));
                    const truthStatus = coreOperationsPlatformTruthStatus(metrics);
                    return {
                        key: platform,
                        label: platform === 'ctrip' ? '携程昨日数据' : '美团昨日数据',
                        icon: platform === 'ctrip' ? 'fas fa-plane' : 'fas fa-store',
                        accentClass: platform === 'ctrip' ? 'bg-blue-600' : 'bg-emerald-600',
                        status,
                        statusText: coreOperationsEvidenceStatusText(status),
                        statusClass: coreOperationsEvidenceStatusClass(status),
                        sourceRows,
                        sourceRowsText: sourceRows === null ? '未验证/未知' : `${sourceRows} 行`,
                        evidenceStatus: String(platformEvidence?.evidence_status || 'unknown'),
                        evidenceStatusText: coreOperationsSourceEvidenceStatusText(platformEvidence?.evidence_status),
                        message: String(result.message || ''),
                        metrics,
                        truthStatus,
                        truthStatusText: onlineTruthStatusText({ status: truthStatus }),
                        truthStatusClass: onlineTruthStatusClass({ status: truthStatus }),
                        verifiedMetricCount: metrics.filter(metric => metric.calculationStatus === 'calculated' && metric.truthStatus === 'verified').length,
                    };
                });
            };
    const buildCoreOperationsCompetitorRows = ({ competitorSummary, coreOperationsDifferenceText, coreOperationsEvidenceCount, coreOperationsEvidenceCountText, coreOperationsHotelId, coreOperationsMeituanComparableValue, coreOperationsMetricValueText, coreOperationsTargetDate, ctripCompetitiveOperationsComparison, ctripCompetitiveOperationsPayload }) => {
                const targetDate = String(coreOperationsTargetDate.value || '');
                const rows = [];
                const ctripComparison = ctripCompetitiveOperationsComparison.value || {};
                const ctripPayload = ctripCompetitiveOperationsPayload.value || {};
                const ctripContext = ctripPayload.context || {};
                const ctripCoverage = ctripPayload.data_coverage || {};
                const ctripDate = String(ctripComparison.latest_date || '');
                const ctripStatus = ctripDate && ctripDate !== targetDate ? 'stale' : String(ctripComparison.status || 'data_missing');
                const ctripBusinessRows = coreOperationsEvidenceCount(ctripCoverage.business_row_count);
                const ctripTrafficRows = coreOperationsEvidenceCount(ctripCoverage.traffic_row_count);
                const ctripStoredRows = ctripBusinessRows !== null && ctripTrafficRows !== null
                    ? ctripBusinessRows + ctripTrafficRows
                    : null;
                const ctripBindingText = ['bound', 'ready', 'verified'].includes(String(ctripContext.binding_status || '').toLowerCase())
                    ? '门店已绑定'
                    : '门店绑定待确认';
                const ctripSourceEvidence = `OTA入库数据 · 门店 #${ctripContext.system_hotel_id || coreOperationsHotelId.value || '-'} · ${ctripBindingText} · 入库 ${coreOperationsEvidenceCountText(ctripStoredRows, '行')}/可用 ${coreOperationsEvidenceCountText(ctripCoverage.decision_eligible_row_count, '行')} · 更新 ${ctripContext.latest_fetched_at || '未返回'}`;
                const ctripDefinitions = [
                    { key: 'amount', label: '成交金额', currency: true, decimals: 2 },
                    { key: 'room_nights', label: '间夜', decimals: 2 },
                    { key: 'orders', label: '订单', decimals: 2 },
                    { key: 'adr', label: 'ADR', currency: true, decimals: 2 },
                ];
                ctripDefinitions.forEach(definition => {
                    const gap = ctripComparison.gaps?.[definition.key] || {};
                    const own = gap.self ?? ctripComparison.self?.[definition.key] ?? null;
                    const competitor = gap.competitor_average ?? ctripComparison.competitor_average?.[definition.key] ?? null;
                    const hasOwn = own !== null && own !== undefined && own !== '' && Number.isFinite(Number(own));
                    const hasCompetitor = competitor !== null && competitor !== undefined && competitor !== '' && Number.isFinite(Number(competitor));
                    const difference = gap.difference ?? (hasOwn && hasCompetitor ? Number(own) - Number(competitor) : null);
                    rows.push({
                        key: `ctrip-${definition.key}`,
                        platform: '携程',
                        metric: definition.label,
                        ownText: coreOperationsMetricValueText(own, definition),
                        competitorText: coreOperationsMetricValueText(competitor, definition),
                        differenceText: coreOperationsDifferenceText(difference, definition),
                        evidenceDate: ctripDate || '未返回',
                        sourceEvidence: ctripSourceEvidence,
                        status: ctripStatus,
                    });
                });

                const meituanRows = Array.isArray(competitorSummary.value?.display_hotels) ? competitorSummary.value.display_hotels : [];
                const selfRow = meituanRows.find(item => item && item.isSelf) || null;
                const competitorRows = meituanRows.filter(item => item && !item.isSelf);
                const meituanDate = String(competitorSummary.value?.latest_data_date || '');
                const meituanStatus = meituanDate && meituanDate !== targetDate
                    ? 'stale'
                    : String(competitorSummary.value?.readiness?.status || competitorSummary.value?.data_status || competitorSummary.value?.status || 'data_missing');
                const meituanSourceEvidence = `OTA入库数据 · 门店 #${competitorSummary.value?.system_hotel_id || coreOperationsHotelId.value || '-'} · 平台门店${competitorSummary.value?.target_poi_id ? '已绑定' : '待确认'} · 入库 ${coreOperationsEvidenceCountText(competitorSummary.value?.record_count, '行')}/展示 ${coreOperationsEvidenceCountText(competitorSummary.value?.display_hotel_count, '店')} · 更新 ${competitorSummary.value?.latest_fetched_at || '未返回'}`;
                const meituanDefinitions = [
                    { key: 'avgRoomPrice', label: '平均房价', currency: true, decimals: 2 },
                    { key: 'avgSalesPrice', label: '销售客单', currency: true, decimals: 2 },
                    { key: 'orderCount', label: '订单', decimals: 2 },
                    { key: 'currentPlatformRank', label: '圈内排名', decimals: 2 },
                ];
                meituanDefinitions.forEach(definition => {
                    const own = coreOperationsMeituanComparableValue(selfRow, definition.key);
                    const values = competitorRows
                        .map(item => coreOperationsMeituanComparableValue(item, definition.key))
                        .filter(value => value !== null);
                    const competitor = values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null;
                    const difference = own !== null && competitor !== null ? own - competitor : null;
                    rows.push({
                        key: `meituan-${definition.key}`,
                        platform: '美团',
                        metric: definition.label,
                        ownText: coreOperationsMetricValueText(own, definition),
                        competitorText: coreOperationsMetricValueText(competitor, definition),
                        differenceText: coreOperationsDifferenceText(difference, definition),
                        evidenceDate: meituanDate || '未返回',
                        sourceEvidence: meituanSourceEvidence,
                        status: meituanStatus,
                    });
                });
                return rows;
            };
    const buildCoreOperationsAiSuggestions = ({ coreOperationsDiagnoses, coreOperationsHotelId, otaDiagnosisBaselineBusinessDate }) => {
                const rows = [];
                const platformLabels = { ctrip: '携程', meituan: '美团' };
                Object.entries(coreOperationsDiagnoses.value || {}).forEach(([platform, result]) => {
                    const diagnosis = result?.data && typeof result.data === 'object' ? result.data : null;
                    const recordId = Number(diagnosis?.saved_record?.id || 0);
                    const recordStatus = String(diagnosis?.record_status || diagnosis?.saved_record?.status || '').toLowerCase();
                    const baselineBusinessDate = otaDiagnosisBaselineBusinessDate(diagnosis);
                    const actionItems = Array.isArray(diagnosis?.action_items) ? diagnosis.action_items : [];
                    const rawActionCount = diagnosis?.action_count;
                    const parsedActionCount = Number(rawActionCount);
                    const actionCount = diagnosis
                        ? (rawActionCount !== null && rawActionCount !== undefined && rawActionCount !== '' && Number.isFinite(parsedActionCount)
                            ? Math.max(0, Math.trunc(parsedActionCount))
                            : actionItems.length)
                        : null;
                    const isApprovable = item => item?.execution_ready === true
                        && item?.can_create_execution_intent === true
                        && item?.decision_quality?.contract_version === 'ai_recommendation_quality.v2'
                        && item?.decision_quality?.execution_ready === true
                        && recordId > 0
                        && recordStatus !== 'superseded';
                    const approvableTaskCount = diagnosis ? actionItems.filter(isApprovable).length : null;
                    actionItems.forEach((item, actionIndex) => {
                        const ready = isApprovable(item);
                        const refs = Array.isArray(item?.evidence_refs) ? item.evidence_refs.filter(Boolean) : [];
                        const missing = Array.isArray(item?.missing_evidence)
                            ? item.missing_evidence.map(entry => entry?.label || entry?.code || '').filter(Boolean)
                            : [];
                        rows.push({
                            key: `diagnosis-${platform}-${recordId || 'missing'}-${actionIndex}`,
                            title: `${platformLabels[platform] || platform.toUpperCase()} · ${item?.title || 'OTA渠道建议'}`,
                            action: String(item?.action || '诊断未返回明确行动。'),
                            evidence: refs.length ? refs.join(' · ') : (missing.join('、') || result?.message || '目标日诊断证据不足'),
                            boundary: `${diagnosis?.source_policy || item?.source_policy || 'database_only'} · 诊断记录 #${recordId || '-' } · 不自动写 OTA`,
                            status: ready ? 'ready' : String(item?.status || result?.status || 'blocked'),
                            ready,
                            platform,
                            recordId,
                            recordStatus,
                            actionCount,
                            approvableTaskCount,
                            actionItemId: String(item?.id || '').trim(),
                            actionIndex,
                            hotelId: Number(diagnosis?.hotel?.id || coreOperationsHotelId.value || 0),
                            baselineBusinessDate,
                        });
                    });
                    if (actionItems.length === 0) {
                        const gaps = Array.isArray(diagnosis?.data_gaps)
                            ? diagnosis.data_gaps.map(item => (
                                typeof item === 'string'
                                    ? item.trim()
                                    : String(item?.message || item?.label || item?.code || '').trim()
                            )).filter(Boolean)
                            : [];
                        rows.push({
                            key: `diagnosis-${platform}-blocked`,
                            title: `${platformLabels[platform] || platform.toUpperCase()} · AI行动建议未形成`,
                            action: '当前仅形成证据缺口或无需行动结论，不创建运营任务。',
                            evidence: gaps.length
                                ? `${gaps.slice(0, 3).join('、')}${gaps.length > 3 ? `（共 ${gaps.length} 项）` : ''}`
                                : (result?.message || '诊断响应未返回'),
                            boundary: `${diagnosis?.source_policy || '真实 OTA 诊断未就绪'} · 不使用默认话术替代`,
                            status: String(result?.status || 'blocked'),
                            ready: false,
                            platform,
                            recordId,
                            recordStatus,
                            actionCount,
                            approvableTaskCount,
                            dataGaps: gaps,
                            gapCount: gaps.length,
                            actionIndex: -1,
                            hotelId: Number(coreOperationsHotelId.value || 0),
                            baselineBusinessDate,
                        });
                    }
                });
                return rows.sort((left, right) => Number(right.ready) - Number(left.ready));
            };
    const buildCoreOperationsStepRows = ({ coreOperationsAiExecutionItems, coreOperationsAiSuggestions, coreOperationsAnomalyRows, coreOperationsCompetitorError, coreOperationsCompetitorRows, coreOperationsDiagnoses, coreOperationsEvidenceStatusClass, coreOperationsEvidenceStatusText, coreOperationsLoading, coreOperationsPlatformCards, coreOperationsSelectedWorkbenchRow, formatDate }) => {
                const readyStatuses = ['ready', 'available', 'success', 'proved', 'complete', 'ok'];
                const platformReadyCount = coreOperationsPlatformCards.value.filter(item => item.truthStatus === 'verified').length;
                const platformEvidenceCount = coreOperationsPlatformCards.value.filter(item => item.metrics.some(metric => metric.calculationStatus === 'calculated')).length;
                const comparableRows = coreOperationsCompetitorRows.value.filter(item => (
                    readyStatuses.includes(String(item.status || '').toLowerCase())
                    && item.ownText !== '未返回'
                    && item.competitorText !== '未返回'
                ));
                const comparablePlatforms = new Set(comparableRows.map(item => String(item.key || '').split('-')[0]));
                const comparableCount = comparableRows.length;
                const dataComplete = platformReadyCount === 2;
                const comparisonComplete = dataComplete
                    && comparablePlatforms.has('ctrip')
                    && comparablePlatforms.has('meituan')
                    && !coreOperationsCompetitorError.value;
                const anomalyEvidenceReady = comparisonComplete && !!coreOperationsSelectedWorkbenchRow.value;
                const readyAiSuggestions = coreOperationsAiSuggestions.value.filter(item => item.ready === true);
                const aiSuggestion = readyAiSuggestions[0] || coreOperationsAiSuggestions.value[0] || null;
                const diagnosisStatuses = ['ctrip', 'meituan'].map(platform => {
                    const result = coreOperationsDiagnoses.value?.[platform] || {};
                    return String(result?.data?.decision_status || result?.data?.decision_closure?.status || result?.status || '').toLowerCase();
                });
                const diagnosesResolved = diagnosisStatuses.every(status => ['action_required', 'no_action'].includes(status));
                const noActionRequired = diagnosisStatuses.length === 2 && diagnosisStatuses.every(status => status === 'no_action');
                const adviceComplete = anomalyEvidenceReady
                    && diagnosesResolved
                    && (noActionRequired || readyAiSuggestions.length > 0);
                const aiExecutionItems = coreOperationsAiExecutionItems.value;
                const requiredActionKeys = new Set(readyAiSuggestions.map(item => {
                    const recordId = Number(item?.recordId || 0);
                    const actionItemId = String(item?.actionItemId || '').trim();
                    return recordId > 0 && actionItemId ? `${recordId}:${actionItemId}` : '';
                }).filter(Boolean));
                const executionActionKey = (item) => {
                    const recommendation = item?.recommendation || {};
                    const evidence = recommendation?.evidence && typeof recommendation.evidence === 'object'
                        ? recommendation.evidence
                        : {};
                    const recordId = Number(recommendation?.source_record_id || 0);
                    const actionItemId = String(evidence?.action_item_id || '').trim();
                    return String(recommendation?.source_module || '').toLowerCase() === 'ota_diagnosis_saved'
                        && recordId > 0
                        && actionItemId
                        ? `${recordId}:${actionItemId}`
                        : '';
                };
                const latestAiExecutionByActionKey = new Map();
                aiExecutionItems.forEach(item => {
                    const key = executionActionKey(item);
                    if (!requiredActionKeys.has(key)) return;
                    const current = latestAiExecutionByActionKey.get(key);
                    if (!current || Number(item?.id || 0) > Number(current?.id || 0)) {
                        latestAiExecutionByActionKey.set(key, item);
                    }
                });
                const matchedAiExecutionItems = Array.from(latestAiExecutionByActionKey.values()).filter(item => (
                    !['blocked', 'rejected', 'cancelled', 'canceled', 'failed', 'failure'].includes(String(item?.approval?.status || '').toLowerCase())
                ));
                const matchedActionKeys = new Set(matchedAiExecutionItems.map(executionActionKey).filter(Boolean));
                const requiredActionCount = readyAiSuggestions.length;
                const matchedTaskCount = matchedActionKeys.size;
                const actionIdentityComplete = requiredActionCount > 0
                    && requiredActionKeys.size === requiredActionCount
                    && matchedTaskCount === requiredActionCount;
                const taskComplete = noActionRequired || (adviceComplete && actionIdentityComplete);
                const today = formatDate(new Date());
                const reviewedActionKeys = new Set(matchedAiExecutionItems.filter(item => {
                    const status = String(item?.review?.status || '');
                    const availableOn = String(item?.review?.available_on || '');
                    return ['success', 'near_success', 'failed'].includes(status)
                        && item?.review?.is_available === true
                        && (!availableOn || availableOn <= today);
                }).map(executionActionKey).filter(Boolean));
                const reviewedCount = reviewedActionKeys.size;
                const reviewComplete = noActionRequired
                    || (taskComplete && requiredActionCount > 0 && reviewedCount === requiredActionCount);
                const steps = [
                    { key: 'data', number: '01', label: '昨日数据', status: dataComplete ? 'ready' : (platformEvidenceCount ? 'partial' : 'data_missing'), detail: `${platformReadyCount}/2 平台三项核心指标已验证` },
                    { key: 'compare', number: '02', label: '竞品对比', status: comparisonComplete ? 'ready' : (comparablePlatforms.size ? 'partial' : (dataComplete ? 'data_missing' : 'blocked')), detail: coreOperationsCompetitorError.value || `${comparablePlatforms.size}/2 平台、${comparableCount} 项可比` },
                    { key: 'anomaly', number: '03', label: '异常判断', status: anomalyEvidenceReady ? (coreOperationsAnomalyRows.value.length ? 'warning' : 'ready') : (coreOperationsLoading.value ? 'not_loaded' : 'blocked'), detail: anomalyEvidenceReady ? (coreOperationsAnomalyRows.value.length ? `${coreOperationsAnomalyRows.value.length} 条信号` : '双平台未见规则异常') : '等待双平台同日竞品证据' },
                    { key: 'advice', number: '04', label: 'AI建议', status: adviceComplete ? (noActionRequired ? 'no_action' : 'ready') : (anomalyEvidenceReady ? (aiSuggestion?.status || 'data_missing') : 'blocked'), detail: noActionRequired ? '双平台诊断均无需新增行动' : (readyAiSuggestions.length ? `${readyAiSuggestions.length} 条可转任务` : '建议未形成') },
                    { key: 'task', number: '05', label: '运营任务', status: taskComplete ? (noActionRequired ? 'no_action' : 'ready') : (adviceComplete ? 'pending' : 'blocked'), detail: noActionRequired ? '本次无需创建任务' : (requiredActionCount ? `${matchedTaskCount}/${requiredActionCount} 条行动已精确转任务` : '等待 AI 建议转任务') },
                    { key: 'review', number: '06', label: '次日复盘', status: reviewComplete ? (noActionRequired ? 'no_action' : 'ready') : (taskComplete ? 'observing' : 'blocked'), detail: noActionRequired ? '本次无任务需复盘' : (requiredActionCount ? `${reviewedCount}/${requiredActionCount} 条行动已复盘` : '等待次日来源回读') },
                ];
                return steps.map(step => ({
                    ...step,
                    statusText: coreOperationsEvidenceStatusText(step.status),
                    statusClass: coreOperationsEvidenceStatusClass(step.status),
                }));
            };
    const buildCloudAuthorizationRows = ({ cloudBrowserAuthorization, platformAuthorizationEvidenceRows }) => {
                const evidenceByPlatform = new Map(platformAuthorizationEvidenceRows.value.map((row) => [row.platform, row]));
                const profileByPlatform = new Map((Array.isArray(cloudBrowserAuthorization.value?.profiles) ? cloudBrowserAuthorization.value.profiles : [])
                    .map((profile) => [String(profile?.platform || '').toLowerCase(), profile]));
                const platformNames = {
                    ctrip: '携程',
                    meituan: '美团 OTA',
                    dingdandao: '订单来了 PMS',
                    meituan_cloud_pms: '美团云 PMS',
                };
                return ['ctrip', 'meituan', 'dingdandao', 'meituan_cloud_pms'].map((platform) => {
                    const profile = profileByPlatform.get(platform) || null;
                    const evidence = evidenceByPlatform.get(platform) || null;
                    const state = String(profile?.authorization_status || 'unauthorized').toLowerCase();
                    const statusMeta = {
                        unauthorized: ['未授权', 'border-slate-200 bg-slate-50 text-slate-700', '创建登录入口', 'request_login'],
                        awaiting_login: ['等待登录', 'border-amber-200 bg-amber-50 text-amber-800', '查看登录入口', 'request_login'],
                        login_verified: ['已登录待验证', 'border-blue-200 bg-blue-50 text-blue-800', '刷新状态', 'refresh'],
                        ready_to_collect: ['可采集', 'border-emerald-200 bg-emerald-50 text-emerald-800', '刷新状态', 'refresh'],
                        session_expired: ['会话失效', 'border-red-200 bg-red-50 text-red-700', '重新登录', 'request_login'],
                        awaiting_relogin: ['等待重新登录', 'border-amber-200 bg-amber-50 text-amber-800', '查看重新登录入口', 'request_login'],
                    }[state] || ['状态未确认', 'border-slate-200 bg-slate-50 text-slate-700', '刷新状态', 'refresh'];
                    const latestText = evidence?.collectionDone
                        ? `最近采集：${evidence.targetDateText || '目标日期'} 已完成保存与回读`
                        : (evidence?.collectionPartial
                            ? `最近采集：${evidence.targetDateText || '目标日期'} 部分完成`
                            : '最近采集：未取得');
                    return {
                        platform,
                        platformName: platformNames[platform] || platform,
                        status: state,
                        statusText: statusMeta[0],
                        statusClass: statusMeta[1],
                        actionText: statusMeta[2],
                        actionMode: statusMeta[3],
                        latestText,
                        gapText: evidence?.collectionDone
                            ? '数据缺口：无已确认缺口'
                            : `数据缺口：${String(evidence?.failureText || '尚未取得正式报告所需的完整数据').trim()}`,
                        summary: `${statusMeta[0]}；${latestText}；${evidence?.collectionDone
                            ? '数据缺口：无已确认缺口'
                            : `数据缺口：${String(evidence?.failureText || '尚未取得正式报告所需的完整数据').trim()}`}`,
                        l: `${platformNames[platform] || platform}：${statusMeta[0]}；${latestText}；${evidence?.collectionDone
                            ? '无已确认缺口'
                            : String(evidence?.failureText || '尚未取得正式报告所需的完整数据').trim()}`,
                    };
                });
            };
    const buildInvestmentDecisionSectionRows = ({ investmentDecisionSections }) => {
                const sections = investmentDecisionSections.value;
                const singleStore = sections.single_store_quality || {};
                const competitor = sections.competitor_comparison || {};
                const calculation = sections.investment_calculation || {};
                const risks = sections.risk_alerts || {};
                const records = sections.decision_records || {};
                return [
                    {
                        key: 'single_store_quality',
                        index: '01',
                        title: singleStore.title || '单店经营质量',
                        status: singleStore.status || 'blocked',
                        metrics: (singleStore.metrics || []).map((metric) => ({ label: metric.label || metric.key, value: metric.value ?? '-' })),
                        note: singleStore.data_policy || '',
                    },
                    {
                        key: 'competitor_comparison',
                        index: '02',
                        title: competitor.title || '竞对比较',
                        status: competitor.status || 'data_gap',
                        metrics: [
                            { label: '样本数', value: competitor.sample_count || 0 },
                            { label: '来源表', value: (competitor.data_sources || []).length },
                            { label: '最新时间', value: competitor.latest_at || '-' },
                        ],
                        note: competitor.source_scope || '',
                    },
                    {
                        key: 'investment_calculation',
                        index: '03',
                        title: calculation.title || '投资测算',
                        status: calculation.status || 'readiness_gap',
                        metrics: [
                            { label: '测算记录', value: calculation.record_count || 0 },
                            { label: '可复核', value: calculation.ready_record_count || 0 },
                            { label: '公式', value: (calculation.formula_inventory || []).length },
                        ],
                        note: '扩张、转让、可行性报告统一进入准入门控。',
                    },
                    {
                        key: 'risk_alerts',
                        index: '04',
                        title: risks.title || '风险提示',
                        status: risks.status || 'clear',
                        metrics: [
                            { label: '风险数', value: (risks.items || []).length },
                            { label: '阻断', value: risks.blocking_count || 0 },
                        ],
                        note: '高风险阻断投决判断，中低风险保留为补证任务。',
                    },
                    {
                        key: 'decision_records',
                        index: '05',
                        title: records.title || '决策记录',
                        status: records.status || 'not_started',
                        metrics: [
                            { label: '记录', value: records.record_count || 0 },
                            { label: '可复核', value: records.eligible_count || 0 },
                        ],
                        note: '仅展示扩张、转让、可行性报告中的已保存记录。',
                    },
                ];
            };
    const buildCoreOperationsSopProgress = ({ coreOperationsHotelId, operatingMemoryItems }) => {
                const hotelId = Number(coreOperationsHotelId.value || 0);
                const scopedRows = operatingMemoryItems.value.filter(memory =>
                    String(memory?.memory_layer || '') === 'execution_review'
                    && (!hotelId || Number(memory?.hotel_id || 0) === hotelId)
                );
                const eligibleRows = scopedRows.filter(memory => {
                    const context = memory?.context && typeof memory.context === 'object' ? memory.context : {};
                    return String(memory?.quality_status || '') === 'verified'
                        && String(memory?.usage_level || '') === 'decision_support'
                        && context.source_verified === true
                        && context.outcome_verified === true
                        && context.positive_outcome_verified === true
                        && (context.separate_effect_review_required !== true
                            || context.separate_effect_review_verified === true)
                        && Number(memory?.source_record_id || 0) > 0
                        && /^\d{4}-\d{2}-\d{2}$/.test(String(memory?.business_date || ''));
                });
                const groups = new Map();
                eligibleRows.forEach(memory => {
                    const platform = String(memory?.platform || 'unknown').trim().toLowerCase() || 'unknown';
                    const sourceScope = String(memory?.source_scope || 'unknown_scope').trim() || 'unknown_scope';
                    const key = `${platform}|${sourceScope}`;
                    if (!groups.has(key)) groups.set(key, { platform, sourceScope, rows: [], taskIds: new Set(), dates: new Set() });
                    const group = groups.get(key);
                    group.rows.push(memory);
                    group.taskIds.add(Number(memory.source_record_id));
                    group.dates.add(String(memory.business_date));
                });
                const best = [...groups.values()].sort((left, right) =>
                    right.taskIds.size - left.taskIds.size
                    || right.dates.size - left.dates.size
                    || right.rows.length - left.rows.length
                )[0] || null;
                const memoryCount = best ? best.taskIds.size : 0;
                const taskCount = best ? best.taskIds.size : 0;
                const businessDateCount = best ? best.dates.size : 0;
                return {
                    memoryCount,
                    taskCount,
                    businessDateCount,
                    ready: memoryCount >= 3 && taskCount >= 3 && businessDateCount >= 2,
                    excludedCount: Math.max(0, scopedRows.length - (best?.rows?.length || 0)),
                    scopeText: best
                        ? `${best.platform.toUpperCase()} · ${best.sourceScope}`
                        : '尚无已核验的同范围效果复盘',
                };
            };
    const buildPlatformAccountCenterRows = ({ getAutoFetchHotelId, hotelApplicablePlatformBindingRows, hotelPlatformFetchConfigReady, hotelPlatformManualCookieReady, hotels, platformAccountBlockerText, platformAccountCurrentSessionVerified, platformAccountProfileReusable, platformAccountReadinessClass, platformAccountReadinessCode, platformAccountReadinessText, platformCollectionStatus, platformCollectionStatusRows }) => {
                const selectedHotelId = String(getAutoFetchHotelId() || '');
                return (Array.isArray(hotels.value) ? hotels.value : [])
                    .flatMap(hotel => hotelApplicablePlatformBindingRows(hotel).map(account => {
                        const currentSessionVerified = platformAccountCurrentSessionVerified(account);
                        const profileReusable = platformAccountProfileReusable(account);
                        const collectionRow = selectedHotelId === String(hotel.id || '')
                            ? platformCollectionStatusRows.value.find(item => String(item?.platform || '').toLowerCase() === account.platform) || null
                            : null;
                        const storedRowCount = collectionRow && Number.isFinite(Number(collectionRow.storedRowCount))
                            ? Number(collectionRow.storedRowCount)
                            : null;
                        const usesProfile = !!account.profileSource;
                        const usesManualAssist = !usesProfile && (
                            hotelPlatformManualCookieReady(hotel, account)
                            || hotelPlatformFetchConfigReady(hotel, account)
                        );
                        const readinessCode = platformAccountReadinessCode(hotel, account);
                        const recentCollectionFailed = account.captureStatusText === '最近采集失败';
                        const actionTarget = ['auto_ready', 'renewal_warning'].includes(readinessCode)
                            ? (usesProfile ? 'profile-capture' : 'platform-auto')
                            : (readinessCode === 'manual_ready' ? 'platform-manual'
                            : (account.nextActionTarget || (readinessCode === 'waiting_login' || readinessCode === 'login_expired' ? 'profile-login' : 'hotel-ota')));
                        return {
                            key: `${hotel.id || 'unknown'}-${account.platform || 'unknown'}-${account.deleteKey || 'account'}`,
                            hotel,
                            account,
                            platform: account.platform,
                            platformText: account.label || account.platform || '-',
                            pathText: usesProfile ? '浏览器 Profile' : (usesManualAssist ? 'Cookie/API 手动辅助' : '未配置采集路径'),
                            loginText: currentSessionVerified
                                ? '今天已验证'
                                : (profileReusable
                                    ? (account.renewalWarning ? '可采集，建议续登' : '可直接尝试采集')
                                    : (usesProfile ? '登录态未验证' : (usesManualAssist ? '未验证 Profile 登录态' : '未验证'))),
                            currentSessionVerified,
                            profileReusable,
                            identityText: readinessCode === 'hotel_mismatch' ? '门店身份冲突' : (account.level === 'missing' ? '身份待绑定' : '门店身份已绑定'),
                            readinessCode,
                            readinessText: platformAccountReadinessText(readinessCode),
                            readinessClass: platformAccountReadinessClass(readinessCode),
                            blockerText: platformAccountBlockerText(readinessCode, account),
                            recentCollectionText: account.captureStatusText || '未加载',
                            recentCollectionClass: account.captureStatusClass || 'border-slate-200 bg-slate-50 text-slate-600',
                            recentCollectionAt: account.lastCaptureText || '-',
                            lastSuccessText: account.lastSuccessText || '-',
                            storedRowsText: storedRowCount === null ? '入库未加载' : `入库 ${storedRowCount} 条`,
                            targetDateText: collectionRow?.targetDate || platformCollectionStatus.value?.targetDate || '未加载',
                            actionTarget,
                            showFailureLog: recentCollectionFailed,
                            actionText: ['auto_ready', 'renewal_warning'].includes(readinessCode)
                                ? (usesProfile
                                    ? (recentCollectionFailed ? 'Profile 再次采集' : '使用 Profile 采集')
                                    : (recentCollectionFailed ? 'Cookie/API 再次补采' : 'Cookie/API 临时补采'))
                                : (readinessCode === 'manual_ready' ? 'Cookie/API 临时补采'
                                    : (readinessCode === 'waiting_login' ? '验证 Profile 登录'
                                        : (readinessCode === 'login_expired' ? '重新登录' : (account.nextActionText || '处理账号')))),
                        };
                    }))
                    .sort((a, b) => {
                        const weight = { permission_denied: 5, hotel_mismatch: 10, login_expired: 15, waiting_login: 20, missing_config: 30, unbound: 40, manual_ready: 70, renewal_warning: 75, auto_ready: 80, inactive: 90 };
                        return (weight[a.readinessCode] || 60) - (weight[b.readinessCode] || 60)
                            || String(a.hotel?.name || '').localeCompare(String(b.hotel?.name || ''), 'zh-CN');
                    });
            };
    return {
        buildInvestmentDecisionActionQueueRows,
        buildManualNotificationThreeSourceSummary,
        buildManualNotificationAutomaticTaskRows,
        buildEmployeeOtaChecklistRows,
        buildPlatformProfileFlowRows,
        buildCtripTrafficBusinessQuality,
        buildLocalCollectorLoginTaskRows,
        buildManualNotificationFieldErrors,
        buildCoreOperationsPlatformCards,
        buildCoreOperationsCompetitorRows,
        buildCoreOperationsAiSuggestions,
        buildCoreOperationsStepRows,
        buildCloudAuthorizationRows,
        buildInvestmentDecisionSectionRows,
        buildCoreOperationsSopProgress,
        buildPlatformAccountCenterRows,
    };
})();
