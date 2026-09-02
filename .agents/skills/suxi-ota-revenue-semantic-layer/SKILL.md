---
name: suxi-ota-revenue-semantic-layer
description: 用于宿析OS OTA收益分析、定价与投资转让证据边界：选择指标定义、表、来源优先级、粒度、过滤、数据质量，以及定价、运营、投资或转让建议的证据边界和执行闭环；不用于在线采集、报告生成、界面实现或完整 ROI/IRR 现金流测算。
---

# SUXIOS OTA Revenue Semantic Layer

Use this skill before choosing SUXIOS metrics, tables, source precedence, joins, or caveats for analytics work across:

```text
OTA data -> revenue analysis -> AI decisions -> operations management -> investment decisions
```

## Plugin Priority

Use `suxi-plugin-priority-router` when analytics outputs should become dashboards, reports, worksheets, or decks. Prefer Data Analytics for source-backed reports/dashboards and Spreadsheets for calculation-ready metric tables.

## Start Here

1. Read `references/semantic-layer.md`.
2. For intraday operating-target gaps, adjacent-snapshot deltas, pickup pace, target-consumption speed or full-house quality, also read `references/operating-target-delta-detection.md`.
3. Use the listed canonical metrics, tables, grains, filters, and caveats.
4. Treat the layer as source-selection guidance, not as a substitute for live reads from the database, API, dashboards, or provided exports.
5. When source coverage is weak, stale, or conflicts with live data, say so and verify against the cited source.

## References

- `references/semantic-layer.md`: metric definitions, tables, query patterns, gotchas, and open questions.
- `references/operating-target-delta-detection.md`: same-day target gap plus adjacent-snapshot delta logic, small-hotel tolerances, anomaly priority, full-house quality, and current Dingdandao boundaries.
- `references/source-inventory.md`: sources checked, coverage level, gaps, and update boundaries.
- `references/evidence.md`: provenance for the key claims this layer preserves.

## Answering Rules

- Keep OTA-channel metrics separate from whole-hotel operating metrics.
- Do not turn missing denominators, failed collection, or absent fields into `0`.
- Preserve metric grain, date rules, platform scope, source trace IDs, and data-quality statuses.
- Treat AI outputs as recommendations that need source references, confidence handling, and human confirmation when they affect operations or investment decisions.
- Treat pricing and execution outputs as advisory or manual-review workflows unless a real OTA write-back API, approval, and execution evidence are available.
