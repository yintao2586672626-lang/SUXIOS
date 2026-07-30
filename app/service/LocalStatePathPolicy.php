<?php
declare(strict_types=1);

namespace app\service;

final class LocalStatePathPolicy
{
    public const SINGLE_INSTANCE_MODE = 'single_instance';

    /**
     * @param array<string, scalar|null>|null $environment
     * @return array{
     *     deployment_mode: string,
     *     cache_path: string,
     *     lock_path: string,
     *     persistent_paths_required: bool
     * }
     */
    public static function resolve(?array $environment = null): array
    {
        $mode = strtolower(trim(self::readEnvironment(
            'SUXIOS_DEPLOYMENT_MODE',
            self::SINGLE_INSTANCE_MODE,
            $environment
        )));
        if ($mode === '') {
            $mode = self::SINGLE_INSTANCE_MODE;
        }
        if ($mode !== self::SINGLE_INSTANCE_MODE) {
            throw new \RuntimeException(
                'SUXIOS_DEPLOYMENT_MODE only supports single_instance until shared cache and distributed locks are enabled.'
            );
        }

        $cachePath = self::normalizeAbsoluteDirectory(
            self::readEnvironment('SUXIOS_CACHE_PATH', '', $environment),
            'SUXIOS_CACHE_PATH'
        );
        $lockPath = self::normalizeAbsoluteDirectory(
            self::readEnvironment('SUXIOS_LOCAL_LOCK_PATH', '', $environment),
            'SUXIOS_LOCAL_LOCK_PATH'
        );
        $persistentPathsRequired = self::readBooleanEnvironment(
            'SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE',
            false,
            $environment
        );

        if ($persistentPathsRequired && ($cachePath === '' || $lockPath === '')) {
            throw new \RuntimeException(
                'Persistent local state requires absolute SUXIOS_CACHE_PATH and SUXIOS_LOCAL_LOCK_PATH values.'
            );
        }

        return [
            'deployment_mode' => $mode,
            'cache_path' => $cachePath,
            'lock_path' => $lockPath,
            'persistent_paths_required' => $persistentPathsRequired,
        ];
    }

    /**
     * Returns an empty string when the current local-development configuration
     * intentionally keeps the historical runtime-directory fallback.
     *
     * @param array<string, scalar|null>|null $environment
     */
    public static function scopedLockDirectory(string $scope, ?array $environment = null): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $scope) !== 1) {
            throw new \InvalidArgumentException('Local state lock scope is invalid.');
        }

        $basePath = self::resolve($environment)['lock_path'];
        if ($basePath === '') {
            return '';
        }

        return $basePath . DIRECTORY_SEPARATOR . $scope;
    }

    /**
     * @param array<string, scalar|null>|null $environment
     */
    private static function readEnvironment(string $name, string $default, ?array $environment): string
    {
        if ($environment !== null && array_key_exists($name, $environment)) {
            return trim((string)($environment[$name] ?? ''));
        }

        $systemValue = getenv($name);
        if (is_string($systemValue) && trim($systemValue) !== '') {
            return trim($systemValue);
        }

        if (function_exists('env')) {
            return trim((string)env($name, $default));
        }

        return $default;
    }

    /**
     * @param array<string, scalar|null>|null $environment
     */
    private static function readBooleanEnvironment(string $name, bool $default, ?array $environment): bool
    {
        $raw = self::readEnvironment($name, $default ? 'true' : 'false', $environment);
        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new \RuntimeException($name . ' must be true or false.');
        }

        return $value;
    }

    private static function normalizeAbsoluteDirectory(string $path, string $environmentName): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (str_contains($path, "\0")) {
            throw new \RuntimeException($environmentName . ' contains an invalid null byte.');
        }

        $isUnixAbsolute = str_starts_with($path, '/');
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $isUncAbsolute = str_starts_with($path, '\\\\');
        if (!$isUnixAbsolute && !$isWindowsAbsolute && !$isUncAbsolute) {
            throw new \RuntimeException($environmentName . ' must be an absolute path outside the release directory.');
        }

        $normalized = rtrim($path, '\\/');
        if ($normalized === '' || preg_match('/^[A-Za-z]:$/', $normalized) === 1) {
            throw new \RuntimeException($environmentName . ' must not target a filesystem root.');
        }

        return $normalized;
    }
}
