<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'db:init' => 'app\command\InitDatabase',
        'migrate:ota-credentials' => 'app\command\MigrateOtaCredentials',
        'migrate:sensitive-storage' => 'app\command\MigrateSensitiveStorage',
        'migrate:online-data' => 'app\command\MigrateOnlineData',
        'migrate:login-logs' => 'app\command\MigrateLoginLogs',
        'migrate:notification-recipients' => 'app\command\MigrateNotificationRecipients',
        'online-data:auto-fetch' => 'app\command\AutoFetchOnlineData',
        'online-data:auto-fetch-once' => 'app\command\AutoFetchOnlineDataOnce',
        'online-data:manual-fetch-once' => 'app\command\ManualFetchOnlineDataOnce',
        'online-data:cleanup-manual-fetch-tasks' => 'app\command\CleanupManualFetchTasks',
        'online-data:cleanup-dormant-profiles' => 'app\command\CleanupDormantOtaProfiles',
        'online-data:sync-ctrip-public-profiles' => 'app\command\SyncCtripPublicHotelProfiles',
        'online-data:daily-workbench-patrol' => 'app\command\DailyWorkbenchPatrol',
        'online-data:profile-login' => 'app\command\PlatformProfileLogin',
        'online-data:notify-failure' => 'app\command\NotifyOtaFailure',
        'ai-daily-report:generate-once' => 'app\command\GenerateAiDailyReportOnce',
        'ai-daily-report:cleanup' => 'app\command\CleanupAiReportTasks',
        'video-factory:director' => 'app\command\VideoFactoryDirector',
    ],
];
