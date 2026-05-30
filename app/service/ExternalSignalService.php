<?php
declare(strict_types=1);

namespace app\service;

use app\model\SystemConfig;

class ExternalSignalService
{
    public function amapWeather(string $city): array
    {
        $key = $this->amapKey();
        if ($key === '') {
            return ['ok' => false, 'message' => 'AMAP_WEB_API_KEY is not configured'];
        }
        if (trim($city) === '') {
            return ['ok' => false, 'message' => 'city is required'];
        }

        $url = 'https://restapi.amap.com/v3/weather/weatherInfo?' . http_build_query([
            'key' => $key,
            'city' => $city,
            'extensions' => 'all',
            'output' => 'JSON',
        ]);
        $data = $this->getJson($url);
        if (($data['status'] ?? '') !== '1') {
            return ['ok' => false, 'message' => (string)($data['info'] ?? 'AMap weather request failed')];
        }

        $casts = $data['forecasts'][0]['casts'] ?? [];
        if (!is_array($casts) || empty($casts)) {
            return ['ok' => false, 'message' => 'AMap weather response has no forecast'];
        }

        $forecast = [];
        foreach (array_slice($casts, 0, 7) as $item) {
            $forecast[] = [
                'location' => (string)($data['forecasts'][0]['city'] ?? $city),
                'date' => (string)($item['date'] ?? ''),
                'week' => (string)($item['week'] ?? ''),
                'temp_high' => (float)($item['daytemp'] ?? 0),
                'temp_low' => (float)($item['nighttemp'] ?? 0),
                'condition' => (string)($item['dayweather'] ?? $item['nightweather'] ?? ''),
                'wind' => trim((string)($item['daywind'] ?? '') . ' ' . (string)($item['daypower'] ?? '')),
            ];
        }

        return ['ok' => true, 'source' => 'AMap weather', 'forecast' => $forecast];
    }

    public function amapPoi(string $keywords, string $city = ''): array
    {
        $key = $this->amapKey();
        if ($key === '') {
            return ['ok' => false, 'message' => 'AMAP_WEB_API_KEY is not configured'];
        }

        $url = 'https://restapi.amap.com/v3/place/text?' . http_build_query([
            'key' => $key,
            'keywords' => $keywords,
            'city' => $city,
            'offset' => 20,
            'page' => 1,
            'extensions' => 'base',
            'output' => 'JSON',
        ]);
        $data = $this->getJson($url);
        if (($data['status'] ?? '') !== '1') {
            return ['ok' => false, 'message' => (string)($data['info'] ?? 'AMap POI request failed')];
        }

        $pois = array_map(static fn(array $item): array => [
            'name' => (string)($item['name'] ?? ''),
            'type' => (string)($item['type'] ?? ''),
            'address' => (string)($item['address'] ?? ''),
            'location' => (string)($item['location'] ?? ''),
        ], array_values($data['pois'] ?? []));

        return ['ok' => true, 'source' => 'AMap POI', 'pois' => $pois];
    }

    private function amapKey(): string
    {
        $value = trim((string)SystemConfig::getValue('amap_web_api_key', ''));
        if ($value !== '') {
            return $value;
        }
        $envValue = trim((string)env('AMAP_WEB_API_KEY', ''));
        if ($envValue !== '') {
            return $envValue;
        }
        return trim((string)env('AMAP_KEY', ''));
    }

    private function getJson(string $url): array
    {
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return ['status' => '0', 'info' => 'external request failed'];
        }
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : ['status' => '0', 'info' => 'external response is not JSON'];
    }
}
