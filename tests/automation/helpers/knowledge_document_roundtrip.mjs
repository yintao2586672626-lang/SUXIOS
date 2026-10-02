import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { clone, tick } from './knowledge_import_ui_harness.mjs';

// Synthetic server DTOs, using real hashes of the original uploaded bytes and
// extracted text. This does not execute the document parser, AI or persistence.
export async function documentPreview(file, text) {
  const sourceDocument = {
    filename: file.name, extension: file.name.split('.').at(-1).toLowerCase(),
    sha256: createHash('sha256').update(Buffer.from(await file.arrayBuffer())).digest('hex'),
    text_sha256: createHash('sha256').update(text).digest('hex'), char_count: [...text].length,
  };
  return { file, text, sourceDocument, body: { code: 0, data: { ...sourceDocument, text, source_document: sourceDocument } } };
}

export async function answerPreview(p, event, preview) {
  await tick();
  const req = p.pending('document-text'); assert.ok(req, 'Actual file control sends a preview request');
  assert.equal(req.options.method, 'POST'); assert.ok(req.options.body instanceof FormData);
  assert.equal(req.options.body.get('file').name, preview.file.name);
  assert.deepEqual(Buffer.from(await req.options.body.get('file').arrayBuffer()), Buffer.from(await preview.file.arrayBuffer()));
  p.reply(req, preview.body); await event.pending;
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, '');
  assert.equal(p.state.knowledgeCenterImportForm.value.raw, preview.text);
  assert.equal(p.state.knowledgeCenterImportSelectedFile.value, preview.file);
  assert.deepEqual(clone(p.state.knowledgeCenterImportSourceDocument.value), preview.sourceDocument);
  assert.equal(p.state.knowledgeCenterImportPreviewRaw.value, preview.text);
}

export function documentReceipt(preview) {
  const { text, sourceDocument } = preview;
  const unit = { unit_id: 501, hotel_id: 80, current_chunk_id: null, name: 'Synthetic imported document',
    source: 'manual_template', status: 'done', description: 'Synthetic summary', tags: ['人工模板', '行业通用', '未核验'] };
  const content = { material_type: sourceDocument.extension, hotel_id: 80, hotel_name: 'Synthetic A', source: 'manual_template',
    raw_text: text, model_key: 'deepseek_chat', imported_at: '2026-09-15 08:00:00', distilled_at: '2026-09-15 08:00:00',
    source_document: clone(sourceDocument), material_classification: 'manual_template', knowledge_scope: 'industry_general',
    verification_status: 'unverified', facts_scope: 'document_reference_not_hotel_fact', container_scope: 'authorized_hotel_container_only',
    requires_current_verification: true,
    blocked_uses: ['hotel_fact_claim', 'ota_fact_claim', 'business_date_fact_claim', 'operation_task_creation', 'automatic_ota_write'],
    ai_distilled: { title: unit.name, summary: unit.description, hotel_id: 80, hotel_name: 'Synthetic A', material_type: sourceDocument.extension,
      source: 'manual_template', raw_text: text, model_key: 'deepseek_chat', confidence_score: 0, facts: [], analysis_hints: [], actions: [], keywords: [] } };
  const chunk = { chunk_id: 1501, unit_id: 501, type: 'AI资料蒸馏', content };
  return { code: 0, data: { hotel_id: 80, success_count: 1, error_count: 0, errors: [],
    import_context: { source_document: clone(sourceDocument), material_classification: 'manual_template', knowledge_scope: 'industry_general',
      verification_status: 'unverified', container_scope: 'authorized_hotel_container_only' },
    created: [{ unit, chunk, readback_verified: true, readback: { unit_id: 501, chunk_id: 1501, unit_snapshot: clone(unit), chunk_snapshot: clone(chunk) } }] } };
}

export async function assertOriginalUpload(sent, preview) {
  assert.ok(sent.req, 'Actual submit control sends one import');
  const body = sent.req.options.body;
  assert.ok(body instanceof FormData); assert.equal(body.get('hotel_id'), '80'); assert.equal(body.get('model_key'), 'deepseek_chat');
  assert.equal(body.get('file').name, preview.file.name);
  assert.deepEqual(Buffer.from(await body.get('file').arrayBuffer()), Buffer.from(await preview.file.arrayBuffer()));
  assert.equal(createHash('sha256').update(Buffer.from(await body.get('file').arrayBuffer())).digest('hex'), preview.sourceDocument.sha256);
  assert.equal(sent.req.options.headers['Content-Type'], undefined, 'Native FormData owns the multipart boundary');
}

export async function finishDocumentImport(p, sent, preview, { importCount = 1 } = {}) {
  await assertOriginalUpload(sent, preview);
  const post = documentReceipt(preview), item = post.data.created[0];
  p.reply(sent.req, post); await tick();
  const detail = p.pending('501'); assert.ok(detail, 'A separate exact GET is required after POST');
  assert.equal(detail.options.method || 'GET', 'GET');
  p.reply(detail, { code: 0, data: { unit: clone(item.unit), chunks: [clone(item.chunk)], current_chunk: null, history_chunks: [clone(item.chunk)] } });
  await tick();
  const list = p.pending('list'); assert.ok(list, 'The visible hotel list must be reloaded');
  assert.equal(new URL(list.url).searchParams.get('hotel_id'), '80');
  p.reply(list, { code: 0, data: { list: [clone(item.unit)], pagination: { total: 1, page: 1, page_size: 10, total_page: 1 } } });
  await sent.pending;
  assert.equal(p.state.showKnowledgeCenterImportModal.value, false);
  assert.equal(p.state.knowledgeCenterImportDocumentError.value, '');
  assert.equal(p.state.knowledgeCenterImporting.value, false);
  assert.equal(p.state.knowledgeCenterUnits.value[0].unit_id, 501);
  assert.equal(p.state.knowledgeCenterUnits.value[0].hotel_id, 80);
  assert.equal(p.notices.filter(row => row.type === 'success' && row.message.includes('完整独立回读')).length, 1);
  assert.equal(p.requests.filter(req => new URL(req.url).pathname.endsWith('/import')).length, importCount);
  assert.equal(p.requests.filter(req => new URL(req.url).pathname.endsWith('/501')).length, 1);
}
