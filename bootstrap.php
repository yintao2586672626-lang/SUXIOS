<?php
declare(strict_types=1);

// Shared dependencies must not redirect application code to another checkout.
$loader = require __DIR__ . '/vendor/autoload.php';
$loader->setPsr4('app\\', [__DIR__ . '/app']);

// Composer resolves optimized classmaps before PSR-4; pin their application paths too.
$ownedClassmap = [];
foreach ($loader->getClassMap() as $className => $mappedPath) {
    if (strncasecmp($className, 'app\\', 4) === 0) {
        $ownedClassmap[$className] = __DIR__ . '/app/' . str_replace('\\', '/', substr($className, 4)) . '.php';
    }
}
$loader->addClassMap($ownedClassmap);
// A shared authoritative map can omit classes introduced in this checkout.
$loader->setClassMapAuthoritative(false);

return new think\App(__DIR__);
