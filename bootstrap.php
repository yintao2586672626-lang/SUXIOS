<?php
declare(strict_types=1);

// Shared dependencies must not redirect application code to another checkout.
$loader = require __DIR__ . '/vendor/autoload.php';
$loader->setPsr4('app\\', [__DIR__ . '/app']);

return new think\App(__DIR__);
