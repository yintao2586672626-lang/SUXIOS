---
name: suxi-plugin-priority-router
description: 用于宿析OS任务在本地或手工处理前优先路由到已安装 Codex 插件和连接器，触发场景包括插件、连接器、Chrome、GitHub、Computer Use、Figma、Documents、Presentations、Spreadsheets、HyperFrames、Remotion、Canva、Browser、Cloudflare、Data Analytics、Google Drive、OpenAI Developers、Sentry、Skill库改进或插件优先行为。
---

# Suxi Plugin Priority Router

## Priority Rule

Use installed, callable plugins when they directly match the requested action or artifact. Project `AGENTS.md`, `HOTEL/AGENTS.md`, current user scope, data-truth rules, and permission boundaries remain higher priority than plugin convenience.

Do not install plugins, request account authorization, access private systems, or start networked external actions unless the user explicitly asks or confirms the risk.

## Plugin Routing

| Need | Prefer |
| --- | --- |
| Inspect, click, type, screenshot, or verify local web targets such as `localhost` | Browser |
| Use the user's existing browser session, cookies, extensions, or logged-in state | Chrome |
| Operate Windows desktop apps, file dialogs, XAMPP, or non-web UI | Computer Use |
| Repository, PR, issue, CI, or remote collaboration work | GitHub |
| UI mockups, prototypes, design handoff, FigJam, or design-system work | Figma / Product Design |
| Word/Docx/Google Docs-ready deliverables | Documents / Google Drive |
| PPT/PPTX/slide decks | Presentations / Google Drive |
| Excel/Sheets-ready models, formulas, metric tables, investment calculators | Spreadsheets / Google Drive |
| Source-backed KPI reports, dashboards, metric diagnostics, market sizing | Data Analytics |
| Branded visual assets or editable social/marketing designs | Canva |
| Temporary tunnel, Workers, deploy, edge runtime, or Cloudflare config | Cloudflare |
| OpenAI API, Agents SDK, ChatGPT Apps, or API-key workflows | OpenAI Developers |
| Recent production errors, events, or issue traces | Sentry |
| HTML/video composition output | HyperFrames skill when available |
| Code-generated video planning or Remotion project work | Remotion skill when available |

## SUXIOS Skill Handoffs

- `suxi-ai-report`: use Data Analytics for source-backed analysis, Documents for formal reports, Presentations for decks, and Spreadsheets for metric workbooks.
- `suxi-dashboard-ui`: use Figma/Product Design for design exploration or handoff, Browser for local UI verification, and Data Analytics when the dashboard is report-backed.
- `suxi-ota-ops`: use Browser/Chrome only for authorized OTA or local UI flows; keep login, captcha, SMS, cookies, and Profile boundaries explicit.
- `suxi-ctrip-field-table-closure`: use Browser/Chrome for verified response evidence, Data Analytics for metric/table reasoning, and keep the one-endpoint closure chain intact.
- `suxi-ota-revenue-semantic-layer`: use Data Analytics or Spreadsheets for source-backed metric and calculation outputs.
- `suxi-investment-calculation`: use Spreadsheets for calculation models and Presentations/Documents for decision artifacts.
- `suxi-test-guard`: use Browser for local web verification, GitHub for CI/PR evidence, and Sentry for production issue evidence.
- `suxi-skill-installer` and `ecc-codex-adapter`: use local project skills first; use GitHub/OpenAI Developers only when the user asks for remote repo, official API, or plugin integration work.

## Reporting

When a plugin is relevant, state whether it was used, skipped because unavailable/not needed, or blocked by authorization. Do not turn plugin output into business truth unless it is backed by verified SUXIOS data, OTA evidence, database reads, or user-provided files.
