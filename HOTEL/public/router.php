<?php
/**
 * PHP 内置服务器路由文件
 * 用于正确处理静态文件和 API 请求
 */

// 获取请求 URI
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// 静态文件处理
$staticFile = __DIR__ . $path;
if ($path !== '/' && file_exists($staticFile) && is_file($staticFile)) {
    // 返回静态文件
    $mimeTypes = [
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    
    $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mimeType);
    readfile($staticFile);
    return true;
}

// 根路径直接返回 index.html
if ($path === '/') {
    $indexFile = __DIR__ . '/index.html';
    if (file_exists($indexFile)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($indexFile);
        return true;
    }
}

// 其他请求交给 ThinkPHP 处理
require __DIR__ . '/index.php';
