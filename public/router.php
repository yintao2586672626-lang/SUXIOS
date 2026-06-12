<?php
/**
 * 路由入口文件 - 处理所有API请求
 */

function suxi_static_response_variant(string $staticFile, string $extension): string
{
    if ($extension === 'html' && basename($staticFile) === 'index.html') {
        return 'index-indent-trim-v1';
    }

    return 'raw';
}

function suxi_trim_index_html_indent(string $source): string
{
    $blocks = [];
    $working = preg_replace_callback(
        '/<(script|style|textarea|pre)\b[^>]*>[\s\S]*?<\/\1>/iu',
        static function (array $matches) use (&$blocks): string {
            $key = '%%SUXI_STATIC_HTML_BLOCK_' . count($blocks) . '%%';
            $blocks[$key] = $matches[0];
            return $key;
        },
        $source
    );
    if (!is_string($working)) {
        return $source;
    }

    $trimmed = preg_replace('/\r?\n[ \t]+(?=<)/u', "\n", $working);
    if (!is_string($trimmed)) {
        return $source;
    }

    return strtr($trimmed, $blocks);
}

function suxi_static_response_payload(string $staticFile, string $variant): array
{
    if ($variant !== 'index-indent-trim-v1') {
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
$staticFile = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($staticRequestPath));
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
        'map' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];
    $cacheableExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'svg'];
    $compressibleExtensions = ['css', 'html', 'js', 'json', 'map', 'svg'];
    $mtime = (int)filemtime($staticFile);
    $size = (int)filesize($staticFile);
    $responseVariant = suxi_static_response_variant($staticFile, $extension);
    $etag = '"' . md5($staticFile . '|' . $mtime . '|' . $size . '|' . $responseVariant) . '"';
    $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

    header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Vary: Accept-Encoding');
    if (in_array($extension, $cacheableExtensions, true)) {
        header('Cache-Control: public, max-age=2592000, immutable');
    } else {
        header('Cache-Control: no-cache');
    }

    if ($ifNoneMatch === $etag || ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime)) {
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
        $gzipCacheFile = $gzipCacheRoot . DIRECTORY_SEPARATOR . md5($staticFile . '|' . $responseVariant) . '-' . $mtime . '-' . $size . '-' . $responseSize . '.gz';
        if (is_file($gzipCacheFile)) {
            header('Content-Encoding: gzip');
            header('Content-Length: ' . (int)filesize($gzipCacheFile));
            readfile($gzipCacheFile);
            return true;
        }

        if ($responseContent === null && $responseFile !== null) {
            $responseContent = (string)file_get_contents($responseFile);
        }
        $encoded = gzencode($responseContent ?? '', 1);
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
