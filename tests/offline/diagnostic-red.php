<?php
declare(strict_types=1);

// Offline diagnostic only. Reads two PHP source files and extracts the real
// method. No bootstrap, Composer, application, database, HTTP or environment.
$projectRoot = $argv[1] ?? dirname(__DIR__, 2);
$concernPath = $projectRoot . '/app/controller/concern/OtaConfigConcern.php';
$exceptionPath = $argv[2] ?? $projectRoot . '/app/service/OtaExecutionStageException.php';
$source = file_get_contents($concernPath);
if (!is_string($source)) {
    throw new RuntimeException('Unable to read diagnostic source.');
}

function extractMethod(string $source, string $name): string
{
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $cursor = $index + 1;
        while (isset($tokens[$cursor]) && is_array($tokens[$cursor])
            && $tokens[$cursor][0] === T_WHITESPACE) {
            $cursor++;
        }
        if (!isset($tokens[$cursor]) || !is_array($tokens[$cursor])
            || $tokens[$cursor][0] !== T_STRING || $tokens[$cursor][1] !== $name) {
            continue;
        }
        $method = 'private ';
        $depth = 0;
        $started = false;
        for ($cursor = $index; isset($tokens[$cursor]); $cursor++) {
            $part = $tokens[$cursor];
            $method .= is_array($part) ? $part[1] : $part;
            if ($part === '{') {
                $started = true;
                $depth++;
            } elseif ($part === '}' && $started && --$depth === 0) {
                return $method;
            }
        }
    }
    throw new RuntimeException('Required diagnostic method was not found.');
}

require $exceptionPath;
$method = extractMethod($source, 'withOtaCredentialForExecution');
$responseMethod = extractMethod($source, 'otaExecutionStageFailureResponse');
eval('namespace think; final class Response { public function __construct(public string $message, public int $code, public array $data) {} }');
eval('namespace think\\facade; final class Log { public static function error(string $message, array $context): void {} }');
$helpers = <<<'PHP'
    public bool $consumerCalled = false;
    public function __construct(private readonly object $replacementVault) {}
    private function validateOtaCredentialLocator(string $platform, string $configId): void {}
    private function currentUserCanMaintainOtaConfig(int $hotelId): bool { return true; }
    private function otaCredentialTenantIdForHotel(int $hotelId): int { return 1; }
    protected function otaCredentialVault(): object { return $this->replacementVault; }
    private function error(string $message, int $code, array $data): \think\Response {
        return new \think\Response($message, $code, $data);
    }
    public function response(\app\service\OtaExecutionStageException $error): \think\Response {
        return $this->otaExecutionStageFailureResponse('synthetic-operation', $error);
    }
    public function execute(): mixed {
        return $this->withOtaCredentialForExecution(
            'ctrip', 'synthetic-config', 64,
            function (array $payload): array { $this->consumerCalled = true; return []; },
            false, true
        );
    }
PHP;
eval('namespace OfflineCredentialDiagnostic; use RuntimeException; use app\\service\\OtaExecutionStageException; use think\\facade\\Log; final class Harness {' . $method . $responseMethod . $helpers . '}');

$cases = [
    'cryptographic_metadata' => 'Credential cryptographic metadata is not executable.',
    'envelope_key_identifier' => 'OTA credential envelope key identifier does not match.',
    'unknown_failure' => 'Unknown SECRET_SENTINEL_PAYLOAD failure.',
    'unknown_near_match' => 'Credential cryptographic metadata is not executable. SECRET_SENTINEL_PAYLOAD',
    'forbidden_crypto' => 'Credential cryptographic metadata is not executable.',
    'forbidden_unknown' => 'Unknown SECRET_SENTINEL_PAYLOAD failure.',
];
$failures = 0;
foreach ($cases as $name => $syntheticMessage) {
    $errorCode = str_starts_with($name, 'forbidden_') ? 403 : 0;
    $vault = new class($syntheticMessage, $errorCode) {
        public function __construct(private readonly string $message, private readonly int $errorCode) {}
        public function withPayloadForExecution(int $tenantId, int $hotelId, string $platform, string $configId, callable $consumer): mixed {
            throw new RuntimeException($this->message, $this->errorCode);
        }
    };
    $harness = new \OfflineCredentialDiagnostic\Harness($vault);
    try {
        $harness->execute();
        throw new RuntimeException('Expected diagnostic execution to fail.');
    } catch (\app\service\OtaExecutionStageException $error) {
        $safeMessage = $error->safeMessage();
        $response = $harness->response($error);
        $preservesBoundary = $error->httpStatus() === ($errorCode === 403 ? 403 : 409)
            && $error->stage() === ($errorCode === 403 ? 'authorization' : 'credential')
            && !$harness->consumerCalled;
        $noLeak = !str_contains($safeMessage, 'SECRET_SENTINEL')
            && !str_contains($safeMessage, 'key identifier')
            && !str_contains($safeMessage, 'cryptographic metadata');
        $correctDiagnosis = $errorCode === 403
            ? str_contains($safeMessage, '无权使用')
            : (str_starts_with($name, 'unknown_')
                ? preg_replace('/\s+/u', '', $safeMessage) === 'OTA凭据不可用'
                : str_contains($safeMessage, '配置不一致'));
        $expectsConfigurationCode = $errorCode !== 403 && !str_starts_with($name, 'unknown_');
        $correctContract = $response->code === ($errorCode === 403 ? 403 : 409)
            && ($response->data['reason'] ?? '') === 'ota_manual_execution_failed'
            && ($response->data['stage'] ?? '') === ($errorCode === 403 ? 'authorization' : 'credential')
            && ($expectsConfigurationCode
                ? ($response->data['failure_code'] ?? '') === 'credential_configuration_mismatch'
                : !array_key_exists('failure_code', $response->data));
        $passed = $preservesBoundary && $noLeak && $correctDiagnosis && $correctContract;
        $failures += $passed ? 0 : 1;
        echo json_encode([
            'case' => $name, 'passed' => $passed,
            'stage' => $error->stage(), 'http_status' => $error->httpStatus(),
            'safe_message' => $safeMessage, 'consumer_called' => $harness->consumerCalled,
            'no_leak' => $noLeak, 'correct_diagnosis' => $correctDiagnosis,
            'correct_contract' => $correctContract,
            'failure_code' => $response->data['failure_code'] ?? null,
        ], JSON_THROW_ON_ERROR), PHP_EOL;
    }
}
echo json_encode(['cases' => count($cases), 'failed' => $failures], JSON_THROW_ON_ERROR), PHP_EOL;
exit($failures === 0 ? 0 : 1);
