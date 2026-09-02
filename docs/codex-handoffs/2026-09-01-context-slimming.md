# Codex context slimming handoff — 2026-09-01

## Reactivation prompt

We are continuing the SUXIOS Codex context-slimming goal. Read this handoff, inspect the current files and live Codex state, verify what still applies, and continue from the remaining steps. Do not rely on the old chat transcript and do not archive or mutate global Codex state until the user confirms the app can exit.

## Goal

Reduce SUXIOS token use by limiting subagent fan-out, ending oversized sessions, and shrinking automatically injected instructions, Skills, and tools without losing the project's business, data-truth, authorization, or verification boundaries.

## Completed locally

- Replaced the workspace-root `AGENTS.md` with a 7.4 KB always-on contract; the previous file was 25.4 KB.
- Added `AGENTS.override.md` in `HOTEL` as a 5.5 KB compact automatic contract. The existing dirty `HOTEL/AGENTS.md` was not overwritten and remains the on-demand detailed handbook.
- Added synchronized project configs at `../.codex/config.toml` and `.codex/config.toml`:
  - project instruction cap: 12,000 bytes;
  - automatic compaction: 320,000 total tokens;
  - reasoning: high, plan reasoning: medium;
  - per-tool retained output cap: 6,000 tokens;
  - subagent ceiling: two threads, depth one, 30-minute job ceiling;
  - automatic missing Skill-MCP dependency installation disabled;
  - Playwright, Node REPL, Cloudflare API, and OpenAI Docs MCP disabled for routine project work.
- Added `hooks/verify-codex-context-budget.ps1`; it currently passes from `HOTEL`.
- Added `hooks/run-codex-context-maintenance.ps1`. Its default mode is read-only; `-Apply` is rejected unless `-WaitForCodexExit` is also present.
- Created a private local backup of the original root `AGENTS.md` and root project config outside the repository. Keep that backup private.

## Verified evidence

- `codex mcp list --json` loads both project configs successfully.
- Enabled MCP servers fell from six to two (`github`, `codex-security`); five are disabled including the already-disabled CUA REPL.
- `codex debug prompt-input` succeeds after the MCP reduction.
- The root AGENTS payload and HOTEL override are each below the 12 KB guard.
- The existing `HOTEL/AGENTS.md` had unrelated uncommitted changes before this work; preserve them.
- A full `keep-codex-fast --backup-only` backup completed at a short private Windows path after the normal Documents path exposed a long-path copy failure. The verified backup is about 0.76 GB, contains config/state/Skills/plugins/memories/rules, and its config hash matches the live global config.
- Global Skill dedupe dry-run is verified: 55 duplicate top-level gstack aliases each have a retained canonical implementation; projected catalog entries fall from 214 to 159 while the canonical gstack router and `autoplan` remain available. No global Skill setting was written.

## Maintenance completion and post-restart verification

The authorized maintenance completed at 2026-09-01 19:14 Asia/Shanghai through a one-time Windows scheduled task that survived the Codex Desktop shutdown. It created a private backup before applying changes, archived rather than deleted old state, and requested an automatic Codex restart.

- Disabled 55 verified duplicate gstack Skill aliases while retaining their canonical implementations.
- Fresh `codex debug prompt-input` evidence: 159 Skill entries, five remaining intentional `gstack-` entries, canonical gstack router present, and canonical `autoplan` present.
- Automatically injected Skill text is 41,091 characters; the root/project AGENTS payload is 8,936 characters. These are prompt-payload measurements, not account-billing token counts.
- Active session storage is 0.893 GB, down from the approximately 4.1 GB pre-maintenance baseline; archived session storage is 8.307 GB.
- Active logs are 3.2 MB after restart and normal use, down from the approximately 1.09 GB pre-maintenance database; archived logs total 2.138 GB.
- Report-only maintenance finds zero old-session, stale-worktree, or config-prune candidates.
- `hooks/verify-codex-context-budget.ps1` passes: root AGENTS 7,413 bytes, HOTEL override 5,510 bytes, two-agent/depth-one ceilings, 6,000-token tool-output cap, and only the bounded project MCP surface.
- Global config parses successfully after the Skill change.

Two title/first-message display-metadata repair candidates remain. They were deliberately not modified because title/preview repair requires separate explicit authorization and does not reduce the underlying rollout transcript.

No extra live model request was spent merely to obtain an account-facing token count. Compare the next ordinary fresh task's first-call input usage with the earlier approximately 48.8k-token baseline if an account-level measurement is still needed.
