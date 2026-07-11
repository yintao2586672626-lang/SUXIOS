<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use RuntimeException;
use Throwable;

final class OtaCredentialKeyInitializer
{
    private const KEY_VARIABLE = 'OTA_CREDENTIAL_KEY_B64';
    private const ID_VARIABLE = 'OTA_CREDENTIAL_KEY_ID';
    private const KEY_BYTES = 32;

    private Closure $randomBytes;
    private Closure $dateProvider;
    private Closure $writeChunk;
    private Closure $renameFile;
    private ?Closure $permissionGate;

    public function __construct(
        ?callable $randomBytes = null,
        ?callable $dateProvider = null,
        ?callable $writeChunk = null,
        ?callable $renameFile = null,
        ?callable $permissionGate = null
    ) {
        $this->randomBytes = $randomBytes !== null
            ? Closure::fromCallable($randomBytes)
            : static fn(int $length): string => random_bytes($length);
        $this->dateProvider = $dateProvider !== null
            ? Closure::fromCallable($dateProvider)
            : static fn(): string => date('Ymd');
        $this->writeChunk = $writeChunk !== null
            ? Closure::fromCallable($writeChunk)
            : static fn($handle, string $chunk): int|false => fwrite($handle, $chunk);
        $this->renameFile = $renameFile !== null
            ? Closure::fromCallable($renameFile)
            : static fn(string $from, string $to): bool => rename($from, $to);
        $this->permissionGate = $permissionGate !== null
            ? Closure::fromCallable($permissionGate)
            : null;
    }

    /**
     * @return array{mode:string,status:string,configured:bool,initialized:bool,key_id:?string,fingerprint:?string,reason_code:string}
     */
    public function run(string $envPath, bool $execute = false): array
    {
        $mode = $execute ? 'execute' : 'dry-run';
        if ($envPath === '') {
            return $this->summary($mode, 'blocked', false, false, null, null, 'env_path_invalid');
        }

        if (!$execute) {
            $content = $this->readExistingFile($envPath);
            if ($content === null) {
                return $this->summary($mode, 'blocked', false, false, null, null, 'env_read_failed');
            }
            return $this->assess($content, $mode);
        }

        return $this->execute($envPath, $mode);
    }

    /**
     * @return array{mode:string,status:string,configured:bool,initialized:bool,key_id:?string,fingerprint:?string,reason_code:string}
     */
    private function execute(string $envPath, string $mode): array
    {
        $lockHandle = @fopen($envPath . '.ota-key.lock', 'c+b');
        if ($lockHandle === false) {
            return $this->summary($mode, 'blocked', false, false, null, null, 'env_lock_open_failed');
        }

        $locked = false;
        try {
            if (!flock($lockHandle, LOCK_EX)) {
                return $this->summary($mode, 'blocked', false, false, null, null, 'env_lock_failed');
            }
            $locked = true;
            $content = $this->readExistingFile($envPath);
            if ($content === null) {
                return $this->summary($mode, 'blocked', false, false, null, null, 'env_read_failed');
            }
            $existed = is_file($envPath);

            $assessment = $this->assess($content, $mode);
            if ($assessment['status'] !== 'initialization_required') {
                return $assessment;
            }

            try {
                $rawKey = ($this->randomBytes)(self::KEY_BYTES);
                $date = ($this->dateProvider)();
            } catch (Throwable) {
                return $this->summary($mode, 'blocked', false, false, null, null, 'key_generation_failed');
            }
            if (!is_string($rawKey) || strlen($rawKey) !== self::KEY_BYTES || !is_string($date) || preg_match('/^\d{8}$/D', $date) !== 1) {
                return $this->summary($mode, 'blocked', false, false, null, null, 'key_generation_failed');
            }

            $fingerprint = hash('sha256', $rawKey);
            $keyId = 'ota-' . $date . '-' . substr($fingerprint, 0, 12);
            $encodedKey = base64_encode($rawKey);
            $updated = $this->withInitializedValues($content, $encodedKey, $keyId);
            if (!$this->updatedContentMatchesRuntime($updated, $encodedKey, $keyId)) {
                return $this->summary($mode, 'blocked', false, false, null, null, 'env_runtime_validation_failed');
            }
            $failure = $this->atomicReplace($envPath, $content, $updated, $existed);
            if ($failure !== null) {
                return $this->summary($mode, 'blocked', false, false, null, null, $failure);
            }

            return $this->summary($mode, 'initialized', true, true, $keyId, $fingerprint, 'credentials_initialized');
        } finally {
            if ($locked) {
                @flock($lockHandle, LOCK_UN);
            }
            fclose($lockHandle);
        }
    }

    private function readExistingFile(string $envPath): ?string
    {
        if (!file_exists($envPath)) {
            return '';
        }
        if (!is_file($envPath)) {
            return null;
        }
        $content = @file_get_contents($envPath);
        return $content === false ? null : $content;
    }

    /**
     * @return array{mode:string,status:string,configured:bool,initialized:bool,key_id:?string,fingerprint:?string,reason_code:string}
     */
    private function assess(string $content, string $mode): array
    {
        if (!is_array(@parse_ini_string($content, true, INI_SCANNER_RAW))) {
            return $this->summary($mode, 'blocked', false, false, null, null, 'env_runtime_validation_failed');
        }

        $assignments = $this->parseAssignments($content);
        $keys = $assignments[self::KEY_VARIABLE];
        $ids = $assignments[self::ID_VARIABLE];

        foreach (array_merge($keys, $ids) as $assignment) {
            if ($assignment['exported'] || !$assignment['canonical'] || $assignment['section'] !== null) {
                return $this->summary($mode, 'blocked', false, false, null, null, 'unsupported_definition');
            }
        }

        if (count($keys) > 1 || count($ids) > 1) {
            return $this->summary($mode, 'blocked', false, false, null, null, 'duplicate_definition');
        }

        $keyDefined = count($keys) === 1;
        $idDefined = count($ids) === 1;
        if ($keyDefined !== $idDefined) {
            return $this->summary($mode, 'blocked', false, false, null, null, 'partial_configuration');
        }

        $key = $keys[0]['value'] ?? '';
        $keyId = $ids[0]['value'] ?? '';
        $hasKey = $key !== '';
        $hasId = $keyId !== '';
        if (!$hasKey && !$hasId) {
            return $this->summary($mode, 'initialization_required', false, false, null, null, 'credentials_missing');
        }
        if ($hasKey !== $hasId) {
            return $this->summary($mode, 'blocked', false, false, null, null, 'partial_configuration');
        }

        $decoded = base64_decode($key, true);
        $validKey = is_string($decoded)
            && strlen($decoded) === self::KEY_BYTES
            && hash_equals(base64_encode($decoded), $key);
        $validId = preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $keyId) === 1;
        if (!$validKey || !$validId) {
            return $this->summary($mode, 'blocked', false, false, null, null, 'malformed_configuration');
        }

        return $this->summary(
            $mode,
            'already_configured',
            true,
            false,
            $keyId,
            hash('sha256', $decoded),
            'credentials_already_configured'
        );
    }

    /**
     * @return array<string, array<int, array{part_index:int,prefix:string,key:string,equals:string,rhs:string,value:string,exported:bool,canonical:bool,section:?string}>>
     */
    private function parseAssignments(string $content): array
    {
        $result = [
            self::KEY_VARIABLE => [],
            self::ID_VARIABLE => [],
        ];
        $parts = preg_split('/(\r\n|\n|\r)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new RuntimeException('Unable to parse environment file.');
        }

        $currentSection = null;
        foreach ($parts as $index => $line) {
            if ($index % 2 !== 0) {
                continue;
            }
            if (preg_match('/^[ \t]*\[([^\]\r\n]+)\][ \t]*(?:[;#].*)?$/D', $line, $sectionMatch) === 1) {
                $currentSection = $sectionMatch[1];
                continue;
            }
            if (preg_match(
                '/^([ \t]*(?:export[ \t]+)?)(OTA_CREDENTIAL_KEY_B64|OTA_CREDENTIAL_KEY_ID)([ \t]*=[ \t]*)(.*)$/Di',
                $line,
                $match
            ) !== 1) {
                continue;
            }
            $normalizedKey = strtoupper($match[2]);
            $result[$normalizedKey][] = [
                'part_index' => $index,
                'prefix' => $match[1],
                'key' => $match[2],
                'equals' => $match[3],
                'rhs' => $match[4],
                'value' => $this->parseValue($match[4]),
                'exported' => preg_match('/^[ \t]*export[ \t]+/i', $match[1]) === 1,
                'canonical' => $match[2] === $normalizedKey,
                'section' => $currentSection,
            ];
        }

        return $result;
    }

    private function parseValue(string $rhs): string
    {
        $parsed = @parse_ini_string('VALUE=' . $rhs, false, INI_SCANNER_RAW);
        if (!is_array($parsed) || !array_key_exists('VALUE', $parsed) || !is_scalar($parsed['VALUE'])) {
            return "\0invalid";
        }
        return (string)$parsed['VALUE'];
    }

    private function withInitializedValues(string $content, string $encodedKey, string $keyId): string
    {
        $parts = preg_split('/(\r\n|\n|\r)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new RuntimeException('Unable to update environment file.');
        }
        $assignments = $this->parseAssignments($content);
        $values = [
            self::KEY_VARIABLE => $encodedKey,
            self::ID_VARIABLE => $keyId,
        ];

        $missing = array_values(array_filter(
            array_keys($values),
            static fn(string $variable): bool => $assignments[$variable] === []
        ));
        if (count($missing) === count($values)) {
            return $this->insertGlobalCredentialBlock($content, $values, $parts);
        }
        if ($missing !== []) {
            throw new RuntimeException('Partial credential definitions cannot be initialized.');
        }

        foreach ($values as $variable => $value) {
            $assignment = $assignments[$variable][0];
            $parts[$assignment['part_index']] = $assignment['prefix']
                . $assignment['key']
                . $assignment['equals']
                . $this->renderValue($assignment['rhs'], $value);
        }

        return implode('', $parts);
    }

    /**
     * @param array<string, string> $values
     * @param array<int, string> $parts
     */
    private function insertGlobalCredentialBlock(string $content, array $values, array $parts): string
    {
        $newline = preg_match('/\r\n|\n|\r/', $content, $match) === 1 ? $match[0] : PHP_EOL;
        $block = '';
        foreach ($values as $variable => $value) {
            $block .= $variable . '=' . $value . $newline;
        }

        if (preg_match(
            '/^[ \t]*\[[^\]\r\n]+\][ \t]*(?:[;#].*)?(?=\r?$)/m',
            $content,
            $sectionMatch,
            PREG_OFFSET_CAPTURE
        ) === 1) {
            $offset = $sectionMatch[0][1];
            return substr($content, 0, $offset) . $block . substr($content, $offset);
        }

        $updated = implode('', $parts);
        if ($updated !== '' && preg_match('/(?:\r\n|\n|\r)$/D', $updated) !== 1) {
            $updated .= $newline;
        }
        return $updated . $block;
    }

    private function updatedContentMatchesRuntime(string $content, string $encodedKey, string $keyId): bool
    {
        $parsed = @parse_ini_string($content, true, INI_SCANNER_RAW);
        return is_array($parsed)
            && array_key_exists(self::KEY_VARIABLE, $parsed)
            && array_key_exists(self::ID_VARIABLE, $parsed)
            && is_string($parsed[self::KEY_VARIABLE])
            && is_string($parsed[self::ID_VARIABLE])
            && hash_equals($encodedKey, $parsed[self::KEY_VARIABLE])
            && hash_equals($keyId, $parsed[self::ID_VARIABLE]);
    }

    private function renderValue(string $originalRhs, string $value): string
    {
        $trimmed = trim($originalRhs);
        if (preg_match('/^".*"\h*$/D', $trimmed) === 1) {
            return '"' . $value . '"';
        }
        return $value;
    }

    private function atomicReplace(string $envPath, string $original, string $updated, bool $existed): ?string
    {
        $temporary = $this->createTemporaryFile($envPath);
        if ($temporary === null) {
            return 'env_temp_create_failed';
        }
        [$tempPath, $handle] = $temporary;
        $renamed = false;

        try {
            if (!$this->secureAndVerifyPermissions($tempPath, 'temp_prewrite')) {
                return 'env_permission_failed';
            }
            if (!$this->writeAll($handle, $updated) || !$this->flushAndSync($handle)) {
                return 'env_write_failed';
            }
            if (!$this->secureAndVerifyPermissions($tempPath, 'temp_prerename')) {
                return 'env_permission_failed';
            }

            fclose($handle);
            $handle = null;
            try {
                $renamed = (bool)($this->renameFile)($tempPath, $envPath);
            } catch (Throwable) {
                $renamed = false;
            }
            if (!$renamed) {
                return 'env_replace_failed';
            }
            if (!$this->secureAndVerifyPermissions($envPath, 'final')) {
                return $this->restoreOriginal($envPath, $original, $existed)
                    ? 'env_permission_failed'
                    : 'env_rollback_failed';
            }

            return null;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function restoreOriginal(string $envPath, string $original, bool $existed): bool
    {
        if (!$existed) {
            $quarantinePath = $this->uniqueTemporaryPath($envPath, 'rollback');
            if ($quarantinePath === null) {
                return false;
            }
            try {
                if (!(bool)($this->renameFile)($envPath, $quarantinePath)) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
            $removed = @unlink($quarantinePath);
            return $removed && !file_exists($envPath) && !file_exists($quarantinePath);
        }

        $temporary = $this->createTemporaryFile($envPath, 'rollback');
        if ($temporary === null) {
            return false;
        }
        [$tempPath, $handle] = $temporary;

        try {
            if (!$this->secureAndVerifyPermissions($tempPath, 'rollback_prewrite')) {
                return false;
            }
            if (!$this->writeAll($handle, $original) || !$this->flushAndSync($handle)) {
                return false;
            }
            if (!$this->secureAndVerifyPermissions($tempPath, 'rollback_prerename')) {
                return false;
            }
            fclose($handle);
            $handle = null;
            try {
                if (!(bool)($this->renameFile)($tempPath, $envPath)) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
            return $this->secureAndVerifyPermissions($envPath, 'rollback_final');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /** @return array{0:string,1:resource}|null */
    private function createTemporaryFile(string $envPath, string $purpose = 'write'): ?array
    {
        $tempPath = $this->uniqueTemporaryPath($envPath, $purpose);
        if ($tempPath === null) {
            return null;
        }
        $handle = @fopen($tempPath, 'x+b');
        return $handle === false ? null : [$tempPath, $handle];
    }

    private function uniqueTemporaryPath(string $envPath, string $purpose): ?string
    {
        $directory = dirname($envPath);
        if (!is_dir($directory)) {
            return null;
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $suffix = bin2hex(random_bytes(12));
            } catch (Throwable) {
                return null;
            }
            $candidate = $envPath . '.ota-key.tmp.' . $purpose . '.' . $suffix;
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @param resource $handle */
    private function flushAndSync($handle): bool
    {
        if (!@fflush($handle)) {
            return false;
        }
        return !function_exists('fsync') || @fsync($handle);
    }

    /** @param resource $handle */
    private function writeAll($handle, string $content): bool
    {
        $length = strlen($content);
        $offset = 0;
        while ($offset < $length) {
            try {
                $written = ($this->writeChunk)($handle, substr($content, $offset));
            } catch (Throwable) {
                return false;
            }
            if (!is_int($written) || $written <= 0 || $written > ($length - $offset)) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    private function secureAndVerifyPermissions(string $path, string $stage): bool
    {
        $secured = PHP_OS_FAMILY === 'Windows'
            ? $this->secureWindowsAcl($path)
            : $this->securePosixMode($path);
        if (!$secured) {
            return false;
        }
        if ($this->permissionGate === null) {
            return true;
        }
        try {
            return ($this->permissionGate)($path, $stage) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function securePosixMode(string $path): bool
    {
        if (!@chmod($path, 0600)) {
            return false;
        }
        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        return is_int($permissions) && ($permissions & 0777) === 0600;
    }

    private function secureWindowsAcl(string $path): bool
    {
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
function Finish([string]$status, [int]$code) {
    [Console]::Out.Write($status)
    exit $code
}
function IsStrictAcl(
    [System.Security.AccessControl.FileSecurity]$acl,
    [System.Security.Principal.SecurityIdentifier]$owner,
    [array]$expected
) {
    if (-not $acl.AreAccessRulesProtected) { return $false }
    if ($acl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value -ne $owner.Value) { return $false }
    $seen = @{}
    foreach ($sid in $expected) { $seen[$sid.Value] = 0 }
    $rules = @($acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]))
    if ($rules.Count -ne 3) { return $false }
    foreach ($rule in $rules) {
        $sidValue = $rule.IdentityReference.Value
        if (-not $seen.ContainsKey($sidValue) -or $seen[$sidValue] -ne 0) { return $false }
        if ($rule.IsInherited) { return $false }
        if ($rule.AccessControlType -ne [System.Security.AccessControl.AccessControlType]::Allow) { return $false }
        if ($rule.InheritanceFlags -ne [System.Security.AccessControl.InheritanceFlags]::None) { return $false }
        if ($rule.PropagationFlags -ne [System.Security.AccessControl.PropagationFlags]::None) { return $false }
        if ($rule.FileSystemRights -ne [System.Security.AccessControl.FileSystemRights]::FullControl) { return $false }
        $seen[$sidValue] = 1
    }
    return @($seen.Values | Where-Object { $_ -ne 1 }).Count -eq 0
}
try {
    $target = [Environment]::GetEnvironmentVariable('SUXI_OTA_ACL_TARGET', 'Process')
    if ([string]::IsNullOrWhiteSpace($target) -or -not [IO.Path]::IsPathRooted($target)) { Finish 'acl_target_invalid' 10 }
    if (-not [IO.File]::Exists($target)) { Finish 'acl_target_missing' 11 }
    if (([IO.File]::GetAttributes($target) -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Finish 'acl_target_unsafe' 12 }
    $current = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
    if ($null -eq $current) { Finish 'acl_identity_unavailable' 13 }
    $system = New-Object System.Security.Principal.SecurityIdentifier('S-1-5-18')
    $administrators = New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-544')
    $expected = @($current, $system, $administrators)
    $security = New-Object System.Security.AccessControl.FileSecurity
    $security.SetOwner($current)
    $security.SetAccessRuleProtection($true, $false)
    foreach ($sid in $expected) {
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
            $sid,
            [System.Security.AccessControl.FileSystemRights]::FullControl,
            [System.Security.AccessControl.AccessControlType]::Allow
        )
        [void]$security.AddAccessRule($rule)
    }
    [IO.File]::SetAccessControl($target, $security)
    $sections = [System.Security.AccessControl.AccessControlSections]::Access -bor [System.Security.AccessControl.AccessControlSections]::Owner
    $actual = [IO.File]::GetAccessControl($target, $sections)
    if (-not (IsStrictAcl $actual $current $expected)) { Finish 'acl_verify_failed' 14 }
    Finish 'acl_verified' 0
} catch {
    Finish 'acl_apply_failed' 15
}
POWERSHELL;

        $systemRoot = getenv('SystemRoot') ?: getenv('WINDIR');
        if (!is_string($systemRoot) || $systemRoot === '' || !function_exists('iconv')) {
            return false;
        }
        $powershell = $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($powershell)) {
            return false;
        }
        $utf16Script = @iconv('UTF-8', 'UTF-16LE', $script);
        if (!is_string($utf16Script)) {
            return false;
        }
        $encodedScript = base64_encode($utf16Script);
        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', 'NUL', 'a'],
        ];
        $pipes = [];
        try {
            $process = @proc_open(
                [
                    $powershell,
                    '-NoLogo',
                    '-NoProfile',
                    '-NonInteractive',
                    '-EncodedCommand',
                    $encodedScript,
                ],
                $descriptors,
                $pipes,
                null,
                [
                    'SystemRoot' => $systemRoot,
                    'WINDIR' => $systemRoot,
                    'TEMP' => sys_get_temp_dir(),
                    'TMP' => sys_get_temp_dir(),
                    'SUXI_OTA_ACL_TARGET' => $path,
                ],
                ['bypass_shell' => true, 'suppress_errors' => true]
            );
        } catch (Throwable) {
            return false;
        }
        if (!is_resource($process)) {
            return false;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        return $exitCode === 0 && $output === 'acl_verified';
    }

    /**
     * @return array{mode:string,status:string,configured:bool,initialized:bool,key_id:?string,fingerprint:?string,reason_code:string}
     */
    private function summary(
        string $mode,
        string $status,
        bool $configured,
        bool $initialized,
        ?string $keyId,
        ?string $fingerprint,
        string $reasonCode
    ): array {
        return [
            'mode' => $mode,
            'status' => $status,
            'configured' => $configured,
            'initialized' => $initialized,
            'key_id' => $keyId,
            'fingerprint' => $fingerprint,
            'reason_code' => $reasonCode,
        ];
    }
}
