# Release Functional Acceptance Matrix

Updated: 2026-05-30

Scope: OTA data -> revenue analysis -> AI decision -> operations management -> investment decision.

This matrix is a local functional gate. It does not close the external release blockers for production env, LLM connectivity, Figma, Canva, OTA credential cleanup, Codex Security scan, or local Git handoff.

Current Chinese acceptance report: `docs/functional_acceptance_report.zh-CN.md`.

## Functional Chains

| Chain | Business scope | Required local evidence | Command gate | Current status |
|---|---|---|---|---|
| OTA channel data | Ctrip and Meituan data ingestion, validation, provenance, and OTA-channel scoped metrics. | `/api/online-data/save-daily-data`, `/api/online-data/daily-data-list`, `/api/online-data/data-analysis`, OTA validator contracts, OTA channel supplement scope. | `npm run verify:ota-data-batch` plus `npm run review:functional-readiness`. | Structurally controlled; real platform collection still depends on credential and permission state. |
| Revenue analysis | OTA imported rows feed revenue summary, ADR, conversion, order, room-night, and service-quality views without labeling OTA-only data as whole-hotel facts. | `OnlineData::dataAnalysis`, revenue metric docs, business-chain E2E contract, OTA supplement scope guard. | `npm run review:functional-readiness` and `npm run verify:e2e-contracts`. | Structurally controlled; production data quality must still be monitored by source status. |
| AI decision | AI conclusions are routed through `LlmClient`, prompt governance, model config, decision impact, confidence, sources, and human confirmation rules. | `LlmClient`, `ai_model_configs`, AI governance tables, strategy/expansion/simulation/feasibility prompt schemas. | `npm run review:functional-readiness` plus `npm run review:release-llm` for production connectivity. | Code path is controlled; production connectivity attestation is still missing. |
| Operations management | Revenue diagnosis becomes alerts, strategy simulation, execution intent, approval, execution evidence, tracking, review, and ROI feedback. | `/api/operation/*`, operation execution migrations, `OperationExecutionLoopTest.php`, operation execution UI, action tracking. | `npm run review:functional-readiness`, `composer test`, and `npm run test:e2e:business` when a runtime is available. | Structurally controlled; no claim of real OTA auto-execution without field mapping, authorization, platform callback, and evidence. |
| Investment decision | Strategy simulation, quant simulation, feasibility report, expansion evaluation, transfer pricing, timing, and dashboard outputs are persisted and reviewable. | `/api/strategy`, `/api/simulation`, `/api/agent/feasibility-report`, `/api/expansion`, `/api/transfer`, record detail/archive services, business-chain E2E contract. | `npm run review:functional-readiness`, `npm run verify:transfer-p2`, and `npm run test:e2e:business` when a runtime is available. | Structurally controlled; real market, competitor, and transaction data sources still need production evidence. |

## Plugin Handoff Coverage

| Plugin scope | Functional gate responsibility | Separate release blocker |
|---|---|---|
| `@github` | CI must run `npm run review:functional-readiness` and `npm run verify:release-status`. | Local Git state and external PR evidence still require `npm run review:release-external-state`. |
| `@openai-developers` | AI code path, model config contract, and governance fields must remain wired. | Real production env and production LLM connectivity attestation are still required. |
| `@codex-security` | Functional gate must keep execution evidence, tenant/hotel scoping, and secret-free release docs visible. | Formal Codex Security scan and OTA credential cleanup remain separate blockers. |
| `@figma` | Functional gate requires code-side UI handoff coverage for login, OTA data, revenue analysis, AI decision, operations management, and investment decision. | Real Figma source handoff remains blocked until `docs/design_handoff_manifest.json` passes `npm run review:release-design`. |
| `@canva` | Functional gate requires Canva and Brand Kit fields in the design handoff contract. | Real Canva design and Brand Kit URLs remain blocked until `npm run review:release-design` passes. |

## Required Commands

Run these for local functional acceptance:

```bash
npm run review:functional-readiness
npm run verify:e2e-contracts
npm run verify:ota-data-batch
npm run verify:transfer-p2
npm run verify:opening-batch-actions
composer test
```

Run these only when a browser/runtime environment is available:

```bash
npm run test:e2e:business
npm run test:e2e:quick
```

## Close Rules

- Passing this matrix proves local structural functional coverage only.
- It does not prove production readiness.
- It does not prove Figma or Canva source approval.
- It does not prove real OpenAI-compatible model connectivity.
- It does not prove OTA credentials are safe or rotated.
- It does not replace a formal Codex Security scan.
