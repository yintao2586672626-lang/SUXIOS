---
name: suxi-investment-calculation
description: Handle宿析OS酒店投资测算与收益模型任务。Use when the request includes 投资测算、回本周期、ROI、IRR、现金流、GOP、RevPAR、ADR、OCC、出租率、房价、成本、租金、装修、加盟费、盈亏平衡、收益预测、投资回报、酒店项目可研、测算表、模型字段、旧数据兼容。
---

# Suxi Investment Calculation

## Rules

1. Locate existing calculation fields, persistence, edit, and replay logic before changing anything.
2. Preserve historical fields; add compatibility mapping when names or structure differ.
3. Keep formulas explicit and traceable.
4. Validate save, reload, edit, and old-data display paths for new fields.
5. Do not introduce a new data model unless the user explicitly requests it.

## Output Focus

- Calculation scope and assumptions.
- Formula changes.
- Data compatibility impact.
- Verification path.
