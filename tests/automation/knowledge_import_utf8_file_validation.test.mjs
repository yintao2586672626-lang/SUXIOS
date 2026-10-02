import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import { harness, tick, syntheticWorkbookPreview } from './helpers/knowledge_import_ui_harness.mjs';
import { documentPreview, answerPreview, finishDocumentImport } from './helpers/knowledge_document_roundtrip.mjs';

const utf8Message = '文本文档必须使用 UTF-8 编码；非 UTF-8 文档请复制正文后直接粘贴';
const encode = value => new TextEncoder().encode(value);
const invalidGbk = new Uint8Array([0xd6, 0xd0, 0xce, 0xc4]);
const snapshot = p => ({ form: JSON.stringify(p.state.knowledgeCenterImportForm.value),
  file: p.state.knowledgeCenterImportSelectedFile.value, source: JSON.stringify(p.state.knowledgeCenterImportSourceDocument.value),
  previewRaw: p.state.knowledgeCenterImportPreviewRaw.value });
function assertPreserved(p, previous) {
  assert.equal(JSON.stringify(p.state.knowledgeCenterImportForm.value), previous.form);
  assert.equal(p.state.knowledgeCenterImportSelectedFile.value, previous.file);
  assert.equal(JSON.stringify(p.state.knowledgeCenterImportSourceDocument.value), previous.source);
  assert.equal(p.state.knowledgeCenterImportPreviewRaw.value, previous.previewRaw);
  assert.equal(p.state.knowledgeCenterImportReading.value, false);
  assert.equal(p.state.showKnowledgeCenterImportModal.value, true);
}
async function selectFile(p, file) {
  await p.html();
  const input = p.nodes().find(node => node.type === 'input' && node.props?.type === 'file');
  assert.ok(input && !input.props.disabled, 'Original file control is enabled');
  const target = { files: [file], value: 'synthetic-file-selection' };
  const pending = input.props.onChange({ target });
  return { pending, target };
}
async function submittedRaw(p, expected) {
  const sent = await p.submit(); assert.ok(sent.req);
  const body = JSON.parse(sent.req.options.body);
  assert.equal(body.raw, expected); assert.equal(body.mode, 'document');
  p.reply(sent.req, { code: 422, msg: 'Synthetic stop after inspecting request', data: null }, 422);
  await sent.pending;
  assert.equal(p.requests.filter(row => new URL(row.url).pathname === '/api/knowledge/import').length, 1);
}

test('invalid UTF8 file is rejected before replacing an existing typed draft', async () => {
  const p = harness(); await p.open(); await p.model('textarea', 0, '保留原稿 A'); await p.model('input', 0, '保留标签');
  const previous = snapshot(p), selected = await selectFile(p, new File([invalidGbk], 'synthetic-gbk.txt'));
  await selected.pending;
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, utf8Message);
  assertPreserved(p, previous); assert.equal(selected.target.value, '');
  assert.equal(p.requests.length, 0); assert.equal(p.notices.filter(row => row.type === 'success').length, 0);
});

test('invalid UTF8 HTML is rejected before invoking the unchanged HTML parser', async () => {
  let parseCalls = 0;
  // A counting DOM API spy establishes ordering only. It is not a browser HTML
  // parser and this test makes no claim about HTML parsing correctness.
  class ParserSpy { parseFromString() { parseCalls++; return { body: { textContent: 'Synthetic replaced HTML', querySelectorAll: () => [] } }; } }
  const originalRun = vm.runInNewContext; let p;
  try {
    vm.runInNewContext = function (source, context, ...rest) {
      if (String(source).includes('SUXI_KNOWLEDGE_CENTER_DOMAIN')) context.DOMParser = ParserSpy;
      return originalRun.call(this, source, context, ...rest);
    };
    p = harness();
  } finally { vm.runInNewContext = originalRun; }
  await p.open(); await p.model('textarea', 0, '保留 HTML 前原稿'); const previous = snapshot(p);
  const selected = await selectFile(p, new File([encode('<p>'), invalidGbk, encode('</p>')], 'synthetic-gbk.html'));
  await selected.pending;
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, utf8Message);
  assert.equal(parseCalls, 0); assertPreserved(p, previous); assert.equal(p.requests.length, 0);
});

test('invalid UTF8 replacement preserves the complete previously verified XLSX preview', async () => {
  const p = harness(), workbook = syntheticWorkbookPreview(); await p.open();
  const first = await selectFile(p, workbook.file); await tick();
  p.reply(p.pending('document-text'), workbook.body); await first.pending;
  const previous = snapshot(p); assert.equal(previous.file, workbook.file);
  const successBefore = p.notices.filter(row => row.type === 'success').length;
  const second = await selectFile(p, new File([invalidGbk], 'synthetic-bad-replacement.txt')); await second.pending;
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, utf8Message);
  assertPreserved(p, previous); assert.equal(p.notices.filter(row => row.type === 'success').length, successBefore);
  assert.equal(p.requests.length, 1); assert.ok(p.requests[0].url.endsWith('/knowledge/document-text'));
  await p.html(); assert.equal(p.nodes().find(node => node.type === 'textarea').props.readonly, true);
  const recoveredText = '重新选择的正确 UTF-8 文档 🏨';
  const preview = await documentPreview(new File([encode(recoveredText)], 'synthetic-recovered.txt'), recoveredText);
  const recovered = await selectFile(p, preview.file);
  await answerPreview(p, recovered, preview);
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, '');
  assert.equal(p.state.knowledgeCenterImportForm.value.raw, recoveredText);
  assert.equal(p.state.knowledgeCenterImportForm.value.mode, 'txt');
  assert.equal(p.state.knowledgeCenterImportForm.value.source, 'manual_template');
  assert.equal(p.state.knowledgeCenterImportSelectedFile.value, preview.file);
  assert.equal(JSON.stringify(p.state.knowledgeCenterImportSourceDocument.value), JSON.stringify(preview.sourceDocument));
  assert.equal(p.state.knowledgeCenterImportPreviewRaw.value, recoveredText);
  assert.equal(p.requests.length, 2, 'Successful replacement requires its own server preview');
  await finishDocumentImport(p, await p.submit(), preview);
});

test('arrayBuffer read failure preserves the original error and draft rather than claiming encoding failure', async () => {
  const p = harness(); await p.open(); await p.model('textarea', 0, '保存原稿'); const previous = snapshot(p);
  const file = new File([encode('Synthetic native file')], 'synthetic-read-failure.txt');
  file.arrayBuffer = async () => { throw new Error('Synthetic arrayBuffer read failure'); };
  const selected = await selectFile(p, file); await selected.pending;
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, 'Synthetic arrayBuffer read failure');
  assertPreserved(p, previous); assert.equal(p.requests.length, 0);
});

for (const [label, value, prefix] of [
  ['Chinese', '中文资料', []], ['UTF8_BOM', '中文资料', [0xef, 0xbb, 0xbf]],
  ['emoji', '🏨📚 酒店资料', []], ['literal_U_FFFD', '合法字面字符 �', []], ['zero', '0', []],
]) {
  test(`valid UTF8 ${label} remains readable and submitted exactly`, async () => {
    const p = harness(); await p.open();
    const preview = await documentPreview(new File([new Uint8Array(prefix), encode(value)], `synthetic-${label}.txt`), value);
    const selected = await selectFile(p, preview.file);
    await answerPreview(p, selected, preview);
    assert.equal(p.state.knowledgeCenterImportDocumentError.value, '');
    assert.equal(p.state.knowledgeCenterImportForm.value.raw, value);
    assert.equal(p.state.knowledgeCenterImportReading.value, false);
    assert.equal(p.state.knowledgeCenterImportSelectedFile.value, preview.file);
    assert.equal(p.requests.length, 1); assert.equal(p.notices.filter(row => row.type === 'success').length, 1);
    await p.html(); assert.equal(p.nodes().find(node => node.type === 'textarea').props.readonly, true);
    await finishDocumentImport(p, await p.submit(), preview);
  });
}

test('typed plain-text paste keeps its existing native input path including literal replacement characters', async () => {
  const p = harness(); await p.open(); await p.html(); const value = '直接粘贴 � 🏨 0';
  const textarea = p.nodes().find(node => node.type === 'textarea'); let prevented = false;
  assert.ok(textarea && !textarea.props.disabled && !textarea.props.readonly);
  await textarea.props.onPaste({ target: { tagName: 'TEXTAREA' }, preventDefault() { prevented = true; },
    clipboardData: { files: [], getData: type => type === 'text/plain' ? value : '' } });
  assert.equal(prevented, false); assert.equal(p.state.knowledgeCenterImportDocumentNotice.value, '已粘贴正文');
  // The native browser default insertion is represented by the actual model
  // update handler; no browser or clipboard API is used in this Node fixture.
  await p.model('textarea', 0, value);
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, ''); assert.equal(p.requests.length, 0);
  await submittedRaw(p, value);
});
