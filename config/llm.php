<?php
declare(strict_types=1);

return [
    'provider' => 'deepseek',
    'deepseek' => [
        'base_url' => rtrim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/'),
        'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
        'default_model' => trim((string) env('DEEPSEEK_DEFAULT_MODEL', 'deepseek-v4-flash')),
        'models' => [
            'fast' => trim((string) env('DEEPSEEK_FAST_MODEL', 'deepseek-v4-flash')),
            'pro' => trim((string) env('DEEPSEEK_PRO_MODEL', 'deepseek-v4-pro')),
        ],
        'timeout' => (int) env('DEEPSEEK_TIMEOUT', 60),
    ],
];
