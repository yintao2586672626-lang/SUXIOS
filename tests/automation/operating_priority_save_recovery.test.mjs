import assert from 'node:assert/strict';
import test, { afterEach } from 'node:test';
import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
const out = new URL('./', import.meta.url);
const files = { main: 'public/app-main.js', component: process.env.PRIORITY_COMPONENT_SOURCE || 'public/components/system/operating-opportunity-lab.js', system: 'public/system-static.js', template: 'resources/frontend/templates/fragments/19b-page-operating-opportunities.html' };
const raw = Object.fromEntries(Object.entries(files).map(([key, path]) => [key, readFileSync(path, 'utf8')]));
const main = raw.main.replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a); return main.slice(a, b); };
const requestSource = [
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  section('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'),
].join('\n');
assert.ok(main.includes('managerCapabilityRequest: apiRequest'));
const parentRender = new Function('Vue', compile(raw.template, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
// Pure-producer calibrated fixtures, embedded so the formal test never depends on output.
const samples = {
  "single_candidate_receipt_after": {
    "contract_version": "daily_one_thing_personalization.v1",
    "experience_version": "daily_one_thing.personalized_preview.v3",
    "status": "not_applied",
    "application_mode": "base_rank_exact_tie_break_only",
    "scope": {
      "tenant_id": 7,
      "user_id": 11,
      "hotel_id": 80
    },
    "base_selected_candidate_key": "round46_synthetic_ctrip_check",
    "selected_candidate_key": "round46_synthetic_ctrip_check",
    "selection_changed": false,
    "base_rank": [
      1,
      1,
      1,
      1
    ],
    "base_tie_group_size": 1,
    "why_you": {
      "summary": "当前没有使用个人偏好或反馈改变公共基础排序。",
      "reason_code": "personalization_not_applied"
    },
    "applied_adjustments": [],
    "not_applied_reasons": [
      "no_base_rank_tie"
    ],
    "preference_refs": [],
    "feedback_refs": [],
    "feedback_progress": [],
    "current_feedback": {
      "status": "recorded",
      "readback_verified": true,
      "feedback_status": "accepted",
      "reason_code": "useful",
      "feedback_ref": "ai_suggestion_calibration_feedback_events#2001",
      "recorded_at": "2026-09-15 10:00:01"
    },
    "context_digest": "464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e",
    "decision_digest": "4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77",
    "candidate_preferences_consumed": false,
    "facts_changed": false,
    "eligibility_changed": false,
    "business_rank_changed": false,
    "permissions_changed": false,
    "approval_changed": false,
    "external_write_authorized": false
  }
};
const calibrated = {
  "A|80|2026-09-15": {
    "selected": {
      "candidate_key": "round46_synthetic_ctrip_check",
      "source_type": "strict_fact_signal",
      "problem": "Synthetic same-scope read-only check",
      "scope": {
        "tenant_id": 7,
        "hotel_id": 80,
        "platform": "ctrip",
        "business_date": "2026-09-15",
        "metric_scope": "ota_channel"
      },
      "recommended_action": {
        "type": "human_reviewed_operating_check",
        "object": "ctrip_fact_scope"
      },
      "expected_observation_metric": {
        "key": "detail_exposure",
        "unit": "exposure_count",
        "baseline_value": 10
      },
      "source": {
        "record_id": 101,
        "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "fact_refs": [
          "online_daily_data#101"
        ],
        "gap_codes": []
      },
      "material_identity_digest": "8c76cd4166822a4fc4ccc18457a8c79f8a31b5aca1329e99a3a1610f8fb07d9d",
      "content_digest": "58405029ef65f760f13bf7845e6a243e14b78befbb9fce0bb86fa84a52377872"
    },
    "receipt": {
      "contract_version": "daily_one_thing_personalization.v1",
      "experience_version": "daily_one_thing.personalized_preview.v3",
      "status": "not_applied",
      "application_mode": "base_rank_exact_tie_break_only",
      "scope": {
        "tenant_id": 7,
        "user_id": 11,
        "hotel_id": 80
      },
      "base_selected_candidate_key": "round46_synthetic_ctrip_check",
      "selected_candidate_key": "round46_synthetic_ctrip_check",
      "selection_changed": false,
      "base_rank": [
        1,
        1,
        1,
        1
      ],
      "base_tie_group_size": 1,
      "why_you": {
        "summary": "当前没有使用个人偏好或反馈改变公共基础排序。",
        "reason_code": "personalization_not_applied"
      },
      "applied_adjustments": [],
      "not_applied_reasons": [
        "no_base_rank_tie"
      ],
      "preference_refs": [],
      "feedback_refs": [],
      "feedback_progress": [],
      "current_feedback": {
        "status": "not_recorded",
        "readback_verified": true,
        "feedback_status": null,
        "reason_code": null,
        "feedback_ref": null
      },
      "context_digest": "464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e",
      "decision_digest": "4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77",
      "candidate_preferences_consumed": false,
      "facts_changed": false,
      "eligibility_changed": false,
      "business_rank_changed": false,
      "permissions_changed": false,
      "approval_changed": false,
      "external_write_authorized": false
    }
  },
  "B|81|2026-09-15": {
    "selected": {
      "candidate_key": "round46_synthetic_ctrip_check",
      "source_type": "strict_fact_signal",
      "problem": "Synthetic personal preview B",
      "scope": {
        "tenant_id": 7,
        "hotel_id": 81,
        "platform": "ctrip",
        "business_date": "2026-09-15",
        "metric_scope": "ota_channel"
      },
      "recommended_action": {
        "type": "human_reviewed_operating_check",
        "object": "ctrip_fact_scope"
      },
      "expected_observation_metric": {
        "key": "detail_exposure",
        "unit": "exposure_count",
        "baseline_value": 10
      },
      "source": {
        "record_id": 101,
        "snapshot_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
        "fact_refs": [
          "online_daily_data#102"
        ],
        "gap_codes": []
      },
      "material_identity_digest": "efd563243941feb43d6550fcffa1faf772d8d766cbd29f1fee3a0c205de4b31a",
      "content_digest": "167baf1570a2a172c2682b34861658f20ffb65e65bb73d1fc4d1eac587176087"
    },
    "receipt": {
      "contract_version": "daily_one_thing_personalization.v1",
      "experience_version": "daily_one_thing.personalized_preview.v3",
      "status": "not_applied",
      "application_mode": "base_rank_exact_tie_break_only",
      "scope": {
        "tenant_id": 7,
        "user_id": 11,
        "hotel_id": 81
      },
      "base_selected_candidate_key": "round46_synthetic_ctrip_check",
      "selected_candidate_key": "round46_synthetic_ctrip_check",
      "selection_changed": false,
      "base_rank": [
        1,
        1,
        1,
        1
      ],
      "base_tie_group_size": 1,
      "why_you": {
        "summary": "当前没有使用个人偏好或反馈改变公共基础排序。",
        "reason_code": "personalization_not_applied"
      },
      "applied_adjustments": [],
      "not_applied_reasons": [
        "no_base_rank_tie"
      ],
      "preference_refs": [],
      "feedback_refs": [],
      "feedback_progress": [],
      "current_feedback": {
        "status": "not_recorded",
        "readback_verified": true,
        "feedback_status": null,
        "reason_code": null,
        "feedback_ref": null
      },
      "context_digest": "6e676d76e832d9cab798079b2fed7d720ce4005537798db3b338f81060debbe1",
      "decision_digest": "6dcf6bb39f7844b790c2a6d40a1ff5e7ccb4ba8da55c2ebb660a2cab29d3875a",
      "candidate_preferences_consumed": false,
      "facts_changed": false,
      "eligibility_changed": false,
      "business_rank_changed": false,
      "permissions_changed": false,
      "approval_changed": false,
      "external_write_authorized": false
    }
  },
  "B|80|2026-09-14": {
    "selected": {
      "candidate_key": "round46_synthetic_ctrip_check",
      "source_type": "strict_fact_signal",
      "problem": "Synthetic personal preview B",
      "scope": {
        "tenant_id": 7,
        "hotel_id": 80,
        "platform": "ctrip",
        "business_date": "2026-09-14",
        "metric_scope": "ota_channel"
      },
      "recommended_action": {
        "type": "human_reviewed_operating_check",
        "object": "ctrip_fact_scope"
      },
      "expected_observation_metric": {
        "key": "detail_exposure",
        "unit": "exposure_count",
        "baseline_value": 10
      },
      "source": {
        "record_id": 101,
        "snapshot_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
        "fact_refs": [
          "online_daily_data#102"
        ],
        "gap_codes": []
      },
      "material_identity_digest": "2a8870604391134c827a6f996b9b81d1ffc1c6b22a079b03c8703c4b4a15e1ed",
      "content_digest": "5e08d4dc0b64a9baf6584df52d8a77daee2080ca04c5152ef2864976f4c47449"
    },
    "receipt": {
      "contract_version": "daily_one_thing_personalization.v1",
      "experience_version": "daily_one_thing.personalized_preview.v3",
      "status": "not_applied",
      "application_mode": "base_rank_exact_tie_break_only",
      "scope": {
        "tenant_id": 7,
        "user_id": 11,
        "hotel_id": 80
      },
      "base_selected_candidate_key": "round46_synthetic_ctrip_check",
      "selected_candidate_key": "round46_synthetic_ctrip_check",
      "selection_changed": false,
      "base_rank": [
        1,
        1,
        1,
        1
      ],
      "base_tie_group_size": 1,
      "why_you": {
        "summary": "当前没有使用个人偏好或反馈改变公共基础排序。",
        "reason_code": "personalization_not_applied"
      },
      "applied_adjustments": [],
      "not_applied_reasons": [
        "no_base_rank_tie"
      ],
      "preference_refs": [],
      "feedback_refs": [],
      "feedback_progress": [],
      "current_feedback": {
        "status": "not_recorded",
        "readback_verified": true,
        "feedback_status": null,
        "reason_code": null,
        "feedback_ref": null
      },
      "context_digest": "464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e",
      "decision_digest": "4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77",
      "candidate_preferences_consumed": false,
      "facts_changed": false,
      "eligibility_changed": false,
      "business_rank_changed": false,
      "permissions_changed": false,
      "approval_changed": false,
      "external_write_authorized": false
    }
  }
};
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
let assertions = 0;
const eq = (value, expected, note) => { assertions++; assert.deepEqual(value, expected, note); };
const ok = (value, note) => { assertions++; assert.ok(value, note); };
function memoryHost() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null });
  const remove = child => { if (child.parent) { const i = child.parent.children.indexOf(child); if (i >= 0) child.parent.children.splice(i, 1); } child.parent = null; };
  return { root: node('root'), options: {
    createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(child, parent, anchor = null) { remove(child); child.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(child); else parent.children.splice(i, 0, child); },
    remove, patchProp: (n, key, old, value) => { n.props[key] = value; },
  } };
}
const activeProbes=new Set();
afterEach(async()=>{for(const p of activeProbes){for(const req of p.requests)if(!req.settled){req.settled=true;req.reject(new Error('synthetic fixture closed'));}p.stop();}activeProbes.clear();await tick();});
function harness() {
  const requests = [], notices = [];
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date, Intl, setTimeout, clearTimeout,
    console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 0,
    currentPage: Vue.ref('operating-opportunities'), filterReportHotel: Vue.ref('80'),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 80, name: 'Synthetic A', tenant_id: 7 }, { id: 81, name: 'Synthetic B', tenant_id: 7 }]), hotels: Vue.ref([]),
    user: Vue.ref({ id: 11, is_super_admin: true }), token: Vue.ref(''), revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: sandbox.authSessionEpoch, token: '' }), isAuthSessionCurrent: session => session.epoch === sandbox.authSessionEpoch,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    showToast: (message, type = 'success') => notices.push({ message, type }),
    fetch: (url, options) => new Promise((resolve, reject) => {
      assert.ok(url.startsWith('https://synthetic.invalid/api/operating-opportunities/'));
      assert.ok(['/api/operating-opportunities/overview', '/api/operating-opportunities/priority'].includes(new URL(url).pathname), 'Only synthetic overview and priority endpoints allowed');
      requests.push({ url, options, resolve, reject, settled: false });
    }),
  };
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = name => sandbox.appSystemStatic[name];
  vm.runInContext(requestSource + '\nglobalThis.actualRequest = request;', sandbox);
  const componentWindow = {};
  const FixedDate = class extends Date { constructor(...args) { super(...(args.length ? args : ['2026-09-15T04:00:00Z'])); } };
  vm.runInNewContext(raw.component, { window: componentWindow, Vue, Intl, Date: FixedDate, URLSearchParams });
  const definition = componentWindow.SUXI_SYSTEM_COMPONENTS.OperatingOpportunityLabBody;
  const host = memoryHost();
  const renderer = Vue.createRenderer(host.options);
  const app = renderer.createApp({
    components: { OperatingOpportunityLab: definition },
    setup: () => ({ currentPage: sandbox.currentPage, filterReportHotel: sandbox.filterReportHotel,
      hotels: [{ id: 80, name: 'Synthetic A' }, { id: 81, name: 'Synthetic B' }], managerCapabilityRequest: sandbox.actualRequest,
      assistantSessionEpoch: () => sandbox.authSessionEpoch, openHomeOperatingScheduleItem: () => { throw new Error('Business action outside probe'); }, showToast: sandbox.showToast }),
    render: parentRender,
  });
  app.config.warnHandler = () => {}; app.mount(host.root);
  const walk = (node, found = []) => { if (Array.isArray(node)) { node.forEach(n => walk(n, found)); return found; } if (!node || typeof node !== 'object') return found; found.push(node); if (node.component) walk(node.component.subTree, found); walk(node.children, found); return found; };
  const component = () => walk(app._instance.subTree).find(node => node.type?.name === 'OperatingOpportunityLabBody')?.component?.proxy || null;
  const nodes = () => component() ? walk(component().$.subTree) : [];
  const html = () => component() ? renderToString(Vue.createSSRApp({ render: () => definition.render.call(component()) })) : Promise.resolve('');
  const button = id => nodes().find(node => node.type === 'button' && node.props?.['data-testid'] === id);
  const save = () => { const n = button('daily-one-thing-save'); ok(n && !n.props.disabled, 'Only enabled actual priority control is clicked'); return { pending:n.props.onClick() }; };
  const refresh = () => { const n = nodes().find(node => node.type === 'button' && node.children === '刷新事实'); ok(n && !n.props.disabled, 'Actual refresh remains reachable'); const pending = n.props.onClick(); return { pending }; };
  const reply = (req, body, status = 200) => { req.settled = true; req.response = clone(body); req.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); };
  const pending = method => requests.find(row => !row.settled && String(row.options.method || 'GET') === method);
  const p={ sandbox, requests, notices, component, nodes, button, save, refresh, html, reply, pending, stop: () => app.unmount() };activeProbes.add(p);return p;
}
function overview(version = 'A', feedback = 'not_recorded', hotelId = 80, date = '2026-09-15') {
  const data = clone(calibrated[[version, hotelId, date].join('|')]);
  const receipt = data.receipt;
  if (feedback === 'recorded') receipt.current_feedback = clone(samples.single_candidate_receipt_after.current_feedback);
  else if (feedback === 'unavailable') receipt.current_feedback = { status: 'unavailable', readback_verified: false, reason_code: 'synthetic_feedback_read_failure' };
  const preview = { contract_version: 'daily_one_thing.v2', selected: data.selected, status: 'draft', selection_policy: { full_candidate_list_exposed: false } };
  return { code: 200, data: { contract_version: 'operating_opportunity_lab.v2', tenant_id: 7, system_hotel_id: hotelId, business_date: date,
    today: clone(preview), today_preview: clone(preview), personalized_today_preview: { ...clone(preview), personalization_receipt: receipt }, personalization_receipt: receipt,
    today_saved_run: null, today_execution_intent: null, today_state: 'not_saved' } };
}

// Original pure formatter/readback/projection samples, wrapped according to real endpoint code; no save/ensureIntent/DB.
const prioritySamples = {
  "post_success_shape_minimal": {
    "code": 200,
    "message": "今日一件事已保存并完成精确回读",
    "data": {
      "run": {
        "id": 901,
        "tenant_id": 7,
        "system_hotel_id": 80,
        "feature_key": "daily_one_thing",
        "feature_label": "今日一件事",
        "business_date": "2026-09-15",
        "source_quality_status": "readback_verified",
        "source_reference": "online_daily_data#101",
        "input": {
          "business_date": "2026-09-15",
          "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
          "selected_candidate_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "result": {
          "contract_version": "daily_one_thing.v2",
          "feature_key": "daily_one_thing",
          "business_date": "2026-09-15",
          "status": "draft",
          "selected": {
            "candidate_key": "round47_synthetic_fact",
            "source_type": "strict_fact_signal",
            "problem": "Synthetic same-scope operating check",
            "approval_status": "draft",
            "scope": {
              "tenant_id": 7,
              "hotel_id": 80,
              "business_date": "2026-09-15",
              "platform": "ctrip",
              "metric_scope": "ota_channel"
            },
            "recommended_action": {
              "type": "human_reviewed_operating_check",
              "title": "Synthetic read-only check",
              "description": "Synthetic fixture only",
              "object": "ctrip_fact_scope",
              "steps": []
            },
            "expected_observation_metric": {
              "key": "detail_exposure",
              "unit": "exposure_count",
              "baseline_value": 10
            },
            "responsibility": {
              "owner_id": 11,
              "due_at": "2026-09-15 18:00:00",
              "review_at": "2026-09-16 10:00:00"
            },
            "source": {
              "record_id": 101,
              "record_ref": "online_daily_data#101",
              "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
              "fact_refs": [
                "online_daily_data#101"
              ],
              "gap_codes": []
            },
            "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
          },
          "selection_policy": {
            "full_candidate_list_exposed": false
          },
          "external_write_allowed": false
        },
        "input_digest": "06ad5e55b977a90f0cb74e8570c000eaa61480aed1ebcd8ac7df488df498f857",
        "result_digest": "bc0ce1a7ebd1c95c40462fafb6878576ad7264af3456e972b5c49be99c29e049",
        "created_by": 11,
        "created_at": "2026-09-15 10:00:00",
        "record_readback_status": "readback_verified"
      },
      "replayed": false,
      "readback_verified": true,
      "execution_intent": {
        "id": 301,
        "tenant_id": 7,
        "hotel_id": 80,
        "source_module": "daily_one_thing",
        "source_record_id": 901,
        "status": "pending_approval",
        "tasks": [],
        "action_management": {
          "contract_version": "operation_action_card.v2",
          "action_card": {
            "contract_version": "operation_action_card.v2",
            "trace": {
              "daily_selection_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
            }
          },
          "lifecycle": {
            "status": "pending_approval"
          }
        }
      },
      "execution_intent_id": 301,
      "execution_task_count": 0,
      "lifecycle_status": "pending_approval",
      "external_action_triggered": false,
      "external_write_count": 0
    }
  },
  "overview_saved_current_shape_minimal": {
    "code": 200,
    "data": {
      "contract_version": "operating_opportunity_lab.v2",
      "tenant_id": 7,
      "system_hotel_id": 80,
      "business_date": "2026-09-15",
      "today": {
        "contract_version": "daily_one_thing.v2",
        "feature_key": "daily_one_thing",
        "business_date": "2026-09-15",
        "status": "pending_approval",
        "selected": {
          "candidate_key": "round47_synthetic_fact",
          "source_type": "strict_fact_signal",
          "problem": "Synthetic same-scope operating check",
          "approval_status": "pending_approval",
          "scope": {
            "tenant_id": 7,
            "hotel_id": 80,
            "business_date": "2026-09-15",
            "platform": "ctrip",
            "metric_scope": "ota_channel"
          },
          "recommended_action": {
            "type": "human_reviewed_operating_check",
            "title": "Synthetic read-only check",
            "description": "Synthetic fixture only",
            "object": "ctrip_fact_scope",
            "steps": []
          },
          "expected_observation_metric": {
            "key": "detail_exposure",
            "unit": "exposure_count",
            "baseline_value": 10
          },
          "responsibility": {
            "owner_id": 11,
            "due_at": "2026-09-15 18:00:00",
            "review_at": "2026-09-16 10:00:00"
          },
          "source": {
            "record_id": 101,
            "record_ref": "online_daily_data#101",
            "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            "fact_refs": [
              "online_daily_data#101"
            ],
            "gap_codes": []
          },
          "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317",
          "execution_intent_id": 301,
          "execution_task_id": 0
        },
        "selection_policy": {
          "full_candidate_list_exposed": false
        },
        "external_write_allowed": false,
        "execution_intent_id": 301,
        "execution_task_id": 0,
        "lifecycle": {
          "status": "pending_approval"
        },
        "task_count": 0,
        "external_write_performed_by_system": false
      },
      "today_preview": {
        "contract_version": "daily_one_thing.v2",
        "feature_key": "daily_one_thing",
        "business_date": "2026-09-15",
        "status": "draft",
        "selected": {
          "candidate_key": "round47_synthetic_fact",
          "source_type": "strict_fact_signal",
          "problem": "Synthetic same-scope operating check",
          "approval_status": "draft",
          "scope": {
            "tenant_id": 7,
            "hotel_id": 80,
            "business_date": "2026-09-15",
            "platform": "ctrip",
            "metric_scope": "ota_channel"
          },
          "recommended_action": {
            "type": "human_reviewed_operating_check",
            "title": "Synthetic read-only check",
            "description": "Synthetic fixture only",
            "object": "ctrip_fact_scope",
            "steps": []
          },
          "expected_observation_metric": {
            "key": "detail_exposure",
            "unit": "exposure_count",
            "baseline_value": 10
          },
          "responsibility": {
            "owner_id": 11,
            "due_at": "2026-09-15 18:00:00",
            "review_at": "2026-09-16 10:00:00"
          },
          "source": {
            "record_id": 101,
            "record_ref": "online_daily_data#101",
            "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            "fact_refs": [
              "online_daily_data#101"
            ],
            "gap_codes": []
          },
          "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "selection_policy": {
          "full_candidate_list_exposed": false
        },
        "external_write_allowed": false
      },
      "today_saved_run": {
        "id": 901,
        "tenant_id": 7,
        "system_hotel_id": 80,
        "feature_key": "daily_one_thing",
        "feature_label": "今日一件事",
        "business_date": "2026-09-15",
        "source_quality_status": "readback_verified",
        "source_reference": "online_daily_data#101",
        "input": {
          "business_date": "2026-09-15",
          "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
          "selected_candidate_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "result": {
          "contract_version": "daily_one_thing.v2",
          "feature_key": "daily_one_thing",
          "business_date": "2026-09-15",
          "status": "draft",
          "selected": {
            "candidate_key": "round47_synthetic_fact",
            "source_type": "strict_fact_signal",
            "problem": "Synthetic same-scope operating check",
            "approval_status": "draft",
            "scope": {
              "tenant_id": 7,
              "hotel_id": 80,
              "business_date": "2026-09-15",
              "platform": "ctrip",
              "metric_scope": "ota_channel"
            },
            "recommended_action": {
              "type": "human_reviewed_operating_check",
              "title": "Synthetic read-only check",
              "description": "Synthetic fixture only",
              "object": "ctrip_fact_scope",
              "steps": []
            },
            "expected_observation_metric": {
              "key": "detail_exposure",
              "unit": "exposure_count",
              "baseline_value": 10
            },
            "responsibility": {
              "owner_id": 11,
              "due_at": "2026-09-15 18:00:00",
              "review_at": "2026-09-16 10:00:00"
            },
            "source": {
              "record_id": 101,
              "record_ref": "online_daily_data#101",
              "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
              "fact_refs": [
                "online_daily_data#101"
              ],
              "gap_codes": []
            },
            "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
          },
          "selection_policy": {
            "full_candidate_list_exposed": false
          },
          "external_write_allowed": false
        },
        "input_digest": "06ad5e55b977a90f0cb74e8570c000eaa61480aed1ebcd8ac7df488df498f857",
        "result_digest": "bc0ce1a7ebd1c95c40462fafb6878576ad7264af3456e972b5c49be99c29e049",
        "created_by": 11,
        "created_at": "2026-09-15 10:00:00",
        "record_readback_status": "readback_verified"
      },
      "today_execution_intent": {
        "id": 301,
        "tenant_id": 7,
        "hotel_id": 80,
        "source_module": "daily_one_thing",
        "source_record_id": 901,
        "status": "pending_approval",
        "tasks": [],
        "action_management": {
          "contract_version": "operation_action_card.v2",
          "action_card": {
            "contract_version": "operation_action_card.v2",
            "trace": {
              "daily_selection_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
            }
          },
          "lifecycle": {
            "status": "pending_approval"
          }
        }
      },
      "today_execution_intent_id": 301,
      "today_execution_task_id": 0,
      "today_lifecycle_status": "pending_approval",
      "today_state": "saved_current",
      "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
    }
  }
};
function priorityReceipt(hotelId=80,date='2026-09-15'){
  assert.equal(hotelId,80);assert.equal(date,'2026-09-15');return clone(prioritySamples.post_success_shape_minimal);
}
function savedOverview(hotelId=80,date='2026-09-15'){
  assert.equal(hotelId,80);assert.equal(date,'2026-09-15');return clone(prioritySamples.overview_saved_current_shape_minimal);
}
async function ready(p){await tick();const req=p.pending('GET');ok(req,'actual mounted overview GET');p.reply(req,overview());await tick();ok(p.button('daily-one-thing-save'),'actual shared daily card can save');}
async function save(p){const a=p.save();await tick();const req=p.requests.filter(r=>r.options.method==='POST').at(-1);ok(req);eq(new URL(req.url).pathname,'/api/operating-opportunities/priority');eq(p.button('daily-one-thing-save'),undefined,'save control hides while same-scope write is pending');return{...a,req};}
async function change(p,type,value,body){const field=p.nodes().find(n=>n.type===(type==='hotel'?'select':'input')&&(type==='hotel'||n.props.type==='date'));ok(field&&!field.props.disabled,'real scope control remains editable');if(type==='hotel')field.props.onChange({target:{value}});else field.props.onInput({target:{value}});await tick();const req=p.requests.filter(r=>!r.settled&&(r.options.method||'GET')==='GET').at(-1);ok(req);p.reply(req,body);await tick();}

const stateSamples = {
  "evidence": "Actual overview today_state expression and original pure identity/projection/guard. An unavailable intent is modeled as null per readDailyExecutionIntent catch path; no overview/query/save/approval is run. Changed fresh material verified by original identity helper; source readiness is an explicit true input, not real data quality evidence.",
  "original_state_expression": "!$strictFactReady\n                ? 'source_unavailable'\n                : ($savedPriorityRun === null\n                    ? 'not_saved'\n                    : ($savedPriorityIsCurrent\n                        ? ($dailyIntent === null ? 'saved_without_lifecycle' : 'saved_current')\n                        : 'saved_stale'))",
  "without_lifecycle": {
    "code": 200,
    "data": {
      "contract_version": "operating_opportunity_lab.v2",
      "tenant_id": 7,
      "system_hotel_id": 80,
      "business_date": "2026-09-15",
      "today": {
        "contract_version": "daily_one_thing.v2",
        "feature_key": "daily_one_thing",
        "business_date": "2026-09-15",
        "status": "draft",
        "selected": {
          "candidate_key": "round47_synthetic_fact",
          "source_type": "strict_fact_signal",
          "problem": "Synthetic same-scope operating check",
          "approval_status": "draft",
          "scope": {
            "tenant_id": 7,
            "hotel_id": 80,
            "business_date": "2026-09-15",
            "platform": "ctrip",
            "metric_scope": "ota_channel"
          },
          "recommended_action": {
            "type": "human_reviewed_operating_check",
            "title": "Synthetic read-only check",
            "description": "Synthetic fixture only",
            "object": "ctrip_fact_scope",
            "steps": []
          },
          "expected_observation_metric": {
            "key": "detail_exposure",
            "unit": "exposure_count",
            "baseline_value": 10
          },
          "responsibility": {
            "owner_id": 11,
            "due_at": "2026-09-15 18:00:00",
            "review_at": "2026-09-16 10:00:00"
          },
          "source": {
            "record_id": 101,
            "record_ref": "online_daily_data#101",
            "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            "fact_refs": [
              "online_daily_data#101"
            ],
            "gap_codes": []
          },
          "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "selection_policy": {
          "full_candidate_list_exposed": false
        },
        "external_write_allowed": false
      },
      "today_preview": {
        "contract_version": "daily_one_thing.v2",
        "feature_key": "daily_one_thing",
        "business_date": "2026-09-15",
        "status": "draft",
        "selected": {
          "candidate_key": "round47_synthetic_fact",
          "source_type": "strict_fact_signal",
          "problem": "Synthetic same-scope operating check",
          "approval_status": "draft",
          "scope": {
            "tenant_id": 7,
            "hotel_id": 80,
            "business_date": "2026-09-15",
            "platform": "ctrip",
            "metric_scope": "ota_channel"
          },
          "recommended_action": {
            "type": "human_reviewed_operating_check",
            "title": "Synthetic read-only check",
            "description": "Synthetic fixture only",
            "object": "ctrip_fact_scope",
            "steps": []
          },
          "expected_observation_metric": {
            "key": "detail_exposure",
            "unit": "exposure_count",
            "baseline_value": 10
          },
          "responsibility": {
            "owner_id": 11,
            "due_at": "2026-09-15 18:00:00",
            "review_at": "2026-09-16 10:00:00"
          },
          "source": {
            "record_id": 101,
            "record_ref": "online_daily_data#101",
            "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            "fact_refs": [
              "online_daily_data#101"
            ],
            "gap_codes": []
          },
          "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "selection_policy": {
          "full_candidate_list_exposed": false
        },
        "external_write_allowed": false
      },
      "today_saved_run": {
        "id": 901,
        "tenant_id": 7,
        "system_hotel_id": 80,
        "feature_key": "daily_one_thing",
        "feature_label": "今日一件事",
        "business_date": "2026-09-15",
        "source_quality_status": "readback_verified",
        "source_reference": "online_daily_data#101",
        "input": {
          "business_date": "2026-09-15",
          "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
          "selected_candidate_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "result": {
          "contract_version": "daily_one_thing.v2",
          "feature_key": "daily_one_thing",
          "business_date": "2026-09-15",
          "status": "draft",
          "selected": {
            "candidate_key": "round47_synthetic_fact",
            "source_type": "strict_fact_signal",
            "problem": "Synthetic same-scope operating check",
            "approval_status": "draft",
            "scope": {
              "tenant_id": 7,
              "hotel_id": 80,
              "business_date": "2026-09-15",
              "platform": "ctrip",
              "metric_scope": "ota_channel"
            },
            "recommended_action": {
              "type": "human_reviewed_operating_check",
              "title": "Synthetic read-only check",
              "description": "Synthetic fixture only",
              "object": "ctrip_fact_scope",
              "steps": []
            },
            "expected_observation_metric": {
              "key": "detail_exposure",
              "unit": "exposure_count",
              "baseline_value": 10
            },
            "responsibility": {
              "owner_id": 11,
              "due_at": "2026-09-15 18:00:00",
              "review_at": "2026-09-16 10:00:00"
            },
            "source": {
              "record_id": 101,
              "record_ref": "online_daily_data#101",
              "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
              "fact_refs": [
                "online_daily_data#101"
              ],
              "gap_codes": []
            },
            "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
          },
          "selection_policy": {
            "full_candidate_list_exposed": false
          },
          "external_write_allowed": false
        },
        "input_digest": "06ad5e55b977a90f0cb74e8570c000eaa61480aed1ebcd8ac7df488df498f857",
        "result_digest": "bc0ce1a7ebd1c95c40462fafb6878576ad7264af3456e972b5c49be99c29e049",
        "created_by": 11,
        "created_at": "2026-09-15 10:00:00",
        "record_readback_status": "readback_verified"
      },
      "today_execution_intent": null,
      "today_execution_intent_id": 0,
      "today_execution_task_id": 0,
      "today_lifecycle_status": "pending_approval",
      "today_state": "saved_without_lifecycle",
      "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
    }
  },
  "approved_replay": {
    "code": 200,
    "message": "今日一件事已保存并完成精确回读",
    "data": {
      "run": {
        "id": 901,
        "tenant_id": 7,
        "system_hotel_id": 80,
        "feature_key": "daily_one_thing",
        "feature_label": "今日一件事",
        "business_date": "2026-09-15",
        "source_quality_status": "readback_verified",
        "source_reference": "online_daily_data#101",
        "input": {
          "business_date": "2026-09-15",
          "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
          "selected_candidate_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "result": {
          "contract_version": "daily_one_thing.v2",
          "feature_key": "daily_one_thing",
          "business_date": "2026-09-15",
          "status": "draft",
          "selected": {
            "candidate_key": "round47_synthetic_fact",
            "source_type": "strict_fact_signal",
            "problem": "Synthetic same-scope operating check",
            "approval_status": "draft",
            "scope": {
              "tenant_id": 7,
              "hotel_id": 80,
              "business_date": "2026-09-15",
              "platform": "ctrip",
              "metric_scope": "ota_channel"
            },
            "recommended_action": {
              "type": "human_reviewed_operating_check",
              "title": "Synthetic read-only check",
              "description": "Synthetic fixture only",
              "object": "ctrip_fact_scope",
              "steps": []
            },
            "expected_observation_metric": {
              "key": "detail_exposure",
              "unit": "exposure_count",
              "baseline_value": 10
            },
            "responsibility": {
              "owner_id": 11,
              "due_at": "2026-09-15 18:00:00",
              "review_at": "2026-09-16 10:00:00"
            },
            "source": {
              "record_id": 101,
              "record_ref": "online_daily_data#101",
              "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
              "fact_refs": [
                "online_daily_data#101"
              ],
              "gap_codes": []
            },
            "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
          },
          "selection_policy": {
            "full_candidate_list_exposed": false
          },
          "external_write_allowed": false
        },
        "input_digest": "06ad5e55b977a90f0cb74e8570c000eaa61480aed1ebcd8ac7df488df498f857",
        "result_digest": "bc0ce1a7ebd1c95c40462fafb6878576ad7264af3456e972b5c49be99c29e049",
        "created_by": 11,
        "created_at": "2026-09-15 10:00:00",
        "record_readback_status": "readback_verified"
      },
      "replayed": true,
      "readback_verified": true,
      "execution_intent": {
        "id": 301,
        "tenant_id": 7,
        "hotel_id": 80,
        "source_module": "daily_one_thing",
        "source_record_id": 901,
        "status": "approved",
        "tasks": [
          {
            "id": 701,
            "status": "pending"
          }
        ],
        "action_management": {
          "contract_version": "operation_action_card.v2",
          "action_card": {
            "contract_version": "operation_action_card.v2",
            "trace": {
              "daily_selection_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
            }
          },
          "lifecycle": {
            "status": "approved"
          }
        }
      },
      "execution_intent_id": 301,
      "execution_task_count": 1,
      "lifecycle_status": "approved",
      "external_action_triggered": false,
      "external_write_count": 0
    }
  },
  "approved_read": {
    "code": 200,
    "data": {
      "contract_version": "operating_opportunity_lab.v2",
      "tenant_id": 7,
      "system_hotel_id": 80,
      "business_date": "2026-09-15",
      "today": {
        "contract_version": "daily_one_thing.v2",
        "feature_key": "daily_one_thing",
        "business_date": "2026-09-15",
        "status": "approved",
        "selected": {
          "candidate_key": "round47_synthetic_fact",
          "source_type": "strict_fact_signal",
          "problem": "Synthetic same-scope operating check",
          "approval_status": "approved",
          "scope": {
            "tenant_id": 7,
            "hotel_id": 80,
            "business_date": "2026-09-15",
            "platform": "ctrip",
            "metric_scope": "ota_channel"
          },
          "recommended_action": {
            "type": "human_reviewed_operating_check",
            "title": "Synthetic read-only check",
            "description": "Synthetic fixture only",
            "object": "ctrip_fact_scope",
            "steps": []
          },
          "expected_observation_metric": {
            "key": "detail_exposure",
            "unit": "exposure_count",
            "baseline_value": 10
          },
          "responsibility": {
            "owner_id": 11,
            "due_at": "2026-09-15 18:00:00",
            "review_at": "2026-09-16 10:00:00"
          },
          "source": {
            "record_id": 101,
            "record_ref": "online_daily_data#101",
            "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            "fact_refs": [
              "online_daily_data#101"
            ],
            "gap_codes": []
          },
          "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317",
          "execution_intent_id": 301,
          "execution_task_id": 701
        },
        "selection_policy": {
          "full_candidate_list_exposed": false
        },
        "external_write_allowed": false,
        "execution_intent_id": 301,
        "execution_task_id": 701,
        "lifecycle": {
          "status": "approved"
        },
        "task_count": 1,
        "external_write_performed_by_system": false
      },
      "today_preview": {
        "contract_version": "daily_one_thing.v2",
        "feature_key": "daily_one_thing",
        "business_date": "2026-09-15",
        "status": "draft",
        "selected": {
          "candidate_key": "round47_synthetic_fact",
          "source_type": "strict_fact_signal",
          "problem": "Synthetic same-scope operating check",
          "approval_status": "draft",
          "scope": {
            "tenant_id": 7,
            "hotel_id": 80,
            "business_date": "2026-09-15",
            "platform": "ctrip",
            "metric_scope": "ota_channel"
          },
          "recommended_action": {
            "type": "human_reviewed_operating_check",
            "title": "Synthetic read-only check",
            "description": "Synthetic fixture only",
            "object": "ctrip_fact_scope",
            "steps": []
          },
          "expected_observation_metric": {
            "key": "detail_exposure",
            "unit": "exposure_count",
            "baseline_value": 10
          },
          "responsibility": {
            "owner_id": 11,
            "due_at": "2026-09-15 18:00:00",
            "review_at": "2026-09-16 10:00:00"
          },
          "source": {
            "record_id": 101,
            "record_ref": "online_daily_data#101",
            "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            "fact_refs": [
              "online_daily_data#101"
            ],
            "gap_codes": []
          },
          "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "selection_policy": {
          "full_candidate_list_exposed": false
        },
        "external_write_allowed": false
      },
      "today_saved_run": {
        "id": 901,
        "tenant_id": 7,
        "system_hotel_id": 80,
        "feature_key": "daily_one_thing",
        "feature_label": "今日一件事",
        "business_date": "2026-09-15",
        "source_quality_status": "readback_verified",
        "source_reference": "online_daily_data#101",
        "input": {
          "business_date": "2026-09-15",
          "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
          "selected_candidate_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "result": {
          "contract_version": "daily_one_thing.v2",
          "feature_key": "daily_one_thing",
          "business_date": "2026-09-15",
          "status": "draft",
          "selected": {
            "candidate_key": "round47_synthetic_fact",
            "source_type": "strict_fact_signal",
            "problem": "Synthetic same-scope operating check",
            "approval_status": "draft",
            "scope": {
              "tenant_id": 7,
              "hotel_id": 80,
              "business_date": "2026-09-15",
              "platform": "ctrip",
              "metric_scope": "ota_channel"
            },
            "recommended_action": {
              "type": "human_reviewed_operating_check",
              "title": "Synthetic read-only check",
              "description": "Synthetic fixture only",
              "object": "ctrip_fact_scope",
              "steps": []
            },
            "expected_observation_metric": {
              "key": "detail_exposure",
              "unit": "exposure_count",
              "baseline_value": 10
            },
            "responsibility": {
              "owner_id": 11,
              "due_at": "2026-09-15 18:00:00",
              "review_at": "2026-09-16 10:00:00"
            },
            "source": {
              "record_id": 101,
              "record_ref": "online_daily_data#101",
              "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
              "fact_refs": [
                "online_daily_data#101"
              ],
              "gap_codes": []
            },
            "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
          },
          "selection_policy": {
            "full_candidate_list_exposed": false
          },
          "external_write_allowed": false
        },
        "input_digest": "06ad5e55b977a90f0cb74e8570c000eaa61480aed1ebcd8ac7df488df498f857",
        "result_digest": "bc0ce1a7ebd1c95c40462fafb6878576ad7264af3456e972b5c49be99c29e049",
        "created_by": 11,
        "created_at": "2026-09-15 10:00:00",
        "record_readback_status": "readback_verified"
      },
      "today_execution_intent": {
        "id": 301,
        "tenant_id": 7,
        "hotel_id": 80,
        "source_module": "daily_one_thing",
        "source_record_id": 901,
        "status": "approved",
        "tasks": [
          {
            "id": 701,
            "status": "pending"
          }
        ],
        "action_management": {
          "contract_version": "operation_action_card.v2",
          "action_card": {
            "contract_version": "operation_action_card.v2",
            "trace": {
              "daily_selection_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
            }
          },
          "lifecycle": {
            "status": "approved"
          }
        }
      },
      "today_execution_intent_id": 301,
      "today_execution_task_id": 701,
      "today_lifecycle_status": "approved",
      "today_state": "saved_current",
      "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
    }
  },
  "stale_read": {
    "code": 200,
    "data": {
      "contract_version": "operating_opportunity_lab.v2",
      "tenant_id": 7,
      "system_hotel_id": 80,
      "business_date": "2026-09-15",
      "today": {
        "contract_version": "daily_one_thing.v2",
        "feature_key": "daily_one_thing",
        "business_date": "2026-09-15",
        "status": "draft",
        "selected": {
          "candidate_key": "round47_synthetic_changed_fact",
          "source_type": "strict_fact_signal",
          "problem": "Synthetic changed current fact",
          "approval_status": "draft",
          "scope": {
            "tenant_id": 7,
            "hotel_id": 80,
            "business_date": "2026-09-15",
            "platform": "ctrip",
            "metric_scope": "ota_channel"
          },
          "recommended_action": {
            "type": "human_reviewed_operating_check",
            "title": "Synthetic read-only check",
            "description": "Synthetic fixture only",
            "object": "ctrip_fact_scope",
            "steps": []
          },
          "expected_observation_metric": {
            "key": "detail_exposure",
            "unit": "exposure_count",
            "baseline_value": 10
          },
          "responsibility": {
            "owner_id": 11,
            "due_at": "2026-09-15 18:00:00",
            "review_at": "2026-09-16 10:00:00"
          },
          "source": {
            "record_id": 101,
            "record_ref": "online_daily_data#101",
            "snapshot_digest": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
            "fact_refs": [
              "online_daily_data#101"
            ],
            "gap_codes": []
          },
          "content_digest": "25c13c8d69a1a698951e342b055819e2b7312a272b310c3cda78c133bdc37c2c"
        },
        "selection_policy": {
          "full_candidate_list_exposed": false
        },
        "external_write_allowed": false
      },
      "today_preview": {
        "contract_version": "daily_one_thing.v2",
        "feature_key": "daily_one_thing",
        "business_date": "2026-09-15",
        "status": "draft",
        "selected": {
          "candidate_key": "round47_synthetic_changed_fact",
          "source_type": "strict_fact_signal",
          "problem": "Synthetic changed current fact",
          "approval_status": "draft",
          "scope": {
            "tenant_id": 7,
            "hotel_id": 80,
            "business_date": "2026-09-15",
            "platform": "ctrip",
            "metric_scope": "ota_channel"
          },
          "recommended_action": {
            "type": "human_reviewed_operating_check",
            "title": "Synthetic read-only check",
            "description": "Synthetic fixture only",
            "object": "ctrip_fact_scope",
            "steps": []
          },
          "expected_observation_metric": {
            "key": "detail_exposure",
            "unit": "exposure_count",
            "baseline_value": 10
          },
          "responsibility": {
            "owner_id": 11,
            "due_at": "2026-09-15 18:00:00",
            "review_at": "2026-09-16 10:00:00"
          },
          "source": {
            "record_id": 101,
            "record_ref": "online_daily_data#101",
            "snapshot_digest": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
            "fact_refs": [
              "online_daily_data#101"
            ],
            "gap_codes": []
          },
          "content_digest": "25c13c8d69a1a698951e342b055819e2b7312a272b310c3cda78c133bdc37c2c"
        },
        "selection_policy": {
          "full_candidate_list_exposed": false
        },
        "external_write_allowed": false
      },
      "today_saved_run": {
        "id": 901,
        "tenant_id": 7,
        "system_hotel_id": 80,
        "feature_key": "daily_one_thing",
        "feature_label": "今日一件事",
        "business_date": "2026-09-15",
        "source_quality_status": "readback_verified",
        "source_reference": "online_daily_data#101",
        "input": {
          "business_date": "2026-09-15",
          "source_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
          "selected_candidate_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
        },
        "result": {
          "contract_version": "daily_one_thing.v2",
          "feature_key": "daily_one_thing",
          "business_date": "2026-09-15",
          "status": "draft",
          "selected": {
            "candidate_key": "round47_synthetic_fact",
            "source_type": "strict_fact_signal",
            "problem": "Synthetic same-scope operating check",
            "approval_status": "draft",
            "scope": {
              "tenant_id": 7,
              "hotel_id": 80,
              "business_date": "2026-09-15",
              "platform": "ctrip",
              "metric_scope": "ota_channel"
            },
            "recommended_action": {
              "type": "human_reviewed_operating_check",
              "title": "Synthetic read-only check",
              "description": "Synthetic fixture only",
              "object": "ctrip_fact_scope",
              "steps": []
            },
            "expected_observation_metric": {
              "key": "detail_exposure",
              "unit": "exposure_count",
              "baseline_value": 10
            },
            "responsibility": {
              "owner_id": 11,
              "due_at": "2026-09-15 18:00:00",
              "review_at": "2026-09-16 10:00:00"
            },
            "source": {
              "record_id": 101,
              "record_ref": "online_daily_data#101",
              "snapshot_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
              "fact_refs": [
                "online_daily_data#101"
              ],
              "gap_codes": []
            },
            "content_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
          },
          "selection_policy": {
            "full_candidate_list_exposed": false
          },
          "external_write_allowed": false
        },
        "input_digest": "06ad5e55b977a90f0cb74e8570c000eaa61480aed1ebcd8ac7df488df498f857",
        "result_digest": "bc0ce1a7ebd1c95c40462fafb6878576ad7264af3456e972b5c49be99c29e049",
        "created_by": 11,
        "created_at": "2026-09-15 10:00:00",
        "record_readback_status": "readback_verified"
      },
      "today_execution_intent": {
        "id": 301,
        "tenant_id": 7,
        "hotel_id": 80,
        "source_module": "daily_one_thing",
        "source_record_id": 901,
        "status": "pending_approval",
        "tasks": [],
        "action_management": {
          "contract_version": "operation_action_card.v2",
          "action_card": {
            "contract_version": "operation_action_card.v2",
            "trace": {
              "daily_selection_digest": "5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"
            }
          },
          "lifecycle": {
            "status": "pending_approval"
          }
        }
      },
      "today_execution_intent_id": 301,
      "today_execution_task_id": 0,
      "today_lifecycle_status": "pending_approval",
      "today_state": "saved_stale",
      "source_digest": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
    }
  }
};

async function finishSave(p,a,post=priorityReceipt(),get=savedOverview()){
  p.reply(a.req,post);await tick();const read=p.pending('GET');ok(read,'confirmed current save starts overview read');p.reply(read,get);return a.pending;
}
test('original default mounted reads remain deduplicated; first save recovers the exact pending action',async()=>{
  const p=harness();await ready(p);eq(p.requests.filter(r=>(r.options.method||'GET')==='GET').length,1);
  const a=await save(p);eq(JSON.parse(a.req.options.body),{hotel_id:80,business_date:'2026-09-15',idempotency_key:'daily-one-thing-80-2026-09-15'});
  eq((await finishSave(p,a)).id,301);eq(p.component().error,'');eq(p.component().currentStatus,'pending_approval');ok(p.button('daily-one-thing-open-original'));eq(p.button('daily-one-thing-save'),undefined);
  ok(p.notices.some(n=>n.message==='每日一件事已保存为待人工审批；未执行任何外部写入'));
});
test('current explicit rejection permits another real click; no automatic priority POST',async()=>{
  const p=harness();await ready(p);const a=await save(p);p.reply(a.req,{code:422,message:'Synthetic validation rejection',data:null},422);await a.pending;ok(p.component().error.includes('Synthetic validation rejection'));ok(p.button('daily-one-thing-save'));eq(p.requests.filter(r=>r.options.method==='POST').length,1);
  const b=await save(p);eq(JSON.parse(b.req.options.body),JSON.parse(a.req.options.body));eq((await finishSave(p,b)).id,301);
});
test('unknown save can be recovered by the original read-only refresh without another POST',async()=>{
  const p=harness();await ready(p);const a=await save(p);p.reply(a.req,{code:500,message:'Synthetic unknown transport outcome',data:null},500);await a.pending;
  const r=p.refresh();await tick();p.reply(p.pending('GET'),savedOverview());await r.pending;eq(p.component().intentId,301);eq(p.component().error,'');eq(p.requests.filter(r=>r.options.method==='POST').length,1);
});
for(const type of ['hotel','date'])test('real '+type+' A-B-A cannot let old error clear the new owner lock',async()=>{
  const p=harness();await ready(p);const a=await save(p);const bHotel=type==='hotel'?81:80,bDate=type==='date'?'2026-09-14':'2026-09-15';
  await change(p,type,type==='hotel'?'81':bDate,overview('B','not_recorded',bHotel,bDate));const b=await save(p);
  await change(p,type,type==='hotel'?'80':'2026-09-15',overview());const current=await save(p);eq(p.requests.filter(r=>r.options.method==='POST').length,3);
  p.reply(a.req,{code:500,message:'Synthetic old A failure',data:null},500);await a.pending;eq(p.component().saving,true);eq(p.button('daily-one-thing-save'),undefined);eq(p.component().error,'');eq(p.notices,[]);
  p.reply(b.req,priorityReceipt());await b.pending;eq(p.component().saving,true);eq(p.notices,[]);eq((await finishSave(p,current)).id,301);
});
test('single hotel change suppresses old success and its overview request',async()=>{
  const p=harness();await ready(p);const a=await save(p);await change(p,'hotel','81',overview('B','not_recorded',81));
  const count=p.requests.length;p.reply(a.req,priorityReceipt());eq(await a.pending,null);eq(p.requests.length,count);eq(p.notices,[]);eq(p.component().overview.system_hotel_id,81);
});
for(const succeeded of [false,true])test('actual parent unmount isolates old priority '+(succeeded?'success':'failure'),async()=>{
  const p=harness();await ready(p);const a=await save(p);p.sandbox.currentPage.value='compass';await tick();eq(p.component(),null);const count=p.requests.length;
  p.reply(a.req,succeeded?priorityReceipt():{code:500,message:'Synthetic old page failure',data:null},succeeded?200:500);await tick();eq(p.requests.length,count,'unmounted response cannot start GET');eq(await a.pending,null);eq(p.notices,[]);
});
test('new auth epoch cannot receive an old priority error',async()=>{
  const p=harness();await ready(p);const a=await save(p);p.sandbox.authSessionEpoch+=1;p.reply(a.req,{code:500,message:'Synthetic old auth failure',data:null},500);await a.pending;eq(p.component().error,'');eq(p.notices,[]);eq(p.requests.filter(r=>r.options.method==='POST').length,1);
});
test('confirmed priority forces a second physical read; late pre-save GET cannot show not_saved',async()=>{
  const p=harness();await ready(p);const oldRefresh=p.refresh();await tick();const old=p.pending('GET');ok(old);const a=await save(p);p.reply(a.req,priorityReceipt());await tick();
  const reads=p.requests.filter(r=>(r.options.method||'GET')==='GET');eq(reads.length,3,'mount + old refresh + forced new read');const fresh=reads.at(-1);ok(fresh!==old);
  p.reply(fresh,savedOverview());eq((await a.pending).id,301);p.reply(old,overview());await oldRefresh.pending;
  eq(p.component().overview.today_state,'saved_current');eq(p.component().intentId,301);eq(p.component().error,'');eq(p.button('daily-one-thing-save'),undefined);eq(p.requests.filter(r=>r.options.method==='POST').length,1);
});
test('scope change during post-save GET cannot install an old recovery error',async()=>{
  const p=harness();await ready(p);const a=await save(p);p.reply(a.req,priorityReceipt());await tick();const old=p.pending('GET');ok(old);
  await change(p,'hotel','81',overview('B','not_recorded',81));p.reply(old,savedOverview());await a.pending;eq(p.component().overview.system_hotel_id,81);eq(p.component().error,'');eq(p.notices,[]);
});
test('temporary missing lifecycle exposes a real save button and restores approved canonical status',async()=>{
  const p=harness();await tick();p.reply(p.pending('GET'),stateSamples.without_lifecycle);await tick();eq(p.component().overview.today_state,'saved_without_lifecycle');ok(p.button('daily-one-thing-save'));
  const a=await save(p);eq((await finishSave(p,a,stateSamples.approved_replay,stateSamples.approved_read)).id,301);eq(p.component().currentStatus,'approved');eq(p.component().taskId,701);
  ok(p.notices.some(n=>n.message.includes('已恢复原每日事项')&&n.message.includes('已审批')));ok(p.notices.every(n=>!n.message.includes('保存为待人工审批')));eq(p.requests.filter(r=>r.options.method==='POST').length,1);
});
test('exact saved_stale read preserves the canonical record and explains the source change',async()=>{
  const p=harness();await ready(p);const a=await save(p);eq((await finishSave(p,a,priorityReceipt(),stateSamples.stale_read)).id,301);
  eq(p.component().overview.today_state,'saved_stale');eq(p.component().overview.today_saved_run.id,901);eq(p.component().error,'');ok(p.notices.some(n=>n.message.includes('当前事实已变化')&&n.message.includes('原行动已保留')));ok((await p.html()).includes('查看已保留的原任务'));eq(p.button('daily-one-thing-save'),undefined);
});
test('unavailable current source is distinguished from the already confirmed save',async()=>{
  const p=harness();await ready(p);const a=await save(p);const unavailable=savedOverview();unavailable.data.today_state='source_unavailable';unavailable.data.today={...unavailable.data.today,status:'blocked_by_source_unavailable',selected:null};
  eq(await finishSave(p,a,priorityReceipt(),unavailable),null);ok(p.component().error.includes('保存回执已确认'));ok(p.component().error.includes('当前严格事实来源暂不可用'));eq(p.component().overview.today_saved_run.id,901);eq(p.requests.filter(r=>r.options.method==='POST').length,1);
});
test('post-save read failure preserves its cause and never automatically repeats the mutation',async()=>{
  const p=harness();await ready(p);const a=await save(p);p.reply(a.req,priorityReceipt());await tick();p.reply(p.pending('GET'),{code:500,message:'Synthetic overview unavailable',data:null},500);eq(await a.pending,null);
  ok(p.component().error.includes('保存回执已确认'));ok(p.component().error.includes('Synthetic overview unavailable'));eq(p.requests.filter(r=>r.options.method==='POST').length,1);
});
for(const [name,change]of [
  ['wrong run',v=>{v.today_saved_run.id=999;}],
  ['wrong intent',v=>{v.today_execution_intent.id=999;v.today_execution_intent_id=999;}],
  ['unverified run',v=>{v.today_saved_run.record_readback_status='unavailable';}],
  ['changed saved digest',v=>{v.today_saved_run.result_digest='c'.repeat(64);}],
  ['wrong nested hotel',v=>{v.today_execution_intent.hotel_id=81;}],
])test('saved_stale never bypasses exact receipt identity: '+name,async()=>{
  const p=harness();await ready(p);const a=await save(p);const body=clone(stateSamples.stale_read);change(body.data);eq(await finishSave(p,a,priorityReceipt(),body),null);ok(p.component().error.includes('没有精确对应同一行动'));eq(p.notices.filter(n=>n.type==='success').length,0);
});
for(const [name,change]of [
  ['boolean run ID',v=>{v.run.id=true;}],
  ['wrong intent scope',v=>{v.execution_intent.hotel_id=81;}],
  ['wrong intent source',v=>{v.execution_intent.source_record_id=999;}],
])test('unconfirmed POST is not refreshed or presented as saved: '+name,async()=>{
  const p=harness();await ready(p);const a=await save(p);const receipt=priorityReceipt();change(receipt.data);const count=p.requests.length;p.reply(a.req,receipt);await tick();eq(p.requests.length,count);eq(await a.pending,null);ok(p.component().error);eq(p.notices.filter(n=>n.type==='success').length,0);
});
for(const [name,change]of [
  ['run',v=>{v.run.tenant_id=8;}],
  ['intent',v=>{v.execution_intent.tenant_id=8;}],
])test('POST tenant must match the overview captured before the real save: '+name,async()=>{
  const p=harness();await ready(p);const a=await save(p);const receipt=priorityReceipt();change(receipt.data);const count=p.requests.length;p.reply(a.req,receipt);await tick();eq(p.requests.length,count);eq(await a.pending,null);ok(p.component().error);eq(p.notices.filter(n=>n.type==='success').length,0);
});
for(const [name,change]of [
  ['outer overview',v=>{v.tenant_id=8;}],
  ['saved run',v=>{v.today_saved_run.tenant_id=8;}],
  ['nested intent',v=>{v.today_execution_intent.tenant_id=8;}],
])test('post-save GET tenant must remain bound to the original overview: '+name,async()=>{
  const p=harness();await ready(p);const a=await save(p);const body=savedOverview();change(body.data);eq(await finishSave(p,a,priorityReceipt(),body),null);ok(p.component().error.includes('没有精确对应同一行动'));eq(p.notices.filter(n=>n.type==='success').length,0);
});
test('numeric-string tenant identity and another authorized canonical creator remain compatible',async()=>{
  const p=harness();await ready(p);const a=await save(p);const receipt=priorityReceipt(),body=savedOverview();receipt.data.replayed=true;
  for(const value of [receipt.data.run,receipt.data.execution_intent,body.data,body.data.today_saved_run,body.data.today_execution_intent])value.tenant_id='7';
  receipt.data.run.created_by=902;body.data.today_saved_run.created_by=902;
  eq((await finishSave(p,a,receipt,body)).id,301);eq(p.component().error,'');ok(p.notices.some(n=>n.message.includes('已恢复原每日事项')));
});
