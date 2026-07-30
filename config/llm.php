<?php
declare(strict_types=1);

return [
    'provider' => 'deepseek',
    'report_tasks' => [
        // Bound local worker fan-out so report requests cannot exhaust PHP/MySQL.
        'max_concurrent' => max(1, min(8, (int) env('SUXI_AI_REPORT_MAX_CONCURRENT', 2))),
        // Cleanup removes terminal task metadata/cache only, never persisted reports.
        'task_retention_days' => max(1, min(3650, (int) env('SUXI_AI_REPORT_TASK_RETENTION_DAYS', 30))),
        'cache_retention_days' => max(1, min(3650, (int) env('SUXI_AI_REPORT_CACHE_RETENTION_DAYS', 30))),
    ],
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
