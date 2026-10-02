import assert from 'node:assert/strict';
import test from 'node:test';
import { harness, clone, tick, syntheticWorkbookPreview } from './helpers/knowledge_import_ui_harness.mjs';

// Synthetic subsets of documented POST/detail/list DTOs. Snapshot contents stay
// identical. Actual producer hashes are omitted because this frontend path does
// not inspect them; that omission does not make hashes optional in the server
// contract. The separate read-only probe used original pure recomputed hashes.
// This test reads no output files and runs no persistence, extraction, LLM or network.
function receipt(materials, mode = 'document', sourceDocument = null) {
  const source = sourceDocument ? 'manual_template' : mode;
  const created = materials.map((raw, index) => {
    const id = 501 + index, summary = `Synthetic summary ${index + 1}`;
    const unit = { unit_id: id, hotel_id: 80, current_chunk_id: null, name: `Synthetic material ${index + 1}`,
      source, status: 'done', description: summary,
      tags: sourceDocument ? ['人工模板', '行业通用', '未核验'] : ['synthetic', 'AI资料蒸馏', 'Synthetic Hotel A'] };
    const content = { material_type: mode, hotel_id: 80, hotel_name: 'Synthetic Hotel A', source, raw_text: raw,
      model_key: 'deepseek_chat', imported_at: '2026-09-15 08:00:00', distilled_at: '2026-09-15 08:00:00',
      ai_distilled: { title: unit.name, summary, hotel_id: 80, hotel_name: 'Synthetic Hotel A', material_type: mode, source,
        raw_text: raw, model_key: 'deepseek_chat', confidence_score: 0, facts: [], analysis_hints: [], actions: [], keywords: ['synthetic'] } };
    if (sourceDocument) Object.assign(content, { source_document: clone(sourceDocument), material_classification: 'manual_template',
      knowledge_scope: 'industry_general', verification_status: 'unverified', facts_scope: 'document_reference_not_hotel_fact',
      container_scope: 'authorized_hotel_container_only', requires_current_verification: true,
      blocked_uses: ['hotel_fact_claim', 'ota_fact_claim', 'business_date_fact_claim', 'operation_task_creation', 'automatic_ota_write'] });
    const chunk = { chunk_id: 1501 + index, unit_id: id, type: 'AI资料蒸馏', content };
    return { unit, chunk, readback_verified: true, readback: { unit_id: id, chunk_id: chunk.chunk_id, unit_snapshot: clone(unit), chunk_snapshot: clone(chunk) } };
  });
  const data = { hotel_id: 80, success_count: created.length, error_count: 0, errors: [], created };
  if (sourceDocument) data.import_context = { source_document: clone(sourceDocument), material_classification: 'manual_template',
    knowledge_scope: 'industry_general', verification_status: 'unverified', container_scope: 'authorized_hotel_container_only' };
  return { code: 0, msg: '', data };
}

async function finish(p, sent, post) {
  p.reply(sent.req, post); await tick();
  for (const item of post.data.created) {
    const req = p.pending(String(item.unit.unit_id));
    if (req) {
      p.reply(req, { code: 0, data: { unit: item.unit, chunks: [item.chunk], current_chunk: null, history_chunks: [item.chunk] } });
      await tick();
    }
  }
  const list = p.pending('list');
  if (list) p.reply(list, { code: 0, data: { list: post.data.created.map(item => item.unit), pagination: { total: post.data.created.length, page: 1, page_size: 10, total_page: 1 } } });
  await sent.pending;
}

async function prepare(raw, mode = 'document') {
  const p = harness(); await p.open();
  // Old modes are compatibility inputs only; the current visible entry defaults
  // to document. All text entry/submission still uses the original Vue controls.
  if (mode !== 'document') Object.assign(p.state.knowledgeCenterImportForm.value, { mode, source: mode });
  await p.model('textarea', 0, raw);
  const sent = await p.submit(); assert.ok(sent.req);
  return { p, sent, submitted: JSON.parse(sent.req.options.body) };
}

test('document raw binds to normalized submitted text with CRLF/blanks and null current chunk', async () => {
  const raw = ' \r\nFirst synthetic paragraph. \t\r\n\r\n\r\nSecond synthetic paragraph.\r\n ';
  const normalized = 'First synthetic paragraph.\n\nSecond synthetic paragraph.';
  const { p, sent, submitted } = await prepare(raw);
  assert.equal(submitted.mode, 'document'); assert.equal(submitted.raw, normalized);
  await finish(p, sent, receipt([normalized]));
  assert.equal(p.state.showKnowledgeCenterImportModal.value, false);
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, '');
  assert.equal(p.state.knowledgeCenterUnits.value[0].unit_id, 501);
  assert.equal(p.notices.filter(row => row.type === 'success').length, 1);
  assert.equal(p.requests.filter(row => new URL(row.url).pathname === '/api/knowledge/501').length, 1);
});

for (const kind of ['consistent_other_raw', 'multiple_records_for_single_document']) {
  test(`document refuses ${kind} without clearing draft or retrying import`, async () => {
    const raw = 'Submitted synthetic document A.';
    const { p, sent, submitted } = await prepare(raw);
    const materials = kind === 'consistent_other_raw' ? ['Different synthetic document C.'] : [submitted.raw, submitted.raw];
    await finish(p, sent, receipt(materials));
    assert.equal(p.state.showKnowledgeCenterImportModal.value, true);
    assert.equal(p.state.knowledgeCenterImportForm.value.raw, raw);
    assert.equal(p.state.knowledgeCenterImporting.value, false);
    assert.match(p.state.knowledgeCenterImportDocumentError.value, /已提交.*文档原文回读不一致/);
    assert.doesNotMatch(p.state.knowledgeCenterImportDocumentError.value, /未保存|没有写入/);
    assert.equal(p.notices.filter(row => row.type === 'success').length, 0);
    assert.equal(p.requests.filter(row => row.options.method === 'POST').length, 1);
    assert.equal(p.requests.filter(row => row.options.method !== 'POST').length, 0);
  });
}

for (const mode of ['text', 'link']) {
  test(`legacy ${mode} split materials may each differ from the complete submitted input`, async () => {
    const materials = mode === 'text' ? ['Synthetic material A.', 'Synthetic material B.'] : ['https://example.invalid/a', 'https://example.invalid/b'];
    const raw = materials.join(mode === 'text' ? '\n\n' : '\n');
    const { p, sent, submitted } = await prepare(raw, mode);
    assert.equal(submitted.mode, mode); assert.equal(submitted.raw, raw);
    assert.ok(materials.every(value => value !== raw));
    await finish(p, sent, receipt(materials, mode));
    assert.equal(p.state.showKnowledgeCenterImportModal.value, false);
    assert.equal(p.state.knowledgeCenterImportDocumentError.value, '');
    assert.deepEqual(p.state.knowledgeCenterUnits.value.map(row => row.unit_id), [501, 502]);
    assert.equal(p.notices.filter(row => row.type === 'success').length, 1);
    assert.equal(p.requests.filter(row => row.options.method === 'POST').length, 1);
  });
}

test('XLSX file preview and existing source/raw contract remain compatible', async () => {
  const p = harness(), workbook = syntheticWorkbookPreview(); await p.open(); await p.html();
  const picker = p.nodes().find(node => node.type === 'input' && node.props?.type === 'file');
  assert.ok(picker && !picker.props.disabled);
  const preview = picker.props.onChange({ target: { files: [workbook.file], value: 'synthetic-selection' } });
  await tick(); p.reply(p.pending('document-text'), workbook.body); await preview;
  const sent = await p.submit(); assert.ok(sent.req.options.body instanceof FormData);
  assert.equal(sent.req.options.body.get('hotel_id'), '80');
  await finish(p, sent, receipt([workbook.text], 'xlsx', workbook.sourceDocument));
  assert.equal(p.state.showKnowledgeCenterImportModal.value, false);
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, '');
  assert.equal(p.state.knowledgeCenterImportSelectedFile.value, null);
  assert.equal(p.notices.filter(row => row.type === 'success' && row.message.includes('完整独立回读')).length, 1);
  assert.equal(p.requests.filter(row => new URL(row.url).pathname === '/api/knowledge/import').length, 1);
});
