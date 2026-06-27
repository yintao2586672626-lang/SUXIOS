---
name: ecc-codex-adapter
description: 用于任务涉及 ECC、Everything Claude Code、Claude Code 工作流、Codex 适配、Codex 插件、ECC Skill、ECC 规则，或需要把 Claude Code 资产转换为宿析OS Codex 可控使用的场景。
---

# ECC Codex Adapter

## Plugin Priority

Use `suxi-plugin-priority-router` when ECC/Codex work needs installed plugin support. Prefer local project skills and local ECC files first; use GitHub for remote repo/PR/issue tasks and OpenAI Developers only for official OpenAI API, Agents SDK, ChatGPT App, or plugin integration work requested by the user.

## Scope

This skill adapts the downloaded Everything Claude Code (ECC) source for Codex inside the SUXIOS `HOTEL/` project.

Local ECC source:

- `.agents/vendor/everything-claude-code/`
- Source repo: `https://github.com/affaan-m/everything-claude-code`
- Pinned commit: `bc8e12bb80c904a5a9864797ef1fd1212aa82f3d`

## Priority

1. Project `AGENTS.md` is higher priority than ECC.
2. SUXIOS project skills in `.agents/skills/` are higher priority than generic ECC skills.
3. ECC is a reference and optional Codex plugin source, not a replacement for project rules.

## Codex Mapping

Use Codex-native ECC surfaces first:

- `.agents/vendor/everything-claude-code/.codex/AGENTS.md`
- `.agents/vendor/everything-claude-code/.codex/config.toml`
- `.agents/vendor/everything-claude-code/.codex/agents/`
- `.agents/vendor/everything-claude-code/.codex-plugin/plugin.json`
- `.agents/vendor/everything-claude-code/.agents/skills/`

Map Claude Code concepts conservatively:

- `CLAUDE.md` / Claude rules -> project `AGENTS.md` or a narrow project skill.
- Claude hooks -> Codex instructions plus existing project verifier scripts.
- Slash commands -> Codex task instructions or project skills.
- MCP server suggestions -> optional Codex config changes only when the current task needs them.
- Multi-agent examples -> Codex main-controller workflow in `docs/codex_master_agent_parallel_workflow.md`.

## Guardrails

- Do not run ECC installers (`install.ps1`, `install.sh`, `npx ecc-install`, `node scripts/ecc.js install`) unless the user explicitly asks for installation and the exact target is checked first.
- Do not execute ECC hook scripts directly in this project.
- Do not copy all ECC skills into project `.agents/skills/`.
- Do not enable API-backed MCP servers without a task-specific need and explicit credential boundary.
- Do not change business code, `public/index.html`, database schema, OTA protected capture logic, or release state while managing ECC adaptation.
- If an ECC rule conflicts with SUXIOS OTA scope, missing-state, verification, or no-fallback rules, follow SUXIOS.

## Workflow

1. Confirm the ECC source exists at `.agents/vendor/everything-claude-code/`.
2. Read only the ECC files relevant to the current task; do not bulk-load the vendor tree.
3. Prefer SUXIOS-specific skills for OTA, AI reports, investment calculation, dashboard UI, and testing guard work.
4. Use ECC generic skills only when they add a clear workflow pattern not already covered by SUXIOS.
5. Before claiming the adapter is healthy, run:

```powershell
node scripts/verify_ecc_codex_adapter.mjs
```

## Output

When reporting ECC-related work, include:

- Source commit and local path.
- Whether ECC was only downloaded, registered in the local marketplace, or actually enabled.
- Which Codex-native surface was used.
- Verification command and result.
