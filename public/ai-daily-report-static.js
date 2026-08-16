window.SUXI_AI_DAILY_REPORT_STATIC = (() => {
    const escapeHtml = value => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

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
    const list = value => {
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
    const filenameToken = (value, fallback) => (
        String(value || '').replace(/[^A-Za-z0-9_-]/g, '') || fallback
    );

    const buildXiaohongshuDraftText = (draft = {}) => {
        if (draft?.status !== 'ready_for_human_review') return '';
        return [
            `选题：${draft.topic || '未命名选题'}`,
            '',
            '【标题10选1】',
            ...list(draft.titles_10).map((title, index) => `${index + 1}. ${title}`),
            '',
            '【封面标题5选1】',
            ...list(draft.cover_titles_5).map((title, index) => `${index + 1}. ${title}`),
            '',
            '【8页图文】',
            ...objectList(draft.pages_8).map(page => `P${page.page || '—'} ${page.title || ''}｜${page.points || ''}`),
            '',
            '【发布文案】',
            draft.post_text || '',
            '',
            '【话题标签】',
            list(draft.tags_10).join(' '),
            '',
            '【置顶评论】',
            ...list(draft.comments_3).map((comment, index) => `${index + 1}. ${comment}`),
            '',
            '【人工审核】',
            ...list(draft.human_review_checklist).map((item, index) => `${index + 1}. ${item}`),
        ].join('\n').trim();
    };

    const createCompetitionReportBindings = ({ computed, getBundle = () => ({}) } = {}) => {
        if (typeof computed !== 'function') throw new Error('competition report bindings require computed');
        const reportDocument = computed(() => getBundle()?.report_document || {});
        const reportReady = computed(() => reportDocument.value?.status === 'ready_for_review');
        const xiaohongshuDraft = computed(() => getBundle()?.content_drafts?.xiaohongshu || {});
        const xiaohongshuDraftText = computed(() => buildXiaohongshuDraftText(xiaohongshuDraft.value));
        return { reportDocument, reportReady, xiaohongshuDraft, xiaohongshuDraftText };
    };

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

        const platformRows = list(input.platforms).map(platform => {
            const section = report.platform_sections?.[platform.platform] || {};
            const status = section.status === 'ready_for_review' ? '可人工研判' : '证据不足';
            return `<section><h2>${escapeHtml(platform.label)}</h2><p class="status">${escapeHtml(status)}</p><p>${escapeHtml(platform.factText)}</p><p><strong>渠道角色：</strong>${escapeHtml(section.channel_role || '不输出')}</p><p><strong>第一矛盾：</strong>${escapeHtml(section.first_conflict || '不输出')}</p>${platform.gapText ? `<p class="gap"><strong>数据缺口：</strong>${escapeHtml(platform.gapText)}</p>` : ''}</section>`;
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
        const html = `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(report.title || 'OTA竞争商圈经营报告')}</title><style>
            :root{color-scheme:light;--ink:#10231d;--muted:#64748b;--gold:#9a7b43;--line:#e5e7eb;--soft:#f7f7f4;--gap:#92400e}*{box-sizing:border-box}body{margin:0;background:#eef1ed;color:var(--ink);font-family:"Microsoft YaHei","PingFang SC","Segoe UI",sans-serif;line-height:1.65}main{max-width:980px;margin:32px auto;background:#fff;padding:44px;border-radius:18px;box-shadow:0 18px 45px rgba(6,17,13,.12)}header{border-bottom:2px solid var(--gold);padding-bottom:22px;margin-bottom:26px}.eyebrow{color:var(--gold);font-size:12px;font-weight:700;letter-spacing:.12em}h1{margin:6px 0 4px;font-size:30px}h2{font-size:19px;margin:0 0 8px}h3{font-size:16px;margin-top:28px}p{margin:7px 0}.meta,.limit,small{color:var(--muted);font-size:12px}.identity{word-break:break-all}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.grid section{border:1px solid var(--line);border-radius:12px;padding:18px;background:var(--soft)}.status{display:inline-block;border:1px solid #d6c59e;border-radius:999px;padding:2px 9px;color:#6f572f;font-size:12px}.gap{color:var(--gap)}table{width:100%;border-collapse:collapse;margin:10px 0 22px}th,td{border:1px solid var(--line);padding:9px;text-align:left;vertical-align:top;font-size:13px}th{background:var(--soft)}li{margin:7px 0}li small{display:block}.limit{margin-top:30px;border-top:1px solid var(--line);padding-top:16px}@media(max-width:720px){main{margin:0;padding:24px;border-radius:0}.grid{grid-template-columns:1fr}}@media print{body{background:#fff}main{margin:0;max-width:none;box-shadow:none}}
        </style></head><body><main data-report-id="${escapeHtml(reportId)}" data-bundle-id="${escapeHtml(bundleId)}" data-source-fingerprint="${escapeHtml(fingerprint)}"><header><div class="eyebrow">SUXIOS · OTA CHANNEL REPORT</div><h1>${escapeHtml(report.title || 'OTA竞争商圈经营报告')}</h1><div class="meta">业务日期：${escapeHtml(report.scope?.data_date || input.fallbackReportDate || '未返回')}　质量：${escapeHtml(input.qualityText || '')}　版本：${escapeHtml(input.editionText || '')}</div><div class="meta identity">日报记录 ID：${escapeHtml(reportId)}<br>Bundle ID：${escapeHtml(bundleId)}<br>来源指纹：${escapeHtml(fingerprint)}</div></header><h3>管理层快照</h3><p>可研判平台 ${escapeHtml(report.management_snapshot?.platforms_ready ?? 0)} / ${escapeHtml(report.management_snapshot?.platforms_total ?? 2)}；人工确认动作 ${escapeHtml(report.management_snapshot?.action_count ?? 0)} 项。</p><div class="grid">${platformRows}</div><h3>竞品分组</h3>${groupRows ? `<table><thead><tr><th>分组</th><th>候选酒店</th></tr></thead><tbody>${groupRows}</tbody></table>` : '<p class="gap">当前没有达到展示门槛的竞品分组。</p>'}<h3>人工确认动作</h3>${actionRows ? `<table><thead><tr><th>平台</th><th>事项</th><th>动作</th><th>回滚</th></tr></thead><tbody>${actionRows}</tbody></table>` : '<p class="gap">行动门槛未通过，不输出执行建议。</p>'}<h3>数据缺口</h3>${gapRows ? `<ul>${gapRows}</ul>` : '<p>未发现显式数据缺口。</p>'}<p class="limit">${escapeHtml(report.render_contract?.commercial_boundary || '')}<br>本文件是从已保存并回读的同一 competition bundle 本地导出的界面版；不触发 OTA、飞书或小红书写入，auto_write_ota=false。</p></main></body></html>`;
        const dateToken = filenameToken(report.scope?.data_date || input.fallbackReportDate, 'report');
        const bundleToken = filenameToken(bundleId.slice(-12), 'bundle');
        return {
            ok: true,
            html,
            filename: `suxios-ota-competition-${dateToken}-r${reportId}-${bundleToken}.html`,
        };
    };

    const createCompetitionReportActions = (input = {}) => {
        const notify = typeof input.notify === 'function' ? input.notify : () => {};
        const runtimeDocument = input.document || globalThis.document;
        const runtimeUrl = input.URL || globalThis.URL;
        const BlobConstructor = input.Blob || globalThis.Blob;
        const runtimeNavigator = input.navigator || globalThis.navigator;
        const downloadHtml = () => {
            const result = buildAiDailyCompetitionReportExport({
                report: input.getReport?.() || {},
                bundle: input.getBundle?.() || {},
                reportId: input.getReportId?.() || 0,
                fallbackReportDate: input.getFallbackReportDate?.() || '',
                qualityText: input.getQualityText?.() || '',
                editionText: input.getEditionText?.() || '',
                platforms: input.getPlatforms?.() || [],
                groups: input.getGroups?.() || [],
            });
            if (result.ok !== true) {
                notify(result.message || '竞争商圈报告导出失败', result.level || 'error');
                return false;
            }
            const blob = new BlobConstructor([result.html], { type: 'text/html;charset=utf-8' });
            const url = runtimeUrl.createObjectURL(blob);
            const link = runtimeDocument.createElement('a');
            link.href = url;
            link.download = result.filename;
            runtimeDocument.body.appendChild(link);
            link.click();
            runtimeDocument.body.removeChild(link);
            runtimeUrl.revokeObjectURL(url);
            notify('竞争商圈界面版HTML已生成', 'success');
            return true;
        };
        const copyXiaohongshuDraft = async () => {
            const text = String(input.getDraftText?.() || '');
            if (!text) {
                notify('可信报告未就绪，暂不生成小红书草稿', 'warning');
                return false;
            }
            try {
                if (runtimeNavigator?.clipboard?.writeText) {
                    await runtimeNavigator.clipboard.writeText(text);
                } else {
                    const textarea = runtimeDocument.createElement('textarea');
                    textarea.value = text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    runtimeDocument.body.appendChild(textarea);
                    textarea.select();
                    const copied = runtimeDocument.execCommand('copy');
                    runtimeDocument.body.removeChild(textarea);
                    if (!copied) throw new Error('clipboard unavailable');
                }
                notify('小红书待审核草稿已复制；请人工改稿后再通过官方功能发布', 'success');
                return true;
            } catch {
                notify('复制失败，请展开草稿后手动复制', 'warning');
                return false;
            }
        };
        return { downloadHtml, copyXiaohongshuDraft };
    };

    return Object.freeze({
        buildXiaohongshuDraftText,
        createCompetitionReportBindings,
        buildAiDailyCompetitionReportExport,
        createCompetitionReportActions,
    });
})();
