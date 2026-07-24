# Suxi Hotel Imagegen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a usable project-local hotel photo editing Skill that preserves OTA facts, supports branded creative modes, and returns truthful verification states.

**Architecture:** Keep routing and execution in a concise `SKILL.md`; put scene recipes, QA, output contract, and evidence in one-level `references/` files. Use the existing system `imagegen` Skill for raster execution and add no provider credentials or business-code integration.

**Tech Stack:** Agent Skills Markdown/YAML, JSON eval fixtures, OpenAI skill-creator validation scripts.

---

### Task 1: Record evaluation gaps

**Files:**
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/evals/evals.json`

- [ ] **Step 1: Preserve the observed baseline gaps as assertions**

Create behavior evals that require: explicit input roles, exactly one mode, protected facts, no cross-hotel fact transfer, no invented channel dimensions, `generated_unverified` before human review, and `storyboard_only` when no video provider exists.

- [ ] **Step 2: Include trigger negatives**

Add nearby negative requests for OTA copywriting, occupancy analysis, SVG editing, and generic UI screenshots so the skill does not over-trigger.

- [ ] **Step 3: Validate JSON syntax**

Run:

```powershell
Get-Content -Raw HOTEL/.agents/skills/suxi-hotel-imagegen/evals/evals.json | ConvertFrom-Json | Out-Null
```

Expected: exit code `0` and no output.

### Task 2: Initialize the Skill

**Files:**
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/SKILL.md`
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/agents/openai.yaml`
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/references/`

- [ ] **Step 1: Run the official initializer**

Run:

```powershell
python C:/Users/Administrator/.codex/skills/.system/skill-creator/scripts/init_skill.py suxi-hotel-imagegen --path HOTEL/.agents/skills --resources references --interface 'display_name=宿析酒店修图' --interface 'short_description=酒店实拍修图、创意图与OTA真实性守卫' --interface 'default_prompt=Use $suxi-hotel-imagegen to edit this hotel image while preserving room facts.'
```

Expected: skill directory plus `SKILL.md`, `agents/openai.yaml`, and `references/`.

### Task 3: Write the core workflow and references

**Files:**
- Modify: `HOTEL/.agents/skills/suxi-hotel-imagegen/SKILL.md`
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/references/hotel-image-modes.md`
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/references/prompt-recipes.md`
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/references/visual-qa.md`
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/references/output-contract.md`
- Create: `HOTEL/.agents/skills/suxi-hotel-imagegen/references/source-evidence.md`

- [ ] **Step 1: Replace the generated frontmatter**

Use exactly two fields:

```yaml
---
name: suxi-hotel-imagegen
description: Use when 宿析OS需要处理酒店或民宿实拍修图、客房修床、卫生间/公区精修、外立面、OTA上架图、日转夜、民宿风、扩图、多图参考或酒店图生视频分镜；也用于判断事实修图与品牌创意图边界。
---
```

- [ ] **Step 2: Implement the core route**

Require `view_image`, explicit input roles, one of `ota_fact_edit | brand_creative | video_storyboard`, protected facts, the system `imagegen` Skill for execution, non-destructive output, and truthful verification states.

- [ ] **Step 3: Add scene recipes**

Cover guestroom natural/lit light with flat/fluffy duvet, bathroom, public area, exterior day/night, window lights, temporary clutter, upscaling, sunset/blue-hour/sunshine/homestay, local edits, multi-image reference, outpainting, ground-to-aerial, and video storyboards.

- [ ] **Step 4: Add QA and output contract**

Require before/after inspection where possible, protected-fact checks, one-change iteration, channel labels, `not_visually_verified`, and no automatic OTA publishing.

- [ ] **Step 5: Add sources without copying implementation**

Reference only public pages and official GitHub files. State that third-party models, prompts, APIs, routes, credentials, images, and conversion claims were not copied.

### Task 4: Validate and forward-test

**Files:**
- Verify: `HOTEL/.agents/skills/suxi-hotel-imagegen/`

- [ ] **Step 1: Run official structural validation**

Run:

```powershell
python C:/Users/Administrator/.codex/skills/.system/skill-creator/scripts/quick_validate.py HOTEL/.agents/skills/suxi-hotel-imagegen
```

Expected: `Skill is valid!`

- [ ] **Step 2: Scan for placeholders and forbidden material**

Run:

```powershell
rg -n 'TODO|TBD|example\.com|api[_-]?key|password|cookie|/api/' HOTEL/.agents/skills/suxi-hotel-imagegen
```

Expected: no placeholder, credential, or copied private-route matches; public source URLs are allowed only in `source-evidence.md`.

- [ ] **Step 3: Run independent with-skill scenarios**

Use fresh subagents on at least: a daily Ctrip guestroom edit, a cross-hotel reference request, and a 5-image video request with no provider. Expected behavior follows `evals/evals.json`.

- [ ] **Step 4: Inspect only the new scope**

Run:

```powershell
git -C HOTEL status --short -- .agents/skills/suxi-hotel-imagegen docs/superpowers/specs/2026-07-13-suxi-hotel-imagegen-design.md docs/superpowers/plans/2026-07-13-suxi-hotel-imagegen.md
git -C HOTEL diff --check -- .agents/skills/suxi-hotel-imagegen docs/superpowers/specs/2026-07-13-suxi-hotel-imagegen-design.md docs/superpowers/plans/2026-07-13-suxi-hotel-imagegen.md
```

Expected: only intended new files; `diff --check` returns no whitespace errors.

