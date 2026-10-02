import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { compile, parse } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const fixturePath = 'tests/automation/operating_growth_event_save_recovery.test.mjs';
const definition = fs.readFileSync(fixturePath, 'utf8');
const start = definition.indexOf('const folder =');
const end = definition.indexOf("\ntest('normal real submit");
assert.ok(start > 0 && end > start);
let helper = definition.slice(start, end);
const replace = (before, after) => {
  assert.equal(helper.split(before).length, 2, 'one exact fixture-only substitution: ' + before.slice(0, 60));
  helper = helper.replace(before, after);
};
replace("const domain = slice('const operatingGrowthStaticScript =', 'const addOperatingGrowthAnnotation =');",
  "const domain = slice('const operatingGrowthStaticScript =', 'const openOperatingGrowthSource =');");
replace("const template = fs.readFileSync('resources/frontend/templates/fragments/17a-page-operating-growth-archive.html', 'utf8');",
  "const template = fs.readFileSync('resources/frontend/templates/fragments/17a-page-operating-growth-archive.html', 'utf8') + parse(fs.readFileSync('resources/frontend/templates/fragments/46-global-toast.html', 'utf8')).children.find(node => node.type === 1 && node.props.some(prop => prop.name === 'data-testid' && prop.value?.content === 'workflow-form-dialog')).loc.source;");
replace('let wrongDigest = false, tree,', 'const strictReadShapes = []; let wrongDigest = false, tree, pageTree,');
replace("assert.equal(url, '/operation/growth-archive/events');", "assert.match(url, /^\\/operation\\/growth-archive\\/\\d+\\/milestone$/);");
replace('const list = [...persisted.values()].filter(item => item.hotel_id === hotelId);',
  "const list = [...persisted.values()].filter(item => item.hotel_id === hotelId && item.lifecycle_status === 'active');");
replace("return bodyResponse({ code: 200, data: { ...readback, content_digest: wrongDigest ? 'synthetic-wrong-digest' : memory.content_digest } });",
  "strictReadShapes.push(clone(readback)); return bodyResponse({ code: 200, data: { ...readback, content_digest: wrongDigest ? 'synthetic-wrong-digest' : memory.content_digest } });");
replace('globalThis.ui = { operatingGrowthArchiveBody,', 'globalThis.ui = { workflowFormDialog, submitWorkflowFormDialog, closeWorkflowFormDialog, operatingGrowthBusyActionId, operatingGrowthArchiveBody,');
replace('render: renderPage }', 'render(...args) { pageTree = renderPage.apply(this, args); return pageTree; } }');

const commitStart = helper.indexOf('  const commit = (index, respond = true) => {');
const commitEnd = helper.indexOf('  return { ui, operationFilters,', commitStart);
assert.ok(commitStart > 0 && commitEnd > commitStart);
helper = helper.slice(0, commitStart) + `
  // Synthetic map adapter only. Mirrors markMilestone / persistGrowthRecord key and version rules.
  const commit = (index, respond = true) => {
    const post = posts[index], parentId = Number(post.url.match(/growth-archive\\/(\\d+)/)[1]), parent = persisted.get(parentId);
    assert.ok(parent && parent.memory_layer !== 'milestone');
    const note = String(post.body.note || '').trim(); assert.ok(note.length <= 2000);
    const digest = sha(JSON.stringify({ tenant_id: 5, hotel_id: parent.hotel_id, parent_memory_id: parentId, note, recorded_by: 42 }));
    const key = 'milestone:' + parentId + ':' + sha(post.body.client_request_id);
    const old = keys.get(key); assert.ok(!old || old.content_digest === digest);
    const previous = [...persisted.values()].filter(row => row.memory_layer === 'milestone' && row.source_record_id === parentId
      && row.hotel_id === parent.hotel_id && row.lifecycle_status === 'active').sort((a, b) => b.id - a.id)[0];
    const memory = old || { ...parent, id: Math.max(...persisted.keys()) + 1, memory_key: key, content_digest: digest,
      memory_layer: 'milestone', event_kind: 'milestone', title: '经营里程碑 · ' + parent.title,
      summary: note || '已由操作者设为经营里程碑', source_module: 'operating_growth_archive', source_record_type: 'hotel_operating_memory',
      source_record_id: parentId, previous_memory_id: previous?.id || parentId, parent_memory_id: parentId, is_milestone: true,
      is_owner_annotation: false, usage_level: 'archive_only', lifecycle_status: 'active', recorded_by: 42,
      occurred_at: '2026-09-15 10:00:00', created_at: '2026-09-15 10:00:00', updated_at: '2026-09-15 10:00:00', deleted_at: null,
      source_reference: { module: 'operating_growth_archive', record_type: 'hotel_operating_memory', record_id: parentId },
      evidence_refs: [{ type: 'hotel_operating_memory', id: parentId }],
      context: { event_kind: 'milestone', relation_type: 'milestone', parent_memory_id: parentId,
        parent_quality_status: parent.quality_status, marked_by: 42, marked_at: '2026-09-15 10:00:00' } };
    if (!old && previous) { previous.lifecycle_status = 'superseded'; previous.updated_at = '2026-09-15 10:00:00'; }
    keys.set(key, memory); persisted.set(memory.id, memory);
    if (respond) post.resolve(bodyResponse({ code: 200, data: { memory, created: !old,
      persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } } }));
    return memory;
  };
  const findDialog = predicate => { const node = flatten(pageTree).find(predicate); assert.ok(node, 'actual shared-dialog control'); return node; };
  const clickMilestone = async (id = 101) => {
    await render(); const card = find(node => String(node.props?.['data-event-id'] || '') === String(id));
    const button = flatten(card).find(node => node.type === 'button' && /^(设为里程碑|继续编辑里程碑|确认上次里程碑)$/.test(content(node)));
    assert.ok(button); if (button.props.disabled) return { disabled: true, done: Promise.resolve() };
    const before = tasks.length; button.props.onClick();
    return { disabled: false, done: tasks.length > before ? tasks.at(-1).promise : Promise.resolve() };
  };
  const typeNote = async value => {
    await render(); const field = findDialog(node => node.type === 'textarea');
    assert.ok(!field.props.disabled && !field.props.readonly && !field.props.maxlength);
    field.props['onUpdate:modelValue'](value); await Vue.nextTick();
  };
  const submitNote = async () => {
    await render(); const form = findDialog(node => node.type === 'form');
    const button = flatten(form).find(node => node.type === 'button' && node.props.type === 'submit');
    assert.match(content(button), /保存里程碑|确认上次里程碑/); assert.ok(!button.props.disabled);
    form.props.onSubmit({ preventDefault() {} }); await flush();
  };
  const cancelNote = async () => {
    await render(); findDialog(node => node.type === 'button' && content(node) === '取消').props.onClick(); await flush();
  };
` + helper.slice(commitEnd);
replace('return { ui, operationFilters, posts, gets,', 'return { clickMilestone, typeNote, submitNote, cancelNote, strictReadShapes, ui, operationFilters, posts, gets,');

replace('function fixture() {', 'function fixture(scope = {}) {');
replace("const operationFilters = Vue.ref({ hotel_id: '7' });", "const operationFilters = Vue.ref({ hotel_id: String(scope.hotelId || 7) });");
replace("const operationHotelOptions = Vue.ref([{ id: 7, name: '合成门店七' }, { id: 8, name: '合成门店八' }]);", "const operationHotelOptions = Vue.ref(scope.hotels || [{ id: 7, name: '合成门店七' }, { id: 8, name: '合成门店八' }]);");
replace('crypto: webcrypto,', 'crypto: { randomUUID: () => scope.requestIds?.shift() || webcrypto.randomUUID() },');
replace("authContext: Vue.ref({ permissionStatus: 'allowed', tenantId: '5', hotelId: '7' }),", "authContext: Vue.ref({ permissionStatus: 'allowed', tenantId: String(scope.tenantId || 5), hotelId: String(scope.hotelId || 7) }),");
replace("user: Vue.ref({ id: 42, hotel_id: 7, tenant_id: 5, is_super_admin: true }),", "user: Vue.ref({ id: scope.actorId || 42, hotel_id: scope.hotelId || 7, tenant_id: scope.tenantId || 5, is_super_admin: true }),");
replace("filterReportHotel: Vue.ref('7'),", "filterReportHotel: Vue.ref(String(scope.hotelId || 7)),");
const fixtureProcess = { env: {
  SUXI_GROWTH_MAIN_SOURCE: process.env.SUXI_MILESTONE_MAIN_SOURCE || 'public/app-main.js',
  SUXI_GROWTH_STATIC_SOURCE: process.env.SUXI_MILESTONE_STATIC_SOURCE || 'public/operating-growth-static.js',
} };
const { fixture, flush, bodyResponse } = new Function('assert', 'fs', 'vm', 'createHash', 'webcrypto', 'Vue', 'compile', 'parse', 'renderToString', 'process', helper + '\nreturn { fixture, flush, bodyResponse };')(
  assert, fs, vm, createHash, webcrypto, Vue, compile, parse, renderToString, fixtureProcess);
const clone = value => JSON.parse(JSON.stringify(value));
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();

// Frozen synthetic responses from the real OperatingMemoryService mark/read in the independent closed memoryDb probe (62 assertions).
const backendSamples = {
  "parent_read": {
    "id": 100,
    "tenant_id": 10,
    "hotel_id": 20,
    "memory_key": "synthetic-parent-100",
    "memory_layer": "decision",
    "title": "Synthetic archived decision",
    "summary": "Synthetic parent, no business action",
    "business_date": "2026-09-14",
    "platform": "ctrip",
    "source_scope": "ota_channel",
    "source_module": "synthetic_contract",
    "source_record_type": "synthetic_record",
    "source_record_id": 11,
    "quality_status": "partial",
    "usage_level": "reference",
    "lifecycle_status": "active",
    "content_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "previous_memory_id": 0,
    "recorded_by": 7,
    "occurred_at": "2026-09-14 09:00:00",
    "created_at": "2026-09-14 09:00:00",
    "updated_at": "2026-09-14 09:00:00",
    "deleted_at": null,
    "evidence_refs": [],
    "context": {
      "event_kind": "decision"
    }
  },
  "input_a": {
    "client_request_id": "synthetic-milestone-A",
    "note": "  Synthetic reason A  "
  },
  "input_b": {
    "client_request_id": "synthetic-milestone-B",
    "note": "Synthetic reason B"
  },
  "post_a": {
    "memory": {
      "tenant_id": 10,
      "hotel_id": 20,
      "memory_key": "milestone:100:f1a7a67a838aaedabab1619e9984eb0fd7e7ac9de97b028e5100210ae7f94cc9",
      "memory_layer": "milestone",
      "title": "经营里程碑 · Synthetic archived decision",
      "summary": "Synthetic reason A",
      "business_date": "2026-09-14",
      "platform": "ctrip",
      "source_scope": "ota_channel",
      "source_module": "operating_growth_archive",
      "source_record_type": "hotel_operating_memory",
      "source_record_id": 100,
      "quality_status": "partial",
      "usage_level": "archive_only",
      "lifecycle_status": "active",
      "content_digest": "8a0f5b4423d7a6021c9108ab798f120800cc916b41c67aee1a8f7c434d225606",
      "previous_memory_id": 100,
      "recorded_by": 7,
      "occurred_at": "2026-09-15 09:03:25",
      "created_at": "2026-09-15 09:03:25",
      "updated_at": "2026-09-15 09:03:25",
      "deleted_at": null,
      "id": 101,
      "evidence_refs": [
        {
          "type": "hotel_operating_memory",
          "id": 100
        }
      ],
      "context": {
        "event_kind": "milestone",
        "relation_type": "milestone",
        "parent_memory_id": 100,
        "parent_quality_status": "partial",
        "marked_at": "2026-09-15 09:03:25",
        "marked_by": 7
      },
      "event_kind": "milestone",
      "parent_memory_id": 100,
      "is_owner_annotation": false,
      "is_milestone": true,
      "source_reference": {
        "module": "operating_growth_archive",
        "record_type": "hotel_operating_memory",
        "record_id": 100
      }
    },
    "created": true,
    "persistence_status": "readback_verified",
    "write_boundaries": {
      "ota_write": false,
      "external_message": false
    }
  },
  "post_b": {
    "memory": {
      "tenant_id": 10,
      "hotel_id": 20,
      "memory_key": "milestone:100:cdbb232e1a530509c3ecaa709ff144e34b20afacf096c67277214ed01cf617e8",
      "memory_layer": "milestone",
      "title": "经营里程碑 · Synthetic archived decision",
      "summary": "Synthetic reason B",
      "business_date": "2026-09-14",
      "platform": "ctrip",
      "source_scope": "ota_channel",
      "source_module": "operating_growth_archive",
      "source_record_type": "hotel_operating_memory",
      "source_record_id": 100,
      "quality_status": "partial",
      "usage_level": "archive_only",
      "lifecycle_status": "active",
      "content_digest": "48a636c15205b8857feeb898a1fcf5e95bddf162bde7e42703109bf94300b425",
      "previous_memory_id": 101,
      "recorded_by": 7,
      "occurred_at": "2026-09-15 09:03:25",
      "created_at": "2026-09-15 09:03:25",
      "updated_at": "2026-09-15 09:03:25",
      "deleted_at": null,
      "id": 102,
      "evidence_refs": [
        {
          "type": "hotel_operating_memory",
          "id": 100
        }
      ],
      "context": {
        "event_kind": "milestone",
        "relation_type": "milestone",
        "parent_memory_id": 100,
        "parent_quality_status": "partial",
        "marked_at": "2026-09-15 09:03:25",
        "marked_by": 7
      },
      "event_kind": "milestone",
      "parent_memory_id": 100,
      "is_owner_annotation": false,
      "is_milestone": true,
      "source_reference": {
        "module": "operating_growth_archive",
        "record_type": "hotel_operating_memory",
        "record_id": 100
      }
    },
    "created": true,
    "persistence_status": "readback_verified",
    "write_boundaries": {
      "ota_write": false,
      "external_message": false
    }
  },
  "post_a_after_b": {
    "memory": {
      "tenant_id": 10,
      "hotel_id": 20,
      "memory_key": "milestone:100:f1a7a67a838aaedabab1619e9984eb0fd7e7ac9de97b028e5100210ae7f94cc9",
      "memory_layer": "milestone",
      "title": "经营里程碑 · Synthetic archived decision",
      "summary": "Synthetic reason A",
      "business_date": "2026-09-14",
      "platform": "ctrip",
      "source_scope": "ota_channel",
      "source_module": "operating_growth_archive",
      "source_record_type": "hotel_operating_memory",
      "source_record_id": 100,
      "quality_status": "partial",
      "usage_level": "archive_only",
      "lifecycle_status": "superseded",
      "content_digest": "8a0f5b4423d7a6021c9108ab798f120800cc916b41c67aee1a8f7c434d225606",
      "previous_memory_id": 100,
      "recorded_by": 7,
      "occurred_at": "2026-09-15 09:03:25",
      "created_at": "2026-09-15 09:03:25",
      "updated_at": "2026-09-15 09:03:25",
      "deleted_at": null,
      "id": 101,
      "evidence_refs": [
        {
          "type": "hotel_operating_memory",
          "id": 100
        }
      ],
      "context": {
        "event_kind": "milestone",
        "relation_type": "milestone",
        "parent_memory_id": 100,
        "parent_quality_status": "partial",
        "marked_at": "2026-09-15 09:03:25",
        "marked_by": 7
      },
      "event_kind": "milestone",
      "parent_memory_id": 100,
      "is_owner_annotation": false,
      "is_milestone": true,
      "source_reference": {
        "module": "operating_growth_archive",
        "record_type": "hotel_operating_memory",
        "record_id": 100
      }
    },
    "created": false,
    "persistence_status": "readback_verified",
    "write_boundaries": {
      "ota_write": false,
      "external_message": false
    }
  },
  "post_c_same_body_new_key": {
    "memory": {
      "tenant_id": 10,
      "hotel_id": 20,
      "memory_key": "milestone:100:daedc1bb9f81a4b1620feaedf87be91c1b1eafb04d8a98b61e36b15eec93d7f6",
      "memory_layer": "milestone",
      "title": "经营里程碑 · Synthetic archived decision",
      "summary": "Synthetic reason A",
      "business_date": "2026-09-14",
      "platform": "ctrip",
      "source_scope": "ota_channel",
      "source_module": "operating_growth_archive",
      "source_record_type": "hotel_operating_memory",
      "source_record_id": 100,
      "quality_status": "partial",
      "usage_level": "archive_only",
      "lifecycle_status": "active",
      "content_digest": "8a0f5b4423d7a6021c9108ab798f120800cc916b41c67aee1a8f7c434d225606",
      "previous_memory_id": 102,
      "recorded_by": 7,
      "occurred_at": "2026-09-15 09:03:25",
      "created_at": "2026-09-15 09:03:25",
      "updated_at": "2026-09-15 09:03:25",
      "deleted_at": null,
      "id": 103,
      "evidence_refs": [
        {
          "type": "hotel_operating_memory",
          "id": 100
        }
      ],
      "context": {
        "event_kind": "milestone",
        "relation_type": "milestone",
        "parent_memory_id": 100,
        "parent_quality_status": "partial",
        "marked_at": "2026-09-15 09:03:25",
        "marked_by": 7
      },
      "event_kind": "milestone",
      "parent_memory_id": 100,
      "is_owner_annotation": false,
      "is_milestone": true,
      "source_reference": {
        "module": "operating_growth_archive",
        "record_type": "hotel_operating_memory",
        "record_id": 100
      }
    },
    "created": true,
    "persistence_status": "readback_verified",
    "write_boundaries": {
      "ota_write": false,
      "external_message": false
    }
  },
  "post_b_after_c": {
    "memory": {
      "tenant_id": 10,
      "hotel_id": 20,
      "memory_key": "milestone:100:cdbb232e1a530509c3ecaa709ff144e34b20afacf096c67277214ed01cf617e8",
      "memory_layer": "milestone",
      "title": "经营里程碑 · Synthetic archived decision",
      "summary": "Synthetic reason B",
      "business_date": "2026-09-14",
      "platform": "ctrip",
      "source_scope": "ota_channel",
      "source_module": "operating_growth_archive",
      "source_record_type": "hotel_operating_memory",
      "source_record_id": 100,
      "quality_status": "partial",
      "usage_level": "archive_only",
      "lifecycle_status": "superseded",
      "content_digest": "48a636c15205b8857feeb898a1fcf5e95bddf162bde7e42703109bf94300b425",
      "previous_memory_id": 101,
      "recorded_by": 7,
      "occurred_at": "2026-09-15 09:03:25",
      "created_at": "2026-09-15 09:03:25",
      "updated_at": "2026-09-15 09:03:25",
      "deleted_at": null,
      "id": 102,
      "evidence_refs": [
        {
          "type": "hotel_operating_memory",
          "id": 100
        }
      ],
      "context": {
        "event_kind": "milestone",
        "relation_type": "milestone",
        "parent_memory_id": 100,
        "parent_quality_status": "partial",
        "marked_at": "2026-09-15 09:03:25",
        "marked_by": 7
      },
      "event_kind": "milestone",
      "parent_memory_id": 100,
      "is_owner_annotation": false,
      "is_milestone": true,
      "source_reference": {
        "module": "operating_growth_archive",
        "record_type": "hotel_operating_memory",
        "record_id": 100
      }
    },
    "created": false,
    "persistence_status": "readback_verified",
    "write_boundaries": {
      "ota_write": false,
      "external_message": false
    }
  },
  "get_a": {
    "tenant_id": 10,
    "hotel_id": 20,
    "memory_key": "milestone:100:f1a7a67a838aaedabab1619e9984eb0fd7e7ac9de97b028e5100210ae7f94cc9",
    "memory_layer": "milestone",
    "title": "经营里程碑 · Synthetic archived decision",
    "summary": "Synthetic reason A",
    "business_date": "2026-09-14",
    "platform": "ctrip",
    "source_scope": "ota_channel",
    "source_module": "operating_growth_archive",
    "source_record_type": "hotel_operating_memory",
    "source_record_id": 100,
    "quality_status": "partial",
    "usage_level": "archive_only",
    "lifecycle_status": "superseded",
    "content_digest": "8a0f5b4423d7a6021c9108ab798f120800cc916b41c67aee1a8f7c434d225606",
    "previous_memory_id": 100,
    "recorded_by": 7,
    "occurred_at": "2026-09-15 09:03:25",
    "created_at": "2026-09-15 09:03:25",
    "updated_at": "2026-09-15 09:03:25",
    "deleted_at": null,
    "id": 101,
    "evidence_refs": [
      {
        "type": "hotel_operating_memory",
        "id": 100
      }
    ],
    "context": {
      "event_kind": "milestone",
      "relation_type": "milestone",
      "parent_memory_id": 100,
      "parent_quality_status": "partial",
      "marked_at": "2026-09-15 09:03:25",
      "marked_by": 7
    }
  },
  "get_b": {
    "tenant_id": 10,
    "hotel_id": 20,
    "memory_key": "milestone:100:cdbb232e1a530509c3ecaa709ff144e34b20afacf096c67277214ed01cf617e8",
    "memory_layer": "milestone",
    "title": "经营里程碑 · Synthetic archived decision",
    "summary": "Synthetic reason B",
    "business_date": "2026-09-14",
    "platform": "ctrip",
    "source_scope": "ota_channel",
    "source_module": "operating_growth_archive",
    "source_record_type": "hotel_operating_memory",
    "source_record_id": 100,
    "quality_status": "partial",
    "usage_level": "archive_only",
    "lifecycle_status": "superseded",
    "content_digest": "48a636c15205b8857feeb898a1fcf5e95bddf162bde7e42703109bf94300b425",
    "previous_memory_id": 101,
    "recorded_by": 7,
    "occurred_at": "2026-09-15 09:03:25",
    "created_at": "2026-09-15 09:03:25",
    "updated_at": "2026-09-15 09:03:25",
    "deleted_at": null,
    "id": 102,
    "evidence_refs": [
      {
        "type": "hotel_operating_memory",
        "id": 100
      }
    ],
    "context": {
      "event_kind": "milestone",
      "relation_type": "milestone",
      "parent_memory_id": 100,
      "parent_quality_status": "partial",
      "marked_at": "2026-09-15 09:03:25",
      "marked_by": 7
    }
  }
};

async function ready() {
  const f = fixture();
  f.persisted.set(101, { id: 101, tenant_id: 5, hotel_id: 7, memory_layer: 'fact', event_kind: 'fact',
    business_date: '2026-09-15', occurred_at: '2026-09-15 09:00:00', title: '合成原始事件', summary: '仅合成事实',
    platform: 'ctrip', source_scope: 'ota_channel', source_module: 'operating_growth_archive',
    source_record_type: 'manual_operating_event', content_digest: 'a'.repeat(64), quality_status: 'partial',
    usage_level: 'reference', lifecycle_status: 'active', evidence_refs: [], context: { manual_record: true } });
  const read = await f.click('refresh'); await read.done; return f;
}
const versions = f => [...f.persisted.values()].filter(row => row.memory_layer === 'milestone');
async function submit(f, note, id = 101) {
  const task = await f.clickMilestone(id); assert.equal(task.disabled, false);
  await f.typeNote(note); await f.submitNote(); return task;
}
async function confirm(f, id = 101) {
  const task = await f.clickMilestone(id); assert.equal(task.disabled, false);
  assert.equal(f.ui.workflowFormDialog.value.submitText, '确认上次里程碑');
  assert.equal(f.ui.workflowFormDialog.value.fields.length, 0);
  await f.submitNote(); return task;
}

for (const note of ['合成里程碑说明', '']) test('actual milestone button saves, strictly reads and displays a valid note; blank=' + (note === ''), async () => {
  const f = await ready(), parent = clone(f.persisted.get(101));
  const task = await submit(f, note); assert.equal((await f.clickMilestone()).disabled, true);
  const saved = f.commit(0); await task.done;
  assert.equal(saved.summary, note || '已由操作者设为经营里程碑'); assert.deepEqual(f.persisted.get(101), parent);
  assert.equal(saved.quality_status, 'partial'); assert.equal(saved.usage_level, 'archive_only');
  assert.equal(saved.platform, parent.platform); assert.equal(saved.source_scope, parent.source_scope);
  const read = f.strictReadShapes.at(-1); assert.equal(read.context.relation_type, 'milestone'); assert.equal('is_milestone' in read, false);
  assert.ok(f.ui.operatingGrowthArchivePayload.value.list.some(row => row.id === saved.id));
  assert.match(await f.render(), /已设为里程碑/); assert.equal(f.notices.at(-1).type, 'success');
});

test('cancel preserves an editable same-record note without posting', async () => {
  const f = await ready(); const a = await f.clickMilestone(); await f.typeNote('合成暂存说明'); await f.cancelNote(); await a.done;
  assert.equal(f.posts.length, 0); const reopened = await f.clickMilestone();
  assert.equal(f.ui.workflowFormDialog.value.values.note, '合成暂存说明'); await f.cancelNote(); await reopened.done;
});

test('initial genuine 422 keeps the overlength note and allows an empty corrected version', async () => {
  const f = await ready(), note = '注'.repeat(2001); const a = await submit(f, note);
  f.posts[0].resolve(bodyResponse({ code: 422, message: '里程碑说明不能超过2000字' }, 422)); await a.done;
  assert.match(await f.render(), /继续编辑里程碑/); const b = await f.clickMilestone();
  assert.equal(f.ui.workflowFormDialog.value.values.note, note); await f.typeNote(''); await f.submitNote();
  assert.notEqual(f.posts[1].body.client_request_id, f.posts[0].body.client_request_id); f.commit(1); await b.done;
  assert.equal(versions(f).length, 1); assert.equal(versions(f)[0].summary, '已由操作者设为经营里程碑');
});

for (const failure of ['before-write-transport', 'committed-lost', 'http500-body422', 'strict-GET500', 'strict-digest']) test('unknown milestone confirms only the original request: ' + failure, async () => {
  const f = await ready(), note = '合成原说明 A'; const a = await submit(f, note);
  if (failure === 'before-write-transport') f.posts[0].reject(new Error('Synthetic unknown before commit'));
  else if (failure === 'committed-lost') { f.commit(0, false); f.posts[0].reject(new Error('Synthetic committed lost')); }
  else if (failure === 'http500-body422') { f.commit(0, false); f.posts[0].resolve(bodyResponse({ code: 422 }, 500)); }
  else { if (failure === 'strict-GET500') f.setReadFailure({ body: { code: 500 }, status: 500 }); else f.setWrongDigest(true); f.commit(0); }
  await a.done; assert.match(await f.render(), /确认上次里程碑/);
  const cancel = await f.clickMilestone(); assert.match(f.ui.workflowFormDialog.value.description, /合成原说明 A/);
  assert.equal(f.ui.workflowFormDialog.value.fields.length, 0); await f.cancelNote(); await cancel.done; assert.equal(f.posts.length, 1);
  const retry = await confirm(f); assert.equal((await f.clickMilestone()).disabled, true);
  assert.equal(f.posts[1].options.body, f.posts[0].options.body); assert.equal(f.posts[1].url, f.posts[0].url);
  f.setReadFailure(null); f.setWrongDigest(false); const saved = f.commit(1); await retry.done;
  assert.equal(versions(f).length, 1); assert.equal(f.posts.length, 2); assert.equal(saved.lifecycle_status, 'active');
  assert.doesNotMatch(await f.render(), /确认上次里程碑/);
  const b = await submit(f, '合成明确新版本 B'); assert.notEqual(f.posts[2].body.client_request_id, f.posts[0].body.client_request_id);
  const newer = f.commit(2); await b.done; assert.equal(versions(f).length, 2); assert.equal(newer.previous_memory_id, saved.id);
});

test('a later 422 cannot disprove the earlier unknown save', async () => {
  const f = await ready(); const a = await submit(f, '合成原说明'); f.commit(0, false); f.posts[0].reject(new Error('Synthetic unknown')); await a.done;
  const denied = await confirm(f); f.posts[1].resolve(bodyResponse({ code: 422 }, 422)); await denied.done;
  const retry = await confirm(f); assert.equal(f.posts[2].options.body, f.posts[0].options.body); f.commit(2); await retry.done;
  assert.equal(versions(f).length, 1);
});

test('post-confirmation force sends a second timeline HTTP and rejects the old snapshot', async () => {
  const f = await ready(); const a = await submit(f, '合成保存后必须可见');
  f.pauseTimeline(true); f.ignoreTimelineAbort(true); const old = await f.click('refresh'); await flush();
  const saved = f.commit(0); await flush(); await flush();
  const requestCount = f.timeline.length;
  if (requestCount !== 2) { f.timeline[0].resolve(f.timeline[0].response); await a.done; await old.done; }
  assert.equal(requestCount, 2);
  f.timeline[1].resolve(f.timeline[1].response); await a.done; await old.done;
  f.timeline[0].resolve(f.timeline[0].response); await flush();
  assert.ok(f.ui.operatingGrowthArchivePayload.value.list.some(row => row.id === saved.id)); assert.match(await f.render(), /合成保存后必须可见/);
  assert.equal(f.posts.length, 1);
});

test('confirmed milestone with failed timeline asks only to reread, not save another version', async () => {
  const f = await ready(); const a = await submit(f, '合成已保存'); f.pauseTimeline(true); f.commit(0); await flush(); await flush();
  f.timeline[0].resolve(bodyResponse({ code: 500, message: 'Synthetic list failure' }, 500)); await a.done;
  assert.equal(f.notices.at(-1).type, 'warning'); assert.match(f.notices.at(-1).message, /已保存.*列表刷新失败/);
  assert.doesNotMatch(await f.render(), /确认上次里程碑/); f.pauseTimeline(false);
  const read = await f.click('refresh'); await read.done; assert.match(await f.render(), /合成已保存/); assert.equal(f.posts.length, 1);
});

for (const scope of ['hotel', 'auth']) test('old ' + scope + ' failure cannot unlock or notify over a new milestone', async () => {
  const f = await ready(); const a = await submit(f, '合成旧范围'); let target = 101;
  if (scope === 'hotel') { target = 201; f.persisted.set(201, { ...f.persisted.get(101), id: 201, hotel_id: 8 }); await f.changeHotel(8); }
  else { f.token.value = 'synthetic-next-session'; await Vue.nextTick(); }
  const b = await submit(f, '合成新范围', target); const count = f.notices.length;
  f.posts[0].reject(new Error('Synthetic old failure')); await a.done;
  assert.equal((await f.clickMilestone(target)).disabled, true); assert.equal(f.notices.length, count);
  f.commit(1); await b.done; assert.equal(f.posts.length, 2);
});

test('page return confirms the original pending request and old completion cannot clear the new lock', async () => {
  const f = await ready(); const a = await submit(f, '合成跨页原说明');
  f.currentPage.value = 'compass'; await Vue.nextTick(); f.currentPage.value = 'operating-growth-archive'; await Vue.nextTick();
  const retry = await confirm(f); assert.equal(f.posts[1].options.body, f.posts[0].options.body);
  f.commit(0); await a.done; assert.equal((await f.clickMilestone()).disabled, true); f.commit(1); await retry.done;
  assert.equal(versions(f).length, 1);
});

test('different source keeps its own editable note and cannot inherit another pending request', async () => {
  const f = await ready(); const a = await submit(f, '合成来源101'); f.posts[0].reject(new Error('Synthetic unknown')); await a.done;
  f.persisted.set(201, { ...f.persisted.get(101), id: 201, title: '合成来源201' }); const read = await f.click('refresh'); await read.done;
  const b = await f.clickMilestone(201); assert.equal(f.ui.workflowFormDialog.value.values.note, '');
  await f.typeNote('合成来源201说明'); await f.submitNote(); f.commit(1); await b.done;
  const retry = await confirm(f, 101); assert.equal(f.posts[2].options.body, f.posts[0].options.body); f.commit(2); await retry.done;
  assert.equal(versions(f).length, 2);
});

for (const scope of ['hotel', 'auth', 'page']) test('changing ' + scope + ' while the real dialog is open cancels only the old target', async () => {
  const f = await ready(); const a = await f.clickMilestone(); await f.typeNote('合成不应错发');
  if (scope === 'hotel') f.operationFilters.value.hotel_id = '8';
  else if (scope === 'auth') f.token.value = 'synthetic-next-session'; else f.currentPage.value = 'compass';
  await Vue.nextTick(); const stillVisible = f.ui.workflowFormDialog.value.visible;
  if (stillVisible) await f.cancelNote();
  await a.done; assert.equal(stillVisible, false); assert.equal(f.posts.length, 0);
});

for (const invalid of ['summary', 'source-parent', 'layer', 'quality', 'usage', 'receipt-id', 'context-kind', 'source-module']) test('a mismatched ' + invalid + ' cannot confirm a saved milestone', async () => {
  const f = await ready(); const a = await submit(f, '合成精确说明'); const saved = f.commit(0, false), original = clone(saved);
  if (invalid === 'summary') saved.summary = 'wrong';
  if (invalid === 'source-parent') { saved.source_record_id = 999; saved.context.parent_memory_id = 999; }
  if (invalid === 'layer') saved.memory_layer = 'judgement';
  if (invalid === 'quality') saved.quality_status = 'verified';
  if (invalid === 'usage') saved.usage_level = 'decision';
  if (invalid === 'context-kind') saved.context.event_kind = 'judgement';
  if (invalid === 'source-module') saved.source_module = 'operation_execution';
  const receipt = { memory: invalid === 'receipt-id' ? { ...saved, id: true } : saved, persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } };
  f.posts[0].resolve(bodyResponse({ code: 200, data: receipt })); await a.done;
  assert.match(await f.render(), /确认上次里程碑/); Object.assign(saved, original);
  const retry = await confirm(f); assert.equal(f.posts[1].options.body, f.posts[0].options.body); f.commit(1); await retry.done;
  assert.equal(versions(f).length, 1);
});

for (const version of ['a', 'b']) test('real PHP sample permits superseded ' + version.toUpperCase() + ' replay and displays the later active version', async () => {
  const samples = clone(backendSamples), input = samples['input_' + version];
  const f = fixture({ hotelId: 20, tenantId: 10, actorId: 7, hotels: [{ id: 20, name: '合成合同酒店' }],
    requestIds: ['synthetic-unused-form', input.client_request_id] });
  f.persisted.set(100, samples.parent_read); const load = await f.click('refresh'); await load.done;
  const a = await submit(f, input.note.trim(), 100); assert.equal(f.posts[0].body.client_request_id, input.client_request_id);
  f.persisted.set(101, samples.post_a_after_b.memory);
  f.persisted.set(102, samples.post_b.memory);
  if (version === 'b') { f.persisted.set(102, samples.post_b_after_c.memory); f.persisted.set(103, samples.post_c_same_body_new_key.memory); }
  f.posts[0].reject(new Error('Synthetic original reply lost; later version already exists')); await a.done;
  const retry = await confirm(f, 100); assert.equal(f.posts[1].options.body, f.posts[0].options.body);
  f.posts[1].resolve(bodyResponse({ code: 200, data: version === 'a' ? samples.post_a_after_b : samples.post_b_after_c })); await retry.done;
  const expectedRead = samples['get_' + version]; assert.deepEqual(f.strictReadShapes.at(-1), expectedRead);
  assert.equal(f.notices.at(-1).type, 'success'); assert.match(f.notices.at(-1).message, /该版本已由后续里程碑替代/);
  const latestId = version === 'a' ? 102 : 103;
  assert.ok(f.ui.operatingGrowthArchivePayload.value.list.some(row => row.id === latestId));
  assert.ok(!f.ui.operatingGrowthArchivePayload.value.list.some(row => row.id === expectedRead.id));
  assert.equal(f.persisted.size, version === 'a' ? 3 : 4); assert.equal(f.posts.length, 2);
});
