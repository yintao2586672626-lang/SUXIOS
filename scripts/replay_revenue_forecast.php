<?php
declare(strict_types=1);

// Local, deterministic replay. No database, OTA, LLM, credentials or price writes.
require dirname(__DIR__) . '/tests/bootstrap.php';
$options = getopt('', ['input:', 'synthetic', 'output:']);
try {
    if (isset($options['synthetic'])) {
        $input = \Tests\fixtures\RevenueForecastReplayFixture::input();
        $scope = \Tests\fixtures\RevenueForecastReplayFixture::scope();
    } elseif (isset($options['input'])) {
        $path = (string)$options['input'];
        if (!is_file($path) || filesize($path) > 2000000) throw new InvalidArgumentException('Input file missing or over 2MB.');
        $packet = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $scope = $packet['scope']; $input = $packet['input'];
    } else throw new InvalidArgumentException('Usage: php scripts/replay_revenue_forecast.php --synthetic [--output=...] OR --input=packet.json');
    $result = (new \app\service\RevenueForecastWorkbenchService())->preview($input, $scope);
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (isset($options['output'])) {
        if (file_put_contents((string)$options['output'], $json) !== strlen($json)) throw new RuntimeException('Output write failed.');
        echo json_encode(['status' => 'replayed', 'source_kind' => $result['replay']['source_kind'], 'horizons' => array_keys($result['replay']['forecasts']), 'output' => $options['output']], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else echo $json . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
