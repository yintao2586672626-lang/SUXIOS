---
name: suxi-skill-installer
description: Manage宿析OS项目内 Skill 判断、创建、安装与调用。Use when a task mentions Skill、插件、自动安装、自动调用、suxi-skill-installer、suxi-os-toolkit、.agents/skills、.agents/plugins、plugins/、curated skill、官方 Skill、联网授权、账号授权、外部权限，或需要在宿析OS项目中决定是否补齐专用 Skill。
---

# Suxi Skill Installer

## Workflow

1. Read project `AGENTS.md` first.
2. Infer required skills from the current user request only.
3. Check `.agents/skills/` before installing or creating anything.
4. If a matching project skill exists, use it directly.
5. If missing and the need is宿析OS specific, create a minimal project skill under `.agents/skills/<skill-name>/`.
6. If the user explicitly needs an official curated skill, use `$skill-installer`.
7. Do not install unknown-source skills.
8. Do not install skills that are merely nice to have.
9. Before any network, external permission, account authorization, or private repo access, state the risk and wait for user confirmation.

## Scope Guard

- Never modify business code while managing skills.
- Never modify `public/index.html`.
- Preserve existing rules and files.
- Prefer project-local skills over global installs for宿析OS domain workflows.
