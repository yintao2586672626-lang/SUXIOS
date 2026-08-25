(() => {
    const DELIVERY_VERSION = '2026-08-24.1';

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

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

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
        const competitionReport = ctx.aiDailyReportCompetitionReportDocument || {};
        if (!competitionReport.schema_version) {
            ctx.showToast?.('当前日报没有可导出的竞争商圈报告', 'warning');
            return false;
        }
        const bundle = ctx.aiDailyReportCompetitionBundle || {};
        const explicitEdition = typeof edition === 'string' ? edition.trim().toLowerCase() : '';
        const requestedEdition = explicitEdition
            || String(competitionReport.render_contract?.requested_edition || 'lite').trim().toLowerCase();
        const normalizedEdition = requestedEdition === 'flagship' ? 'flagship' : 'lite';
        const editionLabel = normalizedEdition === 'flagship' ? '旗舰版' : '简易版';
        const flagship = normalizedEdition === 'flagship';
        const qualityText = String(ctx.aiDailyReportCompetitionQualityText || '');
        const platformRows = list(ctx.aiDailyReportCompetitionPlatforms).map(platform => {
            const section = competitionReport.platform_sections?.[platform.platform] || {};
            const evidenceContract = platform.evidenceContract
                || section.evidence_contract
                || bundle.evidence_contracts?.[platform.platform]
                || {};
            const requiredAvailable = Number(evidenceContract.required_checks_available);
            const requiredTotal = Number(evidenceContract.required_checks_total);
            const evidenceCountKnown = Number.isFinite(requiredAvailable)
                && requiredAvailable >= 0
                && Number.isFinite(requiredTotal)
                && requiredTotal > 0;
            const missingEvidence = Array.isArray(platform.missingEvidence)
                ? platform.missingEvidence
                : (Array.isArray(evidenceContract.missing_required_labels)
                    ? evidenceContract.missing_required_labels
                    : []);
            const evidenceText = String(platform.evidenceText || evidenceContract.scope_label || '证据合同未返回');
            const caveatText = String(platform.caveatText || (
                Array.isArray(evidenceContract.caveats) ? evidenceContract.caveats.join('；') : ''
            ));
            const status = section.status === 'ready_for_review' ? '可人工研判' : '证据不足';
            const completenessText = evidenceCountKnown
                ? `${requiredAvailable}/${requiredTotal}`
                : '未返回';
            const missingText = missingEvidence.length ? missingEvidence.join('、') : '无';
            return `<section><h2>${escapeHtml(platform.label)}</h2><p class="status">${escapeHtml(status)}</p><p>${escapeHtml(platform.factText)}</p><p><strong>第一矛盾：</strong>${escapeHtml(section.first_conflict || '不输出')}</p><p><strong>证据范围：</strong>${escapeHtml(evidenceText)}</p><p><strong>证据完整度：</strong>${escapeHtml(completenessText)}；缺失项：${escapeHtml(missingText)}</p>${caveatText ? `<p class="caveat"><strong>口径边界：</strong>${escapeHtml(caveatText)}</p>` : ''}${flagship ? `<p><strong>渠道角色：</strong>${escapeHtml(section.channel_role || '不输出')}</p>${platform.gapText ? `<p class="gap"><strong>数据缺口：</strong>${escapeHtml(platform.gapText)}</p>` : ''}` : ''}</section>`;
        }).join('');
        const evidenceSummaryRows = list(ctx.aiDailyReportCompetitionPlatforms).map(platform => {
            const section = competitionReport.platform_sections?.[platform.platform] || {};
            const evidenceContract = platform.evidenceContract
                || section.evidence_contract
                || bundle.evidence_contracts?.[platform.platform]
                || {};
            const requiredAvailable = Number(evidenceContract.required_checks_available);
            const requiredTotal = Number(evidenceContract.required_checks_total);
            const evidenceCountKnown = Number.isFinite(requiredAvailable)
                && requiredAvailable >= 0
                && Number.isFinite(requiredTotal)
                && requiredTotal > 0;
            const missingEvidence = Array.isArray(evidenceContract.missing_required_labels)
                ? evidenceContract.missing_required_labels : [];
            const fullCircleText = evidenceContract.full_circle_ready === true
                ? '是'
                : (evidenceContract.full_circle_ready === false ? '否' : '未返回');
            return `<tr><td>${escapeHtml(platform.label)}</td><td>${escapeHtml(evidenceContract.scope_label || '证据合同未返回')}</td><td>${escapeHtml(evidenceCountKnown ? `${requiredAvailable}/${requiredTotal}` : '未返回')}</td><td>${escapeHtml(fullCircleText)}</td><td>${escapeHtml(missingEvidence.length ? missingEvidence.join('、') : '无')}</td></tr>`;
        }).join('');
        const evidenceCheckRows = list(ctx.aiDailyReportCompetitionPlatforms).flatMap(platform => {
            const section = competitionReport.platform_sections?.[platform.platform] || {};
            const evidenceContract = platform.evidenceContract
                || section.evidence_contract
                || bundle.evidence_contracts?.[platform.platform]
                || {};
            return list(evidenceContract.checks).map(check => ({ platform, check }));
        }).map(({ platform, check }) => {
            const layerText = check.result_layer === 'source_fact'
                ? '来源事实'
                : (check.result_layer === 'derived_metric' ? '派生指标' : '未标明');
            return `<tr><td>${escapeHtml(platform.label)}</td><td>${escapeHtml(check.label || check.key || '未命名证据')}</td><td>${escapeHtml(layerText)}</td><td>${escapeHtml(check.status || '未返回')}</td><td>${escapeHtml(check.definition || '')}</td></tr>`;
        }).join('');
        const groupRows = list(ctx.aiDailyReportCompetitionGroups).map(group => (
            `<tr><td>${escapeHtml(group.label)}</td><td>${escapeHtml(group.namesText)}</td></tr>`
        )).join('');
        const actions = objectList(competitionReport.actions);
        const gaps = objectList(competitionReport.data_gaps);
        const actionRows = actions.map(action => (
            `<tr><td>${escapeHtml(action.platform || '')}</td><td>${escapeHtml(action.title || '')}</td><td>${escapeHtml(action.action || '')}</td><td>${escapeHtml(action.rollback_condition || '需人工设定')}</td></tr>`
        )).join('');
        const gapRows = gaps.map(gap => (
            `<li><strong>${escapeHtml(gap.code || 'data_gap')}</strong>：${escapeHtml(gap.message || '')}<small>${escapeHtml(gap.source_ref || '')}</small></li>`
        )).join('');
        const liteActionRows = actions.slice(0, 3).map(action => (
            `<li><strong>${escapeHtml(action.title || '待人工确认')}</strong>：${escapeHtml(action.action || '')}</li>`
        )).join('');
        const liteGapRows = gaps.slice(0, 3).map(gap => (
            `<li>${escapeHtml(gap.message || gap.code || '数据缺口')}</li>`
        )).join('');
        const fingerprint = String(competitionReport.render_contract?.source_fingerprint || bundle.source_fingerprint || '');
        const detailSections = flagship
            ? `<h3>证据口径明细</h3>${evidenceCheckRows ? `<table><thead><tr><th>平台</th><th>证据项</th><th>结果层</th><th>状态</th><th>定义</th></tr></thead><tbody>${evidenceCheckRows}</tbody></table>` : '<p class="gap">证据合同未返回明细。</p>'}<h3>竞品分组</h3>${groupRows ? `<table><thead><tr><th>分组</th><th>候选酒店</th></tr></thead><tbody>${groupRows}</tbody></table>` : '<p class="gap">当前没有达到展示门槛的竞品分组。</p>'}<h3>人工确认动作</h3>${actionRows ? `<table><thead><tr><th>平台</th><th>事项</th><th>动作</th><th>回滚</th></tr></thead><tbody>${actionRows}</tbody></table>` : '<p class="gap">行动门槛未通过，不输出执行建议。</p>'}<h3>数据缺口</h3>${gapRows ? `<ul>${gapRows}</ul>` : '<p>未发现显式数据缺口。</p>'}`
            : `<h3>优先动作</h3>${liteActionRows ? `<ol>${liteActionRows}</ol>` : '<p class="gap">行动门槛未通过，不输出执行建议。</p>'}<h3>关键数据缺口</h3>${liteGapRows ? `<ul>${liteGapRows}</ul>` : '<p>未发现显式数据缺口。</p>'}`;
        const title = `${competitionReport.title || 'OTA竞争商圈经营报告'} · ${editionLabel}`;
        const businessDate = competitionReport.scope?.data_date || (ctx.aiDailyReport || {}).report_date || '未返回';
        const html = `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(title)}</title><style>
            :root{color-scheme:light;--ink:#10231d;--muted:#64748b;--gold:#9a7b43;--line:#e5e7eb;--soft:#f7f7f4;--gap:#92400e}*{box-sizing:border-box}body{margin:0;background:#eef1ed;color:var(--ink);font-family:"Microsoft YaHei","PingFang SC","Segoe UI",sans-serif;line-height:1.65}main{max-width:980px;margin:32px auto;background:#fff;padding:44px;border-radius:18px;box-shadow:0 18px 45px rgba(6,17,13,.12)}header{border-bottom:2px solid var(--gold);padding-bottom:22px;margin-bottom:26px}.eyebrow{color:var(--gold);font-size:12px;font-weight:700;letter-spacing:.12em}h1{margin:6px 0 4px;font-size:30px}h2{font-size:19px;margin:0 0 8px}h3{font-size:16px;margin-top:28px}p{margin:7px 0}.meta,.limit,small{color:var(--muted);font-size:12px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.grid section{border:1px solid var(--line);border-radius:12px;padding:18px;background:var(--soft)}.status{display:inline-block;border:1px solid #d6c59e;border-radius:999px;padding:2px 9px;color:#6f572f;font-size:12px}.gap{color:var(--gap)}.caveat{color:#475569;font-size:12px}table{width:100%;border-collapse:collapse;margin:10px 0 22px}th,td{border:1px solid var(--line);padding:9px;text-align:left;vertical-align:top;font-size:13px}th{background:var(--soft)}li{margin:7px 0}li small{display:block}.limit{margin-top:30px;border-top:1px solid var(--line);padding-top:16px}@media(max-width:720px){main{margin:0;padding:24px;border-radius:0}.grid{grid-template-columns:1fr}}@media print{body{background:#fff}main{margin:0;max-width:none;box-shadow:none}}
        </style></head><body><main><header><div class="eyebrow">SUXIOS · OTA CHANNEL REPORT · ${escapeHtml(editionLabel)}</div><h1>${escapeHtml(title)}</h1><div class="meta">业务日期：${escapeHtml(businessDate)}　质量：${escapeHtml(qualityText)}　数据范围：携程/美团竞争圈</div><div class="meta">来源指纹：${escapeHtml(fingerprint)}</div></header><h3>管理层快照</h3><p>可研判平台 ${escapeHtml(competitionReport.management_snapshot?.platforms_ready ?? '未返回')} / ${escapeHtml(competitionReport.management_snapshot?.platforms_total ?? 2)}；完整商圈证据 ${escapeHtml(competitionReport.management_snapshot?.full_circle_platforms ?? '未返回')} / ${escapeHtml(competitionReport.management_snapshot?.platforms_total ?? 2)}；人工确认动作 ${escapeHtml(competitionReport.management_snapshot?.action_count ?? '未返回')} 项。</p><div class="grid">${platformRows}</div><h3>证据完整度</h3>${evidenceSummaryRows ? `<table><thead><tr><th>平台</th><th>证据范围</th><th>已具备/必需</th><th>完整商圈</th><th>缺失项</th></tr></thead><tbody>${evidenceSummaryRows}</tbody></table>` : '<p class="gap">证据合同未返回，完整度保持未知。</p>'}${detailSections}<p class="limit">${escapeHtml(competitionReport.render_contract?.commercial_boundary || '')}<br>${escapeHtml(editionLabel)}只读取同一份已保存并精确回读的携程、美团竞争圈 bundle；不触发 OTA、飞书或小红书写入，auto_write_ota=false。</p></main></body></html>`;
        const filenameDate = competitionReport.scope?.data_date || (ctx.aiDailyReport || {}).report_date || 'report';
        downloadBlob(
            new Blob([html], { type: 'text/html;charset=utf-8' }),
            `suxios-ota-competition-${normalizedEdition}-${filenameDate}.html`
        );
        ctx.showToast?.(`竞争商圈${editionLabel}HTML已生成`, 'success');
        return true;
    };



    const setup = (props) => {
        const { ref, watch, onBeforeUnmount } = Vue;
        const aiDailyReportAudience = ref('owner');
        const aiDailyReportPresentationGenerating = ref(false);
        const aiDailyReportPresentationLoading = ref(false);
        const aiDailyReportPresentationResult = ref(null);
        let presentationReadSequence = 0;
        let presentationGenerationSequence = 0;

        const context = () => props.ctx || {};
        const report = () => context().aiDailyReport || {};
        const notify = (message, type) => context().showToast?.(message, type);
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
                    `/ai-daily-reports/${identity.reportId}/presentation-artifacts?audience=${encodeURIComponent(identity.audience)}`
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
            const payload = buildSharePackage(audience);
            const filename = `suxios-ai-daily-${audience}-${currentReport.report_date || 'result'}.json`;
            downloadBlob(
                new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' }),
                filename
            );
            notify('结果交付件已生成', 'success');
            if (context().aiDailyReportCompetitionReportDocument?.schema_version) {
                downloadAiDailyCompetitionReportHtml();
                if (context().aiDailyReportCompetitionXiaohongshuDraftText) {
                    await copyAiDailyCompetitionXiaohongshuDraft();
                }
            }
        };

        watch(
            [() => Number(report().id || 0), () => Number(report().hotel_id || 0), () => String(aiDailyReportAudience.value || 'owner')],
            () => {
                presentationReadSequence++;
                presentationGenerationSequence++;
                aiDailyReportPresentationGenerating.value = false;
                aiDailyReportPresentationResult.value = null;
                void loadAiDailyReportPresentationArtifact();
            },
            { flush: 'post', immediate: true },
        );

        onBeforeUnmount(() => {
            presentationReadSequence++;
            presentationGenerationSequence++;
        });

        const local = {
            aiDailyReportAudience,
            aiDailyReportPresentationGenerating,
            aiDailyReportPresentationLoading,
            aiDailyReportPresentationResult,
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
        downloadCompetitionReport,
    });
})();
