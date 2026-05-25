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

Returns pending suggestions derived from room base price, demand forecasts, and competitor price records.

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

## Execution lifecycle

```http
POST /api/operation/execution-intents
GET /api/operation/execution-intents
POST /api/operation/execution-intents/{id}/approve
POST /api/operation/execution-tasks/{id}/execute
POST /api/operation/execution-tasks/{id}/evidence
POST /api/operation/execution-tasks/{id}/review
```

Supported `object_type`: `price`, `inventory`, `campaign`.

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
