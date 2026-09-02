<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\App;
use think\Request;
use think\Response;

/**
 * Executes a queued browser-capture request through ThinkPHP's in-process HTTP
 * kernel. Middleware, token authentication, tenant/hotel permissions and the
 * existing controller save/readback contract all still run, while no loopback
 * curl request occupies a second PHP worker.
 */
final class BrowserCaptureTaskExecutionService
{
    private const ALLOWED_PATHS = [
        '/api/online-data/capture-ctrip-browser',
        '/api/online-data/capture-meituan-browser',
    ];

    /** @var callable|null */
    private $httpRunner;
    private string $projectRoot;

    public function __construct(?callable $httpRunner = null, ?string $projectRoot = null)
    {
        $this->httpRunner = $httpRunner;
        $this->projectRoot = $projectRoot ?: dirname(__DIR__, 2);
    }

    /** @return array<string,scalar|array<int,scalar|null>|null> */
    public static function sanitizeBackgroundRequest(array $requestData): array
    {
        $allowed = array_fill_keys([
            'system_hotel_id', 'systemHotelId', 'hotel_id', 'hotelId', 'ctrip_hotel_id',
            'profile_id', 'profileId', 'store_id', 'storeId', 'poi_id', 'poiId',
            'poi_name', 'poiName', 'hotel_name', 'hotelName', 'partner_id', 'partnerId',
            'data_date', 'dataDate', 'target_date', 'targetDate', 'data_period', 'dataPeriod',
            'snapshot_time', 'snapshotTime', 'capture_mode', 'captureMode',
            'temporal_scope', 'temporalScope', 'sections', 'capture_sections', 'captureSections',
            'login_only', 'loginOnly', 'auth_only', 'authOnly', 'prepare_profile', 'prepareProfile',
            'interactive_browser', 'interactiveBrowser', 'browser_headless', 'headless',
            'timeout_seconds', 'timeoutSeconds', 'login_timeout_ms', 'loginTimeoutMs',
            'bind_data_source', 'bindDataSource',
            'approved_mappings_path', 'min_field_coverage_rate', 'max_missing_fields',
            'require_field_coverage', 'capture_plan', 'section_concurrency', 'parallel_fallback',
            'not_applicable_sections', 'require_current_run_session_probe',
        ], true);
        $safe = [];
        foreach (array_intersect_key($requestData, $allowed) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = array_values(array_filter(
                    array_slice($value, 0, 50),
                    static fn(mixed $item): bool => is_scalar($item) || $item === null
                ));
            }
        }
        foreach ([['cdp_url', 'cdpUrl'], ['ads_url', 'adsUrl']] as [$snakeKey, $camelKey]) {
            $normalizedValues = [];
            foreach ([$snakeKey, $camelKey] as $candidateKey) {
                if (!array_key_exists($candidateKey, $requestData)) {
                    continue;
                }
                if (!is_scalar($requestData[$candidateKey]) && $requestData[$candidateKey] !== null) {
                    throw new \InvalidArgumentException('browser_capture_sensitive_url_rejected');
                }
                $raw = trim((string)$requestData[$candidateKey]);
                if ($raw === '') {
                    continue;
                }
                $normalizedValues[] = $snakeKey === 'cdp_url'
                    ? self::sanitizeLoopbackCdpUrl($raw)
                    : self::sanitizeAdsUrl($raw);
            }
            $normalizedValues = array_values(array_unique($normalizedValues));
            if (count($normalizedValues) > 1) {
                throw new \InvalidArgumentException('browser_capture_sensitive_url_conflict');
            }
            if ($normalizedValues !== []) {
                $safe[$snakeKey] = $normalizedValues[0];
            }
        }
        return $safe;
    }

    private static function sanitizeLoopbackCdpUrl(string $value): string
    {
        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string)($parts['host'] ?? ''))) : '';
        $port = is_array($parts) ? (int)($parts['port'] ?? 0) : 0;
        $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
        if (!is_array($parts)
            || $scheme !== 'http'
            || !in_array($host, ['127.0.0.1', '::1'], true)
            || $port <= 0
            || $port > 65535
            || !in_array($path, ['', '/'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('browser_capture_cdp_url_not_persistable');
        }
        return 'http://' . ($host === '::1' ? '[::1]' : $host) . ':' . $port;
    }

    private static function sanitizeAdsUrl(string $value): string
    {
        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string)($parts['host'] ?? ''))) : '';
        $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
        if (!is_array($parts)
            || $scheme !== 'https'
            || $host === ''
            || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('/(?:token|session|signature|secret|authorization|auth[_-]?key|access[_-]?key)/i', $path) === 1
        ) {
            throw new \InvalidArgumentException('browser_capture_ads_url_not_persistable');
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 0;
        if ((isset($parts['port']) && $port <= 0) || $port > 65535) {
            throw new \InvalidArgumentException('browser_capture_ads_url_not_persistable');
        }
        return 'https://' . $host . ($port > 0 ? ':' . $port : '') . ($path !== '' ? $path : '/');
    }

    /** @param array<string,mixed> $body @return array{success:bool,message:string,response:array<string,mixed>} */
    public function execute(string $apiUrl, string $authorization, array $body): array
    {
        $authorization = trim($authorization);
        $parts = parse_url(trim($apiUrl));
        $path = is_array($parts) ? '/' . ltrim((string)($parts['path'] ?? ''), '/') : '';
        if ($authorization === ''
            || !is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string)($parts['host'] ?? '')) === ''
            || !in_array($path, self::ALLOWED_PATHS, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || (int)($body['system_hotel_id'] ?? 0) <= 0
        ) {
            throw new RuntimeException('browser_capture_background_scope_invalid');
        }

        $body['async'] = false;
        $body['background'] = false;
        $body['background_task'] = true;
        $request = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        $request->setMethod('POST')
            ->setUrl($path)
            ->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'))
            ->withPost($body)
            ->withHeader([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $authorization,
                'User-Agent' => 'suxios-browser-capture-task/1',
            ]);

        $response = $this->httpRunner !== null
            ? call_user_func($this->httpRunner, $request)
            : $this->runThroughKernel($request);
        if (!$response instanceof Response) {
            throw new RuntimeException('browser_capture_background_response_invalid');
        }

        $httpCode = $response->getCode();
        $decoded = json_decode((string)$response->getContent(), true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => 'background browser capture returned invalid JSON',
                'response' => [],
            ];
        }

        $envelopeCode = (int)($decoded['code'] ?? $httpCode);
        return [
            'success' => $httpCode >= 200 && $httpCode < 300 && $envelopeCode === 200,
            'message' => (string)($decoded['message'] ?? ('HTTP ' . $httpCode)),
            'response' => $decoded,
        ];
    }

    private function runThroughKernel(Request $request): Response
    {
        $app = new App($this->projectRoot);
        $http = $app->http;
        $response = $http->run($request);
        $http->end($response);
        return $response;
    }
}
