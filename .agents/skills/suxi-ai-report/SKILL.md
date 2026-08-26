---
name: suxi-ai-report
description: 用于宿析OS AI报告、经营诊断、日报、月报、周报、携程/美团竞争商圈和竞品报告、数据分析、经营建议、收益建议、异常归因、Agent.php、DailyReport、LLM、提示词、报告模板、管理层摘要和驾驶舱解读任务。
---

# Suxi AI Report

## Plugin Priority

When the requested report artifact matches an installed plugin, use `suxi-plugin-priority-router` first: Data Analytics for source-backed analysis, Documents for formal reports, Presentations for decks, and Spreadsheets for metric workbooks.

## Rules

1. Separate source data, derived metrics, and AI-generated recommendations.
2. Do not fabricate unavailable metrics; mark missing data clearly.
3. Keep prompts concise, business-oriented, and auditable.
4. Preserve current report fields and export behavior.
5. When adding report fields, verify generation, display, export, edit, and old-data fallback.

## OTA Competition Circle

For Ctrip or Meituan competition-circle reports, read
`references/competition-circle.md` and build the shared governed bundle before
rendering any report. Default to the lite edition unless the user explicitly
asks for flagship/deep/HTML output. Dual delivery must render twice from the
same bundle and must not recalculate.

## Governed Presentation Delivery

For AI daily-report presentation, HTML/PPTX, deck, or slide delivery, read
`references/governed-presentation-delivery.md`. Start from an already saved
report, persist and read back one SUXIOS-native PresentationSpec, and make every
renderer consume that exact fingerprint without recalculation. Formal download
requires the stored artifact bundle to pass exact database readback and browser
SHA-256 verification. Rendering is still not visual review: keep human review
pending, and keep publishing, external sends, and OTA/PMS writes pending until
the user explicitly triggers the authorized action.

## Report Style

- Professional, restrained, decision-oriented.
- Prefer concise bullets and measurable conclusions.
