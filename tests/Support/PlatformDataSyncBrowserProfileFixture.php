<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Shared, filesystem-only fixtures for browser-profile adapter contracts.
 */
trait PlatformDataSyncBrowserProfileFixture
{
    private const READY_NETWORK_FRESHNESS = [
        'status' => 'ready',
        'http_cache_disabled' => true,
        'service_worker_bypassed' => true,
        'sensitive_values_exposed' => false,
    ];

    private function ctripBrowserProfileSource(): array
    {
        return [
            'id' => 77,
            'name' => 'Ctrip Profile Source',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
            'config' => [
                'profile_id' => 'hotel_001',
                'hotel_id' => '24588',
                'hotel_name' => 'Ctrip Demo Hotel',
                'capture_sections' => 'core',
            ],
        ];
    }

    private function meituanBrowserProfileSource(): array
    {
        return [
            'id' => 78,
            'name' => 'Meituan Profile Source',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
            'config' => [
                'store_id' => 'store_001',
                'poi_id' => '68471',
                'poi_name' => 'Meituan Demo Hotel',
                'partner_id' => 'partner_001',
                'capture_sections' => 'traffic,orders',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function readyNetworkFreshness(): array
    {
        return self::READY_NETWORK_FRESHNESS;
    }

    private function createCtripBrowserProfileTestRoot(?string $profileId = null): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ctrip_browser_profile_adapter_' . bin2hex(random_bytes(4));
        mkdir($root . DIRECTORY_SEPARATOR . 'scripts', 0775, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs', '// test script');
        if ($profileId !== null) {
            mkdir($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId, 0775, true);
        }

        return $root;
    }

    private function createMeituanBrowserProfileTestRoot(?string $storeId = null): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meituan_browser_profile_adapter_' . bin2hex(random_bytes(4));
        mkdir($root . DIRECTORY_SEPARATOR . 'scripts', 0775, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs', '// test script');
        if ($storeId !== null) {
            mkdir($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . $storeId, 0775, true);
        }

        return $root;
    }

    private function captureRunner(array $payload): callable
    {
        return static function (array $args) use ($payload): array {
            $outputPath = '';
            foreach ($args as $arg) {
                if (str_starts_with((string)$arg, '--output=')) {
                    $outputPath = substr((string)$arg, strlen('--output='));
                    break;
                }
            }
            if ($outputPath === '') {
                return ['success' => false, 'message' => 'missing output path', 'stdout' => '', 'stderr' => ''];
            }
            $capturePayload = $payload;
            if (!array_key_exists('network_freshness', $capturePayload)) {
                $capturePayload['network_freshness'] = self::READY_NETWORK_FRESHNESS;
            }
            if (!array_key_exists('catalog_facts', $capturePayload)) {
                foreach (is_array($capturePayload['standard_rows'] ?? null) ? $capturePayload['standard_rows'] : [] as $row) {
                    $capturedHotelId = trim((string)($row['hotel_id'] ?? $row['hotelId'] ?? ''));
                    if ($capturedHotelId !== '') {
                        $capturePayload['catalog_facts'] = [[
                            'metric_key' => 'hotel_id',
                            'source_key' => 'masterHotelId',
                            'value' => $capturedHotelId,
                        ]];
                        break;
                    }
                }
            }
            if (($capturePayload['auth_status']['ok'] ?? false) === true
                && !array_key_exists('platform_identity_validation', $capturePayload)
            ) {
                $capturePayload['platform_identity_validation'] = [
                    'status' => 'matched',
                    'source_validation' => true,
                    'validated_identifier' => '68471',
                ];
            }
            file_put_contents($outputPath, json_encode($capturePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
        };
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
