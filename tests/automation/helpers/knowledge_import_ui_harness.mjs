import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
export const files = { main: 'public/app-main.js', domain: 'public/components/system/knowledge-center-domain.js', system: 'public/system-static.js', page: 'resources/frontend/templates/fragments/20-page-knowledge-center.html', dialog: 'resources/frontend/templates/fragments/38-dialogs-knowledge-center.html' };
export const raw = Object.fromEntries(Object.entries(files).map(([key, path]) => [key, readFileSync(path, 'utf8')]));
export const sourceHashes = Object.fromEntries(Object.entries(files).map(([key, path]) => [path, createHash('sha256').update(raw[key]).digest('hex').toUpperCase()]));
const main = raw.main.replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a); return main.slice(a, b); };
const requestSource = [section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='), section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='), section('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='), section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'), section('            const request = async (', '            const apiRequest = request;')].join('\n');
const tagSource = section('            const parseKnowledgeTags =', '            const KNOWLEDGE_CENTER_DISPLAY_LABELS =');
const astWalk = (node, result = []) => { result.push(node); (node.children || []).forEach(child => astWalk(child, result)); return result; };
const pageRoot = parse(raw.page).children.find(node => node.type === 1);
const header = pageRoot.children.find(node => node.type === 1 && node.tag === 'div');
const modal = parse(raw.dialog).children.find(node => node.type === 1 && node.props.some(prop => prop.name === 'if' && prop.exp?.content === 'showKnowledgeCenterImportModal'));
const table = astWalk(pageRoot).find(node => node.type === 1 && node.tag === 'table' && node.loc.source.includes('unit in knowledgeCenterUnits'));
const refreshButton = astWalk(pageRoot).find(node => node.type === 1 && node.tag === 'button' && node.props.some(prop => prop.name === 'on' && prop.exp?.content === 'loadKnowledgeCenter'));
assert.ok(header && modal && table);
const compileRender = text => new Function('Vue', compile(text, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const render = compileRender('<section>' + header.loc.source + refreshButton.loc.source + modal.loc.source + table.loc.source + '</section>');
export const clone = value => JSON.parse(JSON.stringify(value));
export const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
export function harness() {
  const requests = [], notices = [], timers = [];
  const fetchSynthetic = (url, options = {}) => new Promise((resolve, reject) => {
    const parsed = new URL(url);
    assert.equal(parsed.origin, 'https://synthetic.invalid');
    assert.ok(/^\/api\/knowledge\/(import|document-text|list|\d+)$/.test(parsed.pathname), 'Closed synthetic allowlist: ' + parsed.pathname);
    const req = { url, options, resolve, reject, settled: false }; requests.push(req);
    options.signal?.addEventListener('abort', () => { if (!req.settled) { req.settled = true; reject(new DOMException('Synthetic timeout', 'AbortError')); } }, { once: true });
  });
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, File, AbortController, DOMException, structuredClone, Date, Intl, setTimeout, clearTimeout,
    console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 0,
    currentPage: Vue.ref('knowledge-center'), filterReportHotel: Vue.ref('80'),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 80, name: 'Synthetic A', tenant_id: 7 }, { id: 81, name: 'Synthetic B', tenant_id: 7 }]), hotels: Vue.ref([]),
    user: Vue.ref({ id: 11, is_super_admin: true }), token: Vue.ref(''), revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: sandbox.authSessionEpoch, token: '' }), isAuthSessionCurrent: session => session.epoch === sandbox.authSessionEpoch,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    showToast: (message, type = 'success') => notices.push({ message, type }), fetch: fetchSynthetic,
  };
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = name => sandbox.appSystemStatic[name];
  vm.runInContext(requestSource + tagSource + '\nglobalThis.actualRequest=request;globalThis.actualParseTags=parseKnowledgeTags;', sandbox);
  const window = {};
  vm.runInNewContext(raw.domain, { window, URLSearchParams, Date, Math, AbortController, FormData, TextDecoder, fetch: fetchSynthetic,
    setTimeout: (fn, ms) => { assert.equal(ms, 90000); const timer = { fn, active: true }; timers.push(timer); return timer; },
    clearTimeout: timer => { timer.active = false; },
  });
  const defaults = {
    knowledgeCenterForm: {}, knowledgeCenterChunkForm: {}, knowledgeCenterSelectedUnit: null, knowledgeCenterChunks: [],
    showKnowledgeCenterUnitModal: false, showKnowledgeCenterChunksModal: false, showKnowledgeCenterImportModal: false,
    knowledgeCenterImportForm: { mode: 'text', source: 'text', hotel_id: '', model_key: 'deepseek_chat', tags: '', raw: '' },
    knowledgeCenterImporting: false, knowledgeCenterImportReading: false, knowledgeCenterImportDocumentNotice: '', knowledgeCenterImportDocumentError: '',
    knowledgeCenterImportSelectedFile: null, knowledgeCenterImportSourceDocument: null, knowledgeCenterImportPreviewRaw: '',
    knowledgeDocumentFileInput: null, knowledgeDocumentTextarea: null, knowledgeCenterLoading: false, knowledgeCenterListError: '', knowledgeCenterFilter: {},
    knowledgeCenterPagination: { total: 0, page: 1, page_size: 10, total_page: 1 }, knowledgeCenterUnits: [], selectedKnowledgeCenterUnitIds: [],
  };
  const state = Object.fromEntries(Object.entries(defaults).map(([key, value]) => [key, Vue.ref(value)]));
  const statics = sandbox.appSystemStatic;
  const methods = window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({ ...state, currentPage: sandbox.currentPage, computed: Vue.computed,
    request: sandbox.actualRequest, API_BASE: sandbox.API_BASE, captureAuthSession: sandbox.captureAuthSession, isAuthSessionCurrent: sandbox.isAuthSessionCurrent,
    clearAuthSessionIfCurrent: session => { if (sandbox.isAuthSessionCurrent(session)) sandbox.authSessionEpoch++; },
    buildKnowledgeImportRequestBody: statics.buildKnowledgeImportRequestBody, knowledgeImportErrorMessage: statics.knowledgeImportErrorMessage,
    knowledgeImportSuccessMessage: statics.knowledgeImportSuccessMessage, parseKnowledgeTags: sandbox.actualParseTags,
    defaultKnowledgeCenterHotelId: () => '80', defaultKnowledgeExperienceChunk: () => '{}', requireSystemStatic: name => statics[name], showToast: sandbox.showToast,
  });
  let vnode;
  const context = { ...state, ...methods, knowledgeCenterHotelOptions: [{ id: 80, name: 'Synthetic A' }, { id: 81, name: 'Synthetic B' }],
    availableAiModelOptions: [{ value: 'deepseek_chat', label: 'Synthetic selected model' }], knowledgeCenterTagGroups: () => ({ business: [], boundary: [] }),
    getHotelNameById: id => 'Synthetic ' + id, knowledgeCenterStatusClass: () => '', knowledgeCenterStatusLabel: value => value, knowledgeCenterReadinessClass: () => '',
  };
  const html = async () => renderToString(Vue.createSSRApp({ setup: () => context, render() { vnode = render.call(this, this, []); return vnode; } }));
  const walk = (node, result = []) => { if (Array.isArray(node)) { node.forEach(child => walk(child, result)); return result; } if (!node || typeof node !== 'object') return result; result.push(node); walk(node.children, result); return result; };
  const nodes = () => walk(vnode);
  const text = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(text).join('') : node?.children ? text(node.children) : '';
  const findButton = label => nodes().find(node => node.type === 'button' && text(node).trim() === label);
  const open = async () => {
    assert.equal(state.showKnowledgeCenterImportModal.value, false, 'Header is not clicked through a modal overlay'); await html();
    const button = findButton('粘贴文档'); assert.ok(button && !button.props?.disabled); await button.props.onClick(); await html();
  };
  const model = async (tag, index, value) => { await html(); const node = nodes().filter(node => node.type === tag && node.props?.['onUpdate:modelValue'])[index]; assert.ok(node && !node.props.disabled && !node.props.readonly, 'Only enabled actual form model control'); node.props['onUpdate:modelValue'](value); await html(); };
  const submit = async () => {
    await html(); const button = nodes().find(node => node.type === 'button' && node.props?.type === 'submit'); assert.ok(button && !button.props.disabled, 'Only enabled actual submit control');
    const form = nodes().find(node => node.type === 'form'); const pending = form.props.onSubmit({ preventDefault() {} }); await tick(); return { pending, req: requests.findLast(req => new URL(req.url).pathname === '/api/knowledge/import' && !req.settled) };
  };
  const close = async () => { await html(); const button = findButton('取消'); assert.ok(button && !button.props.disabled, 'Only enabled cancel button'); button.props.onClick(); await html(); };
  const pending = path => requests.find(req => !req.settled && new URL(req.url).pathname === '/api/knowledge/' + path);
  const reply = (req, body, status = 200) => { assert.ok(req && !req.settled); req.settled = true; req.response = clone(body); req.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); };
  return { state, methods, sandbox, requests, notices, timers, html, nodes, findButton, text, open, model, submit, close, pending, reply };
}

// Original PHP public preview/parser output over a tiny synthetic workbook; no parser or network runs here.
export function syntheticWorkbookPreview() {
  const body = {"code":0,"data":{"filename":"normal.xlsx","extension":"xlsx","text":"Excel 工作簿：normal.xlsx\n\n来源指纹：sha256:40ba1869a361dec0ae486db5ffc0d87851d0616e846cce7cf0a7a79362a441a3\n\n工作表：Synthetic表\n合并单元格：A1:B1\n第 1 行：A1：Synthetic 文档 | B1：0","char_count":156,"sha256":"40ba1869a361dec0ae486db5ffc0d87851d0616e846cce7cf0a7a79362a441a3","source_document":{"filename":"normal.xlsx","extension":"xlsx","sha256":"40ba1869a361dec0ae486db5ffc0d87851d0616e846cce7cf0a7a79362a441a3","text_sha256":"bf00196badb74dddca52a79865029cf00bb6481ebdbac6a4d5b162005d8d9fc8","char_count":156,"sheets":[{"name":"Synthetic表","row_count":1,"cell_count":2,"cell_refs":["A1","B1"],"cell_refs_truncated":false,"merged_ranges":["A1:B1"]}]}},"msg":"extracted"};
  const file = new File([Buffer.from("UEsDBBQAAgAIAFVcL123inW1nAAAAOoAAAAPAAAAeGwvd29ya2Jvb2sueG1sjY8xDsIwDEWvUvkAuGVgqNpOLMycIKQuidrEkR0EHIeNo3EMqpbuTLb19J/1mzvLeGEei0eYorbgck41olpHweiOE8WZDCzB5PmUK2oSMr06ohwm3JflAYPxEVZDLf84eBi8pSPbW6CYV4nQZLLnqM4nha5ZPuhvFtEEauH8jNlR9vbzekOxkFPfQgWF1H5e5NRXgF2DWxi3ft0XUEsDBBQAAgAIAFVcL122VKrPjgAAAPEAAAAaAAAAeGwvX3JlbHMvd29ya2Jvb2sueG1sLnJlbHONzz0OwjAMBeCrVDlA3TIwoCYTS1fEBaLUbaI2P7KNgNsTMaAiMTBZfpa+Jw8X3KyEnNiHws0jbom18iLlBMDOY7Tc5oKpXuZM0UpdaYFi3WoXhEPXHYH2hjLD3mzGSSsap14112fBf+w8z8HhObtbxCQ/KuCeaWWPKBW1tKBo9YkY3qNvq6rADPD1oXkBUEsDBBQAAgAIAFVcL10CMtl4vAAAAA4BAAAYAAAAeGwvd29ya3NoZWV0cy9zaGVldDEueG1sTZDLjQIxDIZbiVLAetgDB5SJBEsHU0EUDBORx8gxrwrogDuiN+rAA4jlYtmf/f+2bA6FtrVHZHVMMddW98zDDKD6HpOrP2XALJ11oeRYStpAHQjd6ilKEX6bZgrJhaytebKlY2cNlYOiVk+E+jGZT7TiVoccQ8aOSXio1rDtTpl75ODV/XK+X28G2BoYe+Df2sXosreNgf2LgrhL/FqXkDb4hzFW5csu82vzhyrC9XjDTKxAlP/jUnxeYB9QSwECPwMUAAIACABVXC9dt4p1tZwAAADqAAAADwAAAAAAAAAAAAAAtoEAAAAAeGwvd29ya2Jvb2sueG1sUEsBAj8DFAACAAgAVVwvXbZUqs+OAAAA8QAAABoAAAAAAAAAAAAAALaByQAAAHhsL19yZWxzL3dvcmtib29rLnhtbC5yZWxzUEsBAj8DFAACAAgAVVwvXQIy2Xi8AAAADgEAABgAAAAAAAAAAAAAALaBjwEAAHhsL3dvcmtzaGVldHMvc2hlZXQxLnhtbFBLBQYAAAAAAwADAMsAAACBAgAAAAA=", "base64")], body.data.filename);
  return { file, body, text: body.data.text, sourceDocument: body.data.source_document };
}
