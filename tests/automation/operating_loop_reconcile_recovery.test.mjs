import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test, { afterEach } from 'node:test';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
const source=fs.readFileSync(process.env.OPERATING_LOOP_MAIN_SOURCE || 'public/app-main.js','utf8');
const componentSource=fs.readFileSync(process.env.OPERATING_LOOP_COMPONENT_SOURCE || 'public/components/system/app-main-components.js','utf8');
const template=fs.readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html','utf8');
// Synthetic DTOs from actual pure summary/missingSummary/waiting methods; no persisted kernel is executed.
const samples={
  "current_existing_body": {
    "code": 200,
    "message": "操作成功",
    "data": {
      "schema_version": "hotel_operating_cycle.v1",
      "kernel_id": "cycle-901",
      "record_id": 901,
      "revision": 1,
      "authoritative": true,
      "authoritative_state": "active",
      "readback_verified": true,
      "scope": {
        "tenant_id": 3,
        "system_hotel_id": 7,
        "hotel_name": "Synthetic hotel",
        "business_date": "2026-09-14",
        "metric_version": "hotel_operating_metric_bundle.v1",
        "metric_definition_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "source_identities": [],
        "source_identity_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
      },
      "last_completed_stage": "identity_business_date_confirmed",
      "next_required_stage": "trusted_collection",
      "stages": [
        {
          "key": "identity_business_date_confirmed",
          "label": "身份与业务日期确认",
          "status": "complete",
          "event_id": 0,
          "actor_kind": "",
          "actor_id": 0,
          "occurred_at": null,
          "blocking_gap_codes": [],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "trusted_collection",
          "label": "可信采集",
          "status": "not_proved",
          "event_id": 0,
          "actor_kind": "",
          "actor_id": 0,
          "occurred_at": null,
          "blocking_gap_codes": [],
          "next_action": {
            "action_code": "",
            "priority": "medium",
            "status": "active",
            "action": "",
            "entry": "/api/operating-loop/reconcile",
            "question_key": "trusted_collection"
          },
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "formal_save_exact_readback",
          "label": "正式保存与精确回读",
          "status": "not_proved",
          "event_id": 0,
          "actor_kind": "",
          "actor_id": 0,
          "occurred_at": null,
          "blocking_gap_codes": [],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "operating_facts_established",
          "label": "经营事实成立",
          "status": "not_proved",
          "event_id": 0,
          "actor_kind": "",
          "actor_id": 0,
          "occurred_at": null,
          "blocking_gap_codes": [],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "recommendation_human_decision",
          "label": "建议与人工判断",
          "status": "not_proved",
          "event_id": 0,
          "actor_kind": "",
          "actor_id": 0,
          "occurred_at": null,
          "blocking_gap_codes": [],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "real_execution_receipt",
          "label": "真实执行与回执",
          "status": "not_proved",
          "event_id": 0,
          "actor_kind": "",
          "actor_id": 0,
          "occurred_at": null,
          "blocking_gap_codes": [],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "comparable_outcome_readback",
          "label": "同口径结果回读",
          "status": "not_proved",
          "event_id": 0,
          "actor_kind": "",
          "actor_id": 0,
          "occurred_at": null,
          "blocking_gap_codes": [],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "review_experience_promotion",
          "label": "复盘与经验晋级",
          "status": "not_proved",
          "event_id": 0,
          "actor_kind": "",
          "actor_id": 0,
          "occurred_at": null,
          "blocking_gap_codes": [],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        }
      ],
      "what_is_true": "Synthetic identity confirmation",
      "priority_issue": {
        "code": "",
        "title": "",
        "detail": ""
      },
      "next_action": {
        "action_code": "advance_trusted_collection",
        "priority": "medium",
        "status": "active",
        "action": "完成下一阶段：可信采集",
        "owner": {
          "role": "data_operator"
        },
        "due_at": null,
        "entry": "/api/operating-loop/reconcile",
        "question_key": "trusted_collection",
        "kernel_id": "cycle-901",
        "revision": 1
      },
      "yesterday_result": {
        "status": "pending",
        "review_due_at": null,
        "same_metric_version_required": true,
        "result_summary": ""
      },
      "actors": {
        "judged_by": 0,
        "approved_by": 0,
        "executed_by": 0,
        "reviewed_by": 0
      },
      "decision": {
        "status": "",
        "recommendation": "",
        "judgement": "",
        "outcome_metric_definition_digest": ""
      },
      "execution": {
        "intent_id": 0,
        "task_id": 0,
        "executed_action": "",
        "executed_at": null
      },
      "experience": {
        "status": "not_reviewed",
        "review_summary": ""
      },
      "evidence_ref_count": 0,
      "source_policy": "hotel_operating_cycle_kernel_only"
    }
  },
  "current_missing_body": {
    "code": 200,
    "data": {
      "schema_version": "hotel_operating_cycle.v1",
      "kernel_id": null,
      "record_id": 0,
      "revision": 0,
      "authoritative": true,
      "authoritative_state": "not_started",
      "readback_verified": false,
      "scope": {
        "system_hotel_id": 7,
        "business_date": "2026-09-14"
      },
      "last_completed_stage": "",
      "next_required_stage": "identity_business_date_confirmed",
      "stages": [
        {
          "key": "identity_business_date_confirmed",
          "label": "身份与业务日期确认",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_record_not_started"
          ],
          "next_action": {
            "action_code": "open_operating_cycle",
            "priority": "high",
            "status": "missing",
            "action": "确认酒店、平台门店、业务日期和指标版本后建立权威闭环记录",
            "entry": "/api/operating-loop/reconcile",
            "question_key": "identity_business_date_confirmed"
          },
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "trusted_collection",
          "label": "可信采集",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_record_not_started"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "formal_save_exact_readback",
          "label": "正式保存与精确回读",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_record_not_started"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "operating_facts_established",
          "label": "经营事实成立",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_record_not_started"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "recommendation_human_decision",
          "label": "建议与人工判断",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_record_not_started"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "real_execution_receipt",
          "label": "真实执行与回执",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_record_not_started"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "comparable_outcome_readback",
          "label": "同口径结果回读",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_record_not_started"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "review_experience_promotion",
          "label": "复盘与经验晋级",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_record_not_started"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        }
      ],
      "what_is_true": "",
      "priority_issue": {
        "code": "kernel_record_not_started",
        "title": "该酒店该业务日尚无权威经营闭环记录",
        "detail": "现有模块状态只保留为诊断证据，不能替代权威闭环状态。"
      },
      "next_action": {
        "action_code": "open_operating_cycle",
        "priority": "high",
        "status": "missing",
        "action": "确认酒店、平台门店、业务日期和指标版本后建立权威闭环记录",
        "owner": [],
        "due_at": null,
        "entry": "/api/operating-loop/reconcile",
        "question_key": "identity_business_date_confirmed",
        "kernel_id": null,
        "revision": 0
      },
      "yesterday_result": {
        "status": "pending",
        "review_due_at": null,
        "same_metric_version_required": true
      },
      "experience": {
        "status": "not_reviewed"
      },
      "evidence_ref_count": 0,
      "source_policy": "hotel_operating_cycle_kernel_only"
    }
  },
  "current_read_failed_body": {
    "code": 200,
    "data": {
      "schema_version": "hotel_operating_cycle.v1",
      "kernel_id": null,
      "record_id": 0,
      "revision": 0,
      "authoritative": true,
      "authoritative_state": "not_started",
      "readback_verified": false,
      "scope": {
        "system_hotel_id": 7,
        "business_date": "2026-09-14"
      },
      "last_completed_stage": "",
      "next_required_stage": "identity_business_date_confirmed",
      "stages": [
        {
          "key": "identity_business_date_confirmed",
          "label": "身份与业务日期确认",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_readback_failed"
          ],
          "next_action": {
            "action_code": "open_operating_cycle",
            "priority": "high",
            "status": "missing",
            "action": "确认酒店、平台门店、业务日期和指标版本后建立权威闭环记录",
            "entry": "/api/operating-loop/reconcile",
            "question_key": "identity_business_date_confirmed"
          },
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "trusted_collection",
          "label": "可信采集",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_readback_failed"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "formal_save_exact_readback",
          "label": "正式保存与精确回读",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_readback_failed"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "operating_facts_established",
          "label": "经营事实成立",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_readback_failed"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "recommendation_human_decision",
          "label": "建议与人工判断",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_readback_failed"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "real_execution_receipt",
          "label": "真实执行与回执",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_readback_failed"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "comparable_outcome_readback",
          "label": "同口径结果回读",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_readback_failed"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        },
        {
          "key": "review_experience_promotion",
          "label": "复盘与经验晋级",
          "status": "missing",
          "blocking_gap_codes": [
            "kernel_readback_failed"
          ],
          "next_action": null,
          "source_policy": "hotel_operating_cycle_kernel_only"
        }
      ],
      "what_is_true": "",
      "priority_issue": {
        "code": "kernel_readback_failed",
        "title": "该酒店该业务日尚无权威经营闭环记录",
        "detail": "现有模块状态只保留为诊断证据，不能替代权威闭环状态。"
      },
      "next_action": {
        "action_code": "open_operating_cycle",
        "priority": "high",
        "status": "missing",
        "action": "确认酒店、平台门店、业务日期和指标版本后建立权威闭环记录",
        "owner": [],
        "due_at": null,
        "entry": "/api/operating-loop/reconcile",
        "question_key": "identity_business_date_confirmed",
        "kernel_id": null,
        "revision": 0
      },
      "yesterday_result": {
        "status": "pending",
        "review_due_at": null,
        "same_metric_version_required": true
      },
      "experience": {
        "status": "not_reviewed"
      },
      "evidence_ref_count": 0,
      "source_policy": "hotel_operating_cycle_kernel_only"
    }
  },
  "reconcile_waiting_unstarted_body": {
    "code": 200,
    "data": {
      "operating_loop": {
        "schema_version": "hotel_operating_cycle.v1",
        "kernel_id": null,
        "record_id": 0,
        "revision": 0,
        "authoritative": true,
        "authoritative_state": "not_started",
        "readback_verified": false,
        "scope": {
          "system_hotel_id": 7,
          "business_date": "2026-09-14"
        },
        "last_completed_stage": "",
        "next_required_stage": "identity_business_date_confirmed",
        "stages": [
          {
            "key": "identity_business_date_confirmed",
            "label": "身份与业务日期确认",
            "status": "missing",
            "blocking_gap_codes": [
              "kernel_record_not_started"
            ],
            "next_action": {
              "action_code": "open_operating_cycle",
              "priority": "high",
              "status": "missing",
              "action": "确认酒店、平台门店、业务日期和指标版本后建立权威闭环记录",
              "entry": "/api/operating-loop/reconcile",
              "question_key": "identity_business_date_confirmed"
            },
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "trusted_collection",
            "label": "可信采集",
            "status": "missing",
            "blocking_gap_codes": [
              "kernel_record_not_started"
            ],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "formal_save_exact_readback",
            "label": "正式保存与精确回读",
            "status": "missing",
            "blocking_gap_codes": [
              "kernel_record_not_started"
            ],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "operating_facts_established",
            "label": "经营事实成立",
            "status": "missing",
            "blocking_gap_codes": [
              "kernel_record_not_started"
            ],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "recommendation_human_decision",
            "label": "建议与人工判断",
            "status": "missing",
            "blocking_gap_codes": [
              "kernel_record_not_started"
            ],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "real_execution_receipt",
            "label": "真实执行与回执",
            "status": "missing",
            "blocking_gap_codes": [
              "kernel_record_not_started"
            ],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "comparable_outcome_readback",
            "label": "同口径结果回读",
            "status": "missing",
            "blocking_gap_codes": [
              "kernel_record_not_started"
            ],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "review_experience_promotion",
            "label": "复盘与经验晋级",
            "status": "missing",
            "blocking_gap_codes": [
              "kernel_record_not_started"
            ],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          }
        ],
        "what_is_true": "",
        "priority_issue": {
          "code": "kernel_record_not_started",
          "title": "该酒店该业务日尚无权威经营闭环记录",
          "detail": "现有模块状态只保留为诊断证据，不能替代权威闭环状态。"
        },
        "next_action": {
          "action_code": "open_operating_cycle",
          "priority": "high",
          "status": "missing",
          "action": "确认酒店、平台门店、业务日期和指标版本后建立权威闭环记录",
          "owner": [],
          "due_at": null,
          "entry": "/api/operating-loop/reconcile",
          "question_key": "identity_business_date_confirmed",
          "kernel_id": null,
          "revision": 0
        },
        "yesterday_result": {
          "status": "pending",
          "review_due_at": null,
          "same_metric_version_required": true
        },
        "experience": {
          "status": "not_reviewed"
        },
        "evidence_ref_count": 0,
        "source_policy": "hotel_operating_cycle_kernel_only"
      },
      "reconciled_stages": [],
      "waiting": {
        "stage": "identity_business_date_confirmed",
        "code": "source_identity_missing",
        "detail": "Synthetic missing source identity",
        "owner": []
      },
      "persistence_status": "not_written",
      "source_policy": "existing_formal_rows_to_operating_cycle_kernel_only"
    }
  },
  "reconcile_waiting_after_progress_body": {
    "code": 200,
    "message": "操作成功",
    "data": {
      "operating_loop": {
        "schema_version": "hotel_operating_cycle.v1",
        "kernel_id": "cycle-901",
        "record_id": 901,
        "revision": 1,
        "authoritative": true,
        "authoritative_state": "active",
        "readback_verified": true,
        "scope": {
          "tenant_id": 3,
          "system_hotel_id": 7,
          "hotel_name": "Synthetic hotel",
          "business_date": "2026-09-14",
          "metric_version": "hotel_operating_metric_bundle.v1",
          "metric_definition_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
          "source_identities": [],
          "source_identity_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
        },
        "last_completed_stage": "identity_business_date_confirmed",
        "next_required_stage": "trusted_collection",
        "stages": [
          {
            "key": "identity_business_date_confirmed",
            "label": "身份与业务日期确认",
            "status": "complete",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "trusted_collection",
            "label": "可信采集",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": {
              "action_code": "",
              "priority": "medium",
              "status": "active",
              "action": "",
              "entry": "/api/operating-loop/reconcile",
              "question_key": "trusted_collection"
            },
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "formal_save_exact_readback",
            "label": "正式保存与精确回读",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "operating_facts_established",
            "label": "经营事实成立",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "recommendation_human_decision",
            "label": "建议与人工判断",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "real_execution_receipt",
            "label": "真实执行与回执",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "comparable_outcome_readback",
            "label": "同口径结果回读",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "review_experience_promotion",
            "label": "复盘与经验晋级",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          }
        ],
        "what_is_true": "Synthetic identity confirmation",
        "priority_issue": {
          "code": "",
          "title": "",
          "detail": ""
        },
        "next_action": {
          "action_code": "advance_trusted_collection",
          "priority": "medium",
          "status": "active",
          "action": "完成下一阶段：可信采集",
          "owner": {
            "role": "data_operator"
          },
          "due_at": null,
          "entry": "/api/operating-loop/reconcile",
          "question_key": "trusted_collection",
          "kernel_id": "cycle-901",
          "revision": 1
        },
        "yesterday_result": {
          "status": "pending",
          "review_due_at": null,
          "same_metric_version_required": true,
          "result_summary": ""
        },
        "actors": {
          "judged_by": 0,
          "approved_by": 0,
          "executed_by": 0,
          "reviewed_by": 0
        },
        "decision": {
          "status": "",
          "recommendation": "",
          "judgement": "",
          "outcome_metric_definition_digest": ""
        },
        "execution": {
          "intent_id": 0,
          "task_id": 0,
          "executed_action": "",
          "executed_at": null
        },
        "experience": {
          "status": "not_reviewed",
          "review_summary": ""
        },
        "evidence_ref_count": 0,
        "source_policy": "hotel_operating_cycle_kernel_only"
      },
      "reconciled_stages": [
        "identity_business_date_confirmed"
      ],
      "waiting": {
        "stage": "trusted_collection",
        "code": "ota_collection_receipt_missing",
        "detail": "Synthetic missing collection receipt",
        "owner": {
          "role": "data_operator"
        }
      },
      "persistence_status": "readback_verified",
      "source_policy": "existing_formal_rows_to_operating_cycle_kernel_only"
    }
  },
  "reconcile_recorded_block_body": {
    "code": 200,
    "data": {
      "operating_loop": {
        "schema_version": "hotel_operating_cycle.v1",
        "kernel_id": "cycle-901",
        "record_id": 901,
        "revision": 2,
        "authoritative": true,
        "authoritative_state": "blocked",
        "readback_verified": true,
        "scope": {
          "tenant_id": 3,
          "system_hotel_id": 7,
          "hotel_name": "Synthetic hotel",
          "business_date": "2026-09-14",
          "metric_version": "hotel_operating_metric_bundle.v1",
          "metric_definition_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
          "source_identities": [],
          "source_identity_digest": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
        },
        "last_completed_stage": "identity_business_date_confirmed",
        "next_required_stage": "trusted_collection",
        "stages": [
          {
            "key": "identity_business_date_confirmed",
            "label": "身份与业务日期确认",
            "status": "complete",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "trusted_collection",
            "label": "可信采集",
            "status": "missing",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [
              "trusted_collection_failed"
            ],
            "next_action": {
              "action_code": "trusted_collection_failed",
              "priority": "high",
              "status": "blocked",
              "action": "",
              "entry": "/api/operating-loop/reconcile",
              "question_key": "trusted_collection"
            },
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "formal_save_exact_readback",
            "label": "正式保存与精确回读",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "operating_facts_established",
            "label": "经营事实成立",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "recommendation_human_decision",
            "label": "建议与人工判断",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "real_execution_receipt",
            "label": "真实执行与回执",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "comparable_outcome_readback",
            "label": "同口径结果回读",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          },
          {
            "key": "review_experience_promotion",
            "label": "复盘与经验晋级",
            "status": "not_proved",
            "event_id": 0,
            "actor_kind": "",
            "actor_id": 0,
            "occurred_at": null,
            "blocking_gap_codes": [],
            "next_action": null,
            "source_policy": "hotel_operating_cycle_kernel_only"
          }
        ],
        "what_is_true": "Synthetic identity confirmation",
        "priority_issue": {
          "code": "trusted_collection_failed",
          "title": "Synthetic formal collection failure",
          "detail": "Synthetic formal collection failure"
        },
        "next_action": {
          "action_code": "trusted_collection_failed",
          "priority": "high",
          "status": "blocked",
          "action": "完成下一阶段：可信采集",
          "owner": {
            "role": "data_operator"
          },
          "due_at": null,
          "entry": "/api/operating-loop/reconcile",
          "question_key": "trusted_collection",
          "kernel_id": "cycle-901",
          "revision": 2
        },
        "yesterday_result": {
          "status": "pending",
          "review_due_at": null,
          "same_metric_version_required": true,
          "result_summary": ""
        },
        "actors": {
          "judged_by": 0,
          "approved_by": 0,
          "executed_by": 0,
          "reviewed_by": 0
        },
        "decision": {
          "status": "",
          "recommendation": "",
          "judgement": "",
          "outcome_metric_definition_digest": ""
        },
        "execution": {
          "intent_id": 0,
          "task_id": 0,
          "executed_action": "",
          "executed_at": null
        },
        "experience": {
          "status": "not_reviewed",
          "review_summary": ""
        },
        "evidence_ref_count": 0,
        "source_policy": "hotel_operating_cycle_kernel_only"
      },
      "reconciled_stages": [
        "trusted_collection"
      ],
      "waiting": null,
      "persistence_status": "readback_verified",
      "source_policy": "existing_formal_rows_to_operating_cycle_kernel_only"
    }
  },
  "error_bodies": [
    {
      "http_status": 422,
      "body": {
        "code": 422,
        "message": "business_date 必须是有效 YYYY-MM-DD 日期",
        "data": null
      }
    },
    {
      "http_status": 409,
      "body": {
        "code": 409,
        "message": "经营闭环版本冲突：当前 revision=2，请精确回读后重试",
        "data": null
      }
    },
    {
      "http_status": 403,
      "body": {
        "code": 403,
        "message": "无权跨租户访问经营闭环",
        "data": null
      }
    },
    {
      "http_status": 401,
      "body": {
        "code": 401,
        "message": "未登录",
        "data": null
      }
    },
    {
      "http_status": 404,
      "body": {
        "code": 404,
        "message": "经营闭环不存在或不属于当前酒店范围",
        "data": null
      }
    },
    {
      "http_status": 503,
      "body": {
        "code": 503,
        "message": "经营闭环 schema 未就绪",
        "data": null
      }
    },
    {
      "http_status": 500,
      "body": {
        "code": 500,
        "message": "Synthetic read failure",
        "data": null
      }
    }
  ]
};
const slice=(text,start,end)=>{const a=text.indexOf(start),b=text.indexOf(end,a);assert.ok(a>=0&&b>a,start);return text.slice(a,b);};
const auth=slice(source,'const captureAuthSession =','const createDefaultAuthContext =');
const business=slice(source,'const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =','const userHasPermission =');
const policy=slice(source,'const currentPageReadPolicy =','const cancelPageLoadRequests =');
const requests=slice(source,'const COORDINATED_GET_MAX_CONCURRENCY = 3;','const askSystemUsageGuide =');
const state=slice(source,'const operatingLoop =','const compassMetricTab =');
const loader=slice(source,'const loadCompassData = async','const refreshCompassDashboard =');
const refresh=slice(source,'const refreshCompassDashboard =','            watch(homeRevenueFactBusinessDate,');
const reconcile=slice(source,source.includes('const operatingLoopSyncScopeKey =') ? 'const operatingLoopSyncScopeKey =' : 'const reconcileOperatingLoop =','            watch(() => user.value?.id,');
const hotelWatch=slice(source,'watch(filterReportHotel, (newHotelId, previousHotelId) => {','            watch(weatherLocationName,');
const component=slice(componentSource,'const OperatingLoopAuthority =','const managerCapabilityToday =');
const header=template.slice(template.indexOf('<header'),template.indexOf('</header>')+9);
const detailsStart=template.indexOf('<details v-if="currentPage === \'compass\'"');
const details=template.slice(detailsStart,template.indexOf('</details>',detailsStart)+10);
assert.ok(details.includes('home-operating-records-details')&&details.includes('<operating-loop-authority>'));
const renderPage=new Function('Vue',compile(header+details,{mode:'function',prefixIdentifiers:true}).code)(Vue);
const flatten=n=>!n||typeof n!=='object'?[]:[n,...(Array.isArray(n.children)?n.children.flatMap(flatten):[])];
const clone=v=>structuredClone(v);
const defer=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
const response=(body,status=200)=>new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}});
const flush=async()=>{await Vue.nextTick();await new Promise(r=>setTimeout(r,0));await Vue.nextTick();};
let assertions=0;const eq=(a,b,m)=>{assertions++;assert.equal(a,b,m);};const match=(a,b)=>{assertions++;assert.match(a,b);};
function summary(hotel=7,key='current_existing_body'){
  const data=clone(samples[key].data);data.scope.system_hotel_id=hotel;
  if('hotel_name' in data.scope)data.scope.hotel_name='合成酒店 '+hotel;
  if(hotel!==7&&Number(data.record_id)>0){data.record_id=Number(data.record_id)+hotel;data.kernel_id='cycle-'+data.record_id;if(data.next_action)data.next_action.kernel_id=data.kernel_id;}
  return data;
}
const activeFixtures=new Set();
afterEach(async()=>{for(const calls of activeFixtures){for(const call of calls)call.reject(new Error("synthetic fixture closed"));}activeFixtures.clear();await flush();});
function fixture({permitted=true,initial='current_existing_body'}={}){
  const calls=[],toasts=[],scheduled=[],loads=[];activeFixtures.add(calls);let tree,authorityTree;
  const hotels=Vue.ref([{id:7,name:'合成酒店7'},{id:8,name:'合成酒店8'}]);
  const s={...Vue,Vue,h:Vue.h,window:{Vue},URL,URLSearchParams,Headers,AbortController,structuredClone,API_BASE:'https://synthetic.invalid/api',
    currentPage:Vue.ref('compass'),filterReportHotel:Vue.ref('7'),token:Vue.ref('synthetic-session'),authSessionEpoch:0,pageRequestGeneration:1,
    user:Vue.ref({id:42,hotel_id:7,tenant_id:3,is_super_admin:permitted}),authContext:Vue.ref({tenantId:'3',hotelId:'7'}),userHasCapability:()=>false,
    revenueAiBusinessDate:Vue.ref(''),coreOperationsTargetDate:Vue.ref(''),operationYesterday:'2026-09-14',operatingLoopYesterday:'2026-09-14',currentLocale:Vue.ref('zh-CN'),
    compassHotelOptions:hotels,permittedHotels:hotels,hotels,compassLoading:Vue.ref(false),compassRequestSeq:0,compassDisplayedHotelId:'7',compassLayout:Vue.ref({}),compassWeather:Vue.ref([]),compassTodos:Vue.ref([]),compassMetrics:Vue.ref({}),compassAlerts:Vue.ref([]),compassHolidays:Vue.ref([]),compassLastSyncedAt:Vue.ref('synthetic initial'),
    homeRevenueFactBusinessDate:Vue.ref('2026-09-14'),homeRevenueFactLayerLoading:Vue.ref(false),loadHomeRevenueFactLayer:async()=>{},
    homeOperatingScheduleScopeHotelId:Vue.ref('7'),homeOperatingScheduleRequestSeq:0,homeOperatingScheduleFlow:Vue.ref(null),homeOperatingScheduleLastReadAt:Vue.ref(''),homeOperatingScheduleLoading:Vue.ref(false),homeOperatingScheduleError:Vue.ref(''),
    HOME_SECONDARY_PANEL_DELAY_MS:1,COMPASS_WEATHER_REFRESH_DELAY_MS:1,scheduleDelayedPageTask:(fn,ms)=>{scheduled.push({fn,ms});},mergeStoredHomeQuickLayout:v=>v,
    reportHotelOptionExists:id=>['7','8'].includes(String(id)),isCompassDataPage:p=>String(p??s.currentPage.value)==='compass',
    dualOtaPmsSelected:Vue.ref(false),dualOtaConfiguredPms:Vue.ref(null),selectedWeatherCity:Vue.ref(''),externalWeatherForecast:Vue.ref([]),externalWeatherCity:Vue.ref(''),weatherError:Vue.ref(''),
    suppressNextReportHotelDashboardRefresh:false,dualOtaSuppressHotelSearchRecord:true,
    resetAgentCenterClientState(){},clearPostFetchRefreshTimers(){},clearPlatformProfileLoginTimers(){},persistDualOtaWorkbenchPreferences(){},recordDualOtaHotelSearch(){},refreshDualOtaWorkbenchData(){},
    isTerminalAuthFailureResponse:()=>false,createRequestAbortError:(message='Request aborted')=>Object.assign(new Error(message),{name:'AbortError'}),
    showToast:(message,type='success')=>toasts.push({message,type}),console:{error(){}},
    getHotelNameById:id=>'合成酒店 '+id,
    fetch:(url,options)=>{assert.ok(url.startsWith(s.API_BASE));const path=new URL(url).pathname;assert.ok(['/api/compass','/api/operating-loop/reconcile'].includes(path),'only allowed synthetic endpoints');const pending=defer();calls.push({url,options,body:options.body?JSON.parse(options.body):null,...pending});return pending.promise;},
  };
  vm.runInNewContext(fs.readFileSync('public/system-static.js','utf8'),s);
  vm.runInNewContext(`const appSystemStatic=window.SUXI_SYSTEM_STATIC;const requireAppSystemStatic=key=>appSystemStatic[key];const readRequestCooldown=appSystemStatic.createReadRequestCooldown();
    ${auth}\n${business}\n${policy}\n${requests}\n${state}\n${loader}\n${refresh}\n${reconcile}\n${component}
    const clearActiveHotelDashboardSnapshots=()=>{compassRequestSeq+=1;compassDisplayedHotelId='';};
    ${hotelWatch}
    watch(currentPage,()=>{pageRequestGeneration+=1;});
    const homeBusinessTimeModel=computed(()=>({hotelName:'合成酒店 '+filterReportHotel.value,selectedBusinessDate:homeRevenueFactBusinessDate.value,maxBusinessDate:'2026-09-14'}));
    globalThis.ui={...(typeof operatingLoopReadUnconfirmed !== 'undefined' ? {operatingLoopReadUnconfirmed} : {}),operatingLoop,operatingLoopSyncing,operatingLoopError,operatingLoopStateLabel,operatingLoopStateClass,operatingLoopStageClass,canReconcileOperatingLoop,reconcileOperatingLoop,loadCompassData,refreshCompassDashboard,homeBusinessTimeModel};
    globalThis.authority=OperatingLoopAuthority;`,s);
  s.ui.operatingLoop.value=summary(7,initial);
  async function render(){const page=Vue.createSSRApp({setup:()=>({...s.ui,currentPage:s.currentPage,filterReportHotel:s.filterReportHotel,compassHotelOptions:hotels,homeRevenueFactBusinessDate:s.homeRevenueFactBusinessDate,homeRevenueFactLayerLoading:s.homeRevenueFactLayerLoading,compassLoading:s.compassLoading,operationYesterday:s.operationYesterday,getHotelNameById:s.getHotelNameById}),render(...args){tree=renderPage.apply(this,args);return tree;}});
    page.component('operating-loop-authority',{...s.authority,render(){authorityTree=s.authority.render.call(this);return authorityTree;}});return renderToString(page);}
  async function click(){await render();const fold=flatten(tree).find(n=>n.type==='details'&&n.props?.['data-testid']==='home-operating-records-details');assert.ok(fold,'actual discoverable details can be expanded without JS/network');
    const n=flatten(authorityTree).find(n=>n.type==='button'&&n.props?.class==='operating-loop-primary-action');if(!n)return{absent:true,done:Promise.resolve()};if(n.props.disabled)return{disabled:true,done:Promise.resolve()};return{disabled:false,done:n.props.onClick()};}
  async function hotel(id){await render();const n=flatten(tree).find(n=>n.type==='select'&&n.props?.['aria-label']==='首页门店');assert.ok(n&&!n.props.disabled);n.props['onUpdate:modelValue'](String(id));await flush();}
  async function refreshClick(){await render();const n=flatten(tree).find(n=>n.type==='button'&&n.props?.class==='home-facts-refresh');assert.ok(n&&!n.props.disabled);return{done:n.props.onClick()};}
  async function readRetry(){await render();const n=flatten(authorityTree).find(n=>n.type==='button'&&n.props?.['data-testid']==='operating-loop-read-retry');assert.ok(n&&!n.props.disabled,'actual read-only retry control');return{done:n.props.onClick()};}
  const posts=()=>calls.filter(c=>c.body),gets=()=>calls.filter(c=>!c.body);
  function finishRead(index=-1,data,status=200){const c=gets().at(index);assert.ok(c);const hotel=Number(new URL(c.url).searchParams.get('hotel_id'));c.resolve(response(status===200?{code:200,data:{operating_loop:data||summary(hotel)}}:{code:status,message:'synthetic compass read failed',data:null},status));}
  return{s,ui:s.ui,calls,toasts,scheduled,render,click,hotel,refreshClick,readRetry,posts,gets,finishRead};
}

const postBody=(key='reconcile_waiting_after_progress_body',hotel=7)=>{
  const body=clone(samples[key]);body.data.operating_loop.scope.system_hotel_id=hotel;return body;
};
async function finishPost(f,action,body=postBody()){
  f.posts().at(-1).resolve(response(body));await flush();
  assert.equal(f.gets().length>0,true,'confirmed current POST refreshes original compass');
  f.finishRead(-1,body.data.operating_loop);assert.equal(await action.done,true);
}
for(const [key,label] of [['reconcile_waiting_unstarted_body','未建立'],['reconcile_waiting_after_progress_body','进行中'],['reconcile_recorded_block_body','已阻断']]){
  test('actual panel preserves '+key,async()=>{
    const f=fixture({initial:key.includes('unstarted')?'current_missing_body':'current_existing_body'}),a=await f.click();await flush();
    assert.equal(a.disabled,false);assert.equal((await f.click()).disabled,true);assert.equal(f.posts().length,1);
    assert.deepEqual(f.posts()[0].body,{hotel_id:7,business_date:'2026-09-14',max_transitions:8});
    await finishPost(f,a,postBody(key));assert.match(await f.render(),new RegExp(label));
    assert.equal(f.ui.operatingLoopSyncing.value,false);assert.equal(f.toasts[0].type,key.includes('recorded_block')?'success':'warning');
    if(key.includes('unstarted'))assert.equal('tenant_id' in f.ui.operatingLoop.value.scope,false,'real missing contract has no tenant');
    if(key.includes('recorded_block'))assert.notEqual(f.ui.operatingLoopStateLabel.value,'已完成');
  });
}
test('actual permission boundary hides the write control',async()=>{
  const f=fixture({permitted:false});assert.equal((await f.click()).absent,true);assert.equal(f.calls.length,0);
});
test('real hotel switch releases old lock; old success cannot replace B or unlock its pending POST',async()=>{
  const f=fixture(),a=await f.click();await flush();await f.hotel(8);f.finishRead();await flush();
  assert.equal(f.ui.operatingLoopSyncing.value,false,'new hotel has its own write lock');
  const b=await f.click();await flush();assert.equal(b.disabled,false);assert.equal(f.posts().length,2);
  f.posts()[0].resolve(response(postBody()));assert.equal(await a.done,false);assert.equal(f.ui.operatingLoopSyncing.value,true);
  assert.equal(f.ui.operatingLoop.value.scope.system_hotel_id,8);assert.equal(f.toasts.length,0);assert.equal(f.gets().length,1);
  await finishPost(f,b,postBody('reconcile_recorded_block_body',8));assert.equal(f.ui.operatingLoopSyncing.value,false);
});
test('old hotel error stays silent after B is visible',async()=>{
  const f=fixture(),a=await f.click();await flush();await f.hotel(8);f.finishRead();await flush();
  f.posts()[0].resolve(response({code:500,message:'synthetic old A failure',data:null},500));assert.equal(await a.done,false);
  assert.equal(f.ui.operatingLoop.value.scope.system_hotel_id,8);assert.equal(f.ui.operatingLoopError.value,'');assert.equal(f.toasts.length,0);assert.equal(f.gets().length,1);
});
test('hotel A-B-A round trip cannot revive first A or clear new A lock',async()=>{
  const f=fixture(),a=await f.click();await flush();await f.hotel(8);f.finishRead();await flush();await f.hotel(7);f.finishRead();await flush();
  assert.equal(f.ui.operatingLoopSyncing.value,false);const next=await f.click();await flush();assert.equal(f.posts().length,2);
  f.posts()[0].resolve(response(postBody()));assert.equal(await a.done,false);assert.equal(f.ui.operatingLoopSyncing.value,true);assert.equal(f.toasts.length,0);
  await finishPost(f,next);assert.equal(f.gets().length,3);
});
for(const returnHome of [false,true])test('page leave'+(returnHome?' and return':'')+' revokes old write UI ownership',async()=>{
  const f=fixture(),a=await f.click();await flush();f.s.currentPage.value='revenue-research-center';await flush();
  if(returnHome){f.s.currentPage.value='compass';await flush();}
  f.posts()[0].resolve(response(postBody('reconcile_recorded_block_body')));await flush();assert.equal(f.gets().length,0);assert.equal(await a.done,false);
  assert.equal(f.toasts.length,0);assert.equal(f.ui.operatingLoop.value.authoritative_state,'active');assert.equal(f.ui.operatingLoopSyncing.value,false);
});
test('changed auth cannot attach old error or clear a new session lock',async()=>{
  const f=fixture(),a=await f.click();await flush();f.s.authSessionEpoch+=1;f.s.token.value='synthetic-new-session';await flush();
  assert.equal(f.ui.operatingLoopSyncing.value,false);const b=await f.click();await flush();assert.equal(f.posts().length,2);
  f.posts()[0].resolve(response({code:500,message:'synthetic old session failure',data:null},500));assert.equal(await a.done,false);
  assert.equal(f.ui.operatingLoopSyncing.value,true);assert.equal(f.ui.operatingLoopError.value,'');assert.equal(f.toasts.length,0);await finishPost(f,b);
});
test('current HTTP500 keeps current summary and only original read refresh recovers modeled partial progress',async()=>{
  const f=fixture({initial:'current_missing_body'}),a=await f.click();await flush();
  f.posts()[0].resolve(response({code:500,message:'synthetic later-stage failure',data:null},500));assert.equal(await a.done,false);
  assert.equal(f.posts().length,1);assert.equal(f.gets().length,0);assert.equal(f.ui.operatingLoopError.value,'synthetic later-stage failure');
  const r=await f.refreshClick();await flush();f.finishRead(-1,summary());await r.done;
  assert.equal(f.posts().length,1);assert.equal(f.ui.operatingLoop.value.authoritative_state,'active');assert.equal(f.ui.operatingLoopError.value,'');
});
test('confirmed progress followed by current GET failure retains explicit error and manual read recovery',async()=>{
  const f=fixture(),a=await f.click();await flush();f.posts()[0].resolve(response(postBody()));await flush();f.finishRead(-1,null,500);await a.done;
  assert.equal(f.posts().length,1);assert.equal(f.ui.operatingLoop.value,null);assert.match(await f.render(),/synthetic compass read failed/);
  const r=await f.refreshClick();await flush();f.finishRead();await r.done;assert.equal(f.posts().length,1);assert.equal(f.ui.operatingLoop.value.readback_verified,true);
});
const malformed=[
 ['wrong hotel',b=>{b.data.operating_loop.scope.system_hotel_id=8;}],
 ['wrong business day',b=>{b.data.operating_loop.scope.business_date='2026-09-13';}],
 ['missing wrapper',b=>{b.data=b.data.operating_loop;}],
 ['false kernel identity',b=>{b.data.operating_loop.kernel_id='cycle-999';}],
 ['boolean record id',b=>{b.data.operating_loop.record_id=true;}],
 ['unverified established record',b=>{b.data.operating_loop.readback_verified=false;}],
 ['mismatched next action',b=>{b.data.operating_loop.next_action.revision=999;}],
 ['not-written without waiting',b=>{Object.assign(b,postBody('reconcile_waiting_unstarted_body'));b.data.waiting=null;}],
];
for(const [name,change]of malformed)test('malformed POST receipt remains unconfirmed: '+name,async()=>{
  const f=fixture(),initial=JSON.stringify(f.ui.operatingLoop.value),a=await f.click();await flush();const body=postBody();change(body);f.posts()[0].resolve(response(body));await flush();
  assert.equal(f.gets().length,0,'invalid receipt cannot trigger a post-save refresh');assert.equal(await a.done,false);
  assert.equal(JSON.stringify(f.ui.operatingLoop.value),initial);assert.match(f.ui.operatingLoopError.value,/未确认/);assert.equal(f.toasts.at(-1).type,'error');assert.equal(f.posts().length,1);
});
test('waiting stages are not an increment count and numeric-string identity remains compatible',async()=>{
  const f=fixture(),a=await f.click();await flush();const body=postBody();const loop=body.data.operating_loop;
  body.data.reconciled_stages=[];loop.record_id=String(loop.record_id);loop.revision=String(loop.revision);loop.next_action.revision=String(loop.next_action.revision);loop.scope.system_hotel_id='7';
  await finishPost(f,a,body);assert.equal(f.ui.operatingLoop.value.authoritative_state,'active');
});
for(const [code,reason]of [['kernel_readback_failed','精确回读失败'],['kernel_schema_missing','存储结构尚未就绪'],['kernel_scope_invalid','酒店或租户范围未确认']]){
  test('actual unreadable '+code+' offers only a read retry and no established/missing facts',async()=>{
    const f=fixture(),r=await f.refreshClick();await flush();const data=summary(7,'current_read_failed_body');data.priority_issue.code=code;f.finishRead(-1,data);await r.done;
    const html=await f.render();assert.equal(f.ui.operatingLoopStateLabel.value,'状态尚未确认');assert.match(html,/读取失败/);assert.match(html,new RegExp(reason));assert.doesNotMatch(html,/kernel_readback_failed|kernel_schema_missing|kernel_scope_invalid/);
    assert.doesNotMatch(html,/建立并检查闭环|同步权威状态|Kernel：未建立|revision 0|当前业务日尚未建立权威闭环/);
    assert.equal((await f.click()).absent,true);const retry=await f.readRetry();await flush();assert.equal(f.posts().length,0);assert.equal(f.gets().length,2);
    f.finishRead(-1,summary());await retry.done;assert.equal(f.ui.operatingLoopStateLabel.value,'进行中');assert.match(await f.render(),/同步权威状态/);assert.equal(f.posts().length,0);
  });
}
test('unreadable read retry also exists without execute permission; it never enables a write',async()=>{
  const f=fixture({permitted:false,initial:'current_read_failed_body'});assert.equal((await f.click()).absent,true);const r=await f.readRetry();await flush();f.finishRead();await r.done;assert.equal(f.posts().length,0);assert.equal((await f.click()).absent,true);
});
test('unconfirmed operating loop does not display a browser-derived business date as scope',async()=>{
  const f=fixture({initial:'current_read_failed_body'});
  f.ui.operatingLoop.value.scope.business_date='';
  const html=await f.render();
  assert.match(html,/业务日：未确认/);
  assert.doesNotMatch(html,/业务日：2026-09-14/);
});
test('real setup return exports the unconfirmed computed used by the component',()=>{
  assert.ok(/operationYesterday, operatingLoop, operatingLoopSyncing, operatingLoopError, operatingLoopReadUnconfirmed, operatingLoopStateLabel,/.test(source),'real setup return exposes computed');
  assert.match(component,/ctx\.operatingLoopReadUnconfirmed/);
});
test('confirmed write forces a second real GET and late old read cannot overwrite it',async()=>{
  const f=fixture(),first=await f.refreshClick();await flush();assert.equal(f.gets().length,1);const a=await f.click();await flush();
  f.posts()[0].resolve(response(postBody('reconcile_recorded_block_body')));await flush();assert.equal(f.gets().length,2,'real coordinator force starts a second HTTP');
  f.finishRead(1,postBody('reconcile_recorded_block_body').data.operating_loop);await a.done;
  f.finishRead(0,summary(7,'current_missing_body'));await first.done;assert.equal(f.ui.operatingLoop.value.authoritative_state,'blocked');assert.equal(f.posts().length,1);
});
