<?php
declare(strict_types=1);

$allowedOrigins = array_values(array_filter(array_map(
    static fn(string $origin): string => trim($origin),
    explode(',', (string)env(
        'CORS_ALLOWED_ORIGINS',
        'http://127.0.0.1:8080,http://localhost:8080'
    ))
), static fn(string $origin): bool => $origin !== '' && $origin !== '*'));

return [
    'allowed_origins' => array_values(array_unique($allowedOrigins)),
    'allow_credentials' => filter_var(env('CORS_ALLOW_CREDENTIALS', true), FILTER_VALIDATE_BOOL),
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    'max_age' => max(0, (int)env('CORS_MAX_AGE', 600)),
];
