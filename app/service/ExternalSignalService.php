<?php
declare(strict_types=1);

namespace app\service;

use app\model\SystemConfig;

class ExternalSignalService
{
    private const AMAP_POI_CONFIG_MISSING_MESSAGE = '地图/POI 数据源未配置：请在系统配置中填写高德 Web API Key，配置前不生成 POI 判断。';

    public function amapWeather(string $city): array
    {
        if (trim($city) === '') {
            return ['ok' => false, 'message' => '未识别到城市，请先维护门店城市后再获取天气。'];
        }

        $key = $this->amapKey();
        if ($key === '') {
            return $this->openMeteoWeather($city);
        }

        $amap = $this->requestAmapWeather($city, $key);
        if (($amap['ok'] ?? false) === true) {
            return $amap;
        }

        $openMeteo = $this->openMeteoWeather($city);
        if (($openMeteo['ok'] ?? false) === true) {
            $openMeteo['provider_notice'] = 'AMap weather unavailable: ' . (string)($amap['message'] ?? '');
            return $openMeteo;
        }

        return [
            'ok' => false,
            'message' => (string)($amap['message'] ?? 'AMap weather request failed') . '；公开天气源也未返回可用数据：' . (string)($openMeteo['message'] ?? ''),
        ];
    }

    private function requestAmapWeather(string $city, string $key): array
    {
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

    private function openMeteoWeather(string $city): array
    {
        $location = $this->resolveOpenMeteoLocation($city);
        if ($location === null) {
            return ['ok' => false, 'message' => "未找到{$city}的公开天气定位结果，请检查门店城市名称。"];
        }

        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,wind_speed_10m_max',
            'timezone' => 'Asia/Shanghai',
            'forecast_days' => 7,
        ], '', '&', PHP_QUERY_RFC3986);
        $data = $this->getJson($url);
        if (($data['error'] ?? false) === true) {
            return ['ok' => false, 'message' => 'Open-Meteo weather request failed: ' . (string)($data['reason'] ?? 'unknown error')];
        }

        $daily = $data['daily'] ?? [];
        $dates = is_array($daily['time'] ?? null) ? $daily['time'] : [];
        if (empty($dates)) {
            return ['ok' => false, 'message' => 'Open-Meteo weather response has no daily forecast'];
        }

        $forecast = [];
        foreach (array_slice($dates, 0, 7) as $index => $date) {
            $forecast[] = [
                'location' => (string)$location['name'],
                'date' => (string)$date,
                'week' => $this->weekName((string)$date),
                'temp_high' => (float)($daily['temperature_2m_max'][$index] ?? 0),
                'temp_low' => (float)($daily['temperature_2m_min'][$index] ?? 0),
                'condition' => $this->weatherCodeText((int)($daily['weather_code'][$index] ?? -1)),
                'wind' => '最大风速 ' . round((float)($daily['wind_speed_10m_max'][$index] ?? 0), 1) . ' km/h',
            ];
        }

        return ['ok' => true, 'source' => 'Open-Meteo weather', 'forecast' => $forecast];
    }

    private function resolveOpenMeteoLocation(string $city): ?array
    {
        foreach ($this->openMeteoSearchTerms($city) as $term) {
            foreach ([true, false] as $chinaOnly) {
                $params = [
                    'name' => $term,
                    'count' => 10,
                    'language' => 'zh',
                    'format' => 'json',
                ];
                if ($chinaOnly) {
                    $params['countryCode'] = 'CN';
                }

                $data = $this->getJson('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
                $results = $data['results'] ?? [];
                if (!is_array($results) || empty($results)) {
                    continue;
                }

                $location = $this->selectOpenMeteoLocation($results, $city, $term);
                if ($location !== null) {
                    return $location;
                }
            }
        }

        return null;
    }

    private function openMeteoSearchTerms(string $city): array
    {
        $normalized = $this->normalizeCityKeyword($city);
        return array_values(array_unique(array_filter([$normalized, trim($city)], static fn (string $term): bool => mb_strlen($term) >= 2)));
    }

    private function normalizeCityKeyword(string $city): string
    {
        $city = (string)preg_replace('/\s+/u', '', trim($city));
        $normalized = (string)preg_replace('/(特别行政区|自治区|自治州|地区|盟|市|县|区)$/u', '', $city);
        return $normalized !== '' ? $normalized : $city;
    }

    private function selectOpenMeteoLocation(array $results, string $city, string $term): ?array
    {
        $best = null;
        $bestScore = -1;
        $normalizedCity = $this->normalizeCityKeyword($city);
        $normalizedTerm = $this->normalizeCityKeyword($term);

        foreach ($results as $item) {
            if (!is_array($item) || !isset($item['latitude'], $item['longitude'])) {
                continue;
            }

            $name = (string)($item['name'] ?? '');
            $admin1 = (string)($item['admin1'] ?? '');
            $admin2 = (string)($item['admin2'] ?? '');
            $score = 0;
            if (($item['country_code'] ?? '') === 'CN') {
                $score += 50;
            }
            if ($this->normalizeCityKeyword($name) === $normalizedTerm || $this->normalizeCityKeyword($name) === $normalizedCity) {
                $score += 30;
            }
            if ($admin1 !== '' && str_contains($admin1, $normalizedCity)) {
                $score += 10;
            }
            if ($admin2 !== '' && str_contains($admin2, $normalizedCity)) {
                $score += 15;
            }
            if (in_array((string)($item['feature_code'] ?? ''), ['PPLC', 'PPLA', 'PPLA2'], true)) {
                $score += 10;
            }
            $score += min(10, (int)round(((float)($item['population'] ?? 0)) / 1000000));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'name' => (string)($best['name'] ?? $city),
            'latitude' => (float)$best['latitude'],
            'longitude' => (float)$best['longitude'],
        ];
    }

    private function weekName(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }
        return ['日', '一', '二', '三', '四', '五', '六'][(int)date('w', $timestamp)];
    }

    private function weatherCodeText(int $code): string
    {
        return match ($code) {
            0 => '晴',
            1, 2 => '少云',
            3 => '阴',
            45, 48 => '雾',
            51, 53, 55 => '毛毛雨',
            56, 57 => '冻毛毛雨',
            61 => '小雨',
            63 => '中雨',
            65 => '大雨',
            66, 67 => '冻雨',
            71 => '小雪',
            73 => '中雪',
            75 => '大雪',
            77 => '雪粒',
            80 => '阵雨',
            81 => '强阵雨',
            82 => '暴雨',
            85 => '小雪阵',
            86 => '大雪阵',
            95 => '雷阵雨',
            96, 99 => '雷阵雨伴冰雹',
            default => '未知天气',
        };
    }

    public function amapPoi(string $keywords, string $city = ''): array
    {
        $key = $this->amapKey();
        if ($key === '') {
            return ['ok' => false, 'message' => self::AMAP_POI_CONFIG_MISSING_MESSAGE];
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
