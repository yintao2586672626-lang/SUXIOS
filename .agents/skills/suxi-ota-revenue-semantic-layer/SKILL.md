---
name: suxi-ota-revenue-semantic-layer
description: Use when answering SUXIOS data analytics questions about OTA data, revenue metrics, AI decision support, operations execution loops, pricing recommendations, and investment or transfer decisions.
---

# SUXIOS OTA Revenue Semantic Layer

Use this skill before choosing SUXIOS metrics, tables, source precedence, joins, or caveats for analytics work across:

```text
OTA data -> revenue analysis -> AI decisions -> operations management -> investment decisions
```

## Start Here

1. Read `references/semantic-layer.md`.
2. Use the listed canonical metrics, tables, grains, filters, and caveats.
3. Treat the layer as source-selection guidance, not as a substitute for live reads from the database, API, dashboards, or provided exports.
4. When source coverage is weak, stale, or conflicts with live data, say so and verify against the cited source.

## References

- `references/semantic-layer.md`: metric definitions, tables, query patterns, gotchas, and open questions.
- `references/source-inventory.md`: sources checked, coverage level, gaps, and update boundaries.
- `references/evidence.md`: provenance for the key claims this layer preserves.

## Answering Rules

- Keep OTA-channel metrics separate from whole-hotel operating metrics.
- Do not turn missing denominators, failed collection, or absent fields into `0`.
- Preserve metric grain, date rules, platform scope, source trace IDs, and data-quality statuses.
- Treat AI outputs as recommendations that need source references, confidence handling, and human confirmation when they affect operations or investment decisions.
- Treat pricing and execution outputs as advisory or manual-review workflows unless a real OTA write-back API, approval, and execution evidence are available.
