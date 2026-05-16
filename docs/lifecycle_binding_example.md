# Lifecycle Data Binding

Backend endpoint:

```http
GET /api/lifecycle/overview
```

Response shape:

```json
{
  "stages": [
    {
      "key": "opening",
      "title": "Opening",
      "status": "active",
      "status_text": "linked",
      "metrics": [
        { "label": "projects", "value": 2 },
        { "label": "open_tasks", "value": 18 }
      ]
    }
  ]
}
```

Frontend example for `public/index.html`:

```js
const lifecycleOverview = ref(null);
const lifecycleLoading = ref(false);

async function loadLifecycleOverview() {
  lifecycleLoading.value = true;
  try {
    const res = await request('/lifecycle/overview');
    if (res.code === 200) lifecycleOverview.value = res.data;
  } finally {
    lifecycleLoading.value = false;
  }
}
```

Template example:

```html
<div v-if="lifecycleOverview" class="grid grid-cols-1 md:grid-cols-5 gap-4">
  <div v-for="stage in lifecycleOverview.stages" :key="stage.key" class="border rounded-lg p-4">
    <div class="font-semibold">{{ stage.title }}</div>
    <div class="text-xs text-gray-500">{{ stage.status_text }}</div>
    <div v-for="metric in stage.metrics" :key="metric.label" class="mt-2 flex justify-between text-sm">
      <span>{{ metric.label }}</span>
      <strong>{{ metric.value }}</strong>
    </div>
  </div>
</div>
```

The data is sourced from feasibility reports, opening projects/tasks, operation alerts/actions, OTA data, price suggestions, demand forecasts, strategy simulations, and competitor price logs.
