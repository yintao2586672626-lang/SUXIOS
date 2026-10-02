import assert from 'node:assert/strict';
import test, { after } from 'node:test';
import { harness, tick, syntheticWorkbookPreview } from './helpers/knowledge_import_ui_harness.mjs';
import { documentPreview, documentReceipt, answerPreview, assertOriginalUpload, finishDocumentImport } from './helpers/knowledge_document_roundtrip.mjs';

let assertions = 0;
const eq = (actual, expected, label) => { assertions++; assert.deepEqual(actual, expected, label); };
const ok = (value, label) => { assertions++; assert.ok(value, label); };
const fixture = syntheticWorkbookPreview();
async function choose(p, files) {
  await p.html();
  const input = p.nodes().find(node => node.type === 'input' && node.props?.type === 'file');
  ok(input && !input.props.disabled, 'Only enabled original file input');
  const target = { files, value: files.length ? 'synthetic-selected-file' : '' };
  const pending = input.props.onChange({ target }); await tick(); return { pending, target };
}
async function workbook(p) {
  const event = await choose(p, [fixture.file]); const req = p.pending('document-text'); ok(req);
  p.reply(req, fixture.body); await event.pending;
  eq(event.target.value, ''); eq(p.state.knowledgeCenterImportSelectedFile.value, fixture.file);
  ok((await p.html()).includes('XLSX 来源已锁定'));
  ok((await p.html()).includes('Synthetic表'));
}
async function editable(p) {
  await p.html(); eq(p.state.knowledgeCenterImportReading.value, false);
  eq(p.findButton('取消').props.disabled, false); eq(p.findButton('选择文档').props.disabled, false);
}

for (const extension of ['txt', 'docx']) test(`replacing a previewed XLSX with ${extension} locks the replacement source and restores exact document submission`, async () => {
  const p = harness(); await p.open(); await p.model('input', 0, 'keep-tag'); await workbook(p);
  const replacement = 'Replacement B only\nLiteral zero: 0';
  const preview = await documentPreview(new File([replacement], 'replacement.' + extension), replacement);
  const event = await choose(p, [preview.file]);
  await answerPreview(p, event, preview); await editable(p);
  const html = await p.html();
  eq(p.state.knowledgeCenterImportForm.value.raw, replacement);
  eq(p.state.knowledgeCenterImportForm.value.mode, extension); eq(p.state.knowledgeCenterImportForm.value.source, 'manual_template');
  eq(p.state.knowledgeCenterImportSelectedFile.value, preview.file); eq(JSON.stringify(p.state.knowledgeCenterImportSourceDocument.value), JSON.stringify(preview.sourceDocument)); eq(p.state.knowledgeCenterImportPreviewRaw.value, replacement);
  eq(p.nodes().find(node => node.type === 'textarea').props.readonly, true);
  ok(html.includes('replacement.' + extension)); ok(html.includes(extension.toUpperCase() + ' 来源已锁定')); ok(!html.includes('XLSX 来源已锁定')); ok(!html.includes('工作表未知')); ok(!html.includes(fixture.sourceDocument.sha256));
  eq(p.state.knowledgeCenterImportForm.value.hotel_id, '80'); eq(p.state.knowledgeCenterImportForm.value.tags, 'keep-tag');
  const sent = await p.submit(); await assertOriginalUpload(sent, preview);
  eq(JSON.parse(sent.req.options.body.get('tags')), ['keep-tag']);
  p.reply(sent.req, { code: 422, msg: 'Synthetic explicit import rejection', data: null }, 422); await sent.pending;
  eq(p.state.showKnowledgeCenterImportModal.value, true); eq(p.state.knowledgeCenterImportForm.value.raw, replacement);
  eq(p.state.knowledgeCenterImporting.value, false);
  await finishDocumentImport(p, await p.submit(), preview, { importCount: 2 });
});

test('multiple files are explicitly rejected without losing a pasted draft; editable clear permits one clean document', async () => {
  const p = harness(); await p.open(); await p.model('textarea', 0, 'Pasted A');
  const event = await choose(p, [new File(['Text B'], 'B.txt'), new File(['Text C'], 'C.md')]); await event.pending;
  eq(p.state.knowledgeCenterImportForm.value.raw, 'Pasted A');
  ok(p.state.knowledgeCenterImportDocumentError.value.includes('每次选择一个文件以保留原文件指纹'));
  eq(p.state.knowledgeCenterImportForm.value.mode, 'document'); eq(p.state.knowledgeCenterImportForm.value.source, 'document');
  eq(p.requests.length, 0);
  await p.model('textarea', 0, ''); const cleared = await p.submit(); await cleared.pending;
  eq(cleared.req, undefined); ok(p.notices.at(-1).message.includes('请输入导入内容'));
  const preview = await documentPreview(new File(['Fresh D'], 'D.txt'), 'Fresh D');
  const fresh = await choose(p, [preview.file]); await answerPreview(p, fresh, preview);
  eq(p.state.knowledgeCenterImportForm.value.raw, 'Fresh D');
  await finishDocumentImport(p, await p.submit(), preview);
});

for (const [name, files, error] of [
  ['empty local file', [new File([' \n\t'], 'empty.txt')], '未解析到文字内容'],
  ['oversize document', [new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'large.docx')], '超过 5MB'],
  ['workbook combined with text', [fixture.file, new File(['B'], 'B.txt')], '每次选择一个文件以保留原文件指纹'],
]) test(`${name} preserves the entire XLSX preview and allows explicit reselection`, async () => {
  const p = harness(); await p.open(); await workbook(p);
  const old = { ...p.state.knowledgeCenterImportForm.value }, source = p.state.knowledgeCenterImportSourceDocument.value;
  const failed = await choose(p, files);
  if (name === 'empty local file') p.reply(p.pending('document-text'), { code: 0, data: { text: '' } });
  await failed.pending;
  await editable(p); eq(p.requests.length, name === 'empty local file' ? 2 : 1); eq(failed.target.value, '');
  eq({ ...p.state.knowledgeCenterImportForm.value }, old); eq(p.state.knowledgeCenterImportSelectedFile.value, fixture.file);
  eq(p.state.knowledgeCenterImportSourceDocument.value, source); eq(p.state.knowledgeCenterImportPreviewRaw.value, fixture.text);
  ok((await p.html()).includes(error)); eq(p.nodes().find(node => node.type === 'textarea').props.readonly, true);
  const preview = await documentPreview(new File(['Recovered text'], 'recover.txt'), 'Recovered text');
  const retry = await choose(p, [preview.file]); await answerPreview(p, retry, preview);
  eq(p.state.knowledgeCenterImportForm.value.raw, 'Recovered text'); eq(p.state.knowledgeCenterImportDocumentError.value, '');
});

test('pending preview disables actual controls and failed replacement unlocks without losing the prior source', async () => {
  const p = harness(); await p.open(); await workbook(p);
  const before = { ...p.state.knowledgeCenterImportForm.value }, source = p.state.knowledgeCenterImportSourceDocument.value;
  const next = await choose(p, [new File(['broken archive'], 'broken.xlsx')]); const req = p.pending('document-text'); ok(req);
  await p.html();
  for (const label of ['取消', '选择文档', '粘贴正文']) eq(p.findButton(label).props.disabled, true);
  const inputs = p.nodes().filter(node => ['input', 'textarea', 'select'].includes(node.type) && node.props?.disabled !== undefined);
  ok(inputs.length >= 5 && inputs.every(node => node.props.disabled === true));
  eq(p.nodes().find(node => node.type === 'button' && node.props?.type === 'submit').props.disabled, true);
  const close = p.nodes().find(node => node.type === 'button' && p.text(node).trim() === ''); eq(close.props.disabled, true);
  const drop = p.nodes().find(node => node.type === 'div' && node.props?.onDrop);
  await drop.props.onDrop({ preventDefault() {}, dataTransfer: { files: [new File(['Should be ignored'], 'B.txt')], getData: () => '' } });
  eq(p.requests.length, 2); eq({ ...p.state.knowledgeCenterImportForm.value }, before);
  p.reply(req, { code: 422, msg: 'DOCX/XLSX 文件结构无效', data: null }, 422); await next.pending; await editable(p);
  eq(p.state.knowledgeCenterImportSelectedFile.value, fixture.file); eq(p.state.knowledgeCenterImportSourceDocument.value, source);
  eq(p.state.knowledgeCenterImportPreviewRaw.value, fixture.text); eq({ ...p.state.knowledgeCenterImportForm.value }, before);
  const same = await choose(p, [fixture.file]); p.reply(p.pending('document-text'), fixture.body); await same.pending;
  eq(p.state.knowledgeCenterImportDocumentError.value, ''); eq(p.state.knowledgeCenterImportSelectedFile.value, fixture.file);
  const sent = await p.submit(); ok(sent.req.options.body instanceof FormData); eq(sent.req.options.body.get('file').name, fixture.file.name);
  p.reply(sent.req, { code: 500, msg: 'Synthetic unknown import outcome', data: null }, 500); await sent.pending;
  eq(p.state.knowledgeCenterImportSelectedFile.value, fixture.file); eq(p.state.knowledgeCenterImportPreviewRaw.value, fixture.text);
});

test('current HTTP or transport preview failures keep ordinary draft editable and do not automatically retry', async () => {
  const p = harness(); await p.open(); await p.model('textarea', 0, 'Original draft');
  for (const kind of ['http500', 'transport', 'empty']) {
    const read = await choose(p, [new File(['Synthetic bytes'], 'file.docx')]); const req = p.pending('document-text'); ok(req);
    if (kind === 'transport') { req.settled = true; req.reject(new TypeError('Synthetic network read failure')); }
    else p.reply(req, kind === 'empty' ? { code: 0, data: { text: '' } } : { code: 500, msg: 'Synthetic preview failure' }, kind === 'empty' ? 200 : 500);
    await read.pending; await editable(p); eq(p.state.knowledgeCenterImportForm.value.raw, 'Original draft');
    ok(p.state.knowledgeCenterImportDocumentError.value); eq(p.state.knowledgeCenterImportSelectedFile.value, null);
  }
  eq(p.requests.length, 3); eq(p.requests.filter(req => req.url.includes('/import')).length, 0);
  const preview = await documentPreview(new File(['B'], 'B.txt'), 'B');
  const fresh = await choose(p, [preview.file]); await answerPreview(p, fresh, preview);
  eq(p.state.knowledgeCenterImportForm.value.raw, 'B'); eq(p.state.knowledgeCenterImportDocumentError.value, '');
  await finishDocumentImport(p, await p.submit(), preview);
});

test('cancelled native selection preserves preview; explicit modal cancel and reopen clears it', async () => {
  const p = harness(); await p.open(); await workbook(p);
  const cancelled = await choose(p, []); await cancelled.pending;
  eq(p.state.knowledgeCenterImportSelectedFile.value, fixture.file); eq(p.requests.length, 1);
  await p.close(); await p.open(); eq(p.state.knowledgeCenterImportSelectedFile.value, null);
  eq(p.state.knowledgeCenterImportSourceDocument.value, null); eq(p.state.knowledgeCenterImportPreviewRaw.value, '');
  eq(p.state.knowledgeCenterImportForm.value.mode, 'document'); eq(p.state.knowledgeCenterImportForm.value.source, 'document'); eq(p.state.knowledgeCenterImportForm.value.raw, '');
});

test('late old-file preview cannot overwrite the file selected after same-tree session reset', async () => {
  const p = harness(); await p.open();
  const oldPreview = await documentPreview(new File(['旧稿 A'], 'old.txt'), '旧稿 A');
  const old = await choose(p, [oldPreview.file]), oldRequest = p.pending('document-text'); ok(oldRequest);
  // Authenticated-tree reset is an external lifecycle boundary. The new header
  // and file picker below are the actual Vue controls, not a forced busy click.
  p.sandbox.authSessionEpoch += 1; p.state.showKnowledgeCenterImportModal.value = false;
  await p.open();
  const nextPreview = await documentPreview(new File(['当前稿 B 0'], 'new.txt'), '当前稿 B 0');
  const next = await choose(p, [nextPreview.file]);
  const currentRequest = p.requests.findLast(req => !req.settled && req !== oldRequest); ok(currentRequest);
  p.reply(currentRequest, nextPreview.body); await next.pending;
  const notices = JSON.stringify(p.notices);
  p.reply(oldRequest, oldPreview.body); await old.pending;
  eq(p.state.knowledgeCenterImportSelectedFile.value, nextPreview.file);
  eq(p.state.knowledgeCenterImportPreviewRaw.value, nextPreview.text);
  eq(JSON.stringify(p.state.knowledgeCenterImportSourceDocument.value), JSON.stringify(nextPreview.sourceDocument));
  eq(JSON.stringify(p.notices), notices); eq(p.state.knowledgeCenterImportReading.value, false);
  await finishDocumentImport(p, await p.submit(), nextPreview);
});

for (const scope of ['hotel', 'page', 'tenant_session']) test(`preview response after ${scope} changes cannot write draft, source or success feedback`, async () => {
  const p = harness(); await p.open(); await p.model('textarea', 0, '保留本地稿');
  const preview = await documentPreview(new File(['旧响应'], 'scoped.txt'), '旧响应');
  const event = await choose(p, [preview.file]), req = p.pending('document-text'); ok(req);
  if (scope === 'hotel') p.state.knowledgeCenterImportForm.value.hotel_id = '81';
  if (scope === 'page') p.sandbox.currentPage.value = 'compass';
  if (scope === 'tenant_session') { p.sandbox.authSessionEpoch += 1; p.sandbox.authContext.value.tenantId = 8; }
  p.reply(req, preview.body); await event.pending;
  eq(p.state.knowledgeCenterImportForm.value.raw, '保留本地稿'); eq(p.state.knowledgeCenterImportSelectedFile.value, null);
  eq(p.state.knowledgeCenterImportSourceDocument.value, null); eq(p.state.knowledgeCenterImportPreviewRaw.value, '');
  eq(p.state.knowledgeCenterImportReading.value, false); eq(p.notices, []); eq(p.requests.length, 1);
});

for (const mismatch of ['post_file_hash', 'detail_text_hash', 'detail_hotel']) test(`${mismatch} refuses completion and preserves the exact uploaded file and draft`, async () => {
  const p = harness(); await p.open();
  const preview = await documentPreview(new File(['原文件 0 🏨'], 'exact.txt'), '原文件 0 🏨');
  await answerPreview(p, await choose(p, [preview.file]), preview);
  const sent = await p.submit(); await assertOriginalUpload(sent, preview);
  const post = documentReceipt(preview), item = post.data.created[0];
  if (mismatch === 'post_file_hash') post.data.import_context.source_document.sha256 = 'f'.repeat(64);
  p.reply(sent.req, post); await tick();
  if (mismatch !== 'post_file_hash') {
    const detail = JSON.parse(JSON.stringify({ unit: item.unit, chunks: [item.chunk], current_chunk: null, history_chunks: [item.chunk] }));
    if (mismatch === 'detail_text_hash') detail.chunks[0].content.source_document.text_sha256 = 'f'.repeat(64);
    if (mismatch === 'detail_hotel') detail.unit.hotel_id = 81;
    p.reply(p.pending('501'), { code: 0, data: detail });
  }
  await sent.pending;
  eq(p.state.showKnowledgeCenterImportModal.value, true); eq(p.state.knowledgeCenterImporting.value, false);
  eq(p.state.knowledgeCenterImportSelectedFile.value, preview.file); eq(p.state.knowledgeCenterImportForm.value.raw, preview.text);
  eq(JSON.stringify(p.state.knowledgeCenterImportSourceDocument.value), JSON.stringify(preview.sourceDocument));
  ok(p.state.knowledgeCenterImportDocumentError.value.includes('不一致')); ok(!p.state.knowledgeCenterImportDocumentError.value.includes('XLSX'));
  eq(p.pending('list'), undefined); eq(p.requests.filter(req => req.url.endsWith('/import')).length, 1);
  eq(p.notices.filter(row => row.type === 'success' && row.message.includes('完整独立回读')).length, 0);
});

after(() => console.log(JSON.stringify({ suite: 'knowledge_document_preview_recovery', assertions })));
