<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/service/OtaTrafficUrlNormalizer.php';

use app\service\OtaTrafficUrlNormalizer;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$default = OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl('');
assert_true(str_contains($default, 'queryFlowTransforNewV1'), 'default URL must target queryFlowTransforNewV1');
assert_true(str_contains($default, 'hostType=Ebooking'), 'default URL must include hostType');
assert_true((bool)preg_match('/[?&]v=[0-9.]+/', $default), 'default URL must include refreshed v parameter');

$normalized = OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl(
    " https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?foo=1&v=123 "
);
assert_true(!str_contains($normalized, ' '), 'URL whitespace must be removed');
assert_true(str_contains($normalized, 'foo=1'), 'existing query parameters must be preserved');
assert_true(str_contains($normalized, 'hostType=Ebooking'), 'hostType must be added when missing');
assert_true(!str_contains($normalized, 'v=123'), 'v parameter must be refreshed');

$failed = false;
try {
    OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl('https://ebooking.ctrip.com/invalid');
} catch (InvalidArgumentException $exception) {
    $failed = true;
}
assert_true($failed, 'non queryFlowTransforNewV1 URL must be rejected');

echo "OTA traffic URL normalizer verification passed." . PHP_EOL;
