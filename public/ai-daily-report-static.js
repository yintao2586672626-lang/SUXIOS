window.SUXI_AI_DAILY_REPORT_STATIC = (() => {
    const escapeHtml = value => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const list = (value) => {
        const parsed = parsedValue(value);
        if (Array.isArray(parsed)) return parsed;
        if (parsed && typeof parsed === 'object') return Object.values(parsed);
        return [];
    };
    const parsedValue = value => {
        if (typeof value !== 'string') return value;
        const text = value.trim();
        if (!text || !/^[\[{]/.test(text)) return value;
        try {
            return JSON.parse(text);
        } catch {
            return value;
        }
    };
    const objectList = (value, textKey = 'message') => {
        const parsed = parsedValue(value);
        const items = Array.isArray(parsed)
            ? parsed
            : (parsed && typeof parsed === 'object' ? Object.values(parsed) : []);
        return items.map((item, index) => {
            const normalized = parsedValue(item);
            if (normalized && typeof normalized === 'object' && !Array.isArray(normalized)) return normalized;
            if (normalized === null || normalized === undefined || normalized === '') return null;
            return {
                code: `raw_${index}`,
                [textKey]: String(normalized),
                label: String(normalized),
                data_status: '结构待核验',
            };
        }).filter(Boolean);
    };
    const actionList = value => list(value).map((item, index) => {
        const normalized = parsedValue(item);
        if (normalized && typeof normalized === 'object' && !Array.isArray(normalized)) return normalized;
        if (normalized === null || normalized === undefined || normalized === '') return null;
        return {
            title: `建议${index + 1}`,
            action: String(normalized),
            reason: '建议结构不完整，需核验来源字段',
            can_create_execution_intent: false,
            blocked_reason: '建议结构不完整',
        };
    }).filter(Boolean);
    const normalizedList = value => {
        const parsed = parsedValue(value);
        if (Array.isArray(parsed)) return parsed;
        if (parsed && typeof parsed === 'object') return Object.values(parsed);
        return [];
    };
    const truthStatusLabel = status => ({
        verified: '已验证',
        partial: '部分数据',
        unverified: '未验证',
        collection_failed: '采集失败',
    }[String(status || '').trim().toLowerCase()] || '未验证');
    const expandScope = scope => {
        const value = String(scope || '').trim().toLowerCase();
        if (!value || value === 'unknown') return [];
        if (value === 'mixed_whole_hotel_and_ota_channel') return ['whole_hotel_daily_report', 'ota_channel'];
        if (['whole_hotel', 'whole_hotel_daily_report'].includes(value) || value.includes('whole_hotel')) return ['whole_hotel_daily_report'];
        if (value === 'ota' || value === 'ota_channel' || value.includes('ota channel')) return ['ota_channel'];
        if (['manual_input', 'user_input'].includes(value) || value.includes('manual')) return ['manual_input'];
        if (value === 'local_operating_source' || value.includes('local_operating')) return ['local_operating_source'];
        if (value === 'derived' || value === 'derived_metric') return ['derived'];
        return [value];
    };
    const metricScopeMembers = (metric = {}) => {
        const rawScopes = Array.isArray(metric.metric_scopes) ? metric.metric_scopes : [metric.metric_scope];
        return Array.from(new Set(rawScopes.flatMap(expandScope)));
    };
    const sourceRefKey = (source = {}) => String(
        source.ref || source.key || source.source_ref || source.source || ''
    ).trim();
    const sourceScope = (source = {}) => {
        const key = sourceRefKey(source).toLowerCase();
        const sourceName = String(source.source || '').trim().toLowerCase();
        const dataType = String(source.data_type || '').trim().toLowerCase();
        const ingestionMethod = String(source.ingestion_method || '').trim().toLowerCase();
        if (/^online_daily_data#\d+$/.test(key) || sourceName === 'online_daily_data') return 'ota_channel';
        if (/^daily_reports#\d+$/.test(key) || sourceName === 'daily_reports' || dataType === 'whole_hotel_daily_report') return 'whole_hotel_daily_report';
        if (sourceName.includes('manual') || ingestionMethod.includes('manual')) return 'manual_input';
        if (sourceName.includes('local') || ingestionMethod.includes('local')) return 'local_operating_source';
        const explicit = expandScope(source.metric_scope || source.scope);
        if (explicit.length === 1) return explicit[0];
        if (explicit.includes('ota_channel') && explicit.includes('whole_hotel_daily_report')) return 'mixed_whole_hotel_and_ota_channel';
        const platform = String(source.platform || sourceName).trim().toLowerCase();
        if (['ctrip', 'meituan', 'qunar'].includes(platform)) return 'ota_channel';
        return explicit[0] || 'unknown';
    };
    const metricSourceAliases = {
        revenue: ['revenue', 'amount'],
        orders: ['orders', 'book_order_num', 'order_submit_num'],
        room_nights: ['room_nights', 'quantity'],
        adr: ['revenue', 'amount', 'room_nights', 'quantity'],
        exposure: ['exposure', 'list_exposure'],
        visitors: ['visitors', 'detail_exposure'],
        flow_rate: ['flow_rate', 'list_exposure', 'detail_exposure'],
        order_filling: ['order_filling', 'order_filling_num'],
        order_submit: ['order_submit', 'order_submit_num', 'book_order_num'],
        fill_submit_rate: ['fill_submit_rate', 'order_filling', 'order_filling_num', 'order_submit', 'order_submit_num', 'book_order_num'],
    };
    const metricSourceRefs = (metric = {}, report = {}) => {
        const providedTruth = metric.truth && typeof metric.truth === 'object'
            ? metric.truth
            : (metric.truth_context && typeof metric.truth_context === 'object' ? metric.truth_context : {});
        const candidates = [
            ...objectList(report.source_refs, 'label'),
            ...objectList(providedTruth.evidence_sources, 'label'),
        ];
        const sourcesByKey = new Map();
        candidates.forEach((source, index) => {
            const normalized = source && typeof source === 'object' ? source : {};
            const key = sourceRefKey(normalized) || `source-${index}`;
            const previous = sourcesByKey.get(key) || {};
            sourcesByKey.set(key, {
                ...previous,
                ...normalized,
                metric_keys: Array.from(new Set([
                    ...(Array.isArray(previous.metric_keys) ? previous.metric_keys : []),
                    ...(Array.isArray(normalized.metric_keys) ? normalized.metric_keys : []),
                ].map(item => String(item || '').trim()).filter(Boolean))),
            });
        });
        const metricKey = String(metric.key || '').trim();
        const aliases = new Set((metricSourceAliases[metricKey] || [metricKey]).filter(Boolean));
        const metricScopes = metricScopeMembers(metric);
        const refs = Array.isArray(metric.source_refs) ? metric.source_refs : (metric.source_refs ? [metric.source_refs] : []);
        const explicitRefs = new Set(refs.map(item => (
            typeof item === 'string' ? item : sourceRefKey(item)
        )).map(item => String(item || '').trim()).filter(Boolean));
        const singularSourceRef = String(metric.source_ref || '').trim();
        if (sourcesByKey.has(singularSourceRef)) explicitRefs.add(singularSourceRef);
        return Array.from(sourcesByKey.values()).filter(source => {
            const key = sourceRefKey(source);
            if (explicitRefs.size) return explicitRefs.has(key);
            const sourceMetricKeys = Array.isArray(source.metric_keys)
                ? source.metric_keys.map(item => String(item || '').trim())
                : [];
            if (!sourceMetricKeys.some(item => aliases.has(item))) return false;
            if (!metricScopes.length) return true;
            return expandScope(sourceScope(source)).some(scope => metricScopes.includes(scope));
        });
    };
    const sourceReadbackVerified = (source = {}) => {
        const persistence = source.persistence && typeof source.persistence === 'object' ? source.persistence : {};
        const value = source.readback_verified ?? persistence.readback_verified;
        return value === true || value === 1 || value === '1';
    };
    const sourceTruthStatus = (source = {}) => {
        const rawStatus = String(
            source.quality_status || source.persistence_status || source.verification_status
            || source.validation_status || source.data_status || source.status || ''
        ).trim().toLowerCase();
        if (['collection_failed', 'failed', 'error'].includes(rawStatus)) return 'collection_failed';
        if (['partial', 'stale', 'incomplete'].includes(rawStatus)) return 'partial';
        const trusted = ['normal', 'available', 'verified', 'ok', 'success', 'complete', 'completed', 'readback_verified'];
        return sourceReadbackVerified(source) && trusted.includes(rawStatus) ? 'verified' : 'unverified';
    };
    const metricScopeContext = (metric = {}, sources = []) => {
        const members = metricScopeMembers(metric);
        if (!members.length) sources.forEach(source => members.push(...expandScope(sourceScope(source))));
        const unique = Array.from(new Set(members));
        const hasWholeHotel = unique.includes('whole_hotel_daily_report');
        const hasOta = unique.includes('ota_channel');
        if (hasWholeHotel && hasOta) return { code: 'mixed', text: '混合来源', label: '混合口径：全酒店经营日报 + OTA渠道，不可按单一口径解读' };
        if (hasWholeHotel) return { code: 'whole_hotel', text: '全酒店', label: '全酒店经营日报口径' };
        if (hasOta) return { code: 'ota_channel', text: 'OTA渠道', label: 'OTA渠道指标，不代表全酒店经营' };
        if (unique.includes('manual_input')) return { code: 'user_input', text: '用户输入', label: '用户/人工输入口径，不代表已通过外部来源验证' };
        if (unique.includes('local_operating_source')) return { code: 'local_operating_source', text: '本地经营来源', label: '本地经营来源，验证范围以当前来源记录为准' };
        return { code: 'unprovided', text: '口径未提供', label: '指标口径未提供' };
    };
    const buildMetricTruth = ({ metric = {}, report = {}, permittedHotels = [], hotels = [] } = {}) => {
        const sources = metricSourceRefs(metric, report);
        const scope = metricScopeContext(metric, sources);
        const hasValue = metric.value !== null && metric.value !== undefined && metric.value !== ''
            && Number.isFinite(Number(metric.value));
        const sourceStatuses = sources.map(sourceTruthStatus);
        let status = 'unverified';
        if (sourceStatuses.length && sourceStatuses.every(item => item === 'verified')) status = 'verified';
        else if (sourceStatuses.length && sourceStatuses.every(item => item === 'collection_failed')) status = 'collection_failed';
        else if (sourceStatuses.some(item => ['verified', 'partial', 'collection_failed'].includes(item))) status = 'partial';
        const providedTruth = metric.truth && typeof metric.truth === 'object'
            ? metric.truth
            : (metric.truth_context && typeof metric.truth_context === 'object' ? metric.truth_context : {});
        const providedStatus = String(providedTruth.status || '').trim().toLowerCase();
        if (!sources.length && ['verified', 'partial', 'unverified', 'collection_failed'].includes(providedStatus)) {
            const persistence = providedTruth.persistence && typeof providedTruth.persistence === 'object' ? providedTruth.persistence : {};
            const total = Number(persistence.record_count);
            const verified = Number(persistence.readback_verified_count);
            const exactReadback = persistence.readback_verified === true
                || (Number.isFinite(total) && total > 0 && Number.isFinite(verified) && verified === total);
            status = providedStatus === 'verified' && !exactReadback ? 'unverified' : providedStatus;
        }
        const metricDataStatus = String(metric.data_status || '').trim().toLowerCase();
        if (!hasValue && status === 'verified') status = 'partial';
        if (!hasValue && ['collection_failed', 'failed', 'error'].includes(metricDataStatus)) status = 'collection_failed';
        const hotelId = Number(report.hotel_id ?? report.report_scope?.hotel_id);
        const hotel = [...list(permittedHotels), ...list(hotels)].find(item => Number(item?.id) === hotelId);
        const dates = Array.from(new Set(sources.map(source => String(source.data_date || source.date || '').trim()).filter(Boolean))).sort();
        const reportDate = String(report.report_date || report.report_scope?.report_date || '').trim();
        if (!dates.length && reportDate) dates.push(reportDate);
        const platforms = Array.from(new Set(sources.map(source => {
            const platform = String(source.platform || '').trim().toLowerCase();
            if (platform) return platform;
            const sourceName = String(source.source || '').trim().toLowerCase();
            return ['ctrip', 'meituan', 'qunar'].includes(sourceName) ? sourceName : '';
        }).filter(Boolean)));
        const sourceRefs = Array.from(new Set(sources.map(sourceRefKey).filter(Boolean)));
        const sourceTables = Array.from(new Set(sourceRefs.map(ref => (
            /^[a-z_][a-z0-9_]*#\d+$/i.test(String(ref)) ? String(ref).split('#')[0] : ''
        )).filter(Boolean)));
        const sourceMethods = Array.from(new Set(sources.map(source => String(source.ingestion_method || '').trim()).filter(Boolean)));
        const collectedTimes = Array.from(new Set(sources.map(source => String(
            source.collected_at || source.snapshot_time || source.fetched_at || source.updated_at || ''
        ).trim()).filter(Boolean))).sort();
        const directStoredCount = sources.filter(source => {
            const ref = sourceRefKey(source);
            const persistence = source.persistence && typeof source.persistence === 'object' ? source.persistence : {};
            return /^[a-z_][a-z0-9_]*#\d+$/i.test(ref) || persistence.stored === true
                || ['stored', 'persisted', 'success'].includes(String(source.persistence_status || '').trim().toLowerCase());
        }).length;
        const readbackCount = sources.filter(sourceReadbackVerified).length;
        const failures = sources.map(source => String(
            source.failure_reason || source.error || source.error_message || ''
        ).trim()).filter(Boolean);
        let failureReason = String(providedTruth.failure_reason || failures.join('；')).trim();
        if (!failureReason && !hasValue) failureReason = '指标值未提供';
        if (!failureReason && !sources.length) failureReason = '指标来源证据未提供';
        if (!failureReason && status === 'partial') failureReason = '部分来源未通过逐来源验证';
        if (!failureReason && status === 'unverified') failureReason = '来源未提供逐来源验证或入库回读证据';
        if (!failureReason && status === 'collection_failed') failureReason = '来源采集失败';
        if (!failureReason) failureReason = '无';
        const labels = platforms.map(platform => ({ ctrip: '携程', meituan: '美团', qunar: '去哪儿' }[platform] || platform));
        const platformText = labels.length
            ? (scope.code === 'mixed' ? `${labels.join('、')}（OTA部分）；全酒店日报部分不适用` : labels.join('、'))
            : (['ota_channel', 'mixed'].includes(scope.code) ? '未提供' : '不适用');
        const sourceText = sourceRefs.length
            ? `${sourceTables.join('、') || '来源表未提供'}${sourceMethods.length ? ` / ${sourceMethods.join('、')}` : ' / 采集方式未提供'}（${sourceRefs.join('、')}）`
            : `未提供（逻辑引用 ${String(metric.source_ref || '未提供')}）`;
        const dateText = dates.length > 1 ? `${dates[0]} 至 ${dates.at(-1)}` : (dates[0] || '未提供');
        const collectedAtText = collectedTimes.length > 1 ? `${collectedTimes[0]} 至 ${collectedTimes.at(-1)}` : (collectedTimes[0] || '未提供');
        const hotelText = hotelId > 0 ? `${String(hotel?.name || hotel?.hotel_name || '').trim() || '门店'}（ID ${hotelId}）` : '未提供';
        const persistenceText = sources.length ? `已入库 ${directStoredCount}/${sources.length}；回读 ${readbackCount}/${sources.length}` : '未提供来源记录';
        const truth = {
            ...providedTruth,
            status,
            status_label: truthStatusLabel(status),
            metric_scope: scope.code,
            scope_label: scope.label,
            hotels: hotelId > 0 ? [{ system_hotel_id: hotelId, name: String(hotel?.name || hotel?.hotel_name || '').trim() }] : [],
            platforms: platforms.length ? platforms : [platformText],
            date_range: { start: dates[0] || '', end: dates.at(-1) || '' },
            source: { table: sourceTables.join('、') || '未提供', methods: sourceMethods.length ? sourceMethods : ['未提供'] },
            collected_at_range: { start: collectedTimes[0] || '', end: collectedTimes.at(-1) || '' },
            persistence: { record_count: sources.length, stored_count: directStoredCount, readback_verified_count: readbackCount },
            failure_reason: failureReason,
            source_refs: sourceRefs,
        };
        return {
            truth,
            truth_context: truth,
            sources,
            sourceRefsText: sourceRefs.join('、') || String(metric.source_ref || '未提供'),
            scopeCode: scope.code,
            scopeText: scope.text,
            resultTypeCode: String(metric.result_layer || '').trim() === 'derived_metric' ? 'derived' : 'source_fact',
            detail: { hotelText, platformText, dateText, sourceText, collectedAtText, persistenceText, failureReason },
        };
    };
    const metricCalculation = (metric = {}) => {
        const hasValue = metric.value !== null && metric.value !== undefined && metric.value !== ''
            && Number.isFinite(Number(metric.value));
        const dataStatus = String(metric.data_status || '').trim().toLowerCase();
        const derived = String(metric.result_layer || '').trim() === 'derived_metric';
        if (['not_applicable', 'n/a'].includes(dataStatus)) return { code: 'not_applicable', text: '计算：不适用', className: 'border-slate-200 bg-slate-50 text-slate-600' };
        if (!hasValue) return { code: 'missing', text: derived ? '计算：不可计算' : '计算：未提供', className: 'border-amber-200 bg-amber-50 text-amber-700' };
        return derived
            ? { code: 'calculated', text: '计算：已计算', className: 'border-blue-200 bg-blue-50 text-blue-700' }
            : { code: 'available', text: '计算：来源值', className: 'border-slate-200 bg-white text-slate-600' };
    };
    const buildCompetitionPresentation = (bundle = {}) => {
        const labels = { ctrip: '携程', meituan: '美团' };
        const platforms = ['ctrip', 'meituan'].map(platform => {
            const facts = bundle.facts?.[platform] || {};
            const analysis = bundle.analysis?.[platform] || {};
            const gaps = objectList(bundle.quality?.data_gaps).filter(gap => String(gap.code || '').startsWith(`${platform}_`));
            return {
                platform,
                label: labels[platform],
                facts,
                analysis,
                factText: platform === 'ctrip'
                    ? `本店ADR ${facts.self?.adr ?? '—'} / 竞品均值 ${facts.competitor_average?.adr ?? '—'} / 竞品 ${facts.competitor_count ?? '—'} 家`
                    : `本店 ${facts.self_position_text || '未返回'} / TOP1 ${facts.top_hotel_name || '未返回'} / ${facts.top1_gap_text || '差距未返回'}`,
                decisionEligible: analysis.status === 'available',
                gapText: gaps.map(gap => gap.message || gap.code).join('；'),
            };
        });
        const groupLabels = { direct: '直接竞品', attack_benchmark: '进攻标杆', traffic_benchmark: '流量标杆', conversion_benchmark: '转化标杆' };
        const groups = [];
        Object.entries(bundle.candidate_competitors || {}).forEach(([platform, grouped]) => {
            Object.entries(grouped || {}).forEach(([key, items]) => {
                const normalized = objectList(items);
                if (!normalized.length) return;
                groups.push({
                    key: `${platform}-${key}`,
                    label: `${labels[platform] || platform} · ${groupLabels[key] || key}`,
                    items: normalized,
                    namesText: normalized.slice(0, 3).map(item => item.hotel_name || item.ota_hotel_id || '未命名酒店').join('、'),
                });
            });
        });
        const editionText = ({ lite: '简版', flagship: '旗舰版', both: '双版' }[String(bundle.render_contract?.requested_edition || 'lite')] || '简版');
        const qualityText = ({ available: '可进入人工确认', partial: '部分可用', blocked: '已阻断', synthetic: '模拟测试' }[String(bundle.quality?.status || '')] || '待生成');
        const summary = [];
        if (bundle.schema_version) {
            summary.push(`竞对变化 · ${editionText} · ${qualityText}`);
            if (bundle.source?.dataset_kind === 'synthetic') summary.push('synthetic 模拟测试：仅核对页面、权限和契约，不输出角色、矛盾、实验或执行建议。');
            platforms.forEach(platform => {
                summary.push(`${platform.label}｜${platform.factText}｜角色：${platform.analysis.channel_role || '不输出'}｜矛盾：${platform.analysis.first_conflict || '不输出'}`);
                if (platform.gapText) summary.push(`${platform.label}缺口｜${platform.gapText}`);
            });
            groups.forEach(group => summary.push(`${group.label}｜${group.namesText}`));
            summary.push(`行动门槛｜${bundle.quality?.decision_eligible ? '通过，仍需人工确认' : '未通过，不生成执行建议'}｜最多3项｜auto_write_ota=false`);
        }
        const reportDocument = bundle.report_document || {};
        const xiaohongshuDraft = bundle.content_drafts?.xiaohongshu || {};
        const draftLines = xiaohongshuDraft.status === 'ready_for_human_review' ? [
            `选题：${xiaohongshuDraft.topic || '未命名选题'}`, '', '【标题10选1】',
            ...normalizedList(xiaohongshuDraft.titles_10).map((title, index) => `${index + 1}. ${title}`),
            '', '【封面标题5选1】',
            ...normalizedList(xiaohongshuDraft.cover_titles_5).map((title, index) => `${index + 1}. ${title}`),
            '', '【8页图文】',
            ...objectList(xiaohongshuDraft.pages_8).map(page => `P${page.page || '—'} ${page.title || ''}｜${page.points || ''}`),
            '', '【发布文案】', xiaohongshuDraft.post_text || '', '', '【话题标签】',
            normalizedList(xiaohongshuDraft.tags_10).join(' '), '', '【置顶评论】',
            ...normalizedList(xiaohongshuDraft.comments_3).map((comment, index) => `${index + 1}. ${comment}`),
            '', '【人工审核】',
            ...normalizedList(xiaohongshuDraft.human_review_checklist).map((item, index) => `${index + 1}. ${item}`),
        ] : [];
        return {
            platforms,
            groups,
            editionText,
            qualityText,
            summaryText: summary.join('\n'),
            reportDocument,
            reportReady: reportDocument.status === 'ready_for_review',
            xiaohongshuDraft,
            xiaohongshuDraftText: draftLines.join('\n').trim(),
        };
    };
    const readinessClass = stage => {
        if (stage === 'available') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (stage === 'partial') return 'bg-blue-50 text-blue-700 border-blue-200';
        if (['unavailable', 'unverified'].includes(stage)) return 'bg-rose-50 text-rose-700 border-rose-200';
        if (['daily_loop_closed', 'action_closed_loop'].includes(stage)) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (['partial_roi_ready', 'reviewed_no_roi', 'evidence_pending_review'].includes(stage)) return 'bg-blue-50 text-blue-700 border-blue-200';
        if (['executed_missing_evidence', 'execution_in_progress', 'pending_execution_transfer', 'approved_pending_execution', 'intent_pending_approval', 'pending_transfer'].includes(stage)) return 'bg-amber-50 text-amber-700 border-amber-200';
        if (['data_recheck_required', 'blocked', 'blocked_by_data_gap', 'rejected', 'failed'].includes(stage)) return 'bg-rose-50 text-rose-700 border-rose-200';
        if (stage === 'investigation_only') return 'bg-slate-100 text-slate-700 border-slate-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };
    const referenceText = (item = {}) => {
        const basis = item?.reference_basis && typeof item.reference_basis === 'object' ? item.reference_basis : {};
        if (basis.status !== 'available') return basis.note || '未提供同口径参考；当前仅作为待关注信号，不判定为已证实异常。';
        const details = basis.details && typeof basis.details === 'object'
            ? Object.entries(basis.details).slice(0, 3).map(([key, value]) => `${key}: ${typeof value === 'object' ? JSON.stringify(value) : value}`).join(' / ')
            : '';
        return `参考依据：${basis.type || '已声明参考'}${details ? ` / ${details}` : ''}`;
    };
    const judgmentTargetText = value => ({ overall: '整份结果', ai_interpretation: 'AI辅助解读', anomaly_signal: '待关注信号', reference_set: '参考依据', report_usefulness: '结果是否有用' }[String(value || '')] || '整份结果');
    const judgmentDecisionText = value => ({ accepted: '接受', rejected: '驳回', corrected: '修正', needs_more_evidence: '需补证据' }[String(value || '')] || '待判断');
    const buildSharePackage = ({
        audience = 'owner', report = {}, contract = {}, resultReadiness = {}, aiInterpretation = {},
        resultLayers = {}, competitorChanges = [], dataGaps = [], workflowReadiness = {},
        humanJudgments = [], metricCards = [], abnormalMetrics = [],
    } = {}) => {
        const common = {
            package_version: 'ai_daily_share.v1',
            audience,
            report_date: report.report_date || '',
            summary: report.summary || '',
            result_status: resultReadiness,
            result_contract: contract,
            ai_boundary: aiInterpretation?.boundary || 'AI辅助解读，不替代酒店老板或行业专家判断。',
            generated_from_result_version: contract.result_version || '',
            trial_validation: report.trial_validation || {},
        };
        if (audience === 'expert') return {
            ...common,
            result_layers: resultLayers,
            source_refs: objectList(report.source_refs),
            competitor_changes: list(competitorChanges),
            data_gaps: list(dataGaps),
            workflow_gaps: objectList(report.workflow_gaps),
            workflow_status: workflowReadiness,
            human_judgments: list(humanJudgments),
        };
        if (audience === 'training') {
            const safeEnum = (value, allowed, fallback) => {
                const normalized = String(value || '').trim().toLowerCase();
                return allowed.includes(normalized) ? normalized : fallback;
            };
            const safeMetricKeys = new Set([
                ...Object.keys(metricSourceAliases),
                ...Object.values(metricSourceAliases).flat(),
            ]);
            const safeUnits = ['', '元', '间夜', '单', '%', '人', '次', '排名', '房', '晚'];
            const sanitizeMetric = (item = {}, expectedLayer = 'source_fact') => {
                const key = String(item.key || '').trim();
                const numericValue = Number(item.value);
                return {
                    key: safeMetricKeys.has(key) ? key : 'unknown_metric',
                    value: item.value === null || item.value === undefined || item.value === '' || !Number.isFinite(numericValue)
                        ? null
                        : numericValue,
                    unit: safeUnits.includes(String(item.unit || '').trim()) ? String(item.unit || '').trim() : '',
                    data_status: safeEnum(
                        item.data_status,
                        ['verified', 'partial', 'unverified', 'collection_failed', 'available', 'missing', 'not_applicable', 'normal'],
                        'unverified',
                    ),
                    result_layer: expectedLayer,
                };
            };
            return {
                package_version: 'ai_daily_share.v1',
                audience: 'training',
                report_date: '',
                case_id: 'training-case',
                summary: '脱敏训练样本：仅保留结构化指标与枚举状态。',
                anonymization: '已移除酒店ID、来源行标识、精确日期、操作者和人工判断记录。',
                result_contract: {
                    contract_version: 'training.v1',
                    boundary: 'deidentified_training_only',
                },
                source_facts: objectList(resultLayers?.source_facts).map(item => sanitizeMetric(item, 'source_fact')),
                derived_metrics: objectList(resultLayers?.derived_metrics).map(item => sanitizeMetric(item, 'derived_metric')),
                anomaly_signals: list(abnormalMetrics).map((item, index) => ({
                    signal_index: index + 1,
                    level: safeEnum(item.level, ['critical', 'high', 'medium', 'low', 'info'], 'unassessed'),
                    signal_status: safeEnum(item.signal_status, ['verified', 'partial', 'unverified', 'collection_failed'], 'unverified'),
                    reference_status: safeEnum(item.reference_basis?.status, ['available', 'partial', 'missing'], 'missing'),
                })),
                ai_assistance: {
                    status: safeEnum(aiInterpretation?.status, ['available', 'partial', 'unavailable', 'blocked'], 'unavailable'),
                    confidence: safeEnum(aiInterpretation?.confidence, ['high', 'medium', 'low', 'not_assessed', 'unavailable'], 'not_assessed'),
                },
                data_gap_count: list(dataGaps).length,
            };
        }
        return {
            ...common,
            key_metrics: list(metricCards).slice(0, 6),
            key_signals: list(abnormalMetrics).slice(0, 3),
            data_gaps: list(dataGaps),
            ai_assistance: aiInterpretation,
            latest_human_judgment: list(humanJudgments).slice(-1)[0] || null,
        };
    };
    const wecomPartStatusText = (part = {}) => {
        const status = String(part?.delivery_status || '');
        if (status === 'sent') return part?.idempotent_replay === true ? '已送达（重复请求已拦截）' : '已送达';
        if (status === 'not_attempted') return '未尝试';
        if (status === 'render_failed') return '图卡生成失败';
        return status ? `未送达（${status}）` : '未返回';
    };
    const filenameToken = (value, fallback) => (
        String(value || '').replace(/[^A-Za-z0-9_-]/g, '') || fallback
    );

    const buildAiDailyCompetitionReportExport = (input = {}) => {
        const report = input.report && typeof input.report === 'object' ? input.report : {};
        if (!report.schema_version) {
            return {
                ok: false,
                code: 'competition_report_missing',
                message: '当前日报没有可导出的竞争商圈报告',
                level: 'warning',
            };
        }
        const bundle = input.bundle && typeof input.bundle === 'object' ? input.bundle : {};
        const reportId = Number(input.reportId || 0);
        const bundleId = String(bundle.bundle_id || '').trim();
        const reportBundleId = String(report.render_contract?.bundle_id || '').trim();
        const bundleFingerprint = String(bundle.source_fingerprint || '').trim();
        const fingerprint = String(report.render_contract?.source_fingerprint || '').trim();
        if (!Number.isInteger(reportId) || reportId <= 0
            || !bundleId || reportBundleId !== bundleId
            || !bundleFingerprint || fingerprint !== bundleFingerprint) {
            return {
                ok: false,
                code: 'competition_report_identity_mismatch',
                message: '报告身份校验失败：日报ID、Bundle ID或来源指纹不一致，已阻断导出',
                level: 'error',
            };
        }

        const explicitEdition = String(input.requestedEdition || '').trim().toLowerCase();
        const reportEdition = String(report.render_contract?.requested_edition || '').trim().toLowerCase();
        const normalizedEdition = (explicitEdition || reportEdition) === 'flagship' ? 'flagship' : 'lite';
        const editionLabel = normalizedEdition === 'flagship' ? '旗舰版' : '简版';
        const flagship = normalizedEdition === 'flagship';

        const platformRows = list(input.platforms).map(platform => {
            const section = report.platform_sections?.[platform.platform] || {};
            const status = section.status === 'ready_for_review' ? '可人工研判' : '证据不足';
            return `<section><h2>${escapeHtml(platform.label)}</h2><p class="status">${escapeHtml(status)}</p><p>${escapeHtml(platform.factText)}</p><p><strong>第一矛盾：</strong>${escapeHtml(section.first_conflict || '不输出')}</p>${flagship ? `<p><strong>渠道角色：</strong>${escapeHtml(section.channel_role || '不输出')}</p>${platform.gapText ? `<p class="gap"><strong>数据缺口：</strong>${escapeHtml(platform.gapText)}</p>` : ''}` : ''}</section>`;
        }).join('');
        const groupRows = list(input.groups).map(group => (
            `<tr><td>${escapeHtml(group.label)}</td><td>${escapeHtml(group.namesText)}</td></tr>`
        )).join('');
        const actionRows = objectList(report.actions).map(action => (
            `<tr><td>${escapeHtml(action.platform || '')}</td><td>${escapeHtml(action.title || '')}</td><td>${escapeHtml(action.action || '')}</td><td>${escapeHtml(action.rollback_condition || '需人工设定')}</td></tr>`
        )).join('');
        const gapRows = objectList(report.data_gaps).map(gap => (
            `<li><strong>${escapeHtml(gap.code || 'data_gap')}</strong>：${escapeHtml(gap.message || '')}<small>${escapeHtml(gap.source_ref || '')}</small></li>`
        )).join('');
        const liteActionRows = objectList(report.actions).slice(0, 3).map(action => (
            `<li><strong>${escapeHtml(action.title || '待人工确认')}</strong>：${escapeHtml(action.action || '')}</li>`
        )).join('');
        const liteGapRows = objectList(report.data_gaps).slice(0, 3).map(gap => (
            `<li>${escapeHtml(gap.message || gap.code || '数据缺口')}</li>`
        )).join('');
        const detailSections = flagship
            ? `<h3>竞品分组</h3>${groupRows ? `<table><thead><tr><th>分组</th><th>候选酒店</th></tr></thead><tbody>${groupRows}</tbody></table>` : '<p class="gap">当前没有达到展示门槛的竞品分组。</p>'}<h3>人工确认动作</h3>${actionRows ? `<table><thead><tr><th>平台</th><th>事项</th><th>动作</th><th>回滚</th></tr></thead><tbody>${actionRows}</tbody></table>` : '<p class="gap">行动门槛未通过，不输出执行建议。</p>'}<h3>数据缺口</h3>${gapRows ? `<ul>${gapRows}</ul>` : '<p>未发现显式数据缺口。</p>'}`
            : `<h3>优先动作</h3>${liteActionRows ? `<ol>${liteActionRows}</ol>` : '<p class="gap">行动门槛未通过，不输出执行建议。</p>'}<h3>关键数据缺口</h3>${liteGapRows ? `<ul>${liteGapRows}</ul>` : '<p>未发现显式数据缺口。</p>'}`;
        const title = `${report.title || 'OTA竞争商圈经营报告'} · ${editionLabel}`;
        const html = `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(title)}</title><style>
            :root{color-scheme:light;--ink:#10231d;--muted:#64748b;--gold:#9a7b43;--line:#e5e7eb;--soft:#f7f7f4;--gap:#92400e}*{box-sizing:border-box}body{margin:0;background:#eef1ed;color:var(--ink);font-family:"Microsoft YaHei","PingFang SC","Segoe UI",sans-serif;line-height:1.65}main{max-width:980px;margin:32px auto;background:#fff;padding:44px;border-radius:18px;box-shadow:0 18px 45px rgba(6,17,13,.12)}header{border-bottom:2px solid var(--gold);padding-bottom:22px;margin-bottom:26px}.eyebrow{color:var(--gold);font-size:12px;font-weight:700;letter-spacing:.12em}h1{margin:6px 0 4px;font-size:30px}h2{font-size:19px;margin:0 0 8px}h3{font-size:16px;margin-top:28px}p{margin:7px 0}.meta,.limit,small{color:var(--muted);font-size:12px}.identity{word-break:break-all}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.grid section{border:1px solid var(--line);border-radius:12px;padding:18px;background:var(--soft)}.status{display:inline-block;border:1px solid #d6c59e;border-radius:999px;padding:2px 9px;color:#6f572f;font-size:12px}.gap{color:var(--gap)}table{width:100%;border-collapse:collapse;margin:10px 0 22px}th,td{border:1px solid var(--line);padding:9px;text-align:left;vertical-align:top;font-size:13px}th{background:var(--soft)}li{margin:7px 0}li small{display:block}.limit{margin-top:30px;border-top:1px solid var(--line);padding-top:16px}@media(max-width:720px){main{margin:0;padding:24px;border-radius:0}.grid{grid-template-columns:1fr}}@media print{body{background:#fff}main{margin:0;max-width:none;box-shadow:none}}
        </style></head><body><main data-report-id="${escapeHtml(reportId)}" data-bundle-id="${escapeHtml(bundleId)}" data-source-fingerprint="${escapeHtml(fingerprint)}" data-report-edition="${escapeHtml(normalizedEdition)}"><header><div class="eyebrow">SUXIOS · OTA CHANNEL REPORT · ${escapeHtml(editionLabel)}</div><h1>${escapeHtml(title)}</h1><div class="meta">业务日期：${escapeHtml(report.scope?.data_date || input.fallbackReportDate || '未返回')}　质量：${escapeHtml(input.qualityText || '')}　版本：${escapeHtml(editionLabel)}</div><div class="meta identity">日报记录 ID：${escapeHtml(reportId)}<br>Bundle ID：${escapeHtml(bundleId)}<br>来源指纹：${escapeHtml(fingerprint)}</div></header><h3>管理层快照</h3><p>可研判平台 ${escapeHtml(report.management_snapshot?.platforms_ready ?? 0)} / ${escapeHtml(report.management_snapshot?.platforms_total ?? 2)}；人工确认动作 ${escapeHtml(report.management_snapshot?.action_count ?? 0)} 项。</p><div class="grid">${platformRows}</div>${detailSections}<p class="limit">${escapeHtml(report.render_contract?.commercial_boundary || '')}<br>${escapeHtml(editionLabel)}只读取同一份已保存并精确回读的 competition bundle；不触发 OTA、飞书或小红书写入，auto_write_ota=false。</p></main></body></html>`;
        const dateToken = filenameToken(report.scope?.data_date || input.fallbackReportDate, 'report');
        const bundleToken = filenameToken(bundleId.slice(-12), 'bundle');
        return {
            ok: true,
            edition: normalizedEdition,
            html,
            filename: `suxios-ota-competition-${normalizedEdition}-${dateToken}-r${reportId}-${bundleToken}.html`,
        };
    };

    return Object.freeze({
        list,
        objectList,
        actionList,
        buildMetricTruth,
        metricCalculation,
        buildCompetitionPresentation,
        readinessClass,
        referenceText,
        judgmentTargetText,
        judgmentDecisionText,
        buildSharePackage,
        wecomPartStatusText,
        buildAiDailyCompetitionReportExport,
    });
})();
