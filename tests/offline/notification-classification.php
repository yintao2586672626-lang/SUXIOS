<?php
declare(strict_types=1);

// Pure source-extracted notification classifier. No bootstrap, Composer,
// application, database, environment, logs, HTTP or notification delivery.
$sourcePath = $argv[1] ?? dirname(__DIR__, 2) . '/app/service/OtaFailureNotificationService.php';
$source = file_get_contents($sourcePath);
if (!is_string($source)) {
    throw new RuntimeException('Unable to read classifier source.');
}

function extractClassifierMethod(string $source, string $methodName): string
{
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
        $cursor = $index + 1;
        while (isset($tokens[$cursor]) && is_array($tokens[$cursor])
            && $tokens[$cursor][0] === T_WHITESPACE) $cursor++;
        if (!isset($tokens[$cursor]) || !is_array($tokens[$cursor])
            || $tokens[$cursor][0] !== T_STRING || $tokens[$cursor][1] !== $methodName) continue;
        $method = 'private ';
        $depth = 0;
        $started = false;
        for ($cursor = $index; isset($tokens[$cursor]); $cursor++) {
            $part = $tokens[$cursor];
            $method .= is_array($part) ? $part[1] : $part;
            if ($part === '{' || (is_array($part)
                && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $started = true;
                $depth++;
            } elseif ($part === '}' && $started && --$depth === 0) {
                return $method;
            }
        }
    }
    throw new RuntimeException('Pure notification method was not found: ' . $methodName);
}

$constants = '';
foreach (['REASONS', 'AUTH_RESOLUTION_REASONS'] as $constantName) {
    if (!preg_match('/private\s+const\s+' . $constantName . '\s*=\s*(\[[\s\S]*?\]);/', $source, $constant)) {
        throw new RuntimeException('Notification reason constant was not found: ' . $constantName);
    }
    $constants .= 'private const ' . $constantName . ' = ' . $constant[1] . ';';
}
$methods = '';
foreach (['failureReason', 'normalizeReason', 'actionLabel', 'platformLabel', 'notificationTitle', 'notificationMessage'] as $methodName) {
    $methods .= extractClassifierMethod($source, $methodName);
}
eval('namespace OfflineNotificationClassification; final class Harness {'
    . $constants . $methods
    . 'public function inspect(array $event): array {'
    . '$classified = $this->failureReason($event); $normalized = $this->normalizeReason($classified);'
    . 'return ["classified" => $classified, "normalized" => $normalized,'
    . '"requires_auth_resolution" => in_array($normalized, self::AUTH_RESOLUTION_REASONS, true),'
    . '"action" => $this->actionLabel($normalized),'
    . '"title" => $this->notificationTitle("ctrip", $normalized),'
    . '"message" => $this->notificationMessage("ctrip", $normalized, "2026-10-01")]; }}');
$harness = new \OfflineNotificationClassification\Harness();
$configurationCode = 'credential_configuration_mismatch';
$cases = [
    'failure_code_cookie_message' => [
        'failure_code' => $configurationCode,
        'message' => 'Synthetic cookie execution failed because credential configuration differs.',
    ],
    'failure_code_relogin_hint' => [
        'failure_code' => $configurationCode,
        'reason' => 'ota_manual_execution_failed',
        'stage' => 'credential',
        'next_action' => '重新登录',
    ],
    'failure_reason_authorization_message' => [
        'failure_reason' => $configurationCode,
        'message' => 'Synthetic authorization processing failed.',
    ],
    'reason_code_relogin_hint' => [
        'reason_code' => $configurationCode,
        'next_action' => '重新登录',
    ],
    'failure_code_over_stale_login_reason' => [
        'failure_code' => $configurationCode,
        'reason_code' => 'login_expired',
        'message' => 'Synthetic cookie authorization failure.',
    ],
    'ordinary_explicit_login_control' => [
        'reason_code' => 'login_expired',
        'message' => 'Synthetic ordinary expired platform session.',
    ],
    'ordinary_session_unverified_control' => [
        'reason_code' => 'session_unverified',
        'message' => 'Synthetic unverified platform session.',
    ],
    'unknown_failure_code_control' => [
        'failure_code' => 'synthetic_unknown_failure_code',
        'message' => 'Synthetic execution failure.',
    ],
    'near_match_failure_code_control' => [
        'failure_code' => $configurationCode . '_SECRET_SENTINEL_OFFLINE',
        'message' => 'Synthetic execution failure.',
    ],
];
$controlReasons = [
    'ordinary_explicit_login_control' => 'login_expired',
    'ordinary_session_unverified_control' => 'session_unverified',
    'unknown_failure_code_control' => 'collection_failed',
    'near_match_failure_code_control' => 'collection_failed',
];
$failed = 0;
foreach ($cases as $name => $event) {
    $expected = $controlReasons[$name] ?? $configurationCode;
    $actual = $harness->inspect($event);
    $correctClassification = $actual['classified'] === $expected;
    $correctNormalization = $actual['normalized'] === $expected;
    $expectedAuth = in_array($expected, ['login_expired', 'session_unverified'], true);
    $correctAuthBoundary = $actual['requires_auth_resolution'] === $expectedAuth;
    $visibleText = $actual['title'] . ' ' . $actual['message'] . ' ' . $actual['action'];
    $reloginHint = preg_match('/重新登录|再次登录|登录.*验证/u', $visibleText) === 1;
    $correctPresentation = $expected === $configurationCode
        ? str_contains($actual['title'], '凭据配置不一致')
            && str_contains($actual['message'], '联系管理员')
            && !$reloginHint
        : ($expectedAuth ? $reloginHint : !$reloginHint);
    $noLeak = !str_contains($visibleText, 'SECRET_SENTINEL_OFFLINE');
    $passed = $correctClassification && $correctNormalization && $correctAuthBoundary
        && $correctPresentation && $noLeak;
    $failed += $passed ? 0 : 1;
    echo json_encode([
        'case' => $name, 'passed' => $passed, 'expected' => $expected,
        'actual' => $actual['classified'], 'normalized' => $actual['normalized'],
        'requires_auth_resolution' => $actual['requires_auth_resolution'],
        'correct_normalization' => $correctNormalization, 'correct_auth_boundary' => $correctAuthBoundary,
        'correct_presentation' => $correctPresentation, 'relogin_hint' => $reloginHint, 'no_leak' => $noLeak,
        'configuration_misclassified_as_login' => $expected === $configurationCode && $actual['classified'] === 'login_expired',
    ], JSON_THROW_ON_ERROR), PHP_EOL;
}
echo json_encode(['cases' => count($cases), 'failed' => $failed], JSON_THROW_ON_ERROR), PHP_EOL;
exit($failed === 0 ? 0 : 1);
