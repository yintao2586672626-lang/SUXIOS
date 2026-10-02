import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { writeFileAtomic } from './lib/frontend_template_lock.mjs';

// Add only this task's marked blocks. Unrelated dirty source is preserved byte for byte.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const artifact = fs.readFileSync(path.join(root, 'public/components/system/hotel-learning-workbench.min.js'));
const version = hash(artifact).slice(0, 10);
const component = `            // SUXI_HOTEL_LEARNING_BEGIN\n            HotelLearningWorkbench: Vue.defineAsyncComponent({\n                loader: () => loadOnlineDataComponentScript('components/system/hotel-learning-workbench.min.js?v=hotel-learning-h${version}')\n                    .then(() => requireSystemComponent('HotelLearningWorkbench')),\n                loadingComponent: { render: () => h('p', { role: 'status', class: 'p-4 text-sm' }, '正在加载业务工具…') },\n                delay: 150,\n            }),\n            // SUXI_HOTEL_LEARNING_END\n`;
const specs = [
  { file: 'public/app-main.js', start: '            // SUXI_HOTEL_LEARNING_BEGIN', end: '            // SUXI_HOTEL_LEARNING_END', anchor: '        components: {', block: component },
  {
    file: 'resources/frontend/templates/fragments/19c-page-operating-finance.html',
    start: '    <!-- SUXI_HOTEL_LEARNING_BEGIN -->', end: '    <!-- SUXI_HOTEL_LEARNING_END -->', append: true,
    block: `    <!-- SUXI_HOTEL_LEARNING_BEGIN -->\n    <section v-if="currentPage === 'operating-finance'" class="my-4 space-y-4" aria-label="酒店成本与投资工具">\n${[
      ['operations', '耗材领用盘点与计划实绩复盘'], ['investment', '回本目标反推与合同安全垫'],
    ].map(([module, label]) => `        <details class="rounded-xl border border-slate-200 bg-white p-4" data-testid="hotel-learning-${module}-entry">\n            <summary class="cursor-pointer py-2 text-base font-semibold text-slate-800">${label}</summary>\n            <hotel-learning-workbench module="${module}" :request="managerCapabilityRequest" :hotels="hotels" :selected-hotel-id="filterReportHotel" :can-execute="operationFinanceCanExecute"></hotel-learning-workbench>\n        </details>\n`).join('')}    </section>\n    <!-- SUXI_HOTEL_LEARNING_END -->\n`,
  },
  ...[
    ['35-page-online-data.html', 'ota', '搜索价格观测与商圈样本比较'], ['20-page-knowledge-center.html', 'knowledge', '酒店资料来源与 AI 问答观测'],
  ].map(([file, module, label]) => ({
    file: `resources/frontend/templates/fragments/${file}`, start: '    <!-- SUXI_HOTEL_LEARNING_BEGIN -->', end: '    <!-- SUXI_HOTEL_LEARNING_END -->',
    block: `    <!-- SUXI_HOTEL_LEARNING_BEGIN -->\n    <details class="my-4 rounded-xl border border-slate-200 bg-white p-4" data-testid="hotel-learning-${module}-entry">\n        <summary class="cursor-pointer py-2 text-base font-semibold text-slate-800">${label}</summary>\n        <hotel-learning-workbench module="${module}" :request="managerCapabilityRequest" :hotels="hotels" :selected-hotel-id="filterReportHotel" :can-execute="operationFinanceCanExecute"></hotel-learning-workbench>\n    </details>\n    <!-- SUXI_HOTEL_LEARNING_END -->\n`,
  })),
];
const receipts = [];
for (const spec of specs) {
  const file = path.join(root, spec.file); const before = fs.readFileSync(file, 'utf8');
  const newline = before.includes('\r\n') ? '\r\n' : '\n';
  const block = spec.block.replaceAll('\n', newline);
  let bare = before;
  const start = before.indexOf(spec.start);
  if (start >= 0) {
    const end = before.indexOf(spec.end, start);
    if (end < 0 || before.indexOf(spec.start, start + spec.start.length) >= 0) throw new Error(`Ambiguous task block: ${spec.file}`);
    bare = before.slice(0, start) + before.slice(end + spec.end.length + newline.length);
  }
  const anchor = spec.append ? bare.length : (spec.anchor ? bare.indexOf(spec.anchor) + spec.anchor.length : bare.indexOf('>') + 1);
  if (anchor < 1 || (spec.anchor && !bare.includes(spec.anchor))) throw new Error(`Missing host anchor: ${spec.file}`);
  const insertion = spec.append ? bare.length : bare.indexOf(newline, anchor) + newline.length;
  if (insertion < newline.length) throw new Error(`Missing host line: ${spec.file}`);
  const after = bare.slice(0, insertion) + block + bare.slice(insertion);
  // Removing exactly our new region must restore the complete preexisting content.
  if (after.slice(0, insertion) + after.slice(insertion + block.length) !== bare) throw new Error('Unrelated source preservation failed');
  if (process.argv.includes('--verify')) { if (after !== before) throw new Error(`Learning host or resource version is stale: ${spec.file}`); }
  else if (after !== before) {
    if (fs.readFileSync(file, 'utf8') !== before) throw new Error(`Concurrent host changed: ${spec.file}`);
    writeFileAtomic(file, after);
  }
  receipts.push({ file: spec.file, before_sha256: hash(before), retained_source_sha256: hash(bare), after_sha256: hash(after), unrelated_source_preserved: true });
}
console.log(JSON.stringify({ component_sha256: hash(artifact), verified: process.argv.includes('--verify'), hosts: receipts }, null, 2));
