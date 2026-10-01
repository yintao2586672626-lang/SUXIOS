(() => {
    'use strict';
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const shanghaiToday = () => {
        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}`;
    };
    const requestId = () => window.crypto.randomUUID();
    components.InvestmentPaybackBody = {
        name: 'InvestmentPaybackBody',
        components: { InvestmentScenarioWorkbench: components.InvestmentScenarioWorkbench, InvestmentPaybackImport: components.InvestmentPaybackImport },
        props: { request: { type: Function, required: true }, hotels: { type: Array, default: () => [] } },
        setup(props) {
            const { ref, reactive, computed, onMounted } = Vue;
            const asOf = ref(shanghaiToday());
            const projects = ref([]);
            const detail = ref(null);
            const loading = ref(false);
            const saving = ref(false);
            const scenarioBusy = ref(false);
            const scenarioOpened = ref(false);
            const listError = ref('');
            const detailError = ref('');
            const formError = ref('');
            const notice = ref('');
            const search = ref('');
            const includeArchived = ref(false);
            const hasMore = ref(false);
            const totalProjects = ref(0);
            let loadedPage = 1;
            const projectForm = ref(null);
            const entryForm = ref(null);
            const importOpened = ref(false);
            const importProject = ref(null);
            const confirmation = reactive({ type: '', entry: null, reason: '' });
            const detailLoading = ref(false);
            const orderReady = ref(false);
            const orderSaving = ref(false);
            const orderError = ref('');
            const orderNotice = ref('');
            const cardDrag = ref(null);
            const dropTarget = ref(null);
            const orderBusy = computed(() => orderSaving.value || cardDrag.value !== null);
            let dragFrame = null;
            let dragScrollHost = null;
            let dragScrollOrigin = 0;
            let disposed = false;
            let listGeneration = 0;
            let detailGeneration = 0;
            const summary = computed(() => detail.value?.summary || {});
            const monthlyRows = computed(() => {
                const periods = new Map();
                for (const entry of detail.value?.entries || []) {
                    if (entry.voided_at || entry.is_planned) continue;
                    const month = entry.date.slice(0, 7);
                    const effectiveDate = entry.precision === 'month' ? `${month}-${String(new Date(Date.UTC(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0)).getUTCDate()).padStart(2, '0')}` : entry.date;
                    if (effectiveDate > asOf.value) continue;
                    const startDate = entry.precision === 'month' ? `${month}-01` : entry.date;
                    if (detail.value.project.opening_as_of && startDate <= detail.value.project.opening_as_of) continue;
                    const row = periods.get(month) || { month, investment: null, recovery: null, count: 0 };
                    const [whole, fraction = ''] = String(entry.amount).split('.');
                    const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
                    const key = entry.kind === 'investment' ? 'investment' : 'recovery';
                    row[key] = (row[key] ?? 0) + (entry.kind === 'refund' ? -cents : cents);
                    row.count++;
                    periods.set(month, row);
                }
                return [...periods.values()].sort((a, b) => b.month.localeCompare(a.month)).map(row => ({ ...row, investment: row.investment === null ? null : (row.investment / 100).toFixed(2), recovery: row.recovery === null ? null : (row.recovery / 100).toFixed(2) }));
            });
            const rows = computed(() => projects.value.filter(project => `${project.project_name} ${project.investor_name || ''}`.toLowerCase().includes(search.value.trim().toLowerCase())));
            const money = value => value === null || value === undefined || value === '' ? '待录入' : `${Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} 元`;
            const compactMoney = value => value === null || value === undefined || value === '' ? '待录入' : Math.abs(Number(value)) >= 10000 ? `${(Number(value) / 10000).toLocaleString('zh-CN', { maximumFractionDigits: 6 })} 万元` : `${Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 })} 元`;
            const paybackBalance = value => {
                const balance = value || {};
                if (balance.excess_recovered_amount != null && Number(balance.excess_recovered_amount) > 0) return { label: '回本后盈余', amount: balance.excess_recovered_amount, covered: false };
                if (balance.unrecovered_amount != null && balance.unrecovered_amount !== '' && Number(balance.unrecovered_amount) === 0 && Number(balance.invested_amount) > 0) return { label: '已回本', amount: null, covered: true };
                return { label: '尚未收回', amount: balance.unrecovered_amount ?? null, covered: false };
            };
            const progressWidth = value => value == null ? 0 : Math.min(100, Math.max(0, Number(value)));
            const overview = computed(() => {
                const sum = field => {
                    let cents = 0n;
                    let known = 0;
                    for (const project of rows.value) {
                        const amount = project.summary?.[field];
                        if (amount === null || amount === undefined || amount === '') continue;
                        const [whole, fraction = ''] = String(amount).replace('-', '').split('.');
                        cents += (BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))) * (String(amount).startsWith('-') ? -1n : 1n);
                        known++;
                    }
                    const absolute = cents < 0n ? -cents : cents;
                    return { amount: known ? `${cents < 0n ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}` : null, known };
                };
                return { count: rows.value.length, investment: sum('invested_amount'), recovery: sum('net_recovered_amount'), missing: rows.value.filter(project => project.summary?.invested_amount == null).length };
            });
            const forecastBrief = forecast => forecast?.remaining_months != null ? `预计还需 ${forecast.whole_months} 个月${forecast.status === 'trial' ? '（试算）' : ''}` : forecast?.status === 'non_positive' ? '当前假设无法测算回本周期' : ['already_recovered', 'trial_recovered'].includes(forecast?.status) ? '录入金额已覆盖投入' : '回本周期待测算';
            const focusForm = () => Vue.nextTick?.(() => document.querySelector('[data-testid="investment-payback-workbench"] [role="dialog"] input:not([type="checkbox"]):not([disabled])')?.focus());
            const closeForms = () => { if (saving.value || scenarioBusy.value) return; projectForm.value = null; entryForm.value = null; if (confirmation.type === 'delete') confirmation.type = ''; formError.value = ''; };
            const closeDetail = () => { if (saving.value || scenarioBusy.value) return; ++detailGeneration; detail.value = null; detailLoading.value = false; detailError.value = ''; closeForms(); confirmation.type = ''; };
            const dialogKey = event => {
                if (event.key === 'Escape') { event.preventDefault(); closeForms(); return; }
                if (event.key !== 'Tab') return;
                const controls = [...event.currentTarget.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary')].filter(control => control.offsetParent !== null);
                const first = controls[0];
                const last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            };
            const stateLabel = state => ({ draft: '待录入投入', recorded_only: '按已录入记录计算', unrecovered: '尚未回本', recovered: '当前已回本', reopened: '历史回本后有资金缺口' }[state] || '待核对');
            const kindLabel = kind => ({ investment: '投入', recovery: '收回', refund: '退款 / 冲回' }[kind] || kind);
            const entrySource = entry => {
                const source = String(entry?.source || '');
                const imported = source.match(/^人工确认文件导入；来源未独立核验；file=(.*?)；sha256=[a-f0-9]{64}；method=(spreadsheet|image_ocr|pasted_table)；row=(\d+)$/);
                return imported ? `${({ spreadsheet: '表格导入', image_ocr: '图片导入', pasted_table: '粘贴表格' })[imported[2]]} · ${imported[1]} · 第${imported[3]}行` : source || '来源未填写';
            };
            const entryState = entry => {
                if (entry.voided_at) return '已作废';
                if (entry.is_planned) return '计划，未计入实际';
                const startDate = entry.precision === 'month' ? `${entry.date.slice(0, 7)}-01` : entry.date;
                if (detail.value?.project.opening_as_of && startDate <= detail.value.project.opening_as_of) return '期初范围覆盖或重叠，未叠加';
                const effectiveDate = entry.precision === 'month' ? `${entry.date.slice(0, 7)}-${String(new Date(Date.UTC(Number(entry.date.slice(0, 4)), Number(entry.date.slice(5, 7)), 0)).getUTCDate()).padStart(2, '0')}` : entry.date;
                return effectiveDate > asOf.value ? '截至日之后，未计入本次汇总' : '人工实际录入';
            };
            const auditLabel = type => ({ project_created: '新建项目', project_updated: '修改项目 / 测算假设', project_archived: '归档项目', entry_created: '新增资金记录', entry_updated: '修改资金记录', entry_voided: '作废资金记录', entry_deleted: '管理员删除资金记录', scenario_saved: '保存经营测算' }[type] || '变更记录');
            const auditChange = audit => {
                const before = audit.payload?.before;
                const after = audit.payload?.after;
                if (audit.event_type === 'entry_deleted' && before) return `删除 ${kindLabel(before.kind)} ${money(before.amount)} · ${before.date}${before.precision === 'month' ? '（按月）' : ''} · 来源 ${before.source || '历史来源未填写'}`;
                if (!after) return '历史记录资料不足';
                if (audit.event_type === 'scenario_saved') return `测算：${after.scenario_name || '空白草稿'} · 模型 ${after.model_version || '待填写'} · 来源 ${after.source_label || '未填写'}`;
                if (audit.entry_id) {
                    const describe = row => `${kindLabel(row.kind)} ${money(row.amount)} · ${row.date}${row.precision === 'month' ? '（按月）' : ''}`;
                    return before ? `${describe(before)} → ${describe(after)}` : describe(after);
                }
                return before ? `预计每月净收回：${money(before.expected_monthly_amount)} → ${money(after.expected_monthly_amount)}` : `项目：${after.project_name}`;
            };
            const forecastLabel = status => ({ missing_investment: '请先记一笔实际投入', missing_monthly: '待填写每月净收回', non_positive: '当前假设无法测算有限回本周期', ready: '预计剩余回本周期', trial: '按已录入金额试算', already_recovered: '当前已收回全部已投入资金', trial_recovered: '已录入金额覆盖投入，待核对完整账目' }[status] || '待测算');
            const issueText = issue => ({ cutoff_before_opening_balance: '统计截至日早于期初余额日期，请调整截至日。', monthly_record_extends_beyond_cutoff: '当前月份的汇总覆盖截至日之后，未计入本次实际金额。', entry_overlaps_opening_balance: '资金记录与期初余额覆盖范围重叠，请核对。', history_not_checked_through_cutoff: '账目尚未完整核对至本次截至日。', actual_investment_missing: '尚未录入实际投入，暂不能计算回本进度。' }[typeof issue === 'string' ? issue : issue?.code] || issue?.message || '存在需要核对的资金记录。');
            const firstPaybackText = computed(() => {
                const first = summary.value.first_payback || {};
                if (first.status === 'confirmed') return `${first.date}${first.precision === 'month' ? '（按月记录）' : ''}，历时 ${first.elapsed_calendar_months ?? '—'} 个月`;
                if (first.status === 'opening_already_recovered') return '期初已收回投入，首次回本时间未知';
                if (first.status === 'observed_after_opening') return `${first.date} 观察到回本；期初前历史未完整记录`;
                if (first.status === 'recorded_only') return '记录中出现回本，完整账目尚未核对';
                return first.status === 'not_reached' ? '尚未达到实际回本' : '资料不足，尚不能确认';
            });
            const api = async (path, payload) => {
                const response = await props.request(`/investment-payback${path}`, payload === undefined ? { withBusinessContext: false } : { withBusinessContext: false, method: 'POST', body: JSON.stringify({ ...payload, as_of: asOf.value }) });
                if (Number(response?.code) !== 200 || !response?.data) throw new Error(response?.message || response?.msg || '回本台账请求未完成');
                return response.data;
            };
            const validCardOrder = order => Array.isArray(order) && order.every(id => Number.isSafeInteger(id) && id > 0) && new Set(order).size === order.length;
            const applyCardOrder = order => {
                const ranks = new Map(order.map((id, index) => [id, index]));
                projects.value = projects.value.map((project, index) => ({ project, index })).sort((a, b) => (ranks.get(a.project.id) ?? Infinity) - (ranks.get(b.project.id) ?? Infinity) || a.index - b.index).map(row => row.project);
            };
            const restoreCardOrder = result => {
                orderReady.value = validCardOrder(result.layout?.order);
                orderError.value = orderReady.value ? '' : '卡片顺序读取失败，暂不能排序；请刷新重试。';
                if (orderReady.value) applyCardOrder(result.layout.order);
            };
            const canReorder = () => orderReady.value && !orderSaving.value && !loading.value && !saving.value && !scenarioBusy.value && !detailLoading.value && !detail.value && !projectForm.value && !entryForm.value && !importOpened.value;
            const moveCard = async (sourceId, targetId) => {
                if (!canReorder() || cardDrag.value || sourceId === targetId) return;
                const visible = rows.value.map(project => project.id);
                const from = visible.indexOf(sourceId);
                const to = visible.indexOf(targetId);
                if (from < 0 || to < 0) return;
                const previous = [...projects.value];
                const moved = [...visible];
                moved.splice(to, 0, moved.splice(from, 1)[0]);
                const visibleSet = new Set(visible);
                const byId = new Map(previous.map(project => [project.id, project]));
                let position = 0;
                // Search-hidden cards keep their slots. The server preserves unloaded cards too.
                projects.value = previous.map(project => visibleSet.has(project.id) ? byId.get(moved[position++]) : project);
                const requested = projects.value.map(project => project.id);
                orderSaving.value = true;
                orderError.value = '';
                orderNotice.value = '正在保存卡片顺序…';
                try {
                    const saved = await api('/layout', { order: requested });
                    if (disposed) return;
                    const requestedSet = new Set(requested);
                    if (!validCardOrder(saved.order) || JSON.stringify(saved.order.filter(id => requestedSet.has(id))) !== JSON.stringify(requested)) throw new Error('服务器读回的顺序与拖动结果不一致');
                    applyCardOrder(saved.order);
                    orderNotice.value = '顺序已自动保存';
                } catch (error) {
                    if (disposed) return;
                    projects.value = previous;
                    orderReady.value = false;
                    orderNotice.value = '';
                    orderError.value = `顺序保存未确认，已恢复原显示。请刷新核对后重试：${error.message}`;
                } finally { orderSaving.value = false; }
            };
            const moveCardByKey = async (event, id) => {
                const direction = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[event.key];
                if (!direction) return;
                event.preventDefault();
                const visible = rows.value.map(project => project.id);
                const target = visible[visible.indexOf(id) + direction];
                if (target !== undefined) await moveCard(id, target);
                Vue.nextTick?.(() => document.querySelector(`[data-testid="payback-project-card"][data-project-id="${id}"] [data-card-drag-handle]`)?.focus());
            };
            const stopDragFrame = () => { if (dragFrame !== null) window.cancelAnimationFrame?.(dragFrame); dragFrame = null; };
            const cancelCardDrag = () => { stopDragFrame(); cardDrag.value = null; dropTarget.value = null; dragScrollHost = null; };
            const updateDropTarget = () => {
                const drag = cardDrag.value;
                if (!drag?.active) return;
                const card = document.elementsFromPoint(drag.x, drag.y).map(element => element.closest('[data-testid="payback-project-card"]')).find(element => element && Number(element.dataset.projectId) !== drag.id && element.closest('[data-testid="investment-payback-workbench"]'));
                dropTarget.value = card ? Number(card.dataset.projectId) : null;
            };
            const dragScrollFrame = () => {
                const drag = cardDrag.value;
                if (!drag?.active) return;
                const viewport = dragScrollHost?.getBoundingClientRect() || { top: 0, bottom: window.innerHeight };
                const delta = drag.y < viewport.top + 64 ? -12 : drag.y > viewport.bottom - 64 ? 12 : 0;
                if (delta) { (dragScrollHost || window).scrollBy(0, delta); updateDropTarget(); }
                drag.scrollOffset = (dragScrollHost ? dragScrollHost.scrollTop : window.scrollY || 0) - dragScrollOrigin;
                dragFrame = window.requestAnimationFrame?.(dragScrollFrame) ?? null;
            };
            const startCardDrag = (event, id) => {
                if (!canReorder() || cardDrag.value || event.isPrimary === false || (event.pointerType === 'mouse' && event.button !== 0)) return;
                for (let parent = event.currentTarget.parentElement; parent; parent = parent.parentElement) {
                    if (parent.scrollHeight > parent.clientHeight && /auto|scroll/.test(window.getComputedStyle(parent).overflowY)) { dragScrollHost = parent; break; }
                }
                dragScrollOrigin = dragScrollHost ? dragScrollHost.scrollTop : window.scrollY || 0;
                orderNotice.value = '';
                cardDrag.value = { id, pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, x: event.clientX, y: event.clientY, scrollOffset: 0, active: false };
                event.currentTarget.setPointerCapture?.(event.pointerId);
            };
            const moveCardDrag = event => {
                const drag = cardDrag.value;
                if (!drag || drag.pointerId !== event.pointerId) return;
                drag.x = event.clientX;
                drag.y = event.clientY;
                if (!drag.active && Math.hypot(drag.x - drag.startX, drag.y - drag.startY) < 6) return;
                drag.active = true;
                event.preventDefault();
                updateDropTarget();
                if (dragFrame === null) dragFrame = window.requestAnimationFrame?.(dragScrollFrame) ?? null;
            };
            const finishCardDrag = async event => {
                const drag = cardDrag.value;
                if (!drag || drag.pointerId !== event.pointerId) return;
                if (drag.active) { drag.x = event.clientX; drag.y = event.clientY; updateDropTarget(); }
                const target = dropTarget.value;
                const active = drag.active;
                cancelCardDrag();
                if (event.currentTarget.hasPointerCapture?.(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
                if (active && target !== null) await moveCard(drag.id, target);
            };
            const cardDragStyle = id => cardDrag.value?.active && cardDrag.value.id === id ? { transform: `translate(${cardDrag.value.x - cardDrag.value.startX}px, ${cardDrag.value.y - cardDrag.value.startY + cardDrag.value.scrollOffset}px)`, position: 'relative', zIndex: 20, pointerEvents: 'none', boxShadow: '0 12px 28px rgba(20,58,49,.16)' } : {};
            Vue.onUnmounted?.(() => { disposed = true; cancelCardDrag(); });
            const refreshProjects = async () => {
                if (orderBusy.value) return;
                const generation = ++listGeneration;
                loading.value = true;
                listError.value = '';
                try {
                    const result = await api(`/projects?as_of=${asOf.value}&include_archived=${includeArchived.value ? '1' : '0'}&page_size=100`);
                    if (generation !== listGeneration) return;
                    projects.value = result.list;
                    if (!Array.isArray(projects.value)) throw new Error('项目列表响应格式不正确');
                    restoreCardOrder(result);
                    loadedPage = 1;
                    totalProjects.value = Number(result.pagination?.total ?? result.list.length);
                    hasMore.value = projects.value.length < totalProjects.value;
                } catch (error) {
                    if (generation === listGeneration) { projects.value = []; listError.value = error.message; }
                } finally { if (generation === listGeneration) loading.value = false; }
            };
            const loadMore = async () => {
                if (loading.value || saving.value || scenarioBusy.value || orderBusy.value || !hasMore.value) return;
                const generation = ++listGeneration;
                loading.value = true;
                listError.value = '';
                try {
                    const result = await api(`/projects?as_of=${asOf.value}&include_archived=${includeArchived.value ? '1' : '0'}&page_size=100&page=${loadedPage + 1}`);
                    if (generation !== listGeneration) return;
                    if (!Array.isArray(result.list)) throw new Error('项目列表响应格式不正确');
                    projects.value = [...projects.value, ...result.list];
                    restoreCardOrder(result);
                    loadedPage++;
                    totalProjects.value = Number(result.pagination?.total ?? projects.value.length);
                    hasMore.value = projects.value.length < totalProjects.value;
                } catch (error) { if (generation === listGeneration) listError.value = error.message; }
                finally { if (generation === listGeneration) loading.value = false; }
            };
            const selectProject = async id => {
                if (saving.value || scenarioBusy.value || orderBusy.value) return;
                const generation = ++detailGeneration;
                scenarioOpened.value = false;
                detailLoading.value = true;
                detail.value = null;
                detailError.value = '';
                notice.value = '';
                projectForm.value = null;
                entryForm.value = null;
                confirmation.type = '';
                try {
                    const result = await api(`/projects/${id}?as_of=${asOf.value}`);
                    if (generation === detailGeneration) detail.value = result;
                } catch (error) { if (generation === detailGeneration) detailError.value = error.message; }
                finally { if (generation === detailGeneration) detailLoading.value = false; }
            };
            const openCard = (event, id) => {
                if (event.target?.closest?.('button,a,input,select,textarea,summary,[role="button"]') || window.getSelection?.()?.toString()) return;
                return selectProject(id);
            };
            const quickEntry = async (id, kind) => {
                if (saving.value || scenarioBusy.value) return;
                await selectProject(id);
                if (detail.value?.project.id === id && !detail.value.project.archived_at) beginEntry(kind);
            };
            const changeAsOf = async () => {
                if (orderBusy.value) return;
                const selected = detail.value?.project.id;
                detail.value = null;
                await refreshProjects();
                if (selected) await selectProject(selected);
            };
            const beginProject = project => {
                if (orderBusy.value) return;
                ++detailGeneration;
                detailLoading.value = false;
                formError.value = '';
                entryForm.value = null;
                const empty = { project_name: '', investor_name: '本人', hotel_id: '', basis: 'investor_cash', currency: 'CNY', status: 'operating', first_invested_on: '', expected_monthly_amount: '', expected_source: '人工填写未来每月净收回假设', forecast_as_of: asOf.value, history_complete_through: '', opening_as_of: '', opening_invested: '', opening_recovered: '', opening_source: '', notes: '', client_request_id: requestId() };
                projectForm.value = project ? { ...empty, ...project, hotel_id: project.hotel_id || '', expected_monthly_amount: project.expected_monthly_amount ?? '', history_complete_through: project.history_complete_through || '', first_invested_on: project.first_invested_on || '', opening_as_of: project.opening_as_of || '', opening_invested: project.opening_invested ?? '', opening_recovered: project.opening_recovered ?? '', opening_source: project.opening_source || '', expected_version: project.version } : empty;
                if (!project) projectForm.value.opening_as_of = asOf.value;
                focusForm();
            };
            const saveProject = async () => {
                if (saving.value || scenarioBusy.value) return;
                saving.value = true;
                formError.value = '';
                const isNew = !projectForm.value.id;
                try {
                    const input = { ...projectForm.value, hotel_id: projectForm.value.hotel_id || null };
                    if (isNew) {
                        const hasInvested = input.opening_invested !== '' && input.opening_invested != null;
                        const hasRecovered = input.opening_recovered !== '' && input.opening_recovered != null;
                        if (hasInvested !== hasRecovered) throw new Error('请同时填写累计投入和累计净收回；尚未收回请明确填写0。也可以两项都留空，先建项目。');
                        if (!hasInvested) { input.opening_as_of = ''; input.opening_source = ''; }
                        else input.opening_source = input.opening_source.trim() || '人工录入累计余额';
                    }
                    detail.value = await api('/projects', input);
                    projectForm.value = null;
                    notice.value = '项目已保存并读回。';
                    await refreshProjects();
                    if (isNew) notice.value = '项目已保存并读回；后续新增资金可直接“记一笔”。';
                } catch (error) { formError.value = error.message; }
                finally { saving.value = false; }
            };
            const beginEntry = (kind, entry) => {
                formError.value = '';
                projectForm.value = null;
                entryForm.value = entry ? { ...entry, expected_version: entry.version, confirmed_zero: Number(entry.amount) === 0 } : { kind, amount: '', date: asOf.value, precision: 'day', is_planned: false, original_entry_id: '', category: kind === 'investment' ? '初始 / 追加投入' : '实际到账', source: '人工录入', notes: '', confirmed_zero: false, client_request_id: requestId() };
                focusForm();
            };
            const changePrecision = () => { entryForm.value.date = entryForm.value.precision === 'month' ? entryForm.value.date.slice(0, 7) : ''; };
            const saveEntry = async () => {
                if (saving.value || scenarioBusy.value) return;
                saving.value = true;
                formError.value = '';
                try {
                    detail.value = await api(`/projects/${detail.value.project.id}/entries`, { ...entryForm.value, confirmed_zero: entryForm.value.kind === 'recovery' && entryForm.value.amount !== '' && Number(entryForm.value.amount) === 0 && entryForm.value.confirmed_zero, original_entry_id: entryForm.value.kind === 'refund' ? (entryForm.value.original_entry_id || null) : null });
                    entryForm.value = null;
                    notice.value = '资金记录已保存并读回，回本汇总已重新计算。';
                    await refreshProjects();
                } catch (error) { formError.value = error.message; }
                finally { saving.value = false; }
            };
            const forecastForm = reactive({ amount: '', source: '' });
            const editForecast = () => { forecastForm.amount = detail.value.project.expected_monthly_amount ?? ''; forecastForm.source = detail.value.project.expected_source || ''; };
            const saveForecast = async () => {
                if (saving.value || scenarioBusy.value) return;
                saving.value = true;
                detailError.value = '';
                try {
                    const project = detail.value.project;
                    detail.value = await api('/projects', { ...project, expected_version: project.version, expected_monthly_amount: forecastForm.amount, expected_source: forecastForm.source, forecast_as_of: asOf.value });
                    notice.value = '测算假设已保存并读回。';
                    await refreshProjects();
                } catch (error) { detailError.value = error.message; }
                finally { saving.value = false; }
            };
            Vue.watch(() => detail.value?.project, () => { if (detail.value) editForecast(); });
            const confirmAction = async () => {
                if (saving.value || scenarioBusy.value) return;
                if (confirmation.type !== 'delete' && !confirmation.reason.trim()) { formError.value = '请填写归档或作废原因'; return; }
                saving.value = true;
                formError.value = '';
                try {
                    const project = detail.value.project;
                    if (confirmation.type === 'archive') {
                        await api(`/projects/${project.id}/archive`, { reason: confirmation.reason, expected_version: project.version });
                        detail.value = null;
                        notice.value = '项目已归档，历史记录保留。';
                    } else if (confirmation.type === 'delete') {
                        const entry = confirmation.entry;
                        detail.value = await api(`/projects/${project.id}/entries/${entry.id}/delete`, { reason: confirmation.reason.trim() || '管理员主动删除', expected_version: entry.version });
                        notice.value = '资金记录已删除并读回，回本汇总已重新计算，删除前快照保留在变更记录中。';
                    } else {
                        const entry = confirmation.entry;
                        detail.value = await api(`/projects/${project.id}/entries/${entry.id}/void`, { reason: confirmation.reason, expected_version: entry.version });
                        notice.value = '记录已作废，历史保留，汇总已重算。';
                    }
                    confirmation.type = '';
                    await refreshProjects();
                } catch (error) { formError.value = error.message; }
                finally { saving.value = false; }
            };
            const openConfirmation = (type, entry) => {
                if (type === 'delete' && !detail.value?.can_delete_entries) return;
                confirmation.type = type; confirmation.entry = entry || null; confirmation.reason = ''; formError.value = '';
                if (type === 'delete') Vue.nextTick?.(() => document.querySelector('[data-testid="payback-delete-confirmation"] [data-delete-cancel]')?.focus());
            };
            const scenarioSaved = async saved => {
                const generation = ++detailGeneration;
                await refreshProjects();
                if (generation !== detailGeneration || String(detail.value?.project.id) !== String(saved.project_id)) return;
                try {
                    const refreshed = await api(`/projects/${saved.project_id}?as_of=${asOf.value}`);
                    if (generation === detailGeneration && String(detail.value?.project.id) === String(saved.project_id)) detail.value = refreshed;
                } catch (error) {
                    if (generation === detailGeneration) detailError.value = `测算已保存，项目版本刷新失败：${error.message}`;
                }
            };
            const beginImport = () => {
                if (saving.value || scenarioBusy.value || orderBusy.value || loading.value || detailLoading.value || projectForm.value || entryForm.value) return;
                importProject.value = detail.value?.project || null;
                importOpened.value = true;
            };
            const importSaved = async result => {
                const savedProjectId = Number(result.project_ids?.[0]);
                const selected = result.mode === 'entries' ? Number.isSafeInteger(savedProjectId) && savedProjectId > 0 ? savedProjectId : importProject.value?.id : null;
                importOpened.value = false;
                notice.value = `已导入 ${result.imported_count} 行${result.replayed ? '（已核对先前保存结果）' : ''}。`;
                await refreshProjects();
                if (selected) await selectProject(selected);
                notice.value = `已导入 ${result.imported_count} 行${result.replayed ? '（已核对先前保存结果）' : ''}。`;
            };
            onMounted(refreshProjects);
            return { importOpened, importProject, beginImport, importSaved, orderReady, orderSaving, orderError, orderNotice, orderBusy, cardDrag, dropTarget, moveCard, moveCardByKey, startCardDrag, moveCardDrag, finishCardDrag, cancelCardDrag, cardDragStyle, asOf, overview, compactMoney, paybackBalance, progressWidth, forecastBrief, detailLoading, quickEntry, closeForms, closeDetail, dialogKey, projects, detail, loading, saving, scenarioBusy, scenarioOpened, scenarioSaved, listError, detailError, formError, notice, search, includeArchived, hasMore, totalProjects, projectForm, entryForm, confirmation, summary, monthlyRows, rows, money, stateLabel, kindLabel, entrySource, entryState, auditLabel, auditChange, forecastLabel, issueText, firstPaybackText, refreshProjects, loadMore, selectProject, openCard, changeAsOf, beginProject, saveProject, beginEntry, changePrecision, saveEntry, forecastForm, saveForecast, confirmAction, openConfirmation, today: shanghaiToday() };
        },
        template: `
          <section class="space-y-5" data-testid="investment-payback-workbench">
            <div :inert="projectForm || entryForm || importOpened || confirmation.type === 'delete' ? true : undefined" class="space-y-5">
              <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <h2 class="text-2xl font-bold text-gray-900">酒店投资回本</h2>
                  <p class="mt-1 text-sm text-gray-500">看清投入、收回与剩余周期。</p>
                  <p class="mt-1 text-xs text-gray-500">人民币 · 投资人实收口径 · 人工录入，来源未独立核验</p>
                </div>
                <div class="flex flex-wrap gap-2"><button class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50" :disabled="saving || scenarioBusy || orderBusy || loading || detailLoading" @click="beginImport">导入表格 / 图片</button><button class="min-h-[44px] rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" style="background:var(--sx-luxury-green,#143a31);color:white" :disabled="saving || scenarioBusy || orderBusy" @click="beginProject()">＋ 新建项目</button></div>
              </header>
              <p v-if="notice" role="status" class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-900">{{ notice }}</p>
              <div class="flex flex-wrap items-end gap-3">
                <label class="text-xs text-gray-500">统计截至日<input class="mt-1 block min-h-[44px] max-w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" type="date" v-model="asOf" :max="today" :disabled="saving || scenarioBusy || orderBusy" @change="changeAsOf" /></label>
                <template v-if="!detail">
                  <label class="w-full min-w-0 text-xs text-gray-500 sm:w-auto sm:flex-1">查找项目<input class="mt-1 block min-h-[44px] w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" v-model="search" :disabled="orderBusy" placeholder="项目名称或投资主体" /></label>
                  <details class="relative">
                    <summary class="cursor-pointer rounded-lg border border-gray-200 bg-white px-3 py-3 text-sm text-gray-600">筛选</summary>
                    <label class="absolute right-0 z-10 mt-2 flex w-44 items-center gap-2 rounded-lg border bg-white p-3 text-sm shadow-sm"><input type="checkbox" v-model="includeArchived" :disabled="saving || scenarioBusy || orderBusy" @change="refreshProjects" />显示归档项目</label>
                  </details>
                  <button class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50" :disabled="loading || saving || scenarioBusy || orderBusy" @click="refreshProjects" aria-label="刷新项目">刷新</button>
                </template>
              </div>
              <p v-if="listError" role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">项目列表读取失败：{{ listError }}。可点击刷新重试。</p>
              <p v-if="loading || detailLoading" role="status" class="text-sm text-gray-500">正在读取项目…</p>
              <template v-if="!detail && !detailLoading && !listError">
                <dl v-if="rows.length" class="grid grid-cols-2 gap-4 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-4 sm:p-5">
                  <div><dt class="text-xs text-gray-500">当前项目</dt><dd class="mt-2 text-xl font-semibold text-gray-900">{{ overview.count }} <span class="text-xs font-normal text-gray-500">个</span></dd></div>
                  <div><dt class="text-xs text-gray-500">已录入投入</dt><dd class="mt-2 text-xl font-semibold text-gray-900" :title="money(overview.investment.amount)">{{ compactMoney(overview.investment.amount) }}</dd><p v-if="overview.investment.known < overview.count" class="mt-1 text-xs text-gray-500">{{ overview.investment.known }} / {{ overview.count }} 个项目有金额</p></div>
                  <div><dt class="text-xs text-gray-500">已录入净收回</dt><dd class="mt-2 text-xl font-semibold text-gray-900" :title="money(overview.recovery.amount)">{{ compactMoney(overview.recovery.amount) }}</dd><p v-if="overview.recovery.known < overview.count" class="mt-1 text-xs text-gray-500">{{ overview.recovery.known }} / {{ overview.count }} 个项目有金额</p></div>
                  <div><dt class="text-xs text-gray-500">待补投入</dt><dd class="mt-2 text-xl font-semibold text-gray-900">{{ overview.missing }} <span class="text-xs font-normal text-gray-500">个</span></dd></div>
                </dl>
                <p v-if="!rows.length && !loading" class="rounded-xl border bg-white p-6 text-sm text-gray-500">{{ search ? '没有匹配的项目。' : '先新建一个项目，可一起填写当前累计投入和收回余额。' }}</p>
                <div v-if="rows.length" class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                  <span>拖动卡片右上角手柄调整顺序，松开自动保存</span>
                  <span v-if="orderNotice" role="status" aria-live="polite">{{ orderNotice }}</span>
                </div>
                <p v-if="orderError" role="alert" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">{{ orderError }}</p>
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2" :aria-busy="orderSaving">
                  <article v-for="project in rows" :key="project.id" class="min-w-0 cursor-pointer rounded-xl border border-gray-200 bg-white p-5 sm:p-6 hover:border-gray-300" :class="dropTarget === project.id ? 'ring-2 ring-green-700' : ''" :style="cardDragStyle(project.id)" data-testid="payback-project-card" :data-project-id="project.id" @click="openCard($event, project.id)">
                    <div class="flex items-start justify-between gap-3">
                      <div class="min-w-0"><h3 class="break-words text-lg font-semibold text-gray-900"><button type="button" class="min-h-[44px] max-w-full break-words text-left hover:underline focus-visible:ring-2 focus-visible:ring-green-700 disabled:opacity-50" :aria-label="'打开' + project.project_name + '台账'" :disabled="saving || scenarioBusy || detailLoading || orderBusy" @click="selectProject(project.id)">{{ project.project_name }}</button></h3><p class="mt-1 text-xs text-gray-500">{{ project.investor_name || '投资主体未填写' }}</p></div>
                      <div class="flex shrink-0 items-center gap-1"><span class="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-500">{{ project.archived_at ? '已归档' : project.summary?.state === 'draft' ? '待补投入' : project.summary?.data_quality?.history_complete ? stateLabel(project.summary?.state) : '按录入计算' }}</span><button type="button" data-card-drag-handle class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-50 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-green-700 disabled:opacity-40" style="touch-action:none;cursor:grab;user-select:none" :aria-label="'拖动' + project.project_name + '调整顺序'" title="拖动调整顺序；也可聚焦后按方向键移动" :disabled="!orderReady || orderSaving || loading || saving || scenarioBusy || detailLoading" @pointerdown="startCardDrag($event, project.id)" @pointermove="moveCardDrag" @pointerup="finishCardDrag" @pointercancel="cancelCardDrag" @lostpointercapture="cancelCardDrag" @keydown="moveCardByKey($event, project.id)"><svg aria-hidden="true" width="16" height="20" viewBox="0 0 16 20" fill="currentColor"><circle cx="5" cy="4" r="1.5"/><circle cx="11" cy="4" r="1.5"/><circle cx="5" cy="10" r="1.5"/><circle cx="11" cy="10" r="1.5"/><circle cx="5" cy="16" r="1.5"/><circle cx="11" cy="16" r="1.5"/></svg></button></div>
                    </div>
                    <div class="mt-5 flex items-baseline justify-between gap-3">
                      <div class="min-w-0"><span class="text-xs text-gray-500">回本进度</span><p class="mt-1 text-3xl font-semibold" style="color:var(--sx-luxury-green,#143a31)">{{ project.summary?.recovery_percent == null ? '待录入' : project.summary.recovery_percent + '%' }}</p></div>
                      <div class="min-w-0 text-right" data-testid="payback-card-balance"><span class="text-xs text-gray-500">{{ paybackBalance(project.summary).label }}</span><p class="mt-1 break-words text-lg font-semibold text-gray-900" :title="paybackBalance(project.summary).covered ? '全部投入已收回' : money(paybackBalance(project.summary).amount)">{{ paybackBalance(project.summary).covered ? '全部投入已收回' : compactMoney(paybackBalance(project.summary).amount) }}</p></div>
                    </div>
                    <div v-if="project.summary?.recovery_percent != null" class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-label="project.project_name + '回本进度'" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progressWidth(project.summary.recovery_percent)" :aria-valuetext="project.summary.recovery_percent + '%，' + stateLabel(project.summary.state)"><div class="h-full rounded-full" style="background:var(--sx-luxury-green,#143a31)" :style="{ width: progressWidth(project.summary.recovery_percent) + '%' }"></div></div>
                    <p v-else class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">补一笔实际投入后显示进度。</p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                      <div><dt class="text-xs text-gray-500">累计投入</dt><dd class="mt-1 break-words text-gray-700" :title="money(project.summary?.invested_amount)">{{ compactMoney(project.summary?.invested_amount) }}</dd></div>
                      <div class="text-right"><dt class="text-xs text-gray-500">累计净收回</dt><dd class="mt-1 break-words text-gray-700" :title="money(project.summary?.net_recovered_amount)">{{ compactMoney(project.summary?.net_recovered_amount) }}</dd></div>
                    </dl>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4">
                      <p class="text-xs text-gray-500">{{ forecastBrief(project.summary?.forecast) }}</p>
                      <div class="flex gap-2"><button class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50" :disabled="saving || scenarioBusy || detailLoading || orderBusy" @click="selectProject(project.id)" :aria-label="'查看' + project.project_name + '台账'">查看台账</button><button v-if="!project.archived_at" class="min-h-[44px] rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" style="background:var(--sx-luxury-green,#143a31);color:white" :disabled="saving || scenarioBusy || detailLoading || orderBusy" @click="quickEntry(project.id, project.summary?.invested_amount == null ? 'investment' : 'recovery')" :aria-label="project.project_name + (project.summary?.invested_amount == null ? '补投入' : '记一笔')">{{ project.summary?.invested_amount == null ? '补投入' : '记一笔' }}</button></div>
                    </div>
                  </article>
                </div>
                <div v-if="hasMore" class="flex flex-wrap items-center gap-3 text-sm text-gray-500"><span>已载入 {{ projects.length }} / {{ totalProjects }} 个项目；当前仅汇总已载入且匹配查找的项目。</span><button class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50" :disabled="loading || saving || scenarioBusy || orderBusy" @click="loadMore">载入更多项目</button></div>
              </template>
              <p v-if="detailError" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ detailError }}</p>
              <article v-if="detail" class="min-w-0 space-y-5 rounded-xl border border-gray-200 bg-white p-4 sm:p-6" data-testid="investment-payback-detail">
                <button class="min-h-[44px] py-2 text-sm text-gray-500" :disabled="saving || scenarioBusy" @click="closeDetail">← 返回项目概览</button>
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div><h3 class="break-words text-xl font-semibold text-gray-900">{{ detail.project.project_name }}</h3><p class="mt-1 text-xs text-gray-500">{{ detail.project.investor_name }} · 截至 {{ summary.as_of }} · {{ stateLabel(summary.state) }}</p></div>
                  <div v-if="!detail.project.archived_at" class="flex flex-wrap gap-2"><button class="min-h-[44px] rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" style="background:var(--sx-luxury-green,#143a31);color:white" :disabled="saving || scenarioBusy" @click="beginEntry('recovery')">记一笔</button><button class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50" :disabled="saving || scenarioBusy" @click="beginEntry('investment')">追加投入</button><button class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50" :disabled="saving || scenarioBusy" @click="beginProject(detail.project)">编辑项目</button></div>
                </div>
                <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4"><div><dt class="text-xs text-gray-500">累计实际投入</dt><dd class="mt-1 break-words text-lg font-semibold">{{ money(summary.invested_amount) }}</dd></div><div><dt class="text-xs text-gray-500">累计净收回</dt><dd class="mt-1 break-words text-lg font-semibold">{{ money(summary.net_recovered_amount) }}</dd></div><div data-testid="payback-detail-balance"><dt class="text-xs text-gray-500">{{ paybackBalance(summary).label }}</dt><dd class="mt-1 break-words text-lg font-semibold">{{ paybackBalance(summary).covered ? '全部投入已收回' : money(paybackBalance(summary).amount) }}</dd></div><div><dt class="text-xs text-gray-500">回本进度</dt><dd class="mt-1 text-lg font-semibold">{{ summary.recovery_percent == null ? '待录入' : summary.recovery_percent + '%' }}</dd></div></dl>
                <div v-if="summary.recovery_percent != null" class="h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" aria-label="资金回收进度" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progressWidth(summary.recovery_percent)" :aria-valuetext="summary.recovery_percent + '%'"><div class="h-full rounded-full" style="background:var(--sx-luxury-green,#143a31)" :style="{width:progressWidth(summary.recovery_percent) + '%'}"></div></div>
                <p class="text-xs text-gray-500">{{ summary.data_quality?.history_complete ? '已核对账目覆盖本次截至日；金额仍为人工录入。' : '按已录入记录计算；账目尚未完整核对，未录入月份不补零。' }}</p>
                <p v-for="(issue,index) in (summary.data_quality?.issues || []).filter(item => (typeof item === 'string' ? item : item?.code) !== 'history_not_checked_through_cutoff')" :key="index" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">{{ issueText(issue) }}</p>
                <div class="rounded-xl bg-gray-50 p-4">
                  <div class="flex flex-wrap items-start justify-between gap-3"><div><h4 class="text-xs text-gray-500">预计剩余回本周期</h4><p class="mt-1 text-lg font-semibold text-gray-900">{{ forecastBrief(summary.forecast) }}</p><p v-if="summary.forecast?.payback_month" class="mt-1 text-xs text-gray-500">预计回本 {{ summary.forecast.payback_month }} · 基准日 {{ summary.forecast.base_date }}</p></div><p class="text-xs text-gray-500">每月净收回假设：{{ money(detail.project.expected_monthly_amount) }}</p></div>
                  <details class="mt-3 border-t border-gray-200 pt-2"><summary class="cursor-pointer py-2 text-sm text-gray-600">调整测算假设</summary>
                    <form class="space-y-3 pt-2" @submit.prevent="saveForecast"><div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <label class="text-sm text-gray-700">未来每月净收回（元）<input :name="'forecastForm-amount-' + (detail.project.id + '-' + detail.project.version)" autocomplete="off" :value="forecastForm.amount" @input="forecastForm.amount = $event.target.value" type="number" step="0.01" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2 min-h-[44px]" :disabled="saving || scenarioBusy || !!detail.project.archived_at" placeholder="可留空" /></label>
                      <label class="text-sm text-gray-700">预测依据<input :name="'forecastForm-source-' + (detail.project.id + '-' + detail.project.version)" autocomplete="off" :value="forecastForm.source" @input="forecastForm.source = $event.target.value" maxlength="255" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2 min-h-[44px]" :disabled="saving || scenarioBusy || !!detail.project.archived_at" /></label>
                    </div><p class="text-xs text-gray-500">{{ forecastLabel(summary.forecast?.status) }}<span v-if="summary.forecast?.remaining_months != null">：约 {{ summary.forecast.remaining_months }} 个月，按整月收回需 {{ summary.forecast.whole_months }} 个月。</span></p><p v-if="summary.forecast?.full_cycle_months != null" class="text-xs text-gray-500">从首次投入起，预计全周期约 {{ summary.forecast.full_cycle_months }} 个月。</p><p class="text-xs text-gray-500">假设以后每月等额净收回，期间没有新增出资或额外退款。预测不代表实际到账；未填写或非正值时不显示0个月。</p><button v-if="!detail.project.archived_at" class="min-h-[44px] rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" style="background:var(--sx-luxury-green,#143a31);color:white" :disabled="saving || scenarioBusy">保存测算假设</button></form>
                  </details>
                </div>
              <div class="border-t pt-4"><h4 class="font-semibold mb-3">资金明细</h4><p v-if="!detail.entries.length" class="text-sm text-gray-500">尚未录入资金明细；期初余额单独计入。点击“记投入”或“记收回”补充。</p><div v-else class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-gray-500 text-left"><th class="py-3 pr-3">日期</th><th class="py-3 pr-3">类型</th><th class="py-3 pr-3 text-right">金额</th><th class="py-3 pr-3">来源 / 状态</th><th class="py-3 pr-3">备注</th><th class="py-3">操作</th></tr></thead><tbody><tr v-for="entry in detail.entries" :key="entry.id" class="border-b"><td class="py-3 pr-3 whitespace-nowrap">{{ entry.date }}{{ entry.precision === 'month' ? '（月）' : '' }}</td><td class="py-3 pr-3 whitespace-nowrap">{{ kindLabel(entry.kind) }}</td><td class="py-3 pr-3 text-right whitespace-nowrap">{{ money(entry.amount) }}</td><td class="py-3 pr-3"><span :title="entry.source">{{ entrySource(entry) }}</span><span class="block text-xs text-gray-500">{{ entryState(entry) }}</span></td><td class="py-3 pr-3 min-w-[120px] break-words">{{ entry.void_reason || entry.notes || '—' }}</td><td class="py-3 whitespace-nowrap"><template v-if="!detail.project.archived_at"><template v-if="!entry.voided_at"><button class="text-green-800 px-2 py-2 min-h-[44px]" :disabled="saving || scenarioBusy" @click="beginEntry(entry.kind,entry)">编辑</button><button class="text-red-700 px-2 py-2 min-h-[44px]" :disabled="saving || scenarioBusy" @click="openConfirmation('void',entry)">作废</button></template><button v-if="detail.can_delete_entries" class="text-red-700 px-2 py-2 min-h-[44px]" :disabled="saving || scenarioBusy" @click="openConfirmation('delete',entry)" :aria-label="'删除' + entry.date + '的' + kindLabel(entry.kind) + '记录'">删除</button><span v-else-if="entry.voided_at" class="text-xs text-gray-500">历史保留</span></template><span v-else class="text-xs text-gray-500">历史保留</span></td></tr></tbody></table></div></div>
              <form v-if="confirmation.type && confirmation.type !== 'delete'" class="border border-amber-200 rounded-lg p-4 space-y-3" @submit.prevent="confirmAction"><p class="text-sm">{{ confirmation.type === 'archive' ? '归档后保留项目历史。' : '作废后保留记录历史，并重新计算回本汇总。' }}</p><label class="block text-sm">操作原因 *<input :name="'confirmation-reason-' + (detail.project.id + '-' + detail.project.version)" autocomplete="off" :value="confirmation.reason" @input="confirmation.reason = $event.target.value" required maxlength="255" class="mt-1 w-full border rounded-lg px-3 py-2" /></label><p v-if="formError" class="text-sm text-red-700" role="alert">{{ formError }}</p><div class="flex gap-3"><button type="button" class="border rounded-lg px-4 py-2" :disabled="saving || scenarioBusy" @click="confirmation.type = ''">取消操作</button><button class="border border-red-200 text-red-800 rounded-lg px-4 py-2" :disabled="saving || scenarioBusy">{{ confirmation.type === 'archive' ? '确认归档' : '确认作废' }}</button></div></form>

                <details class="border-t pt-3"><summary class="cursor-pointer py-2 text-sm text-gray-600">项目资料与核对信息</summary><div class="space-y-2 py-2 text-xs text-gray-500"><p>首次实际回本：{{ firstPaybackText }}</p><p v-if="Number(summary.excess_recovered_amount) > 0">超出投入的净收回金额：{{ money(summary.excess_recovered_amount) }}（不等于会计净利润）</p><p>营业额与账面利润不直接作为已回本金额。</p><div v-if="detail.project.opening_as_of" class="space-y-1"><p>期初累计余额 · 截至 {{ detail.project.opening_as_of }}</p><p>投入 {{ money(detail.project.opening_invested) }} · 净收回 {{ money(detail.project.opening_recovered) }}</p><p class="break-words">来源：{{ detail.project.opening_source }}；期初之前已包含的明细不再叠加。</p></div><p v-if="detail.project.notes" class="break-words">备注：{{ detail.project.notes }}</p><button v-if="!detail.project.archived_at" class="min-h-[44px] py-2 text-gray-500" :disabled="saving || scenarioBusy" @click="openConfirmation('archive')">归档项目</button></div></details>
                <details class="border-t pt-3" @toggle="$event.target.open && (scenarioOpened = true)"><summary class="cursor-pointer py-2 text-sm text-gray-600">经营测算与情景分析</summary><InvestmentScenarioWorkbench v-if="scenarioOpened" :key="detail.project.id" :request="request" :project="detail.project" :ledger-busy="saving" @saved="scenarioSaved" @busy-change="scenarioBusy = $event" /></details>
              <details v-if="monthlyRows.length" class="border-t pt-3"><summary class="cursor-pointer text-sm py-2">按月查看已录入账务</summary><p class="text-xs text-gray-500 my-2">仅汇总截至日内实际明细，期初余额单列于项目资料；未录入月份不补零。</p><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-gray-500 border-b"><th class="text-left py-2">月份</th><th class="text-right py-2">投入</th><th class="text-right py-2">净收回</th><th class="text-right py-2">笔数</th></tr></thead><tbody><tr v-for="row in monthlyRows" :key="row.month" class="border-b"><td class="py-3">{{ row.month }}</td><td class="text-right py-3 whitespace-nowrap">{{ row.investment === null ? '未录入' : money(row.investment) }}</td><td class="text-right py-3 whitespace-nowrap">{{ row.recovery === null ? '未录入' : money(row.recovery) }}</td><td class="text-right py-3">{{ row.count }}</td></tr></tbody></table></div></details>
              <details v-if="detail.audit_history?.length" class="border-t pt-3"><summary class="cursor-pointer text-sm py-2">变更记录与测算版本</summary><p class="text-xs text-gray-500 my-2">展示最近 {{ detail.audit_history_limit || 100 }} 条变更，保留修改前后金额和测算快照。</p><div v-for="audit in detail.audit_history" :key="audit.id" class="border-b py-3 text-xs text-gray-600 break-words"><p class="font-medium">{{ audit.created_at }} · {{ auditLabel(audit.event_type) }} · 项目版本 {{ audit.project_version }}</p><p class="mt-1">{{ auditChange(audit) }}</p><p v-if="audit.payload?.after?.void_reason || audit.payload?.after?.archive_reason || audit.payload?.after?.delete_reason" class="mt-1">原因：{{ audit.payload.after.void_reason || audit.payload.after.archive_reason || audit.payload.after.delete_reason }}</p><p v-if="audit.payload?.summary_after" class="mt-1">当时测算截至 {{ audit.payload.summary_after.as_of }}：累计投入 {{ money(audit.payload.summary_after.invested_amount) }} · 净收回 {{ money(audit.payload.summary_after.net_recovered_amount) }} · 未收回 {{ money(audit.payload.summary_after.unrecovered_amount) }}</p></div></details>
              </article>
            </div>
            <div v-if="confirmation.type === 'delete'" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-3 sm:p-6" @click.self="closeForms">
              <form role="dialog" aria-modal="true" aria-labelledby="payback-delete-title" data-testid="payback-delete-confirmation" class="max-h-[90vh] w-full max-w-md space-y-4 overflow-y-auto rounded-xl bg-white p-5 shadow-xl sm:p-6" @submit.prevent="confirmAction" @keydown="dialogKey">
                <h3 id="payback-delete-title" class="text-lg font-semibold text-gray-900">删除这笔资金记录？</h3>
                <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700"><p>{{ detail.project.project_name }}</p><p class="mt-1">{{ confirmation.entry.date }} · {{ kindLabel(confirmation.entry.kind) }} · {{ money(confirmation.entry.amount) }}</p><p v-if="confirmation.entry.voided_at" class="mt-1 text-xs text-gray-500">此记录已作废，不计入当前实际回本金额。</p></div>
                <p class="text-sm text-gray-600">删除后将从资金明细中移除，并重新计算回本汇总。删除前快照保留在变更记录中。</p>
                <details><summary class="cursor-pointer py-2 text-xs text-gray-500">删除说明（选填）</summary><label class="block text-sm text-gray-700">删除说明<input :name="'delete-reason-' + confirmation.entry.id + '-' + confirmation.entry.version" autocomplete="off" :value="confirmation.reason" @input="confirmation.reason = $event.target.value" maxlength="255" :disabled="saving" class="mt-1 w-full rounded-lg border px-3 py-2 min-h-[44px]" placeholder="默认记录为管理员主动删除" /></label></details>
                <p v-if="formError" role="alert" class="text-sm text-red-700">{{ formError }}</p>
                <div class="flex justify-end gap-3 border-t pt-4"><button type="button" data-delete-cancel class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm text-gray-700" :disabled="saving || scenarioBusy" @click="closeForms">取消</button><button class="min-h-[44px] rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-800" :disabled="saving || scenarioBusy">{{ saving ? '删除中…' : '确认删除' }}</button></div>
              </form>
            </div>
            <div v-if="projectForm" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/30 p-3 sm:p-6" @click.self="closeForms">
              <form :key="projectForm.client_request_id || ('project-' + projectForm.id + '-' + projectForm.version)" role="dialog" aria-modal="true" aria-labelledby="payback-project-dialog-title" autocomplete="off" class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-5 shadow-xl sm:p-6" @submit.prevent="saveProject" @keydown="dialogKey">
                <div class="mb-5 flex items-start justify-between gap-3"><div><h3 id="payback-project-dialog-title" class="text-lg font-semibold text-gray-900">{{ projectForm.id ? '编辑项目' : '新建投资项目' }}</h3><p class="mt-1 text-xs text-gray-500">{{ projectForm.id ? '日常新增投入或收回，请用“记一笔”。' : '可一次录入当前累计余额，也可先只填写名称。' }}</p></div><button type="button" class="min-h-[44px] px-3 text-gray-500" :disabled="saving || scenarioBusy" @click="closeForms" aria-label="关闭项目表单">✕</button></div>
                <fieldset :disabled="saving || scenarioBusy" class="min-w-0 space-y-4">
                  <label class="block text-sm text-gray-700">项目名称 *<input :name="'projectForm-project_name-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.project_name" @input="projectForm.project_name = $event.target.value" required maxlength="120" placeholder="酒店或项目名称" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
                  <div v-if="!projectForm.id" class="rounded-lg bg-gray-50 p-4"><p class="mb-3 text-sm font-medium text-gray-700">当前累计余额 <span class="text-xs font-normal text-gray-500">（选填）</span></p><div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
 <label class="block text-sm text-gray-700">余额截至日<input :name="'projectForm-opening_as_of-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.opening_as_of" @input="projectForm.opening_as_of = $event.target.value" type="date" :max="today" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
 <label class="block text-sm text-gray-700">累计投入（元）<input :name="'projectForm-opening_invested-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.opening_invested" @input="projectForm.opening_invested = $event.target.value" type="number" min="0" step="0.01" placeholder="可暂不填写" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
 <label class="block text-sm text-gray-700">累计净收回（元）<input :name="'projectForm-opening_recovered-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.opening_recovered" @input="projectForm.opening_recovered = $event.target.value" type="number" step="0.01" placeholder="尚未收回请填写 0" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
</div><p class="mt-3 text-xs text-gray-500">累计投入和净收回一起填；没有收回请填 0。此日期之前的金额已包含，后续只记录新增资金。</p></div>
                  <label class="block text-sm text-gray-700">预计每月净收回（元，选填）<input :name="'projectForm-expected_monthly_amount-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.expected_monthly_amount" @input="projectForm.expected_monthly_amount = $event.target.value" type="number" step="0.01" placeholder="填写后测算剩余周期" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
                  <details class="border-t pt-2"><summary class="cursor-pointer py-2 text-sm text-gray-500">更多项目资料与核对信息</summary><div class="grid grid-cols-1 gap-3 pt-3 sm:grid-cols-2">
                    <label class="block text-sm text-gray-700">投资主体<input :name="'projectForm-investor_name-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.investor_name" @input="projectForm.investor_name = $event.target.value" required maxlength="120" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
                    <label class="text-sm text-gray-700">关联酒店<select v-model="projectForm.hotel_id" :disabled="!!projectForm.id" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2 min-h-[44px]"><option value="">暂不关联酒店</option><option v-for="hotel in hotels" :key="hotel.id" :value="hotel.id">{{ hotel.name }}</option></select></label>
                    <label class="text-sm text-gray-700">项目业务状态<select v-model="projectForm.status" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2 min-h-[44px]"><option value="draft">草稿</option><option value="preparing">筹备中</option><option value="operating">经营中</option><option value="exited">已退出</option></select></label>
                    <label class="block text-sm text-gray-700">预测依据<input :name="'projectForm-expected_source-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.expected_source" @input="projectForm.expected_source = $event.target.value" maxlength="255" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
                    <label class="block text-sm text-gray-700">首次实际投入日期<input :name="'projectForm-first_invested_on-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.first_invested_on" @input="projectForm.first_invested_on = $event.target.value" type="date" :max="today" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /><span class="mt-1 block text-xs text-gray-500">不确定可留空，系统依据实际记录判断。</span></label>
                    <label class="block text-sm text-gray-700">账目已核对至<input :name="'projectForm-history_complete_through-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.history_complete_through" @input="projectForm.history_complete_through = $event.target.value" type="date" :max="today" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /><span class="mt-1 block text-xs text-gray-500">仅在该日期之前所有投入与收回已完整核对时填写。</span></label>
                    <label class="block text-sm text-gray-700">累计余额来源<input :name="'projectForm-opening_source-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.opening_source" @input="projectForm.opening_source = $event.target.value" maxlength="255" placeholder="默认：人工录入累计余额" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
                  </div><details v-if="projectForm.id" class="mt-3 border-t pt-2"><summary class="cursor-pointer py-2 text-sm text-gray-500">期初累计余额</summary><p class="mb-3 text-xs text-gray-500">四项一起填写；期初之前已包含的资金不再叠加，首次回本日期不能由余额证明。</p><div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
 <label class="block text-sm text-gray-700">余额截至日<input :name="'projectForm-opening_as_of-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.opening_as_of" @input="projectForm.opening_as_of = $event.target.value" type="date" :max="today" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
 <label class="block text-sm text-gray-700">累计投入（元）<input :name="'projectForm-opening_invested-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.opening_invested" @input="projectForm.opening_invested = $event.target.value" type="number" min="0" step="0.01" placeholder="可暂不填写" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
 <label class="block text-sm text-gray-700">累计净收回（元）<input :name="'projectForm-opening_recovered-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.opening_recovered" @input="projectForm.opening_recovered = $event.target.value" type="number" step="0.01" placeholder="尚未收回请填写 0" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
</div></details><label class="mt-3 block text-sm text-gray-700">项目备注<textarea :name="'projectForm-notes-' + (projectForm.client_request_id || projectForm.id)" autocomplete="off" :value="projectForm.notes" @input="projectForm.notes = $event.target.value" rows="2" maxlength="2000" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2"></textarea></label></details>
                </fieldset>
                <p v-if="formError" class="mt-4 text-sm text-red-700" role="alert">{{ formError }}</p>
                <div class="mt-5 flex justify-end gap-3 border-t pt-4"><button type="button" class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50" :disabled="saving || scenarioBusy" @click="closeForms">取消</button><button class="min-h-[44px] rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" style="background:var(--sx-luxury-green,#143a31);color:white" :disabled="saving || scenarioBusy">{{ saving ? '保存中…' : '保存项目' }}</button></div>
              </form>
            </div>
            <div v-if="entryForm" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/30 p-3 sm:p-6" @click.self="closeForms">
              <form :key="entryForm.client_request_id || ('entry-' + entryForm.id + '-' + entryForm.version)" role="dialog" aria-modal="true" aria-labelledby="payback-entry-dialog-title" autocomplete="off" class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-5 shadow-xl sm:p-6" @submit.prevent="saveEntry" @keydown="dialogKey">
                <div class="mb-5 flex items-start justify-between gap-3"><div><h3 id="payback-entry-dialog-title" class="text-lg font-semibold text-gray-900">{{ entryForm.id ? '编辑资金记录' : '记一笔' }}</h3><p class="mt-1 break-words text-xs text-gray-500">{{ detail.project.project_name }} · 投资人实收</p></div><button type="button" class="min-h-[44px] px-3 text-gray-500" :disabled="saving || scenarioBusy" @click="closeForms" aria-label="关闭资金表单">✕</button></div>
                <fieldset :disabled="saving || scenarioBusy" class="min-w-0 space-y-4">
                  <label class="block text-sm text-gray-700">资金类型<select v-model="entryForm.kind" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2 min-h-[44px]"><option value="recovery">实际收回</option><option value="investment">投入</option><option value="refund">退款 / 冲回（减少收回）</option></select></label>
                  <label class="block text-sm text-gray-700">金额（元） *<input :name="'entryForm-amount-' + (entryForm.client_request_id || entryForm.id)" autocomplete="off" :value="entryForm.amount" @input="entryForm.amount = $event.target.value" type="number" min="0" step="0.01" required placeholder="填写实际金额" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
                  <label class="block text-sm text-gray-700">发生日期 / 月份 *<input :name="'entryForm-date-' + (entryForm.client_request_id || entryForm.id)" autocomplete="off" :value="entryForm.date" @input="entryForm.date = $event.target.value" :type="entryForm.precision === 'month' ? 'month' : 'date'" required class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2 min-h-[44px]" /></label>
                  <label v-if="entryForm.kind === 'recovery' && entryForm.amount !== '' && Number(entryForm.amount) === 0" class="flex items-start gap-2 text-sm text-gray-600"><input v-model="entryForm.confirmed_zero" type="checkbox" class="mt-1" />本期已核对，实际收回 0 元</label>
                  <label v-if="entryForm.kind === 'refund'" class="block text-sm text-gray-700">退款来源与原因 *<textarea :name="'refund-notes-' + (entryForm.client_request_id || entryForm.id)" autocomplete="off" :value="entryForm.notes" @input="entryForm.notes = $event.target.value" required maxlength="2000" rows="2" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2"></textarea></label>
                  <details class="border-t pt-2"><summary class="cursor-pointer py-2 text-sm text-gray-500">备注、来源与其他选项</summary><div class="space-y-3 pt-3">
                    <label class="block text-sm text-gray-700">日期精度<select v-model="entryForm.precision" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2 min-h-[44px]" @change="changePrecision"><option value="day">实际日期</option><option value="month">仅知道业务月份</option></select></label>
                    <label class="block text-sm text-gray-700">来源说明<input :name="'entryForm-source-' + (entryForm.client_request_id || entryForm.id)" autocomplete="off" :value="entryForm.source" @input="entryForm.source = $event.target.value" required maxlength="255" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
                    <label class="block text-sm text-gray-700">资金类别<input :name="'entryForm-category-' + (entryForm.client_request_id || entryForm.id)" autocomplete="off" :value="entryForm.category" @input="entryForm.category = $event.target.value" maxlength="80" class="mt-1 block min-h-[44px] w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2" /></label>
                    <label v-if="entryForm.kind === 'refund'" class="block text-sm text-gray-700">关联原收回记录（选填）<select v-model="entryForm.original_entry_id" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2 min-h-[44px]"><option value="">未关联，独立登记冲回</option><option v-for="receipt in detail.entries.filter(row => row.kind === 'recovery' && !row.voided_at && !row.is_planned && Number(row.amount) > 0)" :key="receipt.id" :value="receipt.id">{{ receipt.date }} · {{ money(receipt.amount) }} · {{ receipt.source }}</option></select><span class="mt-1 block text-xs text-gray-500">关联后校验退款累计不能超过原收回金额。</span></label>
                    <label class="flex gap-2 text-sm text-gray-700"><input v-model="entryForm.is_planned" type="checkbox" />计划金额，尚未实际发生</label>
                    <label v-if="entryForm.kind !== 'refund'" class="block text-sm text-gray-700">备注<textarea :name="'entryForm-notes-' + (entryForm.client_request_id || entryForm.id)" autocomplete="off" :value="entryForm.notes" @input="entryForm.notes = $event.target.value" maxlength="2000" rows="2" class="mt-1 w-full min-w-0 rounded-lg border px-3 py-2"></textarea></label>
                  </div></details>
                  <p class="text-xs text-gray-500">{{ entryForm.is_planned ? '计划金额不计入实际回本。' : '仅记录投资人实际出资或收到的钱。' }}</p>
                </fieldset>
                <p v-if="formError" class="mt-4 text-sm text-red-700" role="alert">{{ formError }}</p>
                <div class="mt-5 flex justify-end gap-3 border-t pt-4"><button type="button" class="min-h-[44px] rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50" :disabled="saving || scenarioBusy" @click="closeForms">取消</button><button class="min-h-[44px] rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" style="background:var(--sx-luxury-green,#143a31);color:white" :disabled="saving || scenarioBusy">{{ saving ? '保存中…' : '保存记录' }}</button></div>
              </form>
            </div>
            <investment-payback-import v-if="importOpened" :request="request" :projects="projects.filter(item => !item.archived_at)" :project="importProject" :today="today" @close="importOpened=false" @saved="importSaved" />
          </section>
        `,
    };
})();
