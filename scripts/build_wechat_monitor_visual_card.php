<?php
declare(strict_types=1);

use app\service\AiDailyReportService;
use app\service\CloudDataHealthService;
use app\service\P0OtaDownstreamGateService;
use app\service\TemporalInsightService;
use app\service\WechatMonitorVisualCardService;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    exit(1);
}
require $autoload;

/** @return array<string,string> */
function visual_card_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (!is_string($argument) || preg_match('/^--([a-z0-9-]+)=(.*)$/i', $argument, $matches) !== 1) {
            continue;
        }
        $options[strtolower($matches[1])] = $matches[2];
    }
    return $options;
}

function visual_card_absolute_path(string $root, string $path): string
{
    $path = trim($path);
    if ($path === '') {
        throw new InvalidArgumentException('图卡输出路径不能为空。');
    }
    $isAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
        || str_starts_with($path, '/')
        || str_starts_with($path, '\\\\');
    return $isAbsolute ? $path : $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

/**
 * @param array<int,string> $command
 * @return array{stdout:string,stderr:string,exit_code:int}
 */
function visual_card_run(array $command, string $workingDirectory): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动图卡渲染器。');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [
        'stdout' => is_string($stdout) ? trim($stdout) : '',
        'stderr' => is_string($stderr) ? trim($stderr) : '',
        'exit_code' => $exitCode,
    ];
}

$options = visual_card_options(array_slice($argv, 1));
$hotelId = (int)($options['hotel-id'] ?? 0);
if ($hotelId <= 0) {
    fwrite(STDERR, "Usage: php scripts/build_wechat_monitor_visual_card.php --hotel-id=<id> [--observed-at=YYYY-mm-dd HH:ii:ss] [--output=runtime/path.png]\n");
    exit(2);
}

$timezone = new DateTimeZone('Asia/Shanghai');
try {
    $observed = isset($options['observed-at']) && trim($options['observed-at']) !== ''
        ? new DateTimeImmutable($options['observed-at'], $timezone)
        : new DateTimeImmutable('now', $timezone);
} catch (Throwable) {
    fwrite(STDERR, "Invalid --observed-at value.\n");
    exit(2);
}
$observed = $observed->setTimezone($timezone);
$output = visual_card_absolute_path(
    $root,
    $options['output']
        ?? ('runtime/wechat_visual_cards/hotel-' . $hotelId . '-' . $observed->format('Ymd-His') . '.png')
);
if (strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'png') {
    fwrite(STDERR, "The visual-card output must use a .png extension.\n");
    exit(2);
}

$temporaryModel = tempnam(sys_get_temp_dir(), 'suxi-wecom-visual-card-');
if (!is_string($temporaryModel) || $temporaryModel === '') {
    fwrite(STDERR, "Unable to create temporary visual-card model.\n");
    exit(1);
}

try {
    (new App($root))->initialize();
    $hotel = Db::name('hotels')
        ->where('id', $hotelId)
        ->where('status', 1)
        ->field('id,tenant_id,name,status')
        ->find();
    if (!is_array($hotel)) {
        throw new RuntimeException('测试门店不存在或未启用。');
    }

    $targetDate = $observed->modify('-1 day')->format('Y-m-d');
    $insight = (new TemporalInsightService())->overview(
        [$hotelId],
        30,
        7,
        $observed->format('Y-m-d')
    );
    $health = (new CloudDataHealthService())->inspectHotel(
        $hotel,
        $targetDate,
        ['ctrip', 'meituan']
    );
    try {
        $health['p0_downstream_gate'] = (new P0OtaDownstreamGateService())
            ->resolveRuntime($targetDate, $hotelId, null, ['ctrip', 'meituan']);
    } catch (Throwable) {
        $health['p0_downstream_gate'] = ['status' => 'unavailable'];
    }
    try {
        $aiDaily = (new AiDailyReportService())->latest([$hotelId], $hotelId);
    } catch (Throwable) {
        $aiDaily = [
            'report' => null,
            'data_status' => 'read_failed',
            'data_gaps' => [[
                'code' => 'ai_daily_report_read_failed',
                'message' => 'AI日报读取失败，当前不输出AI经营结论。',
            ]],
        ];
    }

    $service = new WechatMonitorVisualCardService();
    $model = $service->buildModel(
        $hotel,
        $insight,
        $health,
        $aiDaily,
        $observed->format('Y-m-d H:i:s')
    );
    $json = json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporaryModel, $json) === false) {
        throw new RuntimeException('图卡模型临时文件写入失败。');
    }

    $node = trim((string)(getenv('SUXI_NODE') ?: 'node'));
    $render = visual_card_run([
        $node,
        $root . '/scripts/render_wechat_monitor_visual_card.mjs',
        '--input=' . $temporaryModel,
        '--output=' . $output,
    ], $root);
    if ($render['exit_code'] !== 0) {
        throw new RuntimeException(
            '图卡渲染失败：' . ($render['stderr'] !== '' ? $render['stderr'] : $render['stdout'])
        );
    }

    $payload = $service->imagePayloadFromFile($output);
    $bytes = filesize($output);
    $result = [
        'status' => 'rendered_not_sent',
        'hotel_id' => $hotelId,
        'hotel_name' => (string)($hotel['name'] ?? ''),
        'target_date' => $targetDate,
        'card_type' => (string)($model['card_type'] ?? 'gap'),
        'metric_count' => count((array)($model['metrics'] ?? [])),
        'trend_status' => (string)($model['trend']['status'] ?? 'unavailable'),
        'gap_count' => count((array)($model['gaps'] ?? [])),
        'judgment_status' => (string)($model['judgment']['status'] ?? 'unverified'),
        'output_path' => $output,
        'image_bytes' => is_int($bytes) ? $bytes : null,
        'image_md5' => (string)($payload['image']['md5'] ?? ''),
        'wecom_payload_ready' => ($payload['msgtype'] ?? '') === 'image',
        'delivery_attempted' => false,
    ];
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (is_file($temporaryModel)) {
        @unlink($temporaryModel);
    }
}
