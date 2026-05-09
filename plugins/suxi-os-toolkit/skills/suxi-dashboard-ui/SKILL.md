---
name: suxi-dashboard-ui
description: Handle宿析OS SaaS dashboard UI tasks. Use when the request includes 数据驾驶舱、看板、仪表盘、SaaS界面、经营数据、图表、筛选器、表格、卡片、指标、趋势、收益分析UI、竞品分析UI、运营诊断UI、Tailwind、Vue 3 CDN、public/index.html、hotel-frontend。
---

# Suxi Dashboard UI

## Rules

1. Match the existing宿析OS SaaS/data-dashboard style: professional, restrained, dense, readable.
2. Reuse current components, utilities, theme variables, and naming conventions.
3. Do not change navigation, global style, state management, API shape, or data structure unless required.
4. Keep dashboard changes scoped to the requested view or module.
5. Ensure mobile and desktop text does not overlap or overflow.
6. Treat `public/index.html` as protected; confirm necessity before editing it.

## UI Preference

- Prioritize tables, compact metrics, filters, segmented controls, and clear hierarchy.
- Avoid marketing-style hero sections and decorative UI.
