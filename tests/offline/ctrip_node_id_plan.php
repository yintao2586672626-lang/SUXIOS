<?php
declare(strict_types=1);

// Offline only: no Composer bootstrap, application, database, vault, or collector.
date_default_timezone_set('Asia/Shanghai');
$root = dirname(__DIR__, 2);
require $root . '/app/service/CtripManualFetchRequestService.php';
$source = file_get_contents($root . '/app/controller/concern/AutoFetchConcern.php');
if ($source === false) {
    throw new RuntimeException('Missing isolated production source');
}

// Extract actual production methods, including every helper on the Ctrip plan path.
// Other trait/application dependencies are not loaded or replaced with test doubles.
$names = [
    'autoFetchConfigId', 'autoFetchCredentialReady', 'autoFetchCtripRequestUrl',
    'autoFetchCtripNodeId', 'firstAutoFetchConfigValue', 'compactAutoFetchTaskBody',
    'pushAutoFetchTask', 'buildAutoFetchConfigTaskPlan',
];
$methods = [];
foreach ($names as $name) {
    $pattern = '/^    private function ' . preg_quote($name, '/') . '\([^)]*\)[^{]*\{.*?^    \}/ms';
    if (preg_match($pattern, $source, $match) !== 1) {
        throw new RuntimeException('Missing production method: ' . $name);
    }
    $methods[] = $match[0];
}
eval('namespace app\\controller\\concern; final class OfflineCtripNodeIdPlanHarness {'
    . implode("\n", $methods)
    . ' public function plan(array $config): array {'
    . ' return $this->buildAutoFetchConfigTaskPlan(7, "2026-09-01", $config, []); } }');
$harness = new \app\controller\concern\OfflineCtripNodeIdPlanHarness();
$defaultNode = \app\service\CtripManualFetchRequestService::normalizeNodeId('');
$defaultUrl = \app\service\CtripManualFetchRequestService::normalizeBusinessReportUrl('');
$base = [
    'id' => 'synthetic-ctrip-7',
    'config_id' => 'synthetic-ctrip-7',
    'system_hotel_id' => 7,
    'hotel_id' => 'synthetic-ota-hotel',
    'credential_status' => 'ready',
    'has_cookies' => true,
    'url' => $defaultUrl,
];

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', actual ' . var_export($actual, true));
    }
}

$cases = [];
$nodeCases = [
    ['missing node uses manual default', [], $defaultNode],
    ['null node uses manual default', ['node_id' => null], $defaultNode],
    ['empty node uses manual default', ['node_id' => ''], $defaultNode],
    ['whitespace node uses manual default', ['node_id' => " \t\n"], $defaultNode],
    ['both aliases blank use manual default', ['node_id' => ' ', 'nodeId' => null], $defaultNode],
    ['empty snake alias permits camel alias', ['node_id' => '', 'nodeId' => 'camel-7'], 'camel-7'],
    ['whitespace snake alias permits camel alias', ['node_id' => ' ', 'nodeId' => ' camel-7 '], 'camel-7'],
    ['null snake alias permits camel alias', ['node_id' => null, 'nodeId' => 'camel-7'], 'camel-7'],
    ['nonempty snake alias has priority', ['node_id' => 'snake-7', 'nodeId' => 'camel-7'], 'snake-7'],
    ['custom node is trimmed and retained', ['node_id' => ' node-7.report_v2 '], 'node-7.report_v2'],
    ['camel alias alone remains supported', ['nodeId' => 'camel-7'], 'camel-7'],
    ['invalid snake rejects even with valid camel alias', ['node_id' => 'bad/value', 'nodeId' => 'camel-7'], null],
    ['overlong snake rejects even with valid camel alias', ['node_id' => str_repeat('a', 101), 'nodeId' => 'camel-7'], null],
    ['invalid custom value never defaults', ['node_id' => 'bad value'], null],
    ['maximum valid node length remains supported', ['node_id' => str_repeat('a', 100)], str_repeat('a', 100)],
];
foreach ($nodeCases as [$label, $changes, $expectedNode]) {
    $cases[$label] = static function () use ($harness, $base, $changes, $expectedNode): void {
        $tasks = $harness->plan(array_replace($base, $changes));
        if ($expectedNode === null) {
            expectSame([], $tasks, 'invalid resource must produce no task');
            return;
        }
        expectSame(1, count($tasks), 'one Ctrip plan');
        expectSame($expectedNode, $tasks[0]['body']['node_id'], 'resource identifier');
        expectSame('ctrip-business', $tasks[0]['label'], 'Ctrip business task');
    };
}

$guardCases = [
    ['revoked credential is rejected', ['credential_status' => 'revoked'], []],
    ['status remains case sensitive', ['credential_status' => 'READY'], []],
    ['missing credential status is rejected', [], ['credential_status']],
    ['false cookie metadata is rejected', ['has_cookies' => false], []],
    ['missing cookie metadata is rejected', [], ['has_cookies']],
    ['numeric cookie metadata remains rejected', ['has_cookies' => 1], []],
    ['string cookie metadata remains rejected', ['has_cookies' => 'true'], []],
    ['missing both credential locators is rejected', [], ['config_id', 'id']],
    ['invalid credential locator remains rejected', ['config_id' => 'bad locator'], []],
    ['overlong credential locator remains rejected', ['config_id' => str_repeat('a', 101)], []],
    ['nonempty invalid config locator never falls back to id', ['config_id' => 'bad/locator'], []],
    ['HTTP endpoint remains rejected', ['url' => 'http://ebooking.ctrip.com/report'], []],
    ['foreign endpoint remains rejected', ['url' => 'https://example.invalid/report'], []],
    ['lookalike host remains rejected', ['url' => 'https://ebooking.ctrip.com.example.invalid/report'], []],
    ['file endpoint remains rejected', ['url' => 'file:///synthetic/report'], []],
];
foreach ($guardCases as [$label, $changes, $remove]) {
    $cases[$label] = static function () use ($harness, $base, $changes, $remove): void {
        $config = array_replace($base, $changes, ['node_id' => '']);
        foreach ($remove as $key) {
            unset($config[$key]);
        }
        expectSame([], $harness->plan($config), 'existing configuration gate');
    };
}

$cases['blank URL retains the existing official default'] = static function () use ($harness, $base, $defaultUrl): void {
    $tasks = $harness->plan(array_replace($base, ['node_id' => 'custom-7', 'url' => ' ']));
    expectSame(1, count($tasks), 'blank URL default remains supported');
    expectSame($defaultUrl, $tasks[0]['body']['url'], 'official default URL');
};
$cases['hotel identifier never becomes resource identifier'] = static function () use ($harness, $base, $defaultNode): void {
    $config = array_replace($base, ['hotel_id' => '999999', 'masterHotelId' => '888888']);
    $tasks = $harness->plan($config);
    expectSame(1, count($tasks), 'default resource plan');
    expectSame($defaultNode, $tasks[0]['body']['node_id'], 'resource default is independent of OTA hotel');
    expectSame(7, $tasks[0]['body']['system_hotel_id'], 'requested system hotel remains unchanged');
};
$cases['plan retains scope dates and locator without sensitive fields'] = static function () use ($harness, $base): void {
    $config = array_replace($base, ['node_id' => 'custom-7']);
    $forbidden = ['cookies', 'cookie', 'auth_data', 'authorization', 'token', 'headers',
        'password', 'api_key', 'key_id', 'nonce', 'tag', 'ciphertext', 'encrypted_payload'];
    foreach ($forbidden as $key) {
        $config[$key] = 'synthetic-placeholder';
    }
    $tasks = $harness->plan($config);
    expectSame(1, count($tasks), 'one locator-only plan');
    $task = $tasks[0];
    expectSame('cookie_config', $task['strategy'], 'strategy unchanged');
    expectSame('synthetic-ctrip-7', $task['body']['config_id'], 'credential locator unchanged');
    expectSame(7, $task['body']['system_hotel_id'], 'requested hotel preserved');
    expectSame('2026-09-01', $task['body']['start_date'], 'start date preserved');
    expectSame('2026-09-01', $task['body']['end_date'], 'end date preserved');
    expectSame(true, $task['body']['auto_save'], 'save intention preserved without execution');
    expectSame(false, array_key_exists('required', $task), 'internal requirement metadata removed');
    foreach ($forbidden as $key) {
        expectSame(false, array_key_exists($key, $task['body']), 'forbidden task field ' . $key);
    }
};

$passed = 0;
$failed = 0;
$index = 0;
echo 'TAP version 13', PHP_EOL, '1..', count($cases), PHP_EOL;
foreach ($cases as $label => $run) {
    ++$index;
    try {
        $run();
        ++$passed;
        echo 'ok ', $index, ' - ', $label, PHP_EOL;
    } catch (Throwable $error) {
        ++$failed;
        echo 'not ok ', $index, ' - ', $label, PHP_EOL;
        echo '# ', str_replace(["\r", "\n"], ' ', $error->getMessage()), PHP_EOL;
    }
}
echo 'SUMMARY ', json_encode(['tests' => count($cases), 'pass' => $passed, 'fail' => $failed,
    'php' => PHP_VERSION, 'timezone' => date_default_timezone_get()], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === 0 ? 0 : 1);
