import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const appMain = [
  fs.readFileSync(path.join(root, 'public/components/system/knowledge-center-domain.js'), 'utf8'),
  fs.readFileSync(path.join(root, 'public/app-main.js'), 'utf8'),
].join('\n');
const dialogTemplate = fs.readFileSync(
  path.join(root, 'resources/frontend/templates/fragments/38-dialogs-knowledge-center.html'),
  'utf8',
);
const knowledgeController = fs.readFileSync(path.join(root, 'app/controller/Knowledge.php'), 'utf8');
const ingestionService = fs.readFileSync(
  path.join(root, 'app/service/KnowledgeMaterialIngestionService.php'),
  'utf8',
);

const sliceBetween = (source, start, end) => {
  const startIndex = source.indexOf(start);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

const assertInOrder = (source, markers, message) => {
  let cursor = -1;
  for (const marker of markers) {
    const next = source.indexOf(marker, cursor + 1);
    assert.notEqual(next, -1, `${message}: missing ${marker}`);
    assert.ok(next > cursor, `${message}: ${marker} is out of order`);
    cursor = next;
  }
};

test('knowledge import makes XLSX discoverable and displays its truthful generic scope', () => {
  assert.match(
    dialogTemplate,
    /<input[^>]+ref="knowledgeDocumentFileInput"[^>]+accept="[^"]*\.xlsx[^"]*"[^>]*>/,
  );
  assert.match(dialogTemplate, /XLSX 来源已锁定，提交时由服务端重新解析/);
  assert.match(dialogTemplate, /SHA-256 \{\{ knowledgeCenterImportSourceDocument\.sha256 \}\}/);
  assert.match(dialogTemplate, /knowledgeCenterImportSourceDocument\.sheets/);
  assert.match(dialogTemplate, /人工模板 \/ 行业通用 \/ 未核验/);
  assert.match(dialogTemplate, /所选门店仅用于授权隔离，不代表表格内容是该店事实/);
});

test('XLSX preview accepts one workbook, keeps the same File, and locks server provenance', () => {
  const previewUpload = sliceBetween(
    appMain,
    'const extractKnowledgeDocumentByApi = async (file, actionContext) => {',
    '\n\n            const extractKnowledgeDocumentFileText',
  );
  const fileHandler = sliceBetween(
    appMain,
    'const handleKnowledgeDocumentFiles = async (files) => {',
    '\n\n            const handleKnowledgeDocumentPaste',
  );

  assert.match(previewUpload, /const formData = new FormData\(\)/);
  assert.match(previewUpload, /formData\.append\('file', file\)/);
  assert.match(previewUpload, /fetch\(API_BASE \+ '\/knowledge\/document-text'/);
  assert.doesNotMatch(previewUpload, /Content-Type/);
  assert.match(previewUpload, /const requestSession = actionContext\.session/);
  assert.match(previewUpload, /assertKnowledgeImportActionCurrent\(actionContext\)/);

  assert.match(fileHandler, /const xlsxFiles = list\.filter\(file => knowledgeDocumentExtension\(file\) === 'xlsx'\)/);
  assert.match(fileHandler, /xlsxFiles\.length > 0 && \(xlsxFiles\.length !== 1 \|\| list\.length !== 1\)/);
  assert.match(fileHandler, /XLSX 必须单独选择；每次导入一个工作簿/);
  assert.match(fileHandler, /parsed\.push\(\{ name: file\.name, file, \.\.\.extracted \}\)/);
  assert.match(fileHandler, /normalizeKnowledgeSourceDocumentForReadback/);
  assert.match(fileHandler, /'XLSX 预览 source_document'/);
  assert.match(fileHandler, /knowledgeCenterImportSelectedFile\.value = workbook\.file/);
  assert.match(fileHandler, /knowledgeCenterImportSourceDocument\.value = sourceDocument/);
  assert.match(fileHandler, /mode: 'xlsx'/);
  assert.match(fileHandler, /source: 'manual_template'/);
});

test('XLSX submission sends only multipart file metadata and closes after exact visible readback', () => {
  const loader = sliceBetween(
    appMain,
    "const loadKnowledgeCenter = async ({ hotelId = '' } = {}) => {",
    '\n\n            const reloadKnowledgeCenter',
  );
  const verifier = sliceBetween(
    appMain,
    'const verifyKnowledgeImportReadback = async (importData, expected = {}) => {',
    '\n\n            const importKnowledgeUnits',
  );
  const importer = sliceBetween(
    appMain,
    'const importKnowledgeUnits = async () => {',
    '\n        return Object.freeze({',
  );
  const multipartBranch = sliceBetween(importer, 'if (selectedFile) {', '} else {');

  for (const field of ['file', 'hotel_id', 'model_key', 'tags']) {
    assert.match(multipartBranch, new RegExp(`multipart\\.append\\('${field}'`));
  }
  assert.match(multipartBranch, /multipart\.append\('file', selectedFile\)/);
  assert.doesNotMatch(multipartBranch, /multipart\.append\('raw'/);
  assert.doesNotMatch(multipartBranch, /Content-Type/);
  assert.doesNotMatch(multipartBranch, /JSON\.stringify\(requestBody\)/);

  assert.match(loader, /const requestedHotelId = String\(hotelId \|\| ''\)\.trim\(\)/);
  assert.match(loader, /params\.append\('hotel_id', requestedHotelId\)/);

  assert.match(verifier, /!item\?\.readback_verified/);
  assert.match(verifier, /await request\(`\/knowledge\/\$\{unitId\}`\)/);
  assert.match(verifier, /detailChunks\.find\(chunk => Number\(chunk\?\.chunk_id \|\| 0\) === chunkId\)/);
  assert.match(verifier, /assertKnowledgeSourceDocumentExact/);
  assert.match(verifier, /normalizeKnowledgeDocumentText\(detailContent\.raw_text\) !== expectedRawText/);
  assert.match(verifier, /detailContent\.ai_distilled\?\.summary/);
  assert.match(verifier, /!knowledgeExactJsonMatches\(detailChunk\?\.content, postContent\)/);

  assertInOrder(importer, [
    'await verifyKnowledgeImportReadback(',
    'await loadKnowledgeCenter({ hotelId })',
    'const visibleUnit = knowledgeCenterUnits.value.find(',
    'Number(unit?.unit_id || 0) === importedUnit.unit_id',
    'Number(unit?.hotel_id || 0) === importedUnit.hotel_id',
    'showKnowledgeCenterImportModal.value = false',
  ], 'import must prove server, independent API, and displayed-list readback before closing');
  assert.match(importer, /同酒店列表尚未显示相同摘要/);
  assert.match(importer, /knowledgeCenterImportDocumentError\.value = message/);
});

test('preview and import freeze auth, page, hotel and action epoch across every asynchronous boundary', () => {
  const context = sliceBetween(
    appMain,
    "const knowledgeImportContextChangedCode = 'knowledge_import_context_changed';",
    '\n\n            const requireKnowledgeImportField',
  );
  const preview = sliceBetween(
    appMain,
    'const handleKnowledgeDocumentFiles = async (files) => {',
    '\n\n            const handleKnowledgeDocumentPaste',
  );
  const importer = sliceBetween(
    appMain,
    'const importKnowledgeUnits = async () => {',
    '\n        return Object.freeze({',
  );

  assert.match(context, /epoch: \+\+knowledgeCenterImportActionEpoch/);
  assert.match(context, /session: captureAuthSession\(\)/);
  assert.match(context, /page: currentPage\.value/);
  assert.match(context, /hotelId: String\(hotelId \|\| ''\)\.trim\(\)/);
  assert.match(context, /isAuthSessionCurrent\(context\.session\)/);
  assert.match(context, /currentPage\.value === context\.page/);
  assert.match(context, /knowledgeCenterImportForm\.value\.hotel_id/);
  assert.match(preview, /captureKnowledgeImportActionContext\('preview'\)/);
  assert.match(preview, /assertKnowledgeImportActionCurrent\(actionContext\)/);
  assert.match(preview, /knowledgeCenterImportActionEpoch === actionContext\.epoch/);
  assert.match(importer, /const form = \{ \.\.\.knowledgeCenterImportForm\.value \}/);
  assert.match(importer, /captureKnowledgeImportActionContext\('import', hotelId\)/);
  assert.match(importer, /assertKnowledgeImportActionCurrent\(actionContext\)/);
  assert.match(importer, /knowledgeCenterImportActionEpoch === actionContext\.epoch/);
});

test('XLSX success requires one done AI summary with zero errors before independent readback', () => {
  const verifier = sliceBetween(
    appMain,
    'const verifyKnowledgeImportReadback = async (importData, expected = {}) => {',
    '\n\n            const importKnowledgeUnits',
  );

  assert.match(verifier, /errorCount !== 0/);
  assert.match(verifier, /errors\.length !== 0/);
  assert.match(verifier, /successCount !== created\.length/);
  assert.match(verifier, /singleXlsx && \(successCount !== 1 \|\| created\.length !== 1\)/);
  assert.match(verifier, /String\(postUnit\.status \|\| ''\) !== 'done'/);
  assert.match(verifier, /const postSummary = String\(postContent\?\.ai_distilled\?\.summary \|\| ''\)\.trim\(\)/);
  assert.match(verifier, /String\(postUnit\.description \|\| ''\) !== expectedDescription/);
  assert.match(verifier, /AI 摘要未全部成功，导入结果不会关闭或标记为完成/);
});

test('source document and every worksheet field are normalized for exact POST to GET comparison', () => {
  const normalizer = sliceBetween(
    appMain,
    'const normalizeKnowledgeSourceDocumentForReadback = (source, label) => {',
    '\n\n            const assertKnowledgeSourceDocumentExact',
  );
  for (const field of [
    'filename',
    'extension',
    'sha256',
    'text_sha256',
    'char_count',
    'sheets',
    'name',
    'row_count',
    'cell_count',
    'cell_refs',
    'cell_refs_truncated',
    'merged_ranges',
  ]) {
    assert.ok(normalizer.includes(`'${field}'`), `missing exact source field ${field}`);
  }
  assert.match(appMain, /JSON\.stringify\(actualNormalized\) !== JSON\.stringify\(expectedNormalized\)/);
  assert.match(appMain, /POST import_context\.source_document/);
  assert.match(appMain, /POST 知识 #\$\{unitId\} source_document/);
  assert.match(appMain, /独立 GET 知识 #\$\{unitId\} source_document/);
});

test('dialog locks mutable controls and only success closes and clears XLSX context', () => {
  assert.match(dialogTemplate, /@click="closeKnowledgeImportModal"/);
  assert.match(dialogTemplate, /v-model="knowledgeCenterImportForm\.hotel_id"[^>]+:disabled="knowledgeCenterImporting \|\| knowledgeCenterImportReading"/);
  assert.match(dialogTemplate, /v-model="knowledgeCenterImportForm\.model_key"[^>]+:disabled="knowledgeCenterImporting \|\| knowledgeCenterImportReading"/);
  assert.match(dialogTemplate, /v-model="knowledgeCenterImportForm\.tags"[^>]+:disabled="knowledgeCenterImporting \|\| knowledgeCenterImportReading"/);
  assert.match(dialogTemplate, /ref="knowledgeDocumentTextarea"[^>]+:disabled="knowledgeCenterImporting \|\| knowledgeCenterImportReading"/);
  assert.match(dialogTemplate, /ref="knowledgeDocumentTextarea"[^>]+:readonly="!!knowledgeCenterImportSelectedFile"/);
  assert.match(dialogTemplate, /ref="knowledgeDocumentFileInput"[^>]+:disabled="knowledgeCenterImporting \|\| knowledgeCenterImportReading"/);

  const importer = sliceBetween(
    appMain,
    'const importKnowledgeUnits = async () => {',
    '\n        return Object.freeze({',
  );
  assertInOrder(importer, [
    'for (const importedUnit of importedUnits)',
    'showKnowledgeCenterImportModal.value = false',
    'knowledgeCenterImportSelectedFile.value = null',
    'knowledgeCenterImportSourceDocument.value = null',
    "knowledgeCenterImportPreviewRaw.value = ''",
  ], 'XLSX context can only be cleared after all exact readbacks');
  const catchIndex = importer.lastIndexOf('} catch (error) {');
  const successCloseIndex = importer.indexOf('showKnowledgeCenterImportModal.value = false');
  assert.ok(successCloseIndex >= 0 && catchIndex > successCloseIndex);
  const failureBlock = importer.slice(catchIndex);
  assert.doesNotMatch(failureBlock, /knowledgeCenterImportSelectedFile\.value = null/);
  assert.doesNotMatch(failureBlock, /knowledgeCenterImportSourceDocument\.value = null/);
  assert.doesNotMatch(failureBlock, /knowledgeCenterImportPreviewRaw\.value = ''/);
  assert.match(importer, /const lockedPreviewRaw = normalizeKnowledgeDocumentText\(knowledgeCenterImportPreviewRaw\.value\)/);
  assert.match(importer, /raw !== lockedPreviewRaw/);
});

test('server re-extracts uploaded XLSX bytes, persists provenance, and fails closed on AI/readback gaps', () => {
  const importEndpoint = sliceBetween(
    knowledgeController,
    'public function importMaterials(): Response',
    '\n\n    public function extractDocumentText',
  );
  const serverExtractor = sliceBetween(
    knowledgeController,
    'private function extractUploadedXlsxImport($file): array',
    '\n\n    public function addChunk',
  );
  const persistence = sliceBetween(
    knowledgeController,
    'private function persistImportedKnowledgeMaterial(',
    '\n\n    /**\n     * @param array<string, mixed> $expectedUnit',
  );
  const exactVerifier = sliceBetween(
    knowledgeController,
    'private function verifyImportedKnowledgeReadbackRows(',
    '\n\n    /** @param mixed $value */',
  );

  assert.match(importEndpoint, /\$uploadedFile = \$this->request->file\('file'\)/);
  assert.match(importEndpoint, /\$extracted = \$this->extractUploadedXlsxImport\(\$uploadedFile\)/);
  assert.match(importEndpoint, /'source_document' => \$extracted\['source_document'\]/);
  assert.match(importEndpoint, /'material_classification' => 'manual_template'/);
  assert.match(importEndpoint, /'knowledge_scope' => 'industry_general'/);
  assert.match(importEndpoint, /'verification_status' => 'unverified'/);
  assert.match(serverExtractor, /KnowledgeDocumentTextExtractor\(\)/);
  assert.match(serverExtractor, /extractFromPath\(\$path, \$filename\)/);
  assert.match(serverExtractor, /\^\[a-f0-9\]\{64\}\$/);
  assert.match(serverExtractor, /\$sourceDocument\['sheets'\]/);

  assert.match(persistence, /\$content\['source_document'\] = \$importContext\['source_document'\]/);
  assert.match(persistence, /KnowledgeUnit::where\('unit_id', \(int\)\$unit->unit_id\)->find\(\)/);
  assert.match(persistence, /KnowledgeChunk::where\('unit_id', \(int\)\$unit->unit_id\)/);
  assert.match(persistence, /verifyImportedKnowledgeReadbackRows/);
  assert.match(persistence, /'readback_verified' => true/);
  assert.match(exactVerifier, /Imported knowledge unit readback mismatch/);
  assert.match(exactVerifier, /Imported knowledge chunk readback mismatch/);
  assert.match(exactVerifier, /'content'/);
  assert.match(ingestionService, /if \(\$summary === ''\) \{\s*throw new RuntimeException\('AI summary is empty; knowledge material was not distilled'\);/);
});

test('forecast trial and formal promotion shared-source contracts remain present', () => {
  assert.match(appMain, /homeTemporalTrialListVerified/);
  assert.match(appMain, /immutable_digest/);
  assert.match(appMain, /const captureKnowledgePromotionContext/);
  assert.match(appMain, /readKnowledgePromotionActionSnapshot/);
  assert.match(appMain, /knowledgeCenterImportSelectedFile/);
  assert.match(appMain, /new FormData\(\)/);
  assert.match(appMain, /\/knowledge\/document-text/);
});
