# Governed Presentation Delivery

## Entry and smallest loop

1. Read an existing AI daily report through its tenant/hotel-scoped API.
2. `POST /api/ai-daily-reports/:id/presentation-spec` with an optional
   `audience` of `owner`, `expert`, or `training`.
3. Require `storage_status=saved|already_saved`, `readback_verified=true`, a
   64-character `spec_fingerprint`, and `render_status=not_rendered` before any
   renderer starts. A verified source reference must also carry an exact
   `data_source_id`, system hotel ID, business date, OTA platform, and source
   readback state; a generic or cross-scope reference never upgrades evidence.
4. Exact readback is available at
   `GET /api/ai-daily-reports/:id/presentation-spec?audience=owner`.
   The controller must resolve a positive tenant ID from the already-authorized
   hotel row. A missing tenant is a hard failure; neither spec nor artifact
   reads may fall back to report/hotel-only filtering. Exact readback verifies
   both the stored row identity and the identity embedded in the spec.
5. For a formal downloadable artifact, call
   `POST /api/ai-daily-reports/:id/presentation-artifacts` with `report.export`
   permission, the exact `presentation_spec_id`, and its
   `expected_spec_fingerprint`. Reject a changed report/spec as `409` instead
   of silently rendering a newer identity. The response must be the exact
   stored ZIP readback and include `artifact_readback_verified=true`, its byte
   length and SHA-256.
6. The ZIP contains editable macro-free PPTX, self-contained offline HTML,
   `presentation-spec.json`, and `manifest.json`. Both renderers consume the
   exact stored spec fingerprint; they must not query sources, recalculate
   metrics, add missing values, or alter approval states.
7. Exact artifact metadata/readback is available at
   `GET /api/ai-daily-reports/:id/presentation-artifacts?audience=owner`. A
   specific historical artifact remains readable at
   `GET /api/ai-daily-reports/:id/presentation-artifacts/:artifactId`; both
   endpoints stay hotel-scoped and export-gated. A browser download should
   independently compare decoded bytes and SHA-256. It must also cancel a
   response when report, hotel, audience, spec ID, or fingerprint changed while
   the request was in flight.
8. Artifact persistence runs in one transaction: insert as
   `rendered_pending_readback`, verify the artifact/spec tenant-hotel-report-
   audience identity and stored bytes, then promote to
   `rendered_and_readback_verified`. Any mismatch rolls back; a write occurring
   before readback is not itself a verified artifact.

## Evidence mapping

- `VERIFIED_FACT`: same-hotel, same-business-date persisted source with explicit
  readback verification.
- `DERIVED_METRIC`: calculated from verified sources with a metric version.
- `PROFESSIONAL_JUDGMENT`: only a separately governed structured judgment may
  use this class; report/AI free text never qualifies by itself.
- `ACTION_RECOMMENDATION`: candidate action with execution and external writes
  set to false.
- `HUMAN_DECISION`: an already recorded human decision, not inferred approval.
  Require a supported decision state, non-empty record identity, positive
  operator identity, and a valid recorded timestamp; incomplete placeholders
  do not enter the ledger.
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
- Executive summaries are generated from ledger counts and declared scope, not
  copied from free-form report summaries. Anomaly and AI free text is always
  downgraded to `UNKNOWN / hypothesis_review_required`, independent of keyword
  matching. The visible signal label must come from an allowlisted machine-code
  mapping; unknown types use `异常信号 N`. Raw type/code/key/label/name and text
  fields are not republished and only their combined SHA-256 is retained for
  review tracing.
- Five-row HTML slides use the renderer's compact density while preserving the
  full evidence text in the spec/source notes; passing `overflow:hidden` alone
  is not acceptance. Test the actual 1600×900 offline render and the PPTX
  rasterized canvas.
- Human visual review remains required before formal delivery.
- Training output is a review-required draft: remove structured hotel/report
  identity, exact business date and human judgments; hash source identities.
  Do not call free text fully anonymized without a separate content review.
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
