# Revenue Agent API

## Generate suggestions

```http
POST /api/agent/price-suggestions/generate
Content-Type: application/json

{
  "hotel_id": 1,
  "date": "2026-05-16"
}
```

Returns pending suggestions derived from room base price, demand forecast, pickup pace proxy, price elasticity, competitor price, holiday window, inventory constraint, and historical backtest hit rate.

`date` must be a valid `YYYY-MM-DD` value.

The generation endpoint is advisory-only:

- It creates `price_suggestions` rows with `status=1`.
- It does not update `room_types.base_price`.
- It does not write OTA rates.
- Execution still requires manual review and the existing approval / execution-intent workflow.

Key response fields:

| Field | Meaning |
| --- | --- |
| `advisory_only` | Always `true` for suggestion generation. |
| `model_summary.pickup_curve` | Recent OTA room-night pace by rolling windows. Current project data does not contain on-books snapshots, so every result is marked as an `online_daily_data` quantity proxy. |
| `model_summary.price_elasticity` | Log-log estimate from historical OTA ADR and room nights when at least 10 valid samples exist. |
| `model_summary.backtest.hit_rate` | Median-split historical hit rate for price-vs-demand direction. |
| `model_summary.create_policy` | Suggestion creation threshold, including minimum primary signal count and max single change rate. |
| `model_summary.data_gaps` | Missing or weak inputs surfaced for manual review. |
| `list[].factors.signals` | Full signal bundle saved with each suggestion for audit and replay. |
| `list[].factors.signals.competitor.source_date` | Competitor snapshot date. If the target date has no exact snapshot, the model uses the latest snapshot within 7 days and flags staleness in `data_gaps`. |
| `list[].factors.drivers` | Structured signal drivers with direction and rate contribution. |
| `list[].risk_level` | `low` / `medium` / `high`, derived from confidence, primary signal count, and material data gaps. |
| `list[].review_checklist` | Manual review items operators should verify before approval or OTA execution. |
| `list[].factors.primary_signal_count` | Number of active primary pricing drivers. Calendar-only signals are not enough to create a suggestion. |
| `list[].factors.decision_boundary` | `manual_review_required_no_auto_rate_write`. |
| `skipped[]` | Room types reviewed but not written as pending suggestions, with explicit `reason`, `risk_level`, `review_checklist`, and `data_gaps`. Existing pending suggestions are returned as `pending_suggestion_exists` instead of being silently ignored. |

## Approve or reject

Existing endpoint remains:

```http
POST /api/agent/price-suggestions/{id}/approve?action=approve
POST /api/agent/price-suggestions/{id}/approve?action=reject
```

## Apply to room price

```http
POST /api/agent/price-suggestions/{id}/apply
```

This writes `price_suggestions.suggested_price` back to local `room_types.base_price`, marks the suggestion as applied, and creates an `operation_execution_intents` record for OTA platform execution tracking. The OTA platform is not written automatically; the execution intent must still be approved and completed with evidence.

## Create execution intent

```http
POST /api/agent/price-suggestions/{id}/execution-intent?platform=ctrip
```

This creates an execution intent in `operation_execution_intents`. It does not write to OTA automatically; missing platform room/rate mappings keep the intent blocked with an explicit reason.

## Revenue research execution bridge

```http
POST /api/revenue-research/execution-intent
Content-Type: application/json

{
  "hotel_id": 7,
  "research": {
    "product_key": "demand-forecast",
    "status": "done",
    "readiness": {
      "stage": "research_ready_for_execution",
      "execution_ready": true
    },
    "result": {
      "recommended_actions": ["Review next 7-day pricing strategy"],
      "data_gaps": []
    }
  }
}
```

This converts a `/api/revenue-research/run` result into a standard `operation_execution_intents` row with `source_module=revenue_research` and `object_type=revenue_research`. It is still manual-review-only: the endpoint does not write OTA prices, inventory, campaigns, or platform data. Research outputs with `readiness.stage` other than `research_ready_for_execution` or non-empty `data_gaps` remain blocked with explicit reasons.

## Simulation record execution bridge

```http
POST /api/strategy/records/{id}/execution-intent
POST /api/simulation/records/{id}/execution-intent
Content-Type: application/json

{
  "hotel_id": 7,
  "date_start": "2026-06-25",
  "date_end": "2026-06-25"
}
```

These endpoints convert saved strategy and quant simulation records into standard `operation_execution_intents` rows with `object_type=investment`, `metric_scope=investment_decision`, and source modules `strategy_simulation` / `quant_simulation`. They are investment-decision execution bridges only: they do not prove OTA execution, whole-hotel operating closure, or investment closure.

Records with `readiness.stage` in `review_ready`, `approved_pending_execution`, or `execution_ready` create pending manual-review intents. Records that still lack source evidence, manual review, or execution linkage create blocked intents with explicit simulation readiness gaps instead of being treated as closed.

## Execution lifecycle

```http
POST /api/operation/execution-intents
GET /api/operation/execution-intents
POST /api/operation/execution-intents/{id}/approve
POST /api/operation/execution-tasks/{id}/execute
POST /api/operation/execution-tasks/{id}/evidence
POST /api/operation/execution-tasks/{id}/review
```

Supported `object_type`: `price`, `inventory`, `campaign`, `data_collection`, `investment`, `opening`, `expansion`, `revenue_research`.

## Review effect

```http
GET /api/agent/price-suggestions/{id}/review
```

Returns 7-day before/after OTA revenue, room nights, orders, and ADR delta from `online_daily_data`.

## Frontend binding example

```js
async function generatePriceSuggestions(hotelId, date) {
  return request('/agent/price-suggestions/generate', {
    method: 'POST',
    body: JSON.stringify({ hotel_id: hotelId, date })
  });
}

async function applyPriceSuggestion(id) {
  return request(`/agent/price-suggestions/${id}/apply`, { method: 'POST' });
}

async function loadPriceSuggestionReview(id) {
  return request(`/agent/price-suggestions/${id}/review`);
}
```
