# AI Evaluation Replay External Call Gate

`POST /api/ai-governance/evaluation-cases/replay` defaults to planning mode.

Real external model calls require both:

- `dry_run=false` or `execute=true`
- `allow_external_model_call=true`

If execution is requested without `allow_external_model_call=true`, every otherwise-ready case is returned as `blocked` with `external_model_call_not_allowed`, and no model request is sent.
