# External Signal API

Weather no longer requires AMap Web API Key. When AMap is not configured, the backend resolves the city through Open-Meteo geocoding and reads 7-day weather from Open-Meteo Forecast API.

AMap remains optional for POI and for weather compatibility. Configure AMap key through either:

```env
AMAP_WEB_API_KEY=***
```

or `system_config.config_key = amap_web_api_key`.

## Weather

```http
GET /api/macro-signals/external?type=weather&city=上海
```

## POI

```http
GET /api/macro-signals/external?type=poi&city=上海&keywords=酒店
```

## Macro signal usage

`GET /api/macro-signals/overview` now reads weather from `ExternalSignalService`.
If all real weather providers fail, weather stays in `pending` instead of using static generated data.
