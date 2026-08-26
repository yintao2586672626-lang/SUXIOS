<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controllerDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controller';
$routeDir = $root . DIRECTORY_SEPARATOR . 'route';
$routeFiles = registeredRouteFiles($routeDir);
$autoloadFile = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($autoloadFile)) {
    require_once $autoloadFile;
    foreach (spl_autoload_functions() ?: [] as $autoloadFunction) {
        $loader = is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null;
        if (!$loader instanceof \Composer\Autoload\ClassLoader) {
            continue;
        }
        $loader->setPsr4('app\\', [$root . DIRECTORY_SEPARATOR . 'app']);
    }
}

$ignoredControllers = [
    'app\\controller\\Base' => 'abstract base controller',
    'app\\controller\\Index' => 'ThinkPHP scaffold controller; root path is handled by SPA route',
];

$actions = collectControllerActions($controllerDir, $ignoredControllers);
$routes = collectRouteActions($routeFiles, $root);
$compatibilityAliases = otaCompatibilityRouteAliases();
$compatibilityAliases[actionKey('app\\controller\\Agent', 'approvePrice')]
    = actionKey('app\\controller\\RevenueAi', 'reviewPriceSuggestion');

$missing = [];
foreach ($actions as $key => $action) {
    $aliasKey = $compatibilityAliases[$key] ?? null;
    if (!isset($routes[$key]) && ($aliasKey === null || !isset($routes[$aliasKey]))) {
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
echo "Compatibility aliases: " . count($compatibilityAliases) . PHP_EOL;
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
        echo "- {$route['controller']}::{$route['method']} ({$route['file']}:{$route['line']})" . PHP_EOL;
    }
}

if ($missing !== [] || $invalidRoutes !== []) {
    exit(1);
}

echo PHP_EOL . "All public controller actions are covered by registered route manifests." . PHP_EOL;
exit(0);

/**
 * @return list<string>
 */
function registeredRouteFiles(string $routeDir): array
{
    $bootstrapPath = $routeDir . DIRECTORY_SEPARATOR . 'app.php';
    $bootstrap = file_get_contents($bootstrapPath);
    if (!is_string($bootstrap)) {
        throw new RuntimeException("Unable to read route bootstrap: {$bootstrapPath}");
    }

    preg_match_all(
        "/require __DIR__ \\. '\/domain\/([a-z0-9_]+\\.php)';/",
        $bootstrap,
        $manifestMatches
    );
    $files = [$bootstrapPath];
    $registeredDomainFiles = [];
    foreach ($manifestMatches[1] as $fileName) {
        $domainFile = $routeDir . DIRECTORY_SEPARATOR . 'domain' . DIRECTORY_SEPARATOR . $fileName;
        if (in_array($domainFile, $registeredDomainFiles, true)) {
            throw new RuntimeException("Duplicate route domain manifest registration: {$fileName}");
        }
        if (!is_file($domainFile)) {
            throw new RuntimeException("Registered route domain manifest is missing: {$fileName}");
        }
        $registeredDomainFiles[] = $domainFile;
        $files[] = $domainFile;
    }

    $discoveredDomainFiles = glob($routeDir . DIRECTORY_SEPARATOR . 'domain' . DIRECTORY_SEPARATOR . '*.php') ?: [];
    sort($discoveredDomainFiles);
    $registeredSorted = $registeredDomainFiles;
    sort($registeredSorted);
    if ($discoveredDomainFiles !== $registeredSorted) {
        throw new RuntimeException('Every route domain manifest must be explicitly registered exactly once');
    }

    return $files;
}

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
        if (isPathUnder($path, $controllerDir . DIRECTORY_SEPARATOR . 'concern')) {
            continue;
        }

        $controller = controllerClassFromFile($controllerDir, $path);
        if (isset($ignoredControllers[$controller])) {
            continue;
        }

        foreach (publicControllerMethods($controllerDir, $path, $controller) as $method) {
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
 * @param list<string> $routeFiles
 * @return array<string, array{controller:string, method:string, file:string, line:int}>
 */
function collectRouteActions(array $routeFiles, string $root): array
{
    $routes = [];
    $pattern = '/Route::(?:get|post|put|delete|patch|any|rule)\s*\(\s*([\'"])(?:(?!\1).)*\1\s*,\s*([\'"])([A-Za-z_][A-Za-z0-9_.]*\/[A-Za-z_][A-Za-z0-9_]*)\2/s';

    foreach ($routeFiles as $routeFile) {
        $content = file_get_contents($routeFile);
        if ($content === false) {
            throw new RuntimeException("Unable to read route file: {$routeFile}");
        }

        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches[3] as $match) {
            [$target, $offset] = $match;
            [$controllerName, $method] = explode('/', $target, 2);
            $controller = 'app\\controller\\' . str_replace('.', '\\', $controllerName);
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;

            $routes[actionKey($controller, $method)] = [
                'controller' => $controller,
                'method' => $method,
                'file' => relativePath($root, $routeFile),
                'line' => $line,
            ];
        }
    }

    ksort($routes);
    return $routes;
}

/**
 * @return array<int, array{name:string, line:int, file:string}>
 */
function publicControllerMethods(string $controllerDir, string $path, string $controller): array
{
    if (class_exists($controller)) {
        return publicMethodsInControllerClass($controllerDir, $path, $controller);
    }

    return publicMethodsInFile($path);
}

/**
 * @return array<int, array{name:string, line:int, file:string}>
 */
function publicMethodsInControllerClass(string $controllerDir, string $path, string $controller): array
{
    $reflection = new ReflectionClass($controller);
    $methods = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();
        if ($method->isConstructor() || str_starts_with($name, '__')) {
            continue;
        }

        $methodFile = $method->getFileName();
        if (!is_string($methodFile)) {
            continue;
        }

        if (!isSamePath($methodFile, $path) && !isPathUnder($methodFile, $controllerDir . DIRECTORY_SEPARATOR . 'concern')) {
            continue;
        }

        $methods[] = [
            'name' => $name,
            'line' => $method->getStartLine(),
            'file' => $methodFile,
        ];
    }

    usort($methods, static fn(array $a, array $b): int => [$a['file'], $a['line'], $a['name']] <=> [$b['file'], $b['line'], $b['name']]);

    return $methods;
}

/**
 * @return array<int, array{name:string, line:int, file:string}>
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
            'file' => $path,
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

/** @return array<string, string> legacy action key => domain controller action key */
function otaCompatibilityRouteAliases(): array
{
    if (!class_exists(\app\domain\Ota\OtaActionCatalog::class)) {
        return [];
    }

    $aliases = [];
    foreach (\app\domain\Ota\OtaActionCatalog::all() as $domain => $methods) {
        $controller = 'app\\controller\\ota\\' . ucfirst($domain) . 'Controller';
        if (!class_exists($controller)) {
            throw new RuntimeException("Missing OTA domain controller: {$controller}");
        }

        foreach ($methods as $method) {
            $aliases[actionKey('app\\controller\\OnlineData', $method)] = actionKey($controller, $method);
        }
    }

    return $aliases;
}

function relativePath(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

function isPathUnder(string $path, string $directory): bool
{
    $path = normalizedPath($path);
    $directory = rtrim(normalizedPath($directory), '/') . '/';

    return str_starts_with($path, $directory);
}

function isSamePath(string $left, string $right): bool
{
    return normalizedPath($left) === normalizedPath($right);
}

function normalizedPath(string $path): string
{
    $real = realpath($path);
    if (is_string($real)) {
        $path = $real;
    }

    return strtolower(str_replace('\\', '/', $path));
}
