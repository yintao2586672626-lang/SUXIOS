(() => {
    const DELIVERY_VERSION = '2026-08-26.1';

    const parsedValue = (value) => {
        if (typeof value !== 'string') return value;
        const text = value.trim();
        if (!text || !/^[\[{]/.test(text)) return value;
        try {
            return JSON.parse(text);
        } catch (error) {
            return value;
        }
    };

    const list = (value) => {
        const parsed = parsedValue(value);
        if (Array.isArray(parsed)) return parsed;
        if (parsed && typeof parsed === 'object') return Object.values(parsed);
        return [];
    };

    const objectList = (value, textKey = 'message') => list(value).map((item, index) => {
        const parsed = parsedValue(item);
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) return parsed;
        if (parsed === null || parsed === undefined || parsed === '') return null;
        return {
            code: `raw_${index}`,
            [textKey]: String(parsed),
            label: String(parsed),
            data_status: '结构待核验',
        };
    }).filter(Boolean);

    const safeFilename = (value, fallback) => {
        const normalized = String(value || fallback || 'download')
            .replace(/[\\/:*?"<>|\u0000-\u001f]+/g, '_')
            .trim();
        return normalized || fallback || 'download';
    };

    const downloadBlob = (blob, filename) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    };

    const decodePresentationBundle = (base64) => {
        const encoded = String(base64 || '').trim();
        if (!encoded || !/^[A-Za-z0-9+/]+={0,2}$/.test(encoded)) {
            throw new Error('演示包编码无效');
        }
        let binary = '';
        try {
            binary = window.atob(encoded);
        } catch (error) {
            throw new Error('演示包编码无法解码');
        }
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }
        return bytes;
    };

    const sha256Hex = async (bytes) => {
        if (!globalThis.crypto?.subtle) {
            throw new Error('当前浏览器不支持本地 SHA-256 验真，已停止下载');
        }
        const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
        return Array.from(new Uint8Array(digest))
            .map(value => value.toString(16).padStart(2, '0'))
            .join('');
    };

    const errorMessage = (error, fallback) => String(
        error?.data?.message || error?.message || fallback || '操作失败'
    ).trim() || fallback || '操作失败';

    const downloadCompetitionReport = (ctx, edition = '') => {
        const buildExport = window.SUXI_AI_DAILY_REPORT_STATIC?.buildAiDailyCompetitionReportExport;
        if (typeof buildExport !== 'function') {
            ctx.showToast?.('AI日报竞争报告导出工具未就绪，已停止下载', 'error');
            return false;
        }

        const currentReport = ctx.aiDailyReport || {};
        const report = ctx.aiDailyReportCompetitionReportDocument || {};
        const requestedEdition = String(edition || '').trim().toLowerCase();
        let exportResult;
        try {
            exportResult = buildExport({
                report,
                reportId: Number(currentReport.id || 0),
                bundle: ctx.aiDailyReportCompetitionBundle || {},
                readbackReceipt: currentReport.competition_bundle_readback || {},
                requestedEdition,
                editionText: requestedEdition === 'flagship' ? '旗舰版' : '简版',
                platforms: ctx.aiDailyReportCompetitionPlatforms || [],
                groups: ctx.aiDailyReportCompetitionGroups || [],
                qualityText: String(ctx.aiDailyReportCompetitionQualityText || ''),
                fallbackReportDate: currentReport.report_date || '',
            });
        } catch (error) {
            ctx.showToast?.(
                errorMessage(error, '竞争商圈报告导出校验失败，已停止下载'),
                'error'
            );
            return false;
        }

        if (exportResult?.ok !== true) {
            ctx.showToast?.(
                exportResult?.message || '竞争商圈报告导出失败，已停止下载',
                exportResult?.level || 'error'
            );
            return false;
        }
        if (!String(exportResult.html || '').trim() || !String(exportResult.filename || '').trim()) {
            ctx.showToast?.('竞争商圈报告导出结果无效，已停止下载', 'error');
            return false;
        }

        downloadBlob(
            new Blob([exportResult.html], { type: 'text/html;charset=utf-8' }),
            exportResult.filename
        );
        ctx.showToast?.('竞争商圈界面版HTML已生成', 'success');
        return true;
    };


    const broadcastDateText = (value) => {
        const text = String(value || '').trim();
        const matched = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!matched) return text || '日期未返回';
        return `${matched[1]}年${Number(matched[2])}月${Number(matched[3])}日`;
    };

    const broadcastMetricValue = (metric, ctx) => {
        if (typeof ctx.aiDailyReportMetricValue === 'function') {
            const rendered = String(ctx.aiDailyReportMetricValue(metric) || '').trim();
            if (rendered && rendered !== '—') return rendered;
        }
        const value = Number(metric?.value);
        if (!Number.isFinite(value)) return '';
        const key = String(metric?.key || '').trim().toLowerCase();
        const unit = String(metric?.unit || '').trim();
        const formatted = value.toLocaleString('zh-CN', {
            minimumFractionDigits: Number.isInteger(value) ? 0 : 0,
            maximumFractionDigits: 2,
        });
        if (['revenue', 'adr'].includes(key)) return `¥${formatted}`;
        if (unit === '%' || ['flow_rate', 'fill_submit_rate'].includes(key)) return `${formatted}%`;
        return formatted;
    };

    const broadcastSourceRefKeys = (metric = {}) => {
        const truth = metric.truth && typeof metric.truth === 'object'
            ? metric.truth
            : (metric.truth_context && typeof metric.truth_context === 'object' ? metric.truth_context : {});
        const refs = [
            ...list(truth.source_refs),
            ...list(metric.source_refs),
        ];
        return refs.map((item) => {
            if (typeof item === 'string') return item.trim();
            if (!item || typeof item !== 'object') return '';
            return String(item.key || item.source_ref || item.ref || '').trim();
        }).filter(Boolean);
    };

    const buildAiDailyOperationsBroadcast = (ctx = {}) => {
        const currentReport = ctx.aiDailyReport && typeof ctx.aiDailyReport === 'object'
            ? ctx.aiDailyReport
            : {};
        const reportId = Number(currentReport.id || 0);
        if (!reportId) {
            return {
                status: 'empty',
                statusLabel: '未加载日报',
                text: '请先读取或生成一份已保存的AI经营日报。',
                canUse: false,
                verifiedMetricCount: 0,
                excludedMetricCount: 0,
                sourceRefCount: 0,
                gapCount: 0,
                scopeLabel: '暂无口径',
            };
        }

        const metricRows = objectList(ctx.aiDailyReportMetricCards, 'label');
        const allowedScopes = new Set(['whole_hotel', 'ota_channel']);
        const verifiedMetrics = metricRows.filter((metric) => {
            const value = Number(metric?.value);
            const truth = metric?.truth && typeof metric.truth === 'object'
                ? metric.truth
                : (metric?.truth_context && typeof metric.truth_context === 'object' ? metric.truth_context : {});
            const truthStatus = String(truth.status || '').trim().toLowerCase();
            const scopeCode = String(metric?.scopeCode || truth.metric_scope || '').trim().toLowerCase();
            const calculationStatus = String(metric?.calculationStatus || '').trim().toLowerCase();
            const calculationUsable = calculationStatus === '' || ['available', 'calculated'].includes(calculationStatus);
            return Number.isFinite(value)
                && truthStatus === 'verified'
                && allowedScopes.has(scopeCode)
                && calculationUsable;
        });

        const hotelOptions = list(ctx.operationHotelOptions);
        const reportHotelId = Number(currentReport.hotel_id || currentReport.report_scope?.hotel_id || 0);
        const selectedHotel = hotelOptions.find(item => Number(item?.id) === reportHotelId) || {};
        const hotelName = String(
            ctx.aiDailyReportWecomHotelName
            || currentReport.hotel_name
            || currentReport.report_scope?.hotel_name
            || selectedHotel.name
            || selectedHotel.hotel_name
            || '当前门店'
        ).trim() || '当前门店';
        const reportDate = broadcastDateText(currentReport.report_date || currentReport.report_scope?.report_date);
        const metricGroups = [
            { key: 'whole_hotel', label: '全酒店经营' },
            { key: 'ota_channel', label: 'OTA渠道' },
        ].map(group => ({
            ...group,
            metrics: verifiedMetrics.filter((metric) => {
                const truth = metric?.truth && typeof metric.truth === 'object'
                    ? metric.truth
                    : (metric?.truth_context && typeof metric.truth_context === 'object' ? metric.truth_context : {});
                return String(metric?.scopeCode || truth.metric_scope || '').trim().toLowerCase() === group.key;
            }),
        })).filter(group => group.metrics.length > 0);

        const metricSentences = metricGroups.map((group) => {
            const values = group.metrics.map((metric) => {
                const label = String(metric.label || metric.key || '未命名指标').trim();
                return `${label}${broadcastMetricValue(metric, ctx)}`;
            });
            return `${group.label}：${values.join('，')}。`;
        });
        const scopeKeys = metricGroups.map(group => group.key);
        const scopeBoundary = scopeKeys.length === 2
            ? '全酒店经营与OTA渠道已分开播报，不合并推断。'
            : (scopeKeys[0] === 'ota_channel'
                ? '以上为OTA渠道口径，不代表全酒店经营。'
                : (scopeKeys[0] === 'whole_hotel' ? '以上为全酒店经营日报口径。' : ''));

        const gaps = objectList(ctx.aiDailyReportDataGaps);
        const gapMessages = Array.from(new Set(gaps.map(gap => String(gap.message || gap.label || gap.code || '').trim()).filter(Boolean)));
        const gapSentence = gaps.length
            ? `仍有${gaps.length}项数据缺口：${gapMessages.slice(0, 2).join('；')}${gapMessages.length > 2 ? '等' : ''}。`
            : '当前报告未声明数据缺口。';
        const sourceRefs = Array.from(new Set(verifiedMetrics.flatMap(broadcastSourceRefKeys)));
        const evidenceSentence = sourceRefs.length
            ? `本稿采用${verifiedMetrics.length}项已验证指标，来自${sourceRefs.length}条已保存并回读的来源记录。`
            : `本稿采用报告中${verifiedMetrics.length}项已验证指标。`;
        const hasVerifiedMetrics = verifiedMetrics.length > 0;
        const factSentence = hasVerifiedMetrics
            ? metricSentences.join('')
            : '当前没有可播报的已验证经营指标，系统不会使用未验证值、缺失值或默认值补位。';

        return {
            status: hasVerifiedMetrics ? 'ready' : 'blocked',
            statusLabel: hasVerifiedMetrics ? '可播报' : '暂无已验证指标',
            text: `宿析OS经营播报。${hotelName}，${reportDate}。${factSentence}${scopeBoundary}${gapSentence}${evidenceSentence}`,
            canUse: true,
            verifiedMetricCount: verifiedMetrics.length,
            excludedMetricCount: Math.max(0, metricRows.length - verifiedMetrics.length),
            sourceRefCount: sourceRefs.length,
            gapCount: gaps.length,
            scopeLabel: scopeKeys.length === 2
                ? '全酒店 / OTA分口径'
                : (scopeKeys[0] === 'ota_channel' ? 'OTA渠道' : (scopeKeys[0] === 'whole_hotel' ? '全酒店经营' : '暂无可用口径')),
        };
    };

    const emptyTrustedBroadcast = (overrides = {}) => ({
        snapshot_id: null,
        version_no: null,
        hotel_id: 0,
        business_date: '',
        facts_broadcast_status: 'waiting_data',
        analysis_status: 'analysis_blocked',
        status_label: '等待数据',
        status_message: '选择酒店和业务日期后读取严格事实。',
        template_version: '',
        view_status: '',
        generated_at: '',
        data_cutoff_at: null,
        snapshot_fingerprint: '',
        final_text_sha256: '',
        final_text: '',
        facts: [],
        fact_refs: [],
        missing_items: [],
        source_status: {},
        can_generate: false,
        can_use: false,
        persisted: false,
        readback_verified: false,
        ...overrides,
    });

    const normalizeTrustedBroadcast = (value = {}) => {
        const source = value && typeof value === 'object' ? value : {};
        const persisted = source.persisted === true;
        const readbackVerified = source.readback_verified === true;
        const factsBroadcastStatus = String(source.facts_broadcast_status || 'waiting_data');
        return emptyTrustedBroadcast({
            ...source,
            snapshot_id: Number(source.snapshot_id || 0) || null,
            version_no: Number(source.version_no || 0) || null,
            hotel_id: Number(source.hotel_id || 0),
            business_date: String(source.business_date || '').slice(0, 10),
            facts_broadcast_status: factsBroadcastStatus,
            analysis_status: String(source.analysis_status || 'analysis_blocked'),
            status_label: String(source.status_label || (
                factsBroadcastStatus === 'facts_broadcast_ready'
                    ? '严格事实可播报'
                    : (factsBroadcastStatus === 'collection_failed' ? '采集失败' : '等待数据')
            )),
            status_message: String(source.status_message || ''),
            template_version: String(source.template_version || ''),
            view_status: String(source.view_status || ''),
            generated_at: String(source.generated_at || ''),
            data_cutoff_at: source.data_cutoff_at ? String(source.data_cutoff_at) : null,
            snapshot_fingerprint: String(source.snapshot_fingerprint || '').toLowerCase(),
            final_text_sha256: String(source.final_text_sha256 || '').toLowerCase(),
            final_text: String(source.final_text || ''),
            facts: objectList(source.facts),
            fact_refs: list(source.fact_refs).map(item => String(item || '').trim()).filter(Boolean),
            missing_items: objectList(source.missing_items),
            source_status: source.source_status && typeof source.source_status === 'object'
                ? source.source_status
                : {},
            can_generate: source.can_generate === true || factsBroadcastStatus === 'facts_broadcast_ready',
            can_use: source.can_use === true && persisted && readbackVerified && String(source.final_text || '').trim() !== '',
            persisted,
            readback_verified: readbackVerified,
        });
    };

    const setupBroadcast = (props) => {
        const { ref, watch, onBeforeUnmount } = Vue;
        const aiDailyTrustedBroadcast = ref(emptyTrustedBroadcast());
        const aiDailyTrustedBroadcastLoading = ref(false);
        const aiDailyTrustedBroadcastGenerating = ref(false);
        const aiDailyTrustedBroadcastError = ref('');
        const aiDailyTrustedBroadcastSpeaking = ref(false);
        let activeUtterance = null;
        let requestSequence = 0;
        let requestedSnapshotIdentity = null;

        const context = () => props.ctx || {};
        const notify = (message, type) => context().showToast?.(message, type);
        const request = (...args) => {
            const handler = context().aiDailyReportDeliveryRequest;
            if (typeof handler !== 'function') {
                throw new Error('可信经营播报请求通道未就绪');
            }
            return handler(...args);
        };
        const currentIdentity = () => {
            const form = context().aiDailyReportForm && typeof context().aiDailyReportForm === 'object'
                ? context().aiDailyReportForm
                : {};
            const report = context().aiDailyReport && typeof context().aiDailyReport === 'object'
                ? context().aiDailyReport
                : {};
            return {
                hotelId: Number(form.hotel_id || report.hotel_id || 0),
                businessDate: String(form.report_date || report.report_date || '').slice(0, 10),
            };
        };
        const hydrateBroadcastIdentityFromUrl = () => {
            const form = context().aiDailyReportForm;
            const href = String(window?.location?.href || '');
            if (!form || typeof form !== 'object' || !href) return;
            try {
                const url = new URL(href);
                const hotelId = String(url.searchParams.get('broadcast_hotel_id') || '').trim();
                const businessDate = String(url.searchParams.get('broadcast_date') || '').trim();
                const snapshotId = String(url.searchParams.get('broadcast_snapshot_id') || '').trim();
                const snapshotFingerprint = String(url.searchParams.get('broadcast_snapshot_fingerprint') || '').trim().toLowerCase();
                const finalTextSha256 = String(url.searchParams.get('broadcast_text_sha256') || '').trim().toLowerCase();
                if (/^[1-9][0-9]*$/.test(hotelId) && /^\d{4}-\d{2}-\d{2}$/.test(businessDate)) {
                    form.hotel_id = hotelId;
                    form.report_date = businessDate;
                    if (/^[1-9][0-9]*$/.test(snapshotId)) {
                        requestedSnapshotIdentity = {
                            snapshotId: Number(snapshotId),
                            hotelId: Number(hotelId),
                            businessDate,
                            snapshotFingerprint,
                            finalTextSha256,
                            complete: /^[a-f0-9]{64}$/.test(snapshotFingerprint)
                                && /^[a-f0-9]{64}$/.test(finalTextSha256),
                        };
                    }
                }
            } catch (error) {
                // Invalid or unavailable browser URL state leaves the visible form authoritative.
            }
        };
        const persistBroadcastIdentityInUrl = (snapshot) => {
            requestedSnapshotIdentity = {
                snapshotId: Number(snapshot.snapshot_id || 0),
                hotelId: Number(snapshot.hotel_id || 0),
                businessDate: String(snapshot.business_date || '').slice(0, 10),
                snapshotFingerprint: String(snapshot.snapshot_fingerprint || '').toLowerCase(),
                finalTextSha256: String(snapshot.final_text_sha256 || '').toLowerCase(),
                complete: true,
            };
            const href = String(window?.location?.href || '');
            if (!href || typeof window?.history?.replaceState !== 'function') return;
            try {
                const url = new URL(href);
                url.searchParams.set('page', 'ai-daily-report');
                url.searchParams.set('broadcast_hotel_id', String(snapshot.hotel_id || ''));
                url.searchParams.set('broadcast_date', String(snapshot.business_date || ''));
                url.searchParams.set('broadcast_snapshot_id', String(snapshot.snapshot_id || ''));
                url.searchParams.set('broadcast_snapshot_fingerprint', String(snapshot.snapshot_fingerprint || ''));
                url.searchParams.set('broadcast_text_sha256', String(snapshot.final_text_sha256 || ''));
                window.history.replaceState(window.history.state, '', url.toString());
            } catch (error) {
                // Snapshot persistence already succeeded; URL convenience must not rewrite that result.
            }
        };
        const identityMatches = (identity, sequence) => {
            const current = currentIdentity();
            return sequence === requestSequence
                && current.hotelId === identity.hotelId
                && current.businessDate === identity.businessDate;
        };
        const assertPayloadScope = (payload, identity) => {
            if (Number(payload.hotel_id || 0) !== identity.hotelId
                || String(payload.business_date || '').slice(0, 10) !== identity.businessDate
            ) {
                throw new Error('可信经营播报回读门店或业务日期不一致');
            }
        };
        const assertStoredSnapshot = (payload, identity) => {
            assertPayloadScope(payload, identity);
            if (payload.persisted !== true
                || payload.readback_verified !== true
                || Number(payload.snapshot_id || 0) <= 0
                || Number(payload.version_no || 0) <= 0
                || !/^[a-f0-9]{64}$/.test(String(payload.snapshot_fingerprint || '').toLowerCase())
                || !/^[a-f0-9]{64}$/.test(String(payload.final_text_sha256 || '').toLowerCase())
                || String(payload.final_text || '').trim() === ''
            ) {
                throw new Error('可信经营播报快照身份或精确回读验证失败');
            }
        };
        const requestedSnapshotFor = (identity) => {
            const requested = requestedSnapshotIdentity;
            if (!requested
                || requested.hotelId !== identity.hotelId
                || requested.businessDate !== identity.businessDate
            ) return null;
            if (!requested.complete) {
                throw new Error('可信经营播报深链缺少完整快照指纹，已拒绝降级到最新版本');
            }
            return requested;
        };
        const assertRequestedSnapshot = (payload, requested) => {
            if (Number(payload.snapshot_id || 0) !== requested.snapshotId
                || String(payload.snapshot_fingerprint || '').toLowerCase() !== requested.snapshotFingerprint
                || String(payload.final_text_sha256 || '').toLowerCase() !== requested.finalTextSha256
            ) {
                throw new Error('可信经营播报深链身份与精确回读不一致');
            }
        };
        const stopAiDailyTrustedBroadcast = () => {
            if (aiDailyTrustedBroadcastSpeaking.value && window?.speechSynthesis) {
                window.speechSynthesis.cancel();
            }
            activeUtterance = null;
            aiDailyTrustedBroadcastSpeaking.value = false;
        };
        const aiDailyTrustedBroadcastSpeechSupported = () => Boolean(
            window?.speechSynthesis && typeof window.SpeechSynthesisUtterance === 'function'
        );

        const loadAiDailyTrustedBroadcast = async () => {
            const identity = currentIdentity();
            const sequence = ++requestSequence;
            stopAiDailyTrustedBroadcast();
            aiDailyTrustedBroadcastError.value = '';
            if (!identity.hotelId || !/^\d{4}-\d{2}-\d{2}$/.test(identity.businessDate)) {
                aiDailyTrustedBroadcastLoading.value = false;
                aiDailyTrustedBroadcast.value = emptyTrustedBroadcast({
                    hotel_id: identity.hotelId,
                    business_date: identity.businessDate,
                    status_message: '请选择一个酒店和业务日期。',
                });
                return;
            }

            aiDailyTrustedBroadcastLoading.value = true;
            try {
                const requestedSnapshot = requestedSnapshotFor(identity);
                const response = await request(
                    requestedSnapshot
                        ? `/ai-daily-reports/broadcast-snapshots/${requestedSnapshot.snapshotId}`
                        : `/ai-daily-reports/broadcast-snapshots/latest?hotel_id=${identity.hotelId}&report_date=${encodeURIComponent(identity.businessDate)}`
                );
                if (!identityMatches(identity, sequence)) return;
                if (response.code !== 200) {
                    throw new Error(response.message || '可信经营播报读取失败');
                }
                const payload = normalizeTrustedBroadcast(response.data || {});
                assertPayloadScope(payload, identity);
                if (payload.persisted) assertStoredSnapshot(payload, identity);
                if (requestedSnapshot) {
                    assertStoredSnapshot(payload, identity);
                    assertRequestedSnapshot(payload, requestedSnapshot);
                }
                aiDailyTrustedBroadcast.value = payload;
            } catch (error) {
                if (!identityMatches(identity, sequence)) return;
                aiDailyTrustedBroadcast.value = emptyTrustedBroadcast({
                    hotel_id: identity.hotelId,
                    business_date: identity.businessDate,
                    status_message: '可信经营播报暂不可读取。',
                });
                aiDailyTrustedBroadcastError.value = errorMessage(error, '可信经营播报读取失败');
            } finally {
                if (identityMatches(identity, sequence)) {
                    aiDailyTrustedBroadcastLoading.value = false;
                }
            }
        };

        const generateAiDailyTrustedBroadcast = async () => {
            const identity = currentIdentity();
            if (!identity.hotelId
                || !/^\d{4}-\d{2}-\d{2}$/.test(identity.businessDate)
                || aiDailyTrustedBroadcastGenerating.value
            ) return false;
            const sequence = ++requestSequence;
            stopAiDailyTrustedBroadcast();
            aiDailyTrustedBroadcastGenerating.value = true;
            aiDailyTrustedBroadcastError.value = '';
            try {
                const response = await request('/ai-daily-reports/broadcast-snapshots', {
                    method: 'POST',
                    body: JSON.stringify({
                        hotel_id: identity.hotelId,
                        report_date: identity.businessDate,
                    }),
                });
                if (!identityMatches(identity, sequence)) return false;
                if (response.code !== 200) {
                    throw new Error(response.message || '可信经营播报生成失败');
                }
                const saved = normalizeTrustedBroadcast(response.data || {});
                assertPayloadScope(saved, identity);
                if (!saved.persisted) {
                    aiDailyTrustedBroadcast.value = saved;
                    notify(saved.status_message || '当前没有可保存的严格事实', 'warning');
                    return false;
                }
                assertStoredSnapshot(saved, identity);

                const exactResponse = await request(
                    `/ai-daily-reports/broadcast-snapshots/${saved.snapshot_id}`
                );
                if (!identityMatches(identity, sequence)) return false;
                if (exactResponse.code !== 200) {
                    throw new Error(exactResponse.message || '可信经营播报精确回读失败');
                }
                const exact = normalizeTrustedBroadcast(exactResponse.data || {});
                assertStoredSnapshot(exact, identity);
                if (exact.snapshot_id !== saved.snapshot_id
                    || exact.snapshot_fingerprint !== saved.snapshot_fingerprint
                    || exact.final_text_sha256 !== saved.final_text_sha256
                    || exact.final_text !== saved.final_text
                ) {
                    throw new Error('可信经营播报保存结果与精确回读不一致');
                }
                aiDailyTrustedBroadcast.value = exact;
                persistBroadcastIdentityInUrl(exact);
                notify(`可信经营播报快照 #${exact.snapshot_id} 已保存并精确回读`, 'success');
                return true;
            } catch (error) {
                if (!identityMatches(identity, sequence)) return false;
                aiDailyTrustedBroadcastError.value = errorMessage(error, '可信经营播报生成失败');
                notify(aiDailyTrustedBroadcastError.value, 'warning');
                return false;
            } finally {
                if (identityMatches(identity, sequence)) {
                    aiDailyTrustedBroadcastGenerating.value = false;
                }
            }
        };

        const copyAiDailyTrustedBroadcast = async () => {
            const snapshot = aiDailyTrustedBroadcast.value;
            const text = snapshot.can_use ? String(snapshot.final_text || '') : '';
            if (!text) {
                notify('请先生成并精确回读可信经营播报', 'warning');
                return false;
            }
            try {
                if (globalThis.navigator?.clipboard?.writeText) {
                    await globalThis.navigator.clipboard.writeText(text);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    const copied = document.execCommand('copy');
                    document.body.removeChild(textarea);
                    if (!copied) throw new Error('clipboard unavailable');
                }
                notify(`快照 #${snapshot.snapshot_id} 的播报文本已复制`, 'success');
                return true;
            } catch (error) {
                notify('复制失败，请手动选择当前快照文本', 'warning');
                return false;
            }
        };

        const toggleAiDailyTrustedBroadcast = () => {
            if (aiDailyTrustedBroadcastSpeaking.value) {
                stopAiDailyTrustedBroadcast();
                notify('经营播报已停止', 'info');
                return false;
            }
            const snapshot = aiDailyTrustedBroadcast.value;
            const text = snapshot.can_use ? String(snapshot.final_text || '') : '';
            if (!text) {
                notify('请先生成并精确回读可信经营播报', 'warning');
                return false;
            }
            if (!aiDailyTrustedBroadcastSpeechSupported()) {
                notify('当前浏览器不支持本机朗读，可复制播报文本使用', 'warning');
                return false;
            }
            const utterance = new window.SpeechSynthesisUtterance(text);
            utterance.lang = 'zh-CN';
            utterance.rate = 0.95;
            utterance.pitch = 1;
            utterance.volume = 1;
            activeUtterance = utterance;
            utterance.onend = () => {
                if (activeUtterance === utterance) {
                    activeUtterance = null;
                    aiDailyTrustedBroadcastSpeaking.value = false;
                }
            };
            utterance.onerror = utterance.onend;
            aiDailyTrustedBroadcastSpeaking.value = true;
            window.speechSynthesis.speak(utterance);
            notify(`正在朗读快照 #${snapshot.snapshot_id}`, 'success');
            return true;
        };

        hydrateBroadcastIdentityFromUrl();
        watch(
            [
                () => Number(currentIdentity().hotelId || 0),
                () => String(currentIdentity().businessDate || ''),
            ],
            () => { void loadAiDailyTrustedBroadcast(); },
            { flush: 'post', immediate: true },
        );

        onBeforeUnmount(() => {
            stopAiDailyTrustedBroadcast();
            requestSequence++;
        });

        const local = {
            aiDailyTrustedBroadcast,
            aiDailyTrustedBroadcastLoading,
            aiDailyTrustedBroadcastGenerating,
            aiDailyTrustedBroadcastError,
            aiDailyTrustedBroadcastSpeaking,
            aiDailyTrustedBroadcastSpeechSupported,
            loadAiDailyTrustedBroadcast,
            generateAiDailyTrustedBroadcast,
            copyAiDailyTrustedBroadcast,
            toggleAiDailyTrustedBroadcast,
        };
        return new Proxy(local, {
            get(target, key) {
                if (key === 'ctx') return props.ctx;
                if (Reflect.has(target, key)) {
                    const value = Reflect.get(target, key);
                    return value?.__v_isRef === true ? value.value : value;
                }
                return props.ctx?.[key];
            },
            set(target, key, value) {
                if (Reflect.has(target, key)) {
                    const current = Reflect.get(target, key);
                    if (current?.__v_isRef === true) {
                        current.value = value;
                        return true;
                    }
                    return Reflect.set(target, key, value);
                }
                if (props.ctx) {
                    props.ctx[key] = value;
                    return true;
                }
                return Reflect.set(target, key, value);
            },
            has(target, key) {
                return Reflect.has(target, key) || Boolean(props.ctx && key in props.ctx);
            },
            ownKeys(target) {
                return Array.from(new Set([
                    ...Reflect.ownKeys(target),
                    ...Reflect.ownKeys(props.ctx || {}),
                ]));
            },
            getOwnPropertyDescriptor() {
                return { enumerable: true, configurable: true };
            },
        });
    };

    const setup = (props) => {
        const { ref, watch, onBeforeUnmount } = Vue;
        const aiDailyReportAudience = ref('owner');
        const aiDailyReportPresentationGenerating = ref(false);
        const aiDailyReportPresentationLoading = ref(false);
        const aiDailyReportPresentationResult = ref(null);
        const aiDailyReportBroadcastSpeaking = ref(false);
        let presentationReadSequence = 0;
        let presentationGenerationSequence = 0;
        let activeBroadcastUtterance = null;

        const context = () => props.ctx || {};
        const report = () => context().aiDailyReport || {};
        const notify = (message, type) => context().showToast?.(message, type);
        const aiDailyOperationsBroadcast = () => buildAiDailyOperationsBroadcast(context());
        const aiDailyReportBroadcastSpeechSupported = () => Boolean(
            window?.speechSynthesis && typeof window.SpeechSynthesisUtterance === 'function'
        );
        const stopAiDailyOperationsBroadcast = () => {
            if (aiDailyReportBroadcastSpeaking.value && window?.speechSynthesis) {
                window.speechSynthesis.cancel();
            }
            activeBroadcastUtterance = null;
            aiDailyReportBroadcastSpeaking.value = false;
        };
        const copyAiDailyOperationsBroadcast = async () => {
            const broadcast = aiDailyOperationsBroadcast();
            if (!broadcast.canUse) {
                notify('请先读取或生成一份已保存日报', 'warning');
                return false;
            }
            try {
                if (globalThis.navigator?.clipboard?.writeText) {
                    await globalThis.navigator.clipboard.writeText(broadcast.text);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = broadcast.text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    const copied = document.execCommand('copy');
                    document.body.removeChild(textarea);
                    if (!copied) throw new Error('clipboard unavailable');
                }
                notify('可信经营播报稿已复制', 'success');
                return true;
            } catch (error) {
                notify('复制失败，请手动选择播报稿', 'warning');
                return false;
            }
        };
        const toggleAiDailyOperationsBroadcast = () => {
            if (aiDailyReportBroadcastSpeaking.value) {
                stopAiDailyOperationsBroadcast();
                notify('经营播报已停止', 'info');
                return false;
            }
            const broadcast = aiDailyOperationsBroadcast();
            if (!broadcast.canUse) {
                notify('请先读取或生成一份已保存日报', 'warning');
                return false;
            }
            if (!aiDailyReportBroadcastSpeechSupported()) {
                notify('当前浏览器不支持本机朗读，可复制播报稿使用', 'warning');
                return false;
            }
            const utterance = new window.SpeechSynthesisUtterance(broadcast.text);
            utterance.lang = 'zh-CN';
            utterance.rate = 0.95;
            utterance.pitch = 1;
            utterance.volume = 1;
            activeBroadcastUtterance = utterance;
            utterance.onend = () => {
                if (activeBroadcastUtterance === utterance) {
                    activeBroadcastUtterance = null;
                    aiDailyReportBroadcastSpeaking.value = false;
                }
            };
            utterance.onerror = utterance.onend;
            aiDailyReportBroadcastSpeaking.value = true;
            window.speechSynthesis.speak(utterance);
            notify('正在朗读可信经营播报', 'success');
            return true;
        };
        const request = (...args) => {
            const handler = context().aiDailyReportDeliveryRequest;
            if (typeof handler !== 'function') {
                throw new Error('演示交付请求通道未就绪');
            }
            return handler(...args);
        };

        const currentIdentity = () => ({
            reportId: Number(report().id || 0),
            hotelId: Number(report().hotel_id || 0),
            audience: String(aiDailyReportAudience.value || 'owner'),
        });

        const identityMatches = (expected, sequence, kind) => {
            const current = currentIdentity();
            const activeSequence = kind === 'generation'
                ? presentationGenerationSequence
                : presentationReadSequence;
            return sequence === activeSequence
                && current.reportId === expected.reportId
                && current.hotelId === expected.hotelId
                && current.audience === expected.audience;
        };

        const loadAiDailyReportPresentationArtifact = async () => {
            const identity = currentIdentity();
            const sequence = ++presentationReadSequence;
            if (!identity.reportId) {
                aiDailyReportPresentationLoading.value = false;
                aiDailyReportPresentationResult.value = null;
                return;
            }

            aiDailyReportPresentationLoading.value = true;
            try {
                const response = await request(
                    `/ai-daily-reports/${identity.reportId}/presentation-artifacts?audience=${encodeURIComponent(identity.audience)}`,
                    { expectedHttpStatuses: [404] }
                );
                if (!identityMatches(identity, sequence, 'read')) return;
                if (response.code === 404) {
                    aiDailyReportPresentationResult.value = null;
                    return;
                }
                if (response.code !== 200) {
                    throw new Error(response.message || '演示包回读失败');
                }
                const artifact = response.data || {};
                if (artifact.status === 'not_generated'
                    || artifact.render_status === 'not_generated') {
                    aiDailyReportPresentationResult.value = null;
                    return;
                }
                if (artifact.artifact_readback_verified !== true
                    || !/^[a-f0-9]{64}$/.test(String(artifact.content_sha256 || '').toLowerCase())
                    || Number(artifact.report_id || 0) !== identity.reportId
                    || Number(artifact.hotel_id || 0) !== identity.hotelId
                    || String(artifact.audience || '') !== identity.audience
                ) {
                    throw new Error('已保存演示包身份或回读验证失败');
                }
                aiDailyReportPresentationResult.value = {
                    status: 'ready',
                    message: '已回读一份保存并验真的演示包；点击生成演示包可再次验真下载',
                    artifactId: Number(artifact.artifact_id || 0),
                    contentBytes: Number(artifact.content_bytes || 0),
                    contentSha256: String(artifact.content_sha256 || '').slice(0, 16),
                    specFingerprint: String(artifact.spec_fingerprint || '').slice(0, 16),
                };
            } catch (error) {
                if (!identityMatches(identity, sequence, 'read')) return;
                const statusCode = Number(error?.data?.code || error?.status || 0);
                if (statusCode === 404) {
                    aiDailyReportPresentationResult.value = null;
                    return;
                }
                aiDailyReportPresentationResult.value = {
                    status: 'failed',
                    message: errorMessage(error, '演示包回读失败'),
                };
            } finally {
                if (identityMatches(identity, sequence, 'read')) {
                    aiDailyReportPresentationLoading.value = false;
                }
            }
        };

        const downloadAiDailyReportPackage = async () => {
            const identity = currentIdentity();
            if (!identity.reportId || aiDailyReportPresentationGenerating.value) return;
            const sequence = ++presentationGenerationSequence;
            const isCurrent = () => identityMatches(identity, sequence, 'generation');
            aiDailyReportPresentationGenerating.value = true;
            aiDailyReportPresentationResult.value = null;
            try {
                if (!identity.hotelId) throw new Error('演示包缺少当前酒店身份');
                const specResponse = await request(`/ai-daily-reports/${identity.reportId}/presentation-spec`, {
                    method: 'POST',
                    body: JSON.stringify({ audience: identity.audience }),
                });
                if (!isCurrent()) return;
                if (specResponse.code !== 200) {
                    throw new Error(specResponse.message || '演示规格保存失败');
                }
                const storedSpec = specResponse.data || {};
                const presentationSpecId = Number(storedSpec.record_id || 0);
                const expectedSpecFingerprint = String(storedSpec.spec_fingerprint || '').trim().toLowerCase();
                if (storedSpec.readback_verified !== true
                    || presentationSpecId <= 0
                    || !/^[a-f0-9]{64}$/.test(expectedSpecFingerprint)
                    || Number(storedSpec.report_id || 0) !== identity.reportId
                    || Number(storedSpec.hotel_id || 0) !== identity.hotelId
                    || String(storedSpec.audience || '') !== identity.audience
                ) {
                    throw new Error('演示规格身份或精确回读验证失败');
                }

                const response = await request(`/ai-daily-reports/${identity.reportId}/presentation-artifacts`, {
                    method: 'POST',
                    body: JSON.stringify({
                        audience: identity.audience,
                        presentation_spec_id: presentationSpecId,
                        expected_spec_fingerprint: expectedSpecFingerprint,
                    }),
                });
                if (!isCurrent()) return;
                if (response.code !== 200) {
                    throw new Error(response.message || '演示包生成失败');
                }

                const artifact = response.data || {};
                const expectedSha = String(artifact.content_sha256 || '').trim().toLowerCase();
                const expectedBytes = Number(artifact.content_bytes || 0);
                if (artifact.artifact_readback_verified !== true
                    || artifact.render_status !== 'rendered_and_readback_verified'
                    || !/^[a-f0-9]{64}$/.test(expectedSha)
                    || !String(artifact.bundle_base64 || '').trim()
                    || Number(artifact.report_id || 0) !== identity.reportId
                    || Number(artifact.hotel_id || 0) !== identity.hotelId
                    || String(artifact.audience || '') !== identity.audience
                    || Number(artifact.presentation_spec_id || 0) !== presentationSpecId
                    || String(artifact.spec_fingerprint || '').trim().toLowerCase() !== expectedSpecFingerprint
                ) {
                    throw new Error('演示包未通过服务端保存回读验证');
                }

                const bytes = decodePresentationBundle(artifact.bundle_base64);
                if (!expectedBytes || bytes.byteLength !== expectedBytes) {
                    throw new Error('演示包字节长度与服务端记录不一致');
                }
                const actualSha = await sha256Hex(bytes);
                if (actualSha !== expectedSha) {
                    throw new Error('演示包 SHA-256 与服务端记录不一致');
                }
                if (!isCurrent()) return;

                const proposedFilename = safeFilename(
                    artifact.filename,
                    `suxios-ai-daily-${identity.audience}-bundle.zip`
                );
                const filename = proposedFilename.endsWith('.zip')
                    ? proposedFilename
                    : `${proposedFilename}.zip`;
                downloadBlob(new Blob([bytes], { type: 'application/zip' }), filename);
                aiDailyReportPresentationResult.value = {
                    status: 'ready',
                    message: artifact.storage_status === 'saved'
                        ? '演示包已保存、回读并通过浏览器验真，下载已开始'
                        : '已读取同一演示包并通过浏览器验真，下载已开始',
                    artifactId: Number(artifact.artifact_id || 0),
                    contentBytes: expectedBytes,
                    contentSha256: expectedSha.slice(0, 16),
                    specFingerprint: String(artifact.spec_fingerprint || '').slice(0, 16),
                };
                notify('PPTX/HTML 演示包已验真并开始下载', 'success');
            } catch (error) {
                if (!isCurrent()) return;
                const message = errorMessage(error, '演示包生成失败');
                aiDailyReportPresentationResult.value = { status: 'failed', message };
                notify(message, 'error');
            } finally {
                if (sequence === presentationGenerationSequence) {
                    aiDailyReportPresentationGenerating.value = false;
                }
            }
        };

        const buildSharePackage = (audience) => {
            const ctx = context();
            const currentReport = report();
            const contract = ctx.aiDailyReportResultContract || {};
            const aiInterpretation = ctx.aiDailyReportAiInterpretation || {};
            const dataGaps = objectList(ctx.aiDailyReportDataGaps);
            const abnormalMetrics = list(ctx.aiDailyReportAbnormalMetrics);
            const humanJudgments = objectList(ctx.aiDailyReportHumanJudgments);
            const common = {
                package_version: 'ai_daily_share.v1',
                audience,
                report_date: currentReport.report_date || '',
                summary: currentReport.summary || '',
                result_status: ctx.aiDailyReportResultReadiness || {},
                result_contract: contract,
                ai_boundary: aiInterpretation.boundary || 'AI辅助解读，不替代酒店老板或行业专家判断。',
                generated_from_result_version: contract.result_version || '',
                trial_validation: currentReport.trial_validation || {},
            };
            if (audience === 'expert') {
                return {
                    ...common,
                    result_layers: ctx.aiDailyReportResultLayers || {},
                    source_refs: objectList(currentReport.source_refs),
                    competitor_changes: objectList(ctx.aiDailyReportCompetitorChanges),
                    data_gaps: dataGaps,
                    workflow_gaps: objectList(currentReport.workflow_gaps),
                    workflow_status: ctx.aiDailyReportWorkflowReadiness || {},
                    human_judgments: humanJudgments,
                };
            }
            if (audience === 'training') {
                const sanitizeMetric = (item = {}) => ({
                    key: item.key || '',
                    label: item.label || '',
                    value: item.value ?? null,
                    unit: item.unit || '',
                    data_status: item.data_status || '',
                    result_layer: item.result_layer || '',
                });
                const layers = ctx.aiDailyReportResultLayers || {};
                return {
                    ...common,
                    report_date: '',
                    case_id: String(contract.result_version || 'unversioned').slice(0, 12),
                    anonymization: '已移除酒店ID、来源行标识、精确日期、操作者和人工判断记录。',
                    result_contract: {
                        contract_version: contract.contract_version || '',
                        metric_version: contract.metric_version || '',
                        reference_version: contract.reference_version || '',
                        boundary: contract.boundary || '',
                    },
                    source_facts: objectList(layers.source_facts).map(sanitizeMetric),
                    derived_metrics: objectList(layers.derived_metrics).map(sanitizeMetric),
                    anomaly_signals: abnormalMetrics.map(item => ({
                        type: item.type || '',
                        label: item.label || '',
                        level: item.level || '',
                        evidence: item.evidence || '',
                        signal_status: item.signal_status || '',
                        reference_status: item.reference_basis?.status || 'missing',
                    })),
                    ai_assistance: aiInterpretation,
                    data_gaps: dataGaps.map(gap => ({
                        code: gap.code || '',
                        message: gap.message || '',
                    })),
                };
            }
            return {
                ...common,
                key_metrics: list(ctx.aiDailyReportMetricCards).slice(0, 6),
                key_signals: abnormalMetrics.slice(0, 3),
                data_gaps: dataGaps,
                ai_assistance: aiInterpretation,
                latest_human_judgment: humanJudgments.slice(-1)[0] || null,
            };
        };

        const copyAiDailyCompetitionXiaohongshuDraft = async () => {
            const text = String(context().aiDailyReportCompetitionXiaohongshuDraftText || '');
            if (!text) {
                notify('可信报告未就绪，暂不生成小红书草稿', 'warning');
                return false;
            }
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    const copied = document.execCommand('copy');
                    document.body.removeChild(textarea);
                    if (!copied) throw new Error('clipboard unavailable');
                }
                notify('小红书待审核草稿已复制；请人工改稿后再通过官方功能发布', 'success');
                return true;
            } catch (error) {
                notify('复制失败，请展开草稿后手动复制', 'warning');
                return false;
            }
        };

        const downloadAiDailyCompetitionReportHtml = (edition = '') => (
            downloadCompetitionReport(context(), edition)
        );
        const downloadAiDailyReportJsonPackage = async () => {
            const currentReport = report();
            if (!currentReport?.id) return;
            const audience = String(aiDailyReportAudience.value || 'owner');
            const includeCompetition = audience !== 'training'
                && Boolean(context().aiDailyReportCompetitionReportDocument?.schema_version);
            if (includeCompetition && !downloadAiDailyCompetitionReportHtml()) return;
            const payload = buildSharePackage(audience);
            const deliveryKey = audience === 'training'
                ? `case-${payload.case_id || 'unversioned'}`
                : (currentReport.report_date || 'result');
            const filename = `suxios-ai-daily-${audience}-${deliveryKey}.json`;
            downloadBlob(
                new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' }),
                filename
            );
            notify('结果交付件已生成', 'success');
            if (includeCompetition && context().aiDailyReportCompetitionXiaohongshuDraftText) {
                await copyAiDailyCompetitionXiaohongshuDraft();
            }
        };

        watch(
            [
                () => Number(report().id || 0),
                () => Number(report().hotel_id || 0),
                () => String(report().result_contract?.result_version || report().updated_at || ''),
                () => String(aiDailyReportAudience.value || 'owner'),
            ],
            () => {
                stopAiDailyOperationsBroadcast();
                presentationReadSequence++;
                presentationGenerationSequence++;
                aiDailyReportPresentationGenerating.value = false;
                aiDailyReportPresentationResult.value = null;
                void loadAiDailyReportPresentationArtifact();
            },
            { flush: 'post', immediate: true },
        );

        onBeforeUnmount(() => {
            stopAiDailyOperationsBroadcast();
            presentationReadSequence++;
            presentationGenerationSequence++;
        });

        const local = {
            aiDailyReportAudience,
            aiDailyReportPresentationGenerating,
            aiDailyReportPresentationLoading,
            aiDailyReportPresentationResult,
            aiDailyReportBroadcastSpeaking,
            aiDailyOperationsBroadcast,
            aiDailyReportBroadcastSpeechSupported,
            copyAiDailyOperationsBroadcast,
            toggleAiDailyOperationsBroadcast,
            downloadAiDailyReportJsonPackage,
            downloadAiDailyReportPackage,
            downloadAiDailyCompetitionReportHtml,
            copyAiDailyCompetitionXiaohongshuDraft,
        };
        return new Proxy(local, {
            get(target, key) {
                if (key === 'ctx') return props.ctx;
                if (Reflect.has(target, key)) {
                    const value = Reflect.get(target, key);
                    return value?.__v_isRef === true ? value.value : value;
                }
                return props.ctx?.[key];
            },
            set(target, key, value) {
                if (Reflect.has(target, key)) {
                    const current = Reflect.get(target, key);
                    if (current?.__v_isRef === true) {
                        current.value = value;
                        return true;
                    }
                    return Reflect.set(target, key, value);
                }
                if (props.ctx) {
                    props.ctx[key] = value;
                    return true;
                }
                return Reflect.set(target, key, value);
            },
            has(target, key) {
                return Reflect.has(target, key) || Boolean(props.ctx && key in props.ctx);
            },
            ownKeys(target) {
                return Array.from(new Set([
                    ...Reflect.ownKeys(target),
                    ...Reflect.ownKeys(props.ctx || {}),
                ]));
            },
            getOwnPropertyDescriptor() {
                return { enumerable: true, configurable: true };
            },
        });
    };

    window.SUXI_AI_DAILY_REPORT_DELIVERY = Object.freeze({
        version: DELIVERY_VERSION,
        setup,
        setupBroadcast,
        downloadCompetitionReport,
        buildAiDailyOperationsBroadcast,
        normalizeTrustedBroadcast,
    });
})();
