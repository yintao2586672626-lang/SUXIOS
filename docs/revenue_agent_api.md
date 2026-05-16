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

This writes `price_suggestions.suggested_price` back to `room_types.base_price` and marks the suggestion as applied.

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
