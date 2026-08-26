(function () {
    'use strict';

    const create = ({
        computed,
        manualNotificationForm,
        manualNotificationDispatchHistory,
        manualNotificationMetadata,
        manualNotificationFormalRobotOptions,
        manualNotificationDataScopeLabel,
        manualNotificationDataStatus,
        manualNotificationLatestDispatch,
        manualNotificationFieldErrors,
        manualNotificationIsOperatingDaily,
        manualNotificationIsStrictThreeSourceInterval,
        manualNotificationCanConfigureStrictThreeSourceInterval,
        manualNotificationIsStrictThreeSourceHourly,
        manualNotificationCanConfigureStrictThreeSourceHourly,
        manualNotificationError,
        manualNotificationStrictThreeSourceSections,
        operationToday,
        operationYesterday,
        applyCurrentHotelNotificationChannel,
        manualNotificationPreview,
        showToast,
        loadManualNotificationMetadata,
        syncManualNotificationTargetRobot,
        manualNotificationExpandedDispatchId,
        operationHotelOptions,
        openAutomationMonitorDrilldown,
        openHotelManualFetchConfig,
        prepareHotelPlatformAccountContext,
        currentPage,
        nextTick,
        openPlatformSourcesTab,
        openHotelManualFetch,
    }) => {
        const manualNotificationPlanRuntimeStatus = computed(() => {
            const notificationId = Number(manualNotificationForm.value?.id || 0);
            const dispatches = (manualNotificationDispatchHistory.value?.list || [])
                .filter(item => Number(item?.notification_id || 0) === notificationId);
            const latest = notificationId > 0 ? (dispatches[0] || null) : null;
            const lastSuccess = dispatches.find(
                item => String(item?.status || '').toLowerCase() === 'sent'
            ) || null;
            const sourceContract = manualNotificationMetadata.value
                ?.three_source_hourly_status;
            if (sourceContract?.contract_version === 'cloud_three_source_hourly_status.v1'
                && sourceContract?.sources
                && typeof sourceContract.sources === 'object'
            ) {
                const statusPresentation = {
                    ready: {
                        label: '已就绪',
                        tone: 'border-emerald-200 bg-emerald-50 text-emerald-700',
                    },
                    stale: {
                        label: '需补采',
                        tone: 'border-amber-200 bg-amber-50 text-amber-700',
                    },
                    login_required: {
                        label: '需重新登录',
                        tone: 'border-rose-200 bg-rose-50 text-rose-700',
                    },
                    binding_missing: {
                        label: '需核对绑定',
                        tone: 'border-rose-200 bg-rose-50 text-rose-700',
                    },
                    readback_missing: {
                        label: '保存回读未通过',
                        tone: 'border-amber-200 bg-amber-50 text-amber-700',
                    },
                    partial: {
                        label: '部分可用',
                        tone: 'border-amber-200 bg-amber-50 text-amber-700',
                    },
                    unknown: {
                        label: '待核验',
                        tone: 'border-slate-200 bg-white text-slate-600',
                    },
                };
                const actionPresentation = {
                    collect_now: ['recollect_source', '去重新采集'],
                    request_login: ['relogin_source', '去重新登录'],
                    check_binding: ['check_source_binding', '检查绑定'],
                };
                const sourceDefinitions = [
                    ['dingdandao_pms', 'pms', '订单来了'],
                    ['ctrip', 'ctrip', '携程'],
                    ['meituan', 'meituan', '美团'],
                ];
                const sources = sourceDefinitions.map(([contractKey, key, label]) => {
                    const source = sourceContract.sources?.[contractKey] || {};
                    const status = String(source.status || 'unknown');
                    const presentation = statusPresentation[status]
                        || statusPresentation.unknown;
                    const expiringSoon = source.profile?.expiring_soon === true;
                    const rawAction = expiringSoon && status === 'ready'
                        ? 'request_login'
                        : String(source.action_key || 'collect_now');
                    const action = status === 'ready' && !expiringSoon
                        ? ['check_source_status', '查看状态']
                        : (actionPresentation[rawAction]
                            || actionPresentation.collect_now);
                    const expiryNote = expiringSoon
                        ? ` 会话约剩 ${Number(source.profile?.hours_remaining || 0)} 小时，建议提前续登。`
                        : '';
                    return {
                        key,
                        label: source.label || label,
                        status_label: expiringSoon && status === 'ready'
                            ? '已就绪·即将到期'
                            : presentation.label,
                        detail: `${String(source.message || '未取得独立来源状态。')}${expiryNote}`,
                        tone: presentation.tone,
                        action_key: action[0],
                        action_label: action[1],
                    };
                });
                const overallStatus = String(sourceContract.status || 'unknown');
                const overallPresentation = statusPresentation[overallStatus]
                    || statusPresentation.unknown;
                const firstBlocker = sources.find((source, index) => (
                    String(sourceContract.sources?.[sourceDefinitions[index][0]]?.status || '')
                        !== 'ready'
                ));
                return {
                    overall_label: overallStatus === 'ready'
                        ? '三源已就绪'
                        : `三源待处理·${overallPresentation.label}`,
                    overall_tone: overallPresentation.tone,
                    sources,
                    observed_at: String(sourceContract.observed_at || ''),
                    last_success_at: lastSuccess?.dispatched_at
                        || lastSuccess?.last_attempt_at
                        || '',
                    recent_blocker: firstBlocker?.detail || '',
                };
            }
            const latestStatus = String(latest?.status || '').toLowerCase();
            const reasonText = [latest?.result_code, latest?.result_message]
                .map(value => String(value || '').trim())
                .filter(Boolean)
                .join(' · ');
            const loweredReason = reasonText.toLowerCase();
            const references = Object.values(
                latest?.source_snapshot_refs && typeof latest.source_snapshot_refs === 'object'
                    ? latest.source_snapshot_refs
                    : {}
            );
            const referencedSources = new Set(references.map(reference => {
                const source = String(reference?.source || '').toLowerCase();
                if (['pms', 'dingdandao', 'dingdandao_pms'].includes(source)) {
                    return 'pms';
                }
                return source;
            }));
            const definitions = [
                {
                    key: 'pms',
                    label: '订单来了',
                    matches: /(?:订单来了|dingdandao|\bpms\b)/i,
                },
                { key: 'ctrip', label: '携程', matches: /(?:携程|ctrip)/i },
                { key: 'meituan', label: '美团', matches: /(?:美团|meituan)/i },
            ];
            const actionFor = (definition, blocked) => {
                if (!blocked) {
                    return {
                        action_key: 'check_source_status',
                        action_label: '查看状态',
                    };
                }
                if (definition.key !== 'pms'
                    && /(?:login|session|cookie|profile|auth|登录|会话|授权)/i.test(loweredReason)
                ) {
                    return {
                        action_key: 'relogin_source',
                        action_label: '去重新登录',
                    };
                }
                if (/(?:binding|identity|hotel_mismatch|绑定|门店不匹配)/i.test(loweredReason)) {
                    return {
                        action_key: 'check_source_binding',
                        action_label: '检查绑定',
                    };
                }
                return {
                    action_key: 'recollect_source',
                    action_label: '去重新采集',
                };
            };
            const sources = definitions.map(definition => {
                const sourceBlocked = latestStatus === 'blocked'
                    && definition.matches.test(reasonText);
                const referenced = referencedSources.has(definition.key);
                let statusLabel = '待核验';
                let detail = '最近记录未提供该来源的独立状态。';
                let tone = 'border-slate-200 bg-white text-slate-600';
                if (sourceBlocked) {
                    statusLabel = '本轮阻断';
                    detail = reasonText || '该来源阻断了本次发送。';
                    tone = 'border-amber-200 bg-amber-50 text-amber-700';
                } else if (referenced && latestStatus === 'sent') {
                    statusLabel = '已送达';
                    detail = '最近成功消息已引用该来源的已保存回读快照。';
                    tone = 'border-emerald-200 bg-emerald-50 text-emerald-700';
                } else if (referenced) {
                    statusLabel = '已引用快照';
                    detail = '本次记录已引用该来源快照，但消息尚未取得成功送达回执。';
                    tone = 'border-sky-200 bg-sky-50 text-sky-700';
                } else if (latestStatus === 'blocked' && reasonText) {
                    detail = '本轮被其他来源或公共门禁阻断，该来源状态未确认。';
                }
                return {
                    ...definition,
                    ...actionFor(definition, sourceBlocked),
                    status_label: statusLabel,
                    detail,
                    tone,
                };
            });
            const overall = latestStatus === 'sent'
                ? {
                    overall_label: '最近一次已送达',
                    overall_tone: 'border-emerald-200 bg-emerald-50 text-emerald-700',
                }
                : latestStatus === 'blocked'
                    ? {
                        overall_label: '最近一次被阻断',
                        overall_tone: 'border-amber-200 bg-amber-50 text-amber-700',
                    }
                    : ['failed', 'outcome_unknown'].includes(latestStatus)
                        ? {
                            overall_label: latestStatus === 'failed' ? '最近一次发送失败' : '最近一次结果不明',
                            overall_tone: 'border-rose-200 bg-rose-50 text-rose-700',
                        }
                        : {
                            overall_label: '状态待核验',
                            overall_tone: 'border-slate-200 bg-slate-50 text-slate-600',
                        };
            return {
                ...overall,
                sources,
                observed_at: latest?.dispatched_at
                    || latest?.last_attempt_at
                    || latest?.claimed_at
                    || '',
                last_success_at: lastSuccess?.dispatched_at
                    || lastSuccess?.last_attempt_at
                    || '',
                recent_blocker: ['blocked', 'failed', 'outcome_unknown'].includes(latestStatus)
                    ? (reasonText || '已记录异常，但未取得详细原因。')
                    : '',
            };
        });
        const manualNotificationDispatchTriggerLabel = (item) => {
            const requestKind = String(item?.request_kind || '').toLowerCase();
            if (requestKind === 'immediate_test') return '手动测试';
            if (requestKind === 'explicit_retry') return '人工重试';
            if (requestKind === 'scheduled') return '自动发送';
            const triggerType = String(item?.trigger_type || '').toLowerCase();
            if (triggerType === 'manual_test') return '手动测试';
            if (triggerType) return '自动发送';
            return '触发方式未取得';
        };
        const manualNotificationDispatchStatusLabel = (item) => ({
            sent: '已送达',
            failed: '发送失败',
            blocked: '门禁阻断',
            outcome_unknown: '送达结果不明',
        }[String(item?.status || '').toLowerCase()] || '执行记录未取得');
        const manualNotificationDispatchStatusClass = (item) => ({
            sent: 'border-emerald-200 bg-emerald-50 text-emerald-700',
            failed: 'border-rose-200 bg-rose-50 text-rose-700',
            blocked: 'border-amber-200 bg-amber-50 text-amber-700',
            outcome_unknown: 'border-amber-200 bg-amber-50 text-amber-700',
        }[String(item?.status || '').toLowerCase()]
            || 'border-slate-200 bg-slate-50 text-slate-600');
        const manualNotificationDispatchRetryClass = (item) => (
            String(item?.status || '').toLowerCase() === 'outcome_unknown'
                ? 'border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300'
                : 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300'
        );
        const manualNotificationDispatchCanRetry = (item) => {
            const status = String(item?.status || '').toLowerCase();
            if (!['failed', 'outcome_unknown'].includes(status)) return false;
            if (item?.retryable === false) return false;
            const attemptCount = Number(item?.attempt_count || 0);
            const maxAttempts = Number(item?.max_attempts || 0);
            return maxAttempts > 0 && attemptCount < maxAttempts;
        };
        const manualNotificationDispatchTimeline = (item = {}) => {
            const attempts = Array.isArray(item?.attempts) ? item.attempts : [];
            const rows = [];
            const statusLabel = status => ({
                sent: '已送达',
                failed: '发送失败',
                blocked: '门禁阻断',
                outcome_unknown: '结果不明',
                pending: '等待处理',
                processing: '处理中',
            }[String(status || '').toLowerCase()] || '状态未取得');
            const statusTone = status => ({
                sent: 'border-emerald-300 bg-emerald-50 text-emerald-700',
                failed: 'border-rose-300 bg-rose-50 text-rose-700',
                blocked: 'border-amber-300 bg-amber-50 text-amber-700',
                outcome_unknown: 'border-amber-300 bg-amber-50 text-amber-700',
            }[String(status || '').toLowerCase()]
                || 'border-slate-300 bg-white text-slate-600');

            if (item.claimed_at) {
                rows.push({
                    key: `claimed-${item.id || ''}`,
                    label: '任务已认领',
                    time: item.claimed_at,
                    detail: '调度器已取得本次任务，尚不能据此判断送达。',
                    tone: 'border-blue-300 bg-blue-50 text-blue-700',
                });
            }
            attempts.forEach((attempt, index) => {
                const attemptNo = Number(attempt?.attempt_no || index + 1);
                const status = String(attempt?.status || '');
                rows.push({
                    key: `attempt-${item.id || ''}-${attemptNo}`,
                    label: `第 ${attemptNo} 次发送 · ${statusLabel(status)}`,
                    time: attempt?.attempted_at || '时间未取得',
                    detail: attempt?.result_message || attempt?.result_code || '未取得本次尝试回执。',
                    tone: statusTone(status),
                });
            });
            if (item.dispatched_at) {
                const status = String(item?.status || '');
                rows.push({
                    key: `dispatched-${item.id || ''}`,
                    label: `本次处理结束 · ${statusLabel(status)}`,
                    time: item.dispatched_at,
                    detail: item?.result_message || item?.result_code || '未取得最终回执说明。',
                    tone: statusTone(status),
                });
            }
            if (!rows.length && item.created_at) {
                rows.push({
                    key: `created-${item.id || ''}`,
                    label: '已创建发送记录',
                    time: item.created_at,
                    detail: '尚未取得认领、尝试或结束时间。',
                    tone: 'border-slate-300 bg-white text-slate-600',
                });
            }
            return rows;
        };
        const toggleManualNotificationDispatchTimeline = (item = {}) => {
            const id = String(item?.id || '');
            manualNotificationExpandedDispatchId.value = (
                id && manualNotificationExpandedDispatchId.value !== id ? id : ''
            );
        };
        const manualNotificationSchedulePanelProps = computed(() => ({
            metadata: manualNotificationMetadata.value,
            form: manualNotificationForm.value,
            robots: manualNotificationFormalRobotOptions.value,
            dataScopeLabel: manualNotificationDataScopeLabel.value,
            dataStatus: manualNotificationDataStatus.value,
            latestDispatch: manualNotificationLatestDispatch.value,
            validationErrors: manualNotificationFieldErrors.value,
            operatingDaily: manualNotificationIsOperatingDaily.value,
            strictThreeSourceInterval:
                manualNotificationIsStrictThreeSourceInterval.value,
            strictThreeSourceIntervalAvailable:
                manualNotificationCanConfigureStrictThreeSourceInterval.value,
            strictThreeSourceHourly:
                manualNotificationIsStrictThreeSourceHourly.value,
            strictThreeSourceHourlyAvailable:
                manualNotificationCanConfigureStrictThreeSourceHourly.value,
            runtimeStatus: manualNotificationPlanRuntimeStatus.value,
            error: manualNotificationError.value,
        }));
        const applyManualNotificationThreeSourceHourlyPreset = () => {
            if (!manualNotificationCanConfigureStrictThreeSourceHourly.value) {
                showToast('请先选择经营日报模板和当前酒店。', 'warning');
                return false;
            }
            manualNotificationForm.value = {
                ...manualNotificationForm.value,
                trigger_type: 'hourly_on_the_hour',
                interval_minutes: 60,
                source_scope: 'combined',
                content_sections: [...manualNotificationStrictThreeSourceSections],
                business_date: operationToday,
                business_date_rule: 'today',
                send_method: 'wecom_formal',
                planned_send_at: '',
                hourly_start_time: '01:00',
                hourly_end_time: '23:00',
                condition_type: 'always',
                active_weekdays: [1, 2, 3, 4, 5, 6, 7],
            };
            applyCurrentHotelNotificationChannel();
            manualNotificationPreview.value = null;
            showToast('已套用三源整点配置；请确认企业微信群、时段和启用状态后保存。', 'success');
            return true;
        };
        const updateManualNotificationScheduleField = ({ field, value } = {}) => {
            if (![
                'business_date',
                'business_date_rule',
                'source_scope',
                'content_sections',
                'trigger_type',
                'interval_minutes',
                'planned_send_at',
                'active_weekdays',
                'effective_from',
                'effective_to',
                'hourly_start_time',
                'hourly_end_time',
                'condition_type',
                'condition_threshold',
                'condition_step',
                'send_method',
                'target_robot_id',
                'enabled',
            ].includes(field)) return;
            if (field === 'business_date_rule') {
                manualNotificationForm.value.business_date_rule = String(value || 'today');
                manualNotificationForm.value.business_date =
                    manualNotificationForm.value.business_date_rule === 'yesterday'
                        ? operationYesterday
                        : operationToday;
                manualNotificationPreview.value = null;
                loadManualNotificationMetadata();
                return;
            }
            if (field === 'trigger_type'
                && String(value || '') === 'interval_minutes'
                && manualNotificationCanConfigureStrictThreeSourceInterval.value
            ) {
                manualNotificationForm.value = {
                    ...manualNotificationForm.value,
                    trigger_type: 'interval_minutes',
                    interval_minutes: 30,
                    source_scope: 'combined',
                    content_sections: [...manualNotificationStrictThreeSourceSections],
                    business_date: operationToday,
                    business_date_rule: 'today',
                    send_method: 'wecom_formal',
                    planned_send_at: '',
                    hourly_end_time: '23:59',
                };
                applyCurrentHotelNotificationChannel();
                manualNotificationPreview.value = null;
                return;
            }
            if (field === 'trigger_type'
                && String(value || '') === 'hourly_on_the_hour'
                && manualNotificationCanConfigureStrictThreeSourceHourly.value
            ) {
                applyManualNotificationThreeSourceHourlyPreset();
                return;
            }
            if (field === 'source_scope') {
                const source = (manualNotificationMetadata.value?.source_scopes || []).find(
                    item => String(item?.key || '') === String(value || 'combined')
                );
                const nextSections = String(
                    manualNotificationForm.value.trigger_type || ''
                ) === 'hourly_on_the_hour'
                    && manualNotificationCanConfigureStrictThreeSourceHourly.value
                    && String(value || '') === 'combined'
                    ? [...manualNotificationStrictThreeSourceSections]
                    : String(manualNotificationForm.value.trigger_type || '')
                        === 'interval_minutes'
                    && manualNotificationCanConfigureStrictThreeSourceInterval.value
                    && String(value || '') === 'combined'
                    ? [...manualNotificationStrictThreeSourceSections]
                    : (Array.isArray(source?.default_sections)
                        ? [...source.default_sections]
                        : []);
                manualNotificationForm.value.source_scope = String(value || 'combined');
                manualNotificationForm.value.content_sections = nextSections;
                if (manualNotificationIsOperatingDaily.value
                    && String(manualNotificationForm.value.condition_type || 'always') !== 'always'
                    && (
                        !['combined', 'dingdandao_pms'].includes(
                            manualNotificationForm.value.source_scope
                        )
                        || !nextSections.includes('pms_efficiency')
                    )
                ) {
                    manualNotificationForm.value.condition_type = 'always';
                    manualNotificationForm.value.condition_state = null;
                }
                manualNotificationPreview.value = null;
                return;
            }
            manualNotificationForm.value[field] = value;
            manualNotificationPreview.value = null;
            if (field === 'content_sections'
                && manualNotificationIsOperatingDaily.value
                && String(manualNotificationForm.value.condition_type || 'always') !== 'always'
                && !(Array.isArray(value) ? value : []).includes('pms_efficiency')
            ) {
                manualNotificationForm.value.condition_type = 'always';
                manualNotificationForm.value.condition_state = null;
            }
            if (field === 'target_robot_id') syncManualNotificationTargetRobot();
            if (field === 'business_date') loadManualNotificationMetadata();
        };
        const handleManualNotificationSourceAction = async (payload = {}) => {
            const source = String(payload?.source || '').toLowerCase();
            const actionKey = String(payload?.action_key || '').trim();
            const hotelId = String(manualNotificationForm.value?.hotel_id || '').trim();
            const businessDate = String(
                manualNotificationForm.value?.business_date || operationToday
            ).trim();
            if (!hotelId || !['pms', 'ctrip', 'meituan'].includes(source)) {
                showToast('当前来源缺少可操作的酒店范围，请先重新选择酒店。', 'warning');
                return false;
            }
            if (source === 'pms') {
                openAutomationMonitorDrilldown({
                    hotel_id: hotelId,
                    business_date: businessDate,
                }, 'pms');
                showToast(
                    actionKey === 'check_source_binding'
                        ? '已打开订单来了绑定与经营数据，请核对门店身份。'
                        : '已打开订单来了经营数据，可在确认范围后重新读取。',
                    'info'
                );
                return true;
            }

            const hotel = operationHotelOptions.value.find(
                item => String(item?.id || '') === hotelId
            );
            if (!hotel) {
                showToast('当前账号未取得该酒店，已停止来源穿透。', 'warning');
                return false;
            }
            if (actionKey === 'check_source_binding') {
                await openHotelManualFetchConfig(hotel, source);
                return true;
            }
            if (actionKey === 'relogin_source') {
                const preparedHotelId = await prepareHotelPlatformAccountContext(hotel, source);
                if (!preparedHotelId) return false;
                currentPage.value = 'online-data';
                await nextTick();
                openPlatformSourcesTab({ force: true, delayMs: 0 });
                showToast(`已打开${source === 'ctrip' ? '携程' : '美团'}登录状态；请在原授权设备完成重新登录。`, 'warning');
                return true;
            }
            if (actionKey === 'recollect_source') {
                await openHotelManualFetch(hotel, source);
                showToast(`已定位${source === 'ctrip' ? '携程' : '美团'}手动补采入口，确认后再执行采集。`, 'info');
                return true;
            }
            openAutomationMonitorDrilldown({
                hotel_id: hotelId,
                business_date: businessDate,
            }, source);
            showToast(`已打开${source === 'ctrip' ? '携程' : '美团'}来源状态。`, 'info');
            return true;
        };
        const manualNotificationSchedulePanelEvents = {
            fieldChange: updateManualNotificationScheduleField,
            applyHourlyPreset: applyManualNotificationThreeSourceHourlyPreset,
            sourceAction: payload => handleManualNotificationSourceAction(payload),
        };
        return Object.freeze({
            manualNotificationPlanRuntimeStatus,
            manualNotificationDispatchTriggerLabel,
            manualNotificationDispatchStatusLabel,
            manualNotificationDispatchStatusClass,
            manualNotificationDispatchRetryClass,
            manualNotificationDispatchCanRetry,
            manualNotificationDispatchTimeline,
            toggleManualNotificationDispatchTimeline,
            manualNotificationSchedulePanelProps,
            applyManualNotificationThreeSourceHourlyPreset,
            updateManualNotificationScheduleField,
            handleManualNotificationSourceAction,
        });
    };

    window.SUXI_MANUAL_NOTIFICATION_ORCHESTRATION_STATIC = Object.freeze({ create });
})();
