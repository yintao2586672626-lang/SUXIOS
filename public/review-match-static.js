window.SUXI_REVIEW_MATCH_STATIC = (() => {
    const parseCtripReviewMatchJsonValue = (value, label, emptyValue) => {
        const text = String(value || '').trim();
        if (!text) return emptyValue;
        try {
            return JSON.parse(text);
        } catch (error) {
            throw new Error(`${label} JSON 格式错误: ${error.message}`);
        }
    };

    const requireCtripReviewMatchObject = (value, label) => {
        if (value && typeof value === 'object' && !Array.isArray(value)) return value;
        throw new Error(`${label} 必须是 JSON 对象`);
    };

    const assignCtripReviewMatchText = (target, key, value) => {
        const text = String(value ?? '').trim();
        if (text) target[key] = text;
    };

    const buildCtripReviewMatchPayload = ({
        action,
        form = {},
        systemHotelId,
        sample = null,
        requireOrder = false,
        requireReason = false,
    } = {}) => {
        const base = { system_hotel_id: String(systemHotelId || '').trim() };
        const buildReview = () => {
            const raw = requireCtripReviewMatchObject(
                parseCtripReviewMatchJsonValue(form.rawReviewJson, '评价信息', {}),
                '评价信息'
            );
            const review = { ...raw };
            assignCtripReviewMatchText(review, 'commentId', form.commentId);
            assignCtripReviewMatchText(review, 'userName', form.userName);
            assignCtripReviewMatchText(review, 'checkinTimeStr', form.checkinTimeStr);
            assignCtripReviewMatchText(review, 'hotelRoomInfo', form.hotelRoomInfo);
            if (!String(review.commentId || review.comment_id || review.id || review.reviewId || review.review_id || '').trim()) {
                throw new Error('请填写评价 commentId');
            }
            return review;
        };

        if (action === 'review') return { ...base, review: buildReview() };
        if (action === 'im') {
            const rawValue = parseCtripReviewMatchJsonValue(form.rawImSessionJson, 'IM 会话', {});
            const session = Array.isArray(rawValue)
                ? { members: rawValue }
                : { ...requireCtripReviewMatchObject(rawValue, 'IM 会话') };
            assignCtripReviewMatchText(session, 'groupId', form.imGroupId);
            assignCtripReviewMatchText(session, 'sessionId', form.imSessionId);
            assignCtripReviewMatchText(session, 'orderId', form.imOrderId);
            assignCtripReviewMatchText(session, 'arrivalDate', form.imArrivalDate);
            assignCtripReviewMatchText(session, 'departureDate', form.imDepartureDate);
            assignCtripReviewMatchText(session, 'roomName', form.imRoomName);
            session.members = [];
            if (!String(session.groupId || session.group_id || '').trim()) {
                throw new Error('请填写携程 IM groupId');
            }
            return { ...base, session };
        }
        if (action === 'order') {
            const raw = requireCtripReviewMatchObject(
                parseCtripReviewMatchJsonValue(form.rawOrderJson, '订单信息', {}),
                '订单信息'
            );
            const order = { ...raw };
            assignCtripReviewMatchText(order, 'orderId', form.orderId);
            assignCtripReviewMatchText(order, 'arrivalDate', form.orderArrivalDate);
            assignCtripReviewMatchText(order, 'departureDate', form.orderDepartureDate);
            assignCtripReviewMatchText(order, 'roomName', form.orderRoomName);
            assignCtripReviewMatchText(order, 'orderStatus', form.orderStatus);
            if (!String(order.orderId || order.order_id || order.platform_order_id || '').trim()) {
                throw new Error('请填写携程订单号');
            }
            return { ...base, order };
        }
        if (action === 'lookup') {
            const payload = { ...base };
            const sampleCommentId = String(sample?.comment_id || sample?.commentId || '').trim();
            if (sampleCommentId) return { ...payload, commentId: sampleCommentId };
            const rawReview = requireCtripReviewMatchObject(
                parseCtripReviewMatchJsonValue(form.rawReviewJson, '评价信息', {}),
                '评价信息'
            );
            const hasReviewInput = Object.keys(rawReview).length > 0
                || String(form.userName || form.checkinTimeStr || form.hotelRoomInfo || '').trim() !== '';
            if (hasReviewInput) {
                payload.review = buildReview();
            } else {
                assignCtripReviewMatchText(payload, 'commentId', form.commentId);
                if (!payload.commentId) throw new Error('请填写评价 commentId，或粘贴评价 JSON');
            }
            return payload;
        }
        if (action === 'bind') {
            const payload = { ...base };
            assignCtripReviewMatchText(payload, 'commentId', form.commentId);
            assignCtripReviewMatchText(payload, 'orderId', form.orderId || form.imOrderId);
            if (!payload.commentId || !payload.orderId) {
                throw new Error('人工绑定需要 commentId 和订单号');
            }
            return payload;
        }
        if (action === 'decision') {
            const payload = { ...base };
            assignCtripReviewMatchText(payload, 'commentId', sample?.comment_id || sample?.commentId || form.commentId);
            assignCtripReviewMatchText(
                payload,
                'orderId',
                sample?.order_id || sample?.orderId || sample?.candidate_order_id || sample?.candidateOrderId || form.orderId || form.imOrderId
            );
            assignCtripReviewMatchText(payload, 'reason', form.decisionReason);
            if (!payload.commentId) throw new Error('人工决策需要 commentId');
            if (requireOrder && !payload.orderId) throw new Error('人工确认需要当前酒店订单号');
            if (requireReason && !payload.reason) throw new Error('人工否决需要填写原因');
            return payload;
        }
        if (action === 'automation') {
            const payload = {
                ...base,
                raw_limit: 30,
                review_limit: 200,
                review_collection_policy: 'explicit_review_match_only',
            };
            const rawPayload = parseCtripReviewMatchJsonValue(form.rawPayloadJson, '授权 payload', null);
            if (rawPayload !== null) payload.payload = rawPayload;
            return payload;
        }
        return base;
    };

    const normalizeCtripReviewMatchSamples = (result) => {
        const data = result?.data || {};
        const samples = Array.isArray(data.review_cards) ? data.review_cards : data.samples;
        if (!Array.isArray(samples)) return [];
        return samples.map(sample => ({
            ...sample,
            comment_id: String(sample.comment_id || sample.commentId || '').trim(),
            source_username: String(sample.source_username || sample.sourceUsername || '').trim(),
            avatar_url: String(sample.avatar_url || sample.avatarUrl || '').trim(),
            review_date: String(sample.review_date || sample.reviewDate || '').trim(),
            checkin_date: String(sample.checkin_date || sample.checkinDate || '').trim(),
            room_name: String(sample.room_name || sample.roomName || '').trim(),
            content: String(sample.content || '').trim(),
            status: String(sample.status || sample.match_status || sample.matchStatus || 'unknown').trim(),
            status_text: String(sample.status_text || sample.statusText || '').trim(),
            confidence: String(sample.confidence || 'none').trim(),
            match_score: Number.isFinite(Number(sample.match_score ?? sample.matchScore)) ? Number(sample.match_score ?? sample.matchScore) : null,
            score_breakdown: sample.score_breakdown && typeof sample.score_breakdown === 'object' ? sample.score_breakdown : {},
            review_flags: Array.isArray(sample.review_flags) ? sample.review_flags : [],
            missing_evidence: Array.isArray(sample.missing_evidence) ? sample.missing_evidence : [],
            window_used: String(sample.window_used || sample.windowUsed || '').trim(),
            reason: String(sample.reason || '').trim(),
            order_id: String(sample.order_id || sample.orderId || '').trim(),
            candidate_count: Number(sample.candidate_count || sample.candidateCount || 0),
            candidate_order_id: String(sample.candidate_order_id || sample.candidateOrderId || sample.candidate_order?.order_id || sample.candidateOrder?.orderId || '').trim(),
            candidate_arrival_date: String(sample.candidate_arrival_date || sample.candidateArrivalDate || sample.candidate_order?.arrival_date || sample.candidateOrder?.arrivalDate || '').trim(),
            updated_at: String(sample.updated_at || sample.updatedAt || '').trim(),
        }));
    };

    const ctripReviewMatchStatusLabel = (status) => ({
        confirmed: '已确认', high_confidence: '高置信', candidate: '候选', ambiguous: '有歧义',
        not_found: '未找到', found: '已匹配', matched: '人工已绑定', rejected: '人工已否决',
        unbound: '已撤销绑定', person_locked: '待确认', needs_ops: '需人工',
        out_of_coverage: '超范围', unmatched: '未匹配', unknown: '未知',
    }[String(status || 'unknown')] || String(status || '未知'));

    const ctripReviewMatchStatusClass = (status) => {
        const normalized = String(status || 'unknown');
        const base = 'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium border';
        if (['confirmed', 'high_confidence', 'found', 'matched'].includes(normalized)) return `${base} border-emerald-100 bg-emerald-50 text-emerald-700`;
        if (['ambiguous', 'rejected'].includes(normalized)) return `${base} border-rose-100 bg-rose-50 text-rose-700`;
        if (['unbound', 'not_found', 'out_of_coverage'].includes(normalized)) return `${base} border-slate-200 bg-slate-50 text-slate-600`;
        if (normalized === 'candidate' || normalized === 'needs_ops') return `${base} border-amber-100 bg-amber-50 text-amber-700`;
        if (normalized === 'person_locked') return `${base} border-blue-100 bg-blue-50 text-blue-700`;
        return `${base} border-gray-200 bg-gray-50 text-gray-600`;
    };

    const buildCtripReviewMatchPayloadTemplate = (systemHotelId) => ({
        system_hotel_id: Number(systemHotelId),
        scope: 'ctrip_ota_channel',
        template_notice: 'Replace every replace-with-* value before dry-run or execute; template payload is rejected by the importer.',
        store_mapping_verified: false,
        store_mapping: {
            ctrip_store_name: 'replace-with-ctrip-store-name',
            order_store_name: 'replace-with-authorized-order-store-name',
        },
        room_mapping: { 'replace-with-ctrip-room-name': ['replace-with-order-room-name'] },
        reviews: [{
            commentId: 'replace-with-ctrip-comment-id',
            publishTime: 'replace-with-review-publish-time',
            check_in_date: 'replace-with-check-in-date',
            room_type: 'replace-with-room-type',
            content: 'replace-with-review-content',
        }],
        im_sessions: [{
            groupId: 'replace-with-ctrip-im-group-id',
            orderId: 'replace-with-ctrip-order-no',
            arrivalDate: 'replace-with-check-in-date',
            roomName: 'replace-with-order-room-type',
            members: [],
        }],
        orders: [{
            orderNo: 'replace-with-ctrip-order-no',
            checkIn: 'replace-with-check-in-date',
            checkOut: 'replace-with-check-out-date',
            room_type_name: 'replace-with-order-room-type',
            orderStatus: 'replace-with-order-status',
            amount: 'replace-with-order-amount',
            detailVerified: false,
        }],
    });

    const buildCtripReviewMatchCliCommand = (systemHotelId, execute = false) => {
        const scriptName = execute === 'preflight'
            ? 'import:ctrip-review-match-payload:preflight'
            : (execute ? 'import:ctrip-review-match-payload:execute' : 'import:ctrip-review-match-payload');
        return [
            'cd D:\\桌面\\SUXIOS\\宿析OS初始版\\HOTEL',
            `npm.cmd run ${scriptName} -- --file=<授权payload.json> --system-hotel-id=${systemHotelId}`,
        ].join('\n');
    };

    const createCtripReviewMatchActionController = ({
        request,
        captureRequestContext,
        isRequestContextCurrent,
        staleActionResult,
        showToast,
        loading,
        lookupLoadingCommentId,
        result,
        buildImPayload,
        buildReviewPayload,
        buildOrderPayload,
        buildLookupPayload,
        buildAutomationPayload,
        buildBasePayload,
        buildBindPayload,
        buildDecisionPayload,
    }) => {
        let requestSeq = 0;
        const runAction = async (actionLabel, url, buildPayload, successMessage) => {
            if (loading.value) return { status: 'busy' };
            const requestContext = captureRequestContext('ctrip');
            if (!requestContext.hotelId) {
                showToast('请先在顶部选择携程当前酒店', 'warning');
                return { status: 'missing_hotel' };
            }
            const currentRequestSeq = ++requestSeq;
            loading.value = actionLabel;
            try {
                const response = await request(url, {
                    method: 'POST',
                    body: JSON.stringify(buildPayload()),
                });
                if (!isRequestContextCurrent(requestContext)) return staleActionResult(requestContext);
                result.value = response;
                const ok = Number(response?.code) === 200;
                showToast(response?.message || successMessage || `${actionLabel}完成`, ok ? 'success' : 'error');
                return response;
            } catch (error) {
                if (!isRequestContextCurrent(requestContext)) return staleActionResult(requestContext);
                const message = error.message || `${actionLabel}失败`;
                result.value = error.data && typeof error.data === 'object'
                    ? error.data
                    : { code: 500, message, action: actionLabel };
                showToast(message, 'error');
                return { status: 'exception', error };
            } finally {
                if (currentRequestSeq === requestSeq) loading.value = '';
            }
        };

        const mergeLookupResult = (response) => {
            const data = response?.data || {};
            const commentId = String(data.comment_id || data.commentId || data.review?.comment_id || data.review?.commentId || '').trim();
            if (!commentId || !result.value?.data) return;
            const patch = {
                status: String(data.status || 'unknown').trim(),
                status_text: String(data.status_text || data.statusText || '').trim(),
                order_id: String(data.order_id || data.order?.order_id || '').trim(),
                confidence: String(data.confidence || 'none').trim(),
                match_score: Number.isFinite(Number(data.match_score ?? data.matchScore ?? data.score)) ? Number(data.match_score ?? data.matchScore ?? data.score) : null,
                score_breakdown: data.score_breakdown && typeof data.score_breakdown === 'object' ? data.score_breakdown : {},
                review_flags: Array.isArray(data.review_flags) ? data.review_flags : [],
                window_used: String(data.window_used || data.windowUsed || '').trim(),
                reason: String(data.reason || '').trim(),
                missing_evidence: Array.isArray(data.missing_evidence) ? data.missing_evidence : [],
                candidate_count: Number(data.candidate_count || data.candidateCount || 0),
                candidate_order_id: String(data.candidate_order_id || data.candidateOrderId || data.candidate_order?.order_id || data.candidateOrder?.orderId || '').trim(),
                candidate_arrival_date: String(data.candidate_arrival_date || data.candidateArrivalDate || data.candidate_order?.arrival_date || data.candidateOrder?.arrivalDate || '').trim(),
                updated_at: new Date().toLocaleString(),
            };
            const mergeRows = rows => Array.isArray(rows)
                ? rows.map(row => String(row.comment_id || row.commentId || '').trim() === commentId ? { ...row, ...patch } : row)
                : rows;
            result.value = {
                ...result.value,
                data: {
                    ...result.value.data,
                    review_cards: mergeRows(result.value.data.review_cards),
                    samples: mergeRows(result.value.data.samples),
                },
            };
        };

        const checkClosure = () => runAction(
            '验证完成状态',
            '/online-data/ctrip-review-matches/closure',
            () => ({ ...buildBasePayload(), min_matched: 1 }),
            '携程评价订单匹配闭环检查完成'
        );
        const refreshAfterDecision = async (response) => Number(response?.code) === 200
            ? checkClosure()
            : response;

        return {
            invalidate: () => {
                requestSeq += 1;
                loading.value = '';
                lookupLoadingCommentId.value = '';
            },
            saveIm: () => runAction('保存IM会话', '/online-data/ctrip-review-matches/im-sessions', buildImPayload, '携程 IM 会话缓存已保存'),
            saveReview: () => runAction('保存评价', '/online-data/ctrip-review-matches/reviews', buildReviewPayload, '携程评价已保存'),
            saveOrder: () => runAction('保存订单', '/online-data/ctrip-review-matches/orders', buildOrderPayload, '携程订单已保存'),
            lookup: async (sample = null) => {
                if (loading.value) return { status: 'busy' };
                const requestContext = captureRequestContext('ctrip');
                if (!requestContext.hotelId) {
                    showToast('请先在顶部选择携程当前酒店', 'warning');
                    return { status: 'missing_hotel' };
                }
                const currentRequestSeq = ++requestSeq;
                const sampleCommentId = String(sample?.comment_id || sample?.commentId || '').trim();
                loading.value = '匹配订单证据';
                lookupLoadingCommentId.value = sampleCommentId;
                try {
                    const response = await request('/online-data/ctrip-review-matches/lookup', {
                        method: 'POST',
                        body: JSON.stringify(buildLookupPayload(sample)),
                    });
                    if (!isRequestContextCurrent(requestContext)) return staleActionResult(requestContext);
                    if (sampleCommentId) mergeLookupResult(response);
                    else result.value = response;
                    const data = response?.data || {};
                    const message = data.status_text || response?.message || '携程点评治理规则校验完成';
                    const displayOrderId = data.order_id || data.candidate_order_id || data.candidateOrderId || '';
                    showToast(displayOrderId ? `${message}：${displayOrderId}` : message, Number(response?.code) === 200 ? 'success' : 'error');
                    return response;
                } catch (error) {
                    if (!isRequestContextCurrent(requestContext)) return staleActionResult(requestContext);
                    const message = error.message || '携程点评订单证据匹配失败';
                    result.value = error.data && typeof error.data === 'object'
                        ? error.data
                        : { code: 500, message, action: '匹配订单证据' };
                    showToast(message, 'error');
                    return { status: 'exception', error };
                } finally {
                    if (currentRequestSeq === requestSeq) {
                        loading.value = '';
                        lookupLoadingCommentId.value = '';
                    }
                }
            },
            preflight: () => runAction(
                '预检授权payload',
                '/online-data/ctrip-review-matches/run',
                () => ({ ...buildAutomationPayload(true), review_collection_policy: 'explicit_review_match_only' }),
                '携程评价匹配 payload 预检完成'
            ),
            dryRun: () => runAction(
                '干跑匹配',
                '/online-data/ctrip-review-matches/run',
                () => ({ ...buildAutomationPayload(false, true), review_collection_policy: 'explicit_review_match_only' }),
                '携程评价订单干跑匹配完成'
            ),
            checkClosure,
            runAutomation: () => runAction(
                '执行自动匹配',
                '/online-data/ctrip-review-matches/run',
                () => ({ ...buildAutomationPayload(), review_collection_policy: 'explicit_review_match_only' }),
                '携程点评订单证据匹配完成'
            ).then(() => {
                if (result.value && typeof result.value === 'object') {
                    result.value = {
                        ...result.value,
                        data: {
                            ...(result.value.data || {}),
                            review_policy: '匹配动作只读取已授权点评/IM/订单数据；当前只返回点评治理规则和边界状态；不进入默认采集，不反查匿名身份，不自动操作携程后台。',
                        },
                    };
                }
            }),
            bind: sample => runAction(
                '人工确认订单',
                '/online-data/ctrip-review-matches/bind',
                () => sample ? buildDecisionPayload(sample, { requireOrder: true }) : buildBindPayload(),
                '携程评价订单已人工确认'
            ).then(refreshAfterDecision),
            reject: sample => runAction(
                '人工否决候选',
                '/online-data/ctrip-review-matches/reject',
                () => buildDecisionPayload(sample, { requireReason: true }),
                '携程评价订单候选已人工否决'
            ).then(refreshAfterDecision),
            unbind: sample => runAction(
                '撤销人工绑定',
                '/online-data/ctrip-review-matches/unbind',
                () => buildDecisionPayload(sample),
                '携程评价订单绑定已撤销'
            ).then(refreshAfterDecision),
        };
    };

    const createCtripReviewMatchController = ({
        ref,
        computed,
        request,
        captureRequestContext,
        isRequestContextCurrent,
        staleActionResult,
        showToast,
        copyText,
        buildBasePayload,
        state = {},
    }) => {
        const ctripReviewMatchForm = state.ctripReviewMatchForm || ref({
            commentId: '',
            userName: '',
            checkinTimeStr: '',
            hotelRoomInfo: '',
            rawPayloadJson: '',
            rawReviewJson: '',
            imGroupId: '',
            imSessionId: '',
            imOrderId: '',
            imArrivalDate: '',
            imDepartureDate: '',
            imRoomName: '',
            rawImSessionJson: '',
            orderId: '',
            orderArrivalDate: '',
            orderDepartureDate: '',
            orderRoomName: '',
            orderStatus: '',
            rawOrderJson: '',
            decisionReason: '',
        });
        const ctripReviewMatchLoading = state.ctripReviewMatchLoading || ref('');
        const ctripReviewMatchLookupLoadingCommentId = state.ctripReviewMatchLookupLoadingCommentId || ref('');
        const ctripReviewMatchResult = state.ctripReviewMatchResult || ref(null);
        const showCtripReviewMatchManualPanel = state.showCtripReviewMatchManualPanel || ref(false);
        const buildPayload = (action, sample = null, options = {}) => buildCtripReviewMatchPayload({
            action,
            form: ctripReviewMatchForm.value,
            systemHotelId: buildBasePayload().system_hotel_id,
            sample,
            ...options,
        });
        const buildAutomationPayload = (preflightOnly = false, dryRun = false) => {
            const payload = buildPayload('automation');
            if (preflightOnly) payload.preflight_only = true;
            if (dryRun) payload.dry_run = true;
            return payload;
        };
        const actionController = createCtripReviewMatchActionController({
            request,
            captureRequestContext,
            isRequestContextCurrent,
            staleActionResult,
            showToast,
            loading: ctripReviewMatchLoading,
            lookupLoadingCommentId: ctripReviewMatchLookupLoadingCommentId,
            result: ctripReviewMatchResult,
            buildImPayload: () => buildPayload('im'),
            buildReviewPayload: () => buildPayload('review'),
            buildOrderPayload: () => buildPayload('order'),
            buildLookupPayload: sample => buildPayload('lookup', sample),
            buildAutomationPayload,
            buildBasePayload,
            buildBindPayload: () => buildPayload('bind'),
            buildDecisionPayload: (sample, options) => buildPayload('decision', sample, options),
        });
        const ctripReviewMatchSamples = state.ctripReviewMatchSamples
            || computed(() => normalizeCtripReviewMatchSamples(ctripReviewMatchResult.value));
        const applyCtripReviewMatchSample = (sample) => {
            if (!sample || typeof sample !== 'object') return;
            const form = ctripReviewMatchForm.value;
            form.commentId = String(sample.comment_id || sample.commentId || form.commentId || '').trim();
            form.userName = String(sample.source_username || sample.sourceUsername || form.userName || '').trim();
            form.checkinTimeStr = String(sample.checkin_date || sample.checkinDate || form.checkinTimeStr || '').trim();
            form.hotelRoomInfo = String(sample.room_name || sample.roomName || form.hotelRoomInfo || '').trim();
            form.orderId = String(sample.order_id || sample.orderId || sample.candidate_order_id || sample.candidateOrderId || form.orderId || '').trim();
            showCtripReviewMatchManualPanel.value = true;
            showToast('已带入单条复核表单', 'success');
        };
        const payloadTemplate = () => buildCtripReviewMatchPayloadTemplate(buildBasePayload().system_hotel_id);
        const fillCtripReviewMatchPayloadTemplate = () => {
            ctripReviewMatchForm.value.rawPayloadJson = JSON.stringify(payloadTemplate(), null, 2);
            showToast('已填入携程评价匹配 payload 模板');
        };
        const copyCtripReviewMatchPayloadTemplate = () => {
            copyText(JSON.stringify(payloadTemplate(), null, 2));
            showToast('已复制携程点评订单证据模板；姓名、UID、头像和 IM members 不会入库。', 'warning');
        };
        const copyCtripReviewMatchCliCommand = (execute = false) => {
            copyText(buildCtripReviewMatchCliCommand(buildBasePayload().system_hotel_id, execute));
        };

        return {
            ctripReviewMatchForm,
            ctripReviewMatchLoading,
            ctripReviewMatchLookupLoadingCommentId,
            ctripReviewMatchResult,
            ctripReviewMatchSamples,
            ctripReviewMatchStatusLabel,
            ctripReviewMatchStatusClass,
            showCtripReviewMatchManualPanel,
            applyCtripReviewMatchSample,
            fillCtripReviewMatchPayloadTemplate,
            copyCtripReviewMatchPayloadTemplate,
            copyCtripReviewMatchCliCommand,
            invalidateCtripReviewMatch: actionController.invalidate,
            saveCtripReviewImSession: actionController.saveIm,
            saveCtripReviewForMatch: actionController.saveReview,
            saveCtripOrderForMatch: actionController.saveOrder,
            lookupCtripReviewOrderMatch: actionController.lookup,
            runCtripReviewMatchPreflight: actionController.preflight,
            runCtripReviewMatchDryRun: actionController.dryRun,
            checkCtripReviewMatchClosure: actionController.checkClosure,
            runCtripReviewMatchAutomation: actionController.runAutomation,
            bindCtripReviewOrderMatch: actionController.bind,
            rejectCtripReviewOrderMatch: actionController.reject,
            unbindCtripReviewOrderMatch: actionController.unbind,
        };
    };

    return {
        buildCtripReviewMatchPayload,
        normalizeCtripReviewMatchSamples,
        ctripReviewMatchStatusLabel,
        ctripReviewMatchStatusClass,
        buildCtripReviewMatchPayloadTemplate,
        buildCtripReviewMatchCliCommand,
        createCtripReviewMatchActionController,
        createCtripReviewMatchController,
    };
})();
