<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/../vendor/autoload.php';

$thinkHelper = __DIR__ . '/../vendor/topthink/framework/src/helper.php';
if (!function_exists('json') && is_file($thinkHelper)) {
    require_once $thinkHelper;
}
