<?php
declare(strict_types=1);

// Shared dependencies must not redirect application code to another checkout.
$loader = require __DIR__ . '/vendor/autoload.php';
$normalizePath = static function (string $path, bool $resolveExisting = true): string {
    $path = str_replace('\\', '/', ($resolveExisting ? realpath($path) : false) ?: $path);
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
        } else {
            $parts[] = $part;
        }
    }
    return (str_starts_with($path, '/') ? '/' : '') . implode('/', $parts);
};
$applicationRoots = $loader->getPrefixesPsr4()['app\\'] ?? [];
$applicationRoots[] = dirname(realpath(__DIR__ . '/vendor') ?: __DIR__ . '/vendor') . '/app';
$applicationRoots[] = __DIR__ . '/app';
$applicationRoots = array_unique(array_merge(
    array_map($normalizePath, $applicationRoots),
    array_map(static fn(string $path): string => $normalizePath($path, false), $applicationRoots)
));
$loader->setPsr4('app\\', [__DIR__ . '/app']);

// Rebase actual mapped files: one source file may declare more than one class.
$ownedClassmap = [];
foreach ($loader->getClassMap() as $className => $mappedPath) {
    if (strncasecmp($className, 'app\\', 4) === 0) {
        if ($mappedPath === null) continue;
        $mappedPath = $normalizePath($mappedPath);
        foreach ($applicationRoots as $applicationRoot) {
            $prefix = $applicationRoot . '/';
            $matches = DIRECTORY_SEPARATOR === '\\'
                ? strncasecmp($mappedPath, $prefix, strlen($prefix)) === 0
                : str_starts_with($mappedPath, $prefix);
            if ($matches) {
                $localPath = __DIR__ . '/app/' . substr($mappedPath, strlen($prefix));
                $psr4Path = __DIR__ . '/app/' . str_replace('\\', '/', substr($className, 4)) . '.php';
                if (is_file($psr4Path)) $localPath = $psr4Path;
                // Composer's isset lookup skips null entries, allowing local PSR-4
                // fallback or an absent class without including a stale file path.
                $ownedClassmap[$className] = is_file($localPath) ? $localPath : null;
                continue 2;
            }
        }
        throw new LogicException('Application classmap is outside configured application roots: ' . $className);
    }
}
$loader->addClassMap($ownedClassmap);
// A shared authoritative map can omit classes introduced in this checkout.
$loader->setClassMapAuthoritative(false);

return new think\App(__DIR__);
