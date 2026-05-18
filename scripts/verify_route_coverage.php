<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controllerDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controller';
$routeFile = $root . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR . 'app.php';

$ignoredControllers = [
    'app\\controller\\Base' => 'abstract base controller',
    'app\\controller\\Index' => 'ThinkPHP scaffold controller; root path is handled by SPA route',
];

$actions = collectControllerActions($controllerDir, $ignoredControllers);
$routes = collectRouteActions($routeFile);

$missing = [];
foreach ($actions as $key => $action) {
    if (!isset($routes[$key])) {
        $missing[$key] = $action;
    }
}

$invalidRoutes = [];
foreach ($routes as $key => $route) {
    if (!isset($actions[$key]) && !isset($ignoredControllers[$route['controller']])) {
        $invalidRoutes[$key] = $route;
    }
}

echo "Route coverage check\n";
echo "Controllers scanned: " . count(array_unique(array_column($actions, 'controller'))) . PHP_EOL;
echo "Public actions scanned: " . count($actions) . PHP_EOL;
echo "Route targets scanned: " . count($routes) . PHP_EOL;
echo "Ignored controllers: " . count($ignoredControllers) . PHP_EOL;

if ($missing !== []) {
    echo PHP_EOL . "Missing route registrations:" . PHP_EOL;
    foreach ($missing as $action) {
        echo "- {$action['controller']}::{$action['method']} ({$action['file']}:{$action['line']})" . PHP_EOL;
    }
}

if ($invalidRoutes !== []) {
    echo PHP_EOL . "Invalid route targets:" . PHP_EOL;
    foreach ($invalidRoutes as $route) {
        echo "- {$route['controller']}::{$route['method']} (route/app.php:{$route['line']})" . PHP_EOL;
    }
}

if ($missing !== [] || $invalidRoutes !== []) {
    exit(1);
}

echo PHP_EOL . "All public controller actions are covered by route/app.php." . PHP_EOL;
exit(0);

/**
 * @param array<string, string> $ignoredControllers
 * @return array<string, array{controller:string, method:string, file:string, line:int}>
 */
function collectControllerActions(string $controllerDir, array $ignoredControllers): array
{
    $actions = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerDir));

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $controller = controllerClassFromFile($controllerDir, $path);
        if (isset($ignoredControllers[$controller])) {
            continue;
        }

        foreach (publicMethodsInFile($path) as $method) {
            $actions[actionKey($controller, $method['name'])] = [
                'controller' => $controller,
                'method' => $method['name'],
                'file' => relativePath(dirname(__DIR__), $path),
                'line' => $method['line'],
            ];
        }
    }

    ksort($actions);
    return $actions;
}

/**
 * @return array<string, array{controller:string, method:string, line:int}>
 */
function collectRouteActions(string $routeFile): array
{
    $content = file_get_contents($routeFile);
    if ($content === false) {
        throw new RuntimeException("Unable to read route file: {$routeFile}");
    }

    $routes = [];
    $pattern = '/Route::(?:get|post|put|delete|patch|any|rule)\s*\(\s*([\'"])(?:(?!\1).)*\1\s*,\s*([\'"])([A-Za-z_][A-Za-z0-9_.]*\/[A-Za-z_][A-Za-z0-9_]*)\2/s';

    if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        return $routes;
    }

    foreach ($matches[3] as $match) {
        [$target, $offset] = $match;
        [$controllerName, $method] = explode('/', $target, 2);
        $controller = 'app\\controller\\' . str_replace('.', '\\', $controllerName);
        $line = substr_count(substr($content, 0, $offset), "\n") + 1;

        $routes[actionKey($controller, $method)] = [
            'controller' => $controller,
            'method' => $method,
            'line' => $line,
        ];
    }

    ksort($routes);
    return $routes;
}

/**
 * @return array<int, array{name:string, line:int}>
 */
function publicMethodsInFile(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Unable to read controller file: {$path}");
    }

    $tokens = token_get_all($content);
    $methods = [];

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        if (!functionHasPublicVisibility($tokens, $index)) {
            continue;
        }

        $nameToken = nextFunctionNameToken($tokens, $index);
        if ($nameToken === null) {
            continue;
        }

        $name = $nameToken[1];
        if (str_starts_with($name, '__')) {
            continue;
        }

        $methods[] = [
            'name' => $name,
            'line' => $nameToken[2],
        ];
    }

    return $methods;
}

/**
 * @param array<int, mixed> $tokens
 */
function functionHasPublicVisibility(array $tokens, int $functionIndex): bool
{
    for ($i = $functionIndex - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
            continue;
        }

        return is_array($token) && $token[0] === T_PUBLIC;
    }

    return false;
}

/**
 * @param array<int, mixed> $tokens
 * @return array{int, string, int}|null
 */
function nextFunctionNameToken(array $tokens, int $functionIndex): ?array
{
    for ($i = $functionIndex + 1, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === '&' || (is_array($token) && $token[0] === T_WHITESPACE)) {
            continue;
        }

        return is_array($token) && $token[0] === T_STRING ? $token : null;
    }

    return null;
}

function controllerClassFromFile(string $controllerDir, string $path): string
{
    $relative = substr($path, strlen($controllerDir) + 1, -4);
    $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

    return 'app\\controller\\' . $relative;
}

function actionKey(string $controller, string $method): string
{
    return strtolower($controller . '::' . $method);
}

function relativePath(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}
