<?php
declare(strict_types=1);

use app\service\TestProjectProgressWechatService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return string */
function option(string $name): string
{
    foreach ($GLOBALS['argv'] as $index => $argument) {
        if ($argument === '--' . $name) {
            return trim((string)($GLOBALS['argv'][$index + 1] ?? ''));
        }
    }
    return '';
}

$hotelId = (int)option('hotel-id');
$robotId = (int)option('test-robot-id');
$title = option('title');
$message = option('message');
if ($hotelId <= 0 || $robotId <= 0 || $title === '' || $message === '') {
    fwrite(STDERR, "Usage: php scripts/send_test_project_progress.php --hotel-id 80 --test-robot-id 1 --title <title> --message <message>\n");
    exit(1);
}

$app = new App();
$app->initialize();
$result = (new TestProjectProgressWechatService())->send($hotelId, $robotId, $title, $message);
fwrite(STDOUT, (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
exit(($result['delivery_status'] ?? '') === 'sent' ? 0 : 2);
