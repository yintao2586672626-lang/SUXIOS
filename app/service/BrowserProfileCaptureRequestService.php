<?php
declare(strict_types=1);

namespace app\service;

final class BrowserProfileCaptureRequestService
{
    public const MEITUAN_DEFAULT_SECTIONS = 'traffic,orders';
    public const MEITUAN_FULL_SECTIONS = 'traffic,orders,ads,reviews';

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

    public static function resolveNodeBinary(): string
    {
        $configured = trim((string)(getenv('NODE_BINARY') ?: (function_exists('env') ? env('NODE_BINARY', '') : '')));
        $candidates = array_filter([
            $configured,
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            getenv('USERPROFILE') ? getenv('USERPROFILE') . '\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\node\\bin\\node.exe' : '',
            'node',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'node' || is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    public static function resolveChromePath(): string
    {
        $configured = trim((string)(getenv('CHROME_PATH') ?: (function_exists('env') ? env('CHROME_PATH', '') : '')));
        return $configured !== '' && is_file($configured) ? $configured : '';
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
        return self::normalizeMeituanProfileSections($value, '');
    }

    public static function normalizeMeituanProfileSections($value, string $fallback = self::MEITUAN_DEFAULT_SECTIONS): string
    {
        $raw = is_array($value)
            ? implode(',', array_map(static fn($item): string => (string)$item, $value))
            : trim((string)$value);
        $raw = preg_replace('/[^a-zA-Z,_\-\s]+/', '', $raw) ?: '';
        $items = preg_split('/[,\s]+/', strtolower($raw)) ?: [];
        $sections = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (in_array($item, ['full', 'complete', 'all'], true)) {
                foreach (explode(',', self::MEITUAN_FULL_SECTIONS) as $fullSection) {
                    $sections[$fullSection] = true;
                }
                continue;
            }
            if (in_array($item, ['default', 'core'], true)) {
                foreach (explode(',', self::MEITUAN_DEFAULT_SECTIONS) as $defaultSection) {
                    $sections[$defaultSection] = true;
                }
                continue;
            }
            $normalized = match ($item) {
                'ad', 'ads', 'advertising' => 'ads',
                'order', 'orders' => 'orders',
                'review', 'reviews', 'comment', 'comments', 'review_data' => 'reviews',
                'traffic', 'flow', 'flow_data', 'flowdata', 'businessdata', 'business_data',
                'business', 'overview', 'realtime', 'realtime_snapshot', 'peer_rank', 'peerrank',
                'competitor_rank', 'competitorrank', 'traffic_analysis', 'trafficanalysis',
                'flow_analysis', 'flowanalysis', 'traffic_forecast', 'trafficforecast',
                'flow_forecast', 'flowforecast', 'search_keyword', 'search_keywords',
                'searchkeyword', 'searchkeywords', 'room_type', 'room_types', 'roomtype',
                'roomtypes', 'product', 'products' => 'traffic',
                default => '',
            };
            if ($normalized !== '') {
                $sections[$normalized] = true;
            }
        }

        return implode(',', array_keys($sections)) ?: $fallback;
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
        $dataPeriod = trim((string)($requestData['data_period'] ?? $requestData['dataPeriod'] ?? ''));
        if ($dataPeriod !== '') {
            $args[] = '--data-period=' . $dataPeriod;
        }
        $snapshotTime = trim((string)($requestData['snapshot_time'] ?? $requestData['snapshotTime'] ?? ''));
        if ($snapshotTime !== '') {
            $args[] = '--snapshot-time=' . $snapshotTime;
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
            '--login-url=https://ebooking.ctrip.com/home/mainland',
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
                self::normalizeMeituanProfileSections($config['profile_sections'] ?? $config['capture_sections'] ?? self::MEITUAN_DEFAULT_SECTIONS),
                self::MEITUAN_DEFAULT_SECTIONS
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
        $adsUrl = trim((string)($config['ads_url'] ?? $config['adsUrl'] ?? ''));
        if ($adsUrl !== '') {
            $args[] = '--ads-url=' . $adsUrl;
        }
        $dataPeriod = trim((string)($config['data_period'] ?? $config['dataPeriod'] ?? ''));
        if ($dataPeriod !== '') {
            $args[] = '--data-period=' . $dataPeriod;
        }
        $snapshotTime = trim((string)($config['snapshot_time'] ?? $config['snapshotTime'] ?? ''));
        if ($snapshotTime !== '') {
            $args[] = '--snapshot-time=' . $snapshotTime;
        }
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        return $args;
    }
}
