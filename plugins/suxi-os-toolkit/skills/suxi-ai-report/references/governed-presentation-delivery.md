# Governed Presentation Delivery

## Entry and smallest loop

1. Read an existing AI daily report through its tenant/hotel-scoped API.
2. `POST /api/ai-daily-reports/:id/presentation-spec` with an optional
   `audience` of `owner`, `expert`, or `training`.
3. Require `storage_status=saved|already_saved`, `readback_verified=true`, a
   64-character `spec_fingerprint`, and `render_status=not_rendered` before any
   renderer starts.
4. Exact readback is available at
   `GET /api/ai-daily-reports/:id/presentation-spec?audience=owner`.
5. HTML and PPTX must consume the exact returned `spec` and fingerprint. They
   must not query sources, recalculate metrics, add missing values, or alter
   approval states.

## Evidence mapping

- `VERIFIED_FACT`: same-hotel, same-business-date persisted source with explicit
  readback verification.
- `DERIVED_METRIC`: calculated from verified sources with a metric version.
- `PROFESSIONAL_JUDGMENT`: interpretation only; never a causal claim.
- `ACTION_RECOMMENDATION`: candidate action with execution and external writes
  set to false.
- `HUMAN_DECISION`: an already recorded human decision, not inferred approval.
- `UNKNOWN`: missing or untrusted evidence; its value must be `null`, never a
  default zero.
- `MOCK`: training/demo evidence only and must remain labeled.

## Render and acceptance gates

- Spec validation/readback proves only the persisted delivery contract.
- HTML acceptance requires an actual offline render, layout and runtime checks.
- PPTX acceptance requires structure, overflow, font and editability checks
  through the installed Presentations capability; a sample or self-reported QA
  line is not proof.
- Cross-format acceptance compares message, values, units, source/date, unknown
  states and approval states. It does not require pixel identity.
- Human visual review remains required before formal delivery.
- Publishing, external messaging, OTA writes and PMS writes require a separate
  explicit user-triggered authorization path.

## Source provenance boundary

The method was inspired by the fixed public repository
`moyusheng0916-eng/JHIRA-YUSHENG-PPT` at commit
`4dc9898c86ef3c4589c903e69ad12f6e398dcf28`. The repository has no provided
open-source license, incomplete dependency reproducibility, non-shared builder
inputs, and unconditional QA PASS lines. Treat it as `reference_only`: do not
install it, copy its code, adopt its JHIRA brand/palettes, run its builders, or
inherit its quality claims. The SUXIOS adapter is an independent native
implementation and records the source only as method provenance.
