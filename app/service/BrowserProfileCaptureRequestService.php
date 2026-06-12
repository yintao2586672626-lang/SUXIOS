<?php
declare(strict_types=1);

namespace app\service;

final class BrowserProfileCaptureRequestService
{
    public static function safeFilePart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value) ?: 'default';
        return substr($safe, 0, 80);
    }

    public static function timeoutSeconds($value): int
    {
        return max(60, min(900, (int)$value));
    }

    public static function loginTimeoutMs($value): int
    {
        return max(30000, min(600000, (int)$value));
    }

    public static function resolveMeituanStoreId(array $requestData): string
    {
        return trim((string)($requestData['store_id'] ?? $requestData['storeId'] ?? $requestData['poi_id'] ?? ''));
    }

    public static function resolveMeituanPoiId(array $requestData): string
    {
        return trim((string)($requestData['poi_id'] ?? $requestData['poiId'] ?? ''));
    }

    public static function resolveMeituanPoiName(array $requestData): string
    {
        return trim((string)($requestData['poi_name'] ?? $requestData['poiName'] ?? ''));
    }

    public static function resolveMeituanAdsUrl(array $requestData): string
    {
        return trim((string)($requestData['ads_url'] ?? $requestData['adsUrl'] ?? ''));
    }

    public static function normalizeMeituanSections(array $requestData): string
    {
        $value = $requestData['sections'] ?? $requestData['capture_sections'] ?? $requestData['captureSections'] ?? '';
        $sections = is_array($value)
            ? implode(',', array_map('strval', $value))
            : trim((string)$value);
        if ($sections === '') {
            return '';
        }
        return preg_replace('/[^a-zA-Z,_\-\s]+/', '', $sections) ?: '';
    }

    public static function buildMeituanPlan(
        array $requestData,
        string $projectRoot,
        string $nodeBinary,
        bool $loginOnly,
        ?int $systemHotelId,
        string $timestamp,
        string $chromePath = ''
    ): array {
        $storeId = self::resolveMeituanStoreId($requestData);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'meituan_capture_' . self::safeFilePart($storeId) . '_' . $timestamp . '.json';

        $args = [
            $nodeBinary,
            $scriptPath,
            '--store-id=' . $storeId,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . (string)self::loginTimeoutMs($requestData['login_timeout_ms'] ?? 300000),
        ];

        if ($systemHotelId) {
            $args[] = '--system-hotel-id=' . (string)$systemHotelId;
        }

        $poiId = self::resolveMeituanPoiId($requestData);
        if ($poiId !== '') {
            $args[] = '--poi-id=' . $poiId;
        }

        $poiName = self::resolveMeituanPoiName($requestData);
        if ($poiName !== '') {
            $args[] = '--poi-name=' . $poiName;
        }

        $adsUrl = self::resolveMeituanAdsUrl($requestData);
        if ($adsUrl !== '') {
            $args[] = '--ads-url=' . $adsUrl;
        }

        $captureSections = self::normalizeMeituanSections($requestData);
        if ($captureSections !== '') {
            $args[] = '--sections=' . $captureSections;
        }

        if ($loginOnly) {
            $args[] = '--login-only=true';
        }

        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        return [
            'store_id' => $storeId,
            'poi_id' => $poiId,
            'script_path' => $scriptPath,
            'output_dir' => $outputDir,
            'output_path' => $outputPath,
            'timeout_seconds' => self::timeoutSeconds($requestData['timeout_seconds'] ?? 600),
            'args' => $args,
        ];
    }

    public static function resolveCtripHotelId(array $requestData): string
    {
        return trim((string)($requestData['hotel_id'] ?? $requestData['hotelId'] ?? $requestData['ctrip_hotel_id'] ?? ''));
    }

    public static function resolveCtripProfileId(array $requestData, int $systemHotelId, string $hotelId): string
    {
        $profileId = trim((string)($requestData['profile_id'] ?? $requestData['profileId'] ?? $hotelId));
        return $profileId !== '' ? $profileId : 'system_' . (string)$systemHotelId;
    }

    public static function resolveCtripHotelName(array $requestData): string
    {
        return trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? ''));
    }

    public static function buildCtripBasePlan(
        array $requestData,
        string $projectRoot,
        string $nodeBinary,
        int $systemHotelId,
        string $dataDate,
        string $timestamp
    ): array {
        $hotelId = self::resolveCtripHotelId($requestData);
        $profileId = self::resolveCtripProfileId($requestData, $systemHotelId, $hotelId);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_browser_capture_' . self::safeFilePart($profileId) . '_' . $timestamp . '.json';

        $args = [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--data-date=' . $dataDate,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . (string)self::loginTimeoutMs($requestData['login_timeout_ms'] ?? 300000),
            '--login-url=https://ebooking.ctrip.com/login/index',
        ];

        if ($hotelId !== '') {
            $args[] = '--hotel-id=' . $hotelId;
        }

        $hotelName = self::resolveCtripHotelName($requestData);
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }

        return [
            'hotel_id' => $hotelId,
            'profile_id' => $profileId,
            'script_path' => $scriptPath,
            'output_dir' => $outputDir,
            'output_path' => $outputPath,
            'timeout_seconds' => self::timeoutSeconds($requestData['timeout_seconds'] ?? 600),
            'args' => $args,
        ];
    }

    public static function buildCtripAutoArgs(
        string $nodeBinary,
        string $scriptPath,
        string $profileId,
        int $systemHotelId,
        string $dataDate,
        string $outputPath,
        array $sectionsList,
        int $sectionConcurrency,
        bool $interactiveBrowser
    ): array {
        return [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--data-date=' . $dataDate,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . ($interactiveBrowser ? '300000' : '30000'),
            '--sections=' . implode(',', $sectionsList),
            '--section-concurrency=' . (string)$sectionConcurrency,
            $interactiveBrowser ? '--headless=false' : '--headless=true',
        ];
    }

    public static function normalizeProfileSections($value, string $fallback): string
    {
        $raw = is_array($value)
            ? implode(',', array_map(static fn($item): string => (string)$item, $value))
            : (string)$value;
        $raw = preg_replace('/[^a-zA-Z,_\-\s]+/', '', $raw) ?: '';
        $items = preg_split('/[,\s]+/', strtolower($raw)) ?: [];
        $sections = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $sections[$item] = true;
        }

        return implode(',', array_keys($sections)) ?: $fallback;
    }

    public static function buildMeituanAutoArgs(
        array $config,
        string $nodeBinary,
        string $scriptPath,
        int $systemHotelId,
        string $storeId,
        string $outputPath,
        bool $interactiveBrowser,
        string $chromePath = ''
    ): array {
        $args = [
            $nodeBinary,
            $scriptPath,
            '--store-id=' . $storeId,
            '--output=' . $outputPath,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--login-timeout-ms=' . ($interactiveBrowser ? '300000' : '30000'),
            $interactiveBrowser ? '--headless=false' : '--headless=true',
            '--sections=' . self::normalizeProfileSections(
                $config['profile_sections'] ?? $config['capture_sections'] ?? 'traffic,orders',
                'traffic,orders'
            ),
        ];

        $poiId = trim((string)($config['poi_id'] ?? $config['poiId'] ?? ''));
        if ($poiId !== '') {
            $args[] = '--poi-id=' . $poiId;
        }
        $poiName = trim((string)($config['name'] ?? $config['hotel_name'] ?? ''));
        if ($poiName !== '') {
            $args[] = '--poi-name=' . $poiName;
        }
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        return $args;
    }
}
