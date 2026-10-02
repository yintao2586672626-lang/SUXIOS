(() => {
    'use strict';
    const createEvidenceNavigation = ({ state, getContext, canOpen, nextTick, focus, h, icon }) => {
        const operatingEvidenceTarget = (guideResult, turn) => {
            const exact = guideResult?.operating_result;
            const request = {
                id: Number(exact?.id || 0),
                hotel_id: Number(exact?.hotel_id || 0),
                platform: String(exact?.platform || ''),
                date_start: String(exact?.date_start || ''),
                date_end: String(exact?.date_end || ''),
                content_digest: String(exact?.content_digest || ''),
                precise_query_id: Number(guideResult?.precise_query_id || 0),
                question_text: String(exact?.question_text || turn?.query || guideResult?.original_query || ''),
            };
            let error = '';
            if (!Number.isSafeInteger(request.id) || request.id <= 0) {
                error = '这条回答没有已保存的经营问答编号，暂时无法打开完整证据。';
            } else if (!Number.isSafeInteger(request.hotel_id) || request.hotel_id <= 0
                || !request.platform
                || !/^\d{4}-\d{2}-\d{2}$/.test(request.date_start)
                || !/^\d{4}-\d{2}-\d{2}$/.test(request.date_end)
                || request.date_start > request.date_end
                || !/^[a-f0-9]{64}$/.test(request.content_digest)
            ) {
                error = '这条回答缺少完整范围或回读凭证，暂时无法定位原始证据。';
            }
            return {
                key: `operating-evidence:${String(turn?.id || request.id)}`,
                request,
                error,
            };
        };
        const openOperatingWorkspace = async (guideResult, turn) => {
            if (state.value.opening_key) return false;
            const target = operatingEvidenceTarget(guideResult, turn);
            const setError = (message) => {
                state.value.evidence_errors = { ...state.value.evidence_errors, [target.key]: message };
            };
            setError('');
            if (target.error) {
                setError(target.error);
                return false;
            }
            state.value.opening_key = target.key;
            try {
                const ctx = getContext();
                if (!canOpen()) {
                    throw new Error('当前账号没有专业问答入口权限，请联系管理员核对。');
                }
                if (typeof ctx.openOperatingQuestionEvidence !== 'function') {
                    throw new Error('完整证据入口尚未加载，请稍后重试。');
                }
                const exact = await ctx.openOperatingQuestionEvidence(target.request);
                if (Number(exact?.id || 0) !== target.request.id
                    || Number(exact?.hotel_id || 0) !== target.request.hotel_id
                    || ['platform', 'date_start', 'date_end', 'content_digest'].some(
                        (key) => String(exact?.[key] || '') !== target.request[key]
                    )
                ) throw new Error('回读结果与这条回答的编号、范围或凭证不一致，请重试。');
                await nextTick();
                await focus();
                return true;
            } catch (error) {
                setError(error?.message || '完整证据回读失败，请重试。');
                return false;
            } finally {
                state.value.opening_key = '';
            }
        };
        const renderEvidenceButton = (guideResult, turn, isLatest, assistantMode, evidenceTarget) => h('button', {
            type: 'button',
            'data-testid': isLatest ? 'system-guide-open-operating-workspace' : `system-guide-open-operating-workspace-${turn?.id || evidenceTarget.request.id}`,
            'data-operating-question-id': evidenceTarget.request.id || undefined,
            disabled: Boolean(evidenceTarget.error || state.value.opening_key),
            'aria-busy': state.value.opening_key === evidenceTarget.key,
            onClick: () => openOperatingWorkspace(guideResult, turn),
        }, [
            icon(state.value.opening_key === evidenceTarget.key ? 'fa-spinner fa-spin' : 'fa-arrow-right'),
            h('span', state.value.opening_key === evidenceTarget.key
                ? '正在打开这条回答…'
                : (assistantMode === 'action' ? '到专业页面复核草案' : '查看完整证据与引用')),
        ]);
        const operatingScopeText = (result) => {
            const ctx = getContext();
            const hotelId = Number(result?.hotel_id || 0);
            const hotel = (Array.isArray(ctx?.otaDiagnosisHotelOptions) ? ctx.otaDiagnosisHotelOptions : [])
                .find((item) => Number(item?.value || 0) === hotelId);
            const hotelText = String(hotel?.name || (hotelId > 0 ? `酒店 #${hotelId}` : '未锁定酒店'));
            const platformText = String(ctx?.operatingQuestionPlatformText?.(result?.platform) || result?.platform || '未锁定平台');
            const dateStart = String(result?.date_start || '');
            const dateEnd = String(result?.date_end || '');
            const dateText = dateStart ? `${dateStart}${dateEnd && dateEnd !== dateStart ? ` 至 ${dateEnd}` : ''}` : '未锁定日期';
            return `${hotelText} · ${platformText} · ${dateText}`;
        };
        return { operatingEvidenceTarget, openOperatingWorkspace, renderEvidenceButton, operatingScopeText };
    };
    window.SUXI_OPERATING_EVIDENCE_NAVIGATION = Object.freeze({ createEvidenceNavigation });
})();
