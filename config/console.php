<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'db:init' => 'app\command\InitDatabase',
        'migrate:online-data' => 'app\command\MigrateOnlineData',
        'migrate:login-logs' => 'app\command\MigrateLoginLogs',
        'online-data:auto-fetch' => 'app\command\AutoFetchOnlineData',
    ],
];
