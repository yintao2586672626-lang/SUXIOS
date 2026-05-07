<?php
/**
 * 路由入口文件 - 处理所有API请求
 */

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$staticFile = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($requestPath));

if ($requestPath !== '/' && is_file($staticFile)) {
    return false;
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
