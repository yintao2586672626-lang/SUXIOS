<?php
/**
 * 路由入口文件 - 处理所有API请求
 */

const SUXI_STATIC_GZIP_LEVEL = 6;
const SUXI_CSP_REPORT_ONLY = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'sha256-sGmHtz3c5oX2Qt7Y8ows2buHFKo+q72CzwRiUl1Q3HQ=' 'sha256-KtMDY/XkEbZFabcPqczrirnIjdmMQ+36AkxBBm4FVM4=' 'sha256-LVv/26o2w7ZGbEFyp2a1eeKNc3cZnV/P5lalHh3/N3s='; script-src-attr 'none'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; media-src 'self'; worker-src 'self'; manifest-src 'self'";

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy-Report-Only: ' . SUXI_CSP_REPORT_ONLY);

$suxiHttpsValue = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
$suxiIsHttps = $suxiHttpsValue !== '' && !in_array($suxiHttpsValue, ['off', '0', 'false', 'no'], true);
if ($suxiIsHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}

function suxi_static_response_variant(string $staticFile, string $extension): string
{
    if ($extension === 'html' && basename($staticFile) === 'index.html') {
        return 'index-indent-trim-v3';
    }

    return 'raw';
}

function suxi_static_request_has_content_hash(string $requestUri, string $staticFile): bool
{
    $basename = basename($staticFile);
    if (preg_match('/(?:^|[._-])h[0-9a-f]{10}(?:[._-]|$)/iD', $basename) === 1) {
        return true;
    }

    $query = parse_url($requestUri, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return false;
    }
    parse_str($query, $params);
    $version = is_scalar($params['v'] ?? null) ? trim((string)$params['v']) : '';

    return $version !== ''
        && preg_match('/(?:^|[-_])h[0-9a-f]{10}(?:[-_]|$)/iD', $version) === 1;
}

function suxi_trim_index_html_indent(string $source): string
{
    $segments = preg_split('/(\r\n|\n|\r)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($segments)) {
        return $source;
    }

    $output = '';
    $rawTag = null;
    $segmentCount = count($segments);
    for ($index = 0; $index < $segmentCount; $index += 2) {
        $line = (string)$segments[$index];
        $lineEnding = isset($segments[$index + 1]) ? (string)$segments[$index + 1] : '';

        if ($rawTag === null) {
            $line = preg_replace('/^[ \t]+(?=<)/', '', $line) ?? $line;
        }

        $output .= $line . $lineEnding;

        if ($rawTag === null) {
            if (preg_match('/<(script|style|textarea|pre)\b/i', $line, $matches)) {
                $tag = strtolower($matches[1]);
                $openPosition = stripos($line, '<' . $tag);
                $closePosition = stripos($line, '</' . $tag . '>');
                if ($openPosition !== false && ($closePosition === false || $closePosition < $openPosition)) {
                    $rawTag = $tag;
                }
            }
        } elseif (stripos($line, '</' . $rawTag . '>') !== false) {
            $rawTag = null;
        }
    }

    return $output;
}

function suxi_static_response_payload(string $staticFile, string $variant): array
{
    if ($variant !== 'index-indent-trim-v3') {
        return [
            'file' => $staticFile,
            'content' => null,
            'size' => (int)filesize($staticFile),
        ];
    }

    $sourceMtime = (int)filemtime($staticFile);
    $sourceSize = (int)filesize($staticFile);
    $cacheRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'static-html';
    $cacheFile = $cacheRoot . DIRECTORY_SEPARATOR . md5($staticFile) . '-' . $sourceMtime . '-' . $sourceSize . '-' . $variant . '.html';

    if (is_file($cacheFile)) {
        return [
            'file' => $cacheFile,
            'content' => null,
            'size' => (int)filesize($cacheFile),
        ];
    }

    $content = suxi_trim_index_html_indent((string)file_get_contents($staticFile));
    if ((is_dir($cacheRoot) || mkdir($cacheRoot, 0775, true)) && is_writable($cacheRoot)) {
        $tmpFile = $cacheFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmpFile, $content, LOCK_EX) !== false && rename($tmpFile, $cacheFile)) {
            return [
                'file' => $cacheFile,
                'content' => null,
                'size' => (int)filesize($cacheFile),
            ];
        }
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }

    return [
        'file' => null,
        'content' => $content,
        'size' => strlen($content),
    ];
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$staticRequestPath = $requestPath === '/' ? '/index.html' : $requestPath;
$decodedStaticPath = rawurldecode($staticRequestPath);
$pathSegments = array_values(array_filter(
    explode('/', str_replace('\\', '/', $decodedStaticPath)),
    static fn(string $segment): bool => $segment !== ''
));
$hasHiddenPathSegment = count(array_filter(
    $pathSegments,
    static fn(string $segment): bool => str_starts_with($segment, '.')
)) > 0;
if (str_contains($decodedStaticPath, "\0") || $hasHiddenPathSegment) {
    http_response_code(404);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    return true;
}
$staticFile = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $decodedStaticPath);
$publicRoot = realpath(__DIR__);
$resolvedStaticFile = realpath($staticFile);

if ($publicRoot !== false
    && $resolvedStaticFile !== false
    && str_starts_with($resolvedStaticFile, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($resolvedStaticFile)
) {
    $staticFile = $resolvedStaticFile;
    $extension = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];
    $basename = basename($staticFile);
    if (str_starts_with($basename, '.') || !array_key_exists($extension, $mimeTypes)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Not Found';
        return true;
    }
    $cacheableExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'avif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'svg'];
    $compressibleExtensions = ['css', 'html', 'js', 'json', 'map', 'svg'];
    $mtime = (int)filemtime($staticFile);
    $size = (int)filesize($staticFile);
    $responseVariant = suxi_static_response_variant($staticFile, $extension);
    $contentHashedRequest = suxi_static_request_has_content_hash(
        (string)($_SERVER['REQUEST_URI'] ?? ''),
        $staticFile
    );
    $etag = '"' . md5($staticFile . '|' . $mtime . '|' . $size . '|' . $responseVariant) . '"';
    $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

    header('Content-Type: ' . $mimeTypes[$extension]);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Vary: Accept-Encoding');
    if (in_array($extension, $cacheableExtensions, true)) {
        header($contentHashedRequest
            ? 'Cache-Control: public, max-age=2592000, immutable'
            : 'Cache-Control: public, max-age=300, must-revalidate');
    } else {
        header('Cache-Control: no-cache');
        if ($extension === 'html' && basename($staticFile) === 'index.html') {
            header('Cloudflare-CDN-Cache-Control: public, max-age=60, stale-while-revalidate=30');
        }
    }

    $notModified = $ifNoneMatch !== ''
        ? $ifNoneMatch === $etag
        : ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime);
    if ($notModified) {
        http_response_code(304);
        return true;
    }

    $responsePayload = suxi_static_response_payload($staticFile, $responseVariant);
    $responseFile = is_string($responsePayload['file']) ? $responsePayload['file'] : null;
    $responseContent = is_string($responsePayload['content']) ? $responsePayload['content'] : null;
    $responseSize = (int)$responsePayload['size'];
    $acceptEncoding = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
    $canGzip = $responseSize > 1024
        && in_array($extension, $compressibleExtensions, true)
        && function_exists('gzencode')
        && str_contains($acceptEncoding, 'gzip');
    if ($canGzip) {
        $gzipCacheRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'static-gzip';
        $gzipCacheFile = $gzipCacheRoot . DIRECTORY_SEPARATOR . md5($staticFile . '|' . $responseVariant) . '-' . $mtime . '-' . $size . '-' . $responseSize . '-gzip-l' . SUXI_STATIC_GZIP_LEVEL . '.gz';
        if (is_file($gzipCacheFile)) {
            header('Content-Encoding: gzip');
            header('Content-Length: ' . (int)filesize($gzipCacheFile));
            readfile($gzipCacheFile);
            return true;
        }

        if ($responseContent === null && $responseFile !== null) {
            $responseContent = (string)file_get_contents($responseFile);
        }
        $encoded = gzencode($responseContent ?? '', SUXI_STATIC_GZIP_LEVEL);
        if ($encoded !== false) {
            if ((is_dir($gzipCacheRoot) || mkdir($gzipCacheRoot, 0775, true)) && is_writable($gzipCacheRoot)) {
                file_put_contents($gzipCacheFile, $encoded, LOCK_EX);
            }
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($encoded));
            echo $encoded;
            return true;
        }
    }

    header('Content-Length: ' . $responseSize);
    if ($responseContent !== null) {
        echo $responseContent;
    } elseif ($responseFile !== null) {
        readfile($responseFile);
    }
    return true;
}

define('APP_PATH', dirname(__DIR__) . '/app/');

// 加载应用引导文件
if (file_exists(APP_PATH . 'start.php')) {
    require APP_PATH . 'start.php';
} elseif (file_exists(__DIR__ . '/../think')) {
    // ThinkPHP 框架
    require __DIR__ . '/../vendor/autoload.php';
    
    // 执行HTTP应用
    $http = (new think\App())->http;
    $response = $http->run();
    $response->send();
} else {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => '路由文件配置错误', 'code' => 404], JSON_UNESCAPED_UNICODE);
}
