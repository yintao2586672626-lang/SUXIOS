# External Signal API

Configure AMap key through either:

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
If the external key is missing or the provider fails, weather stays in `pending` instead of using static generated data.
