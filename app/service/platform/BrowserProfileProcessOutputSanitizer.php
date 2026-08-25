<?php
declare(strict_types=1);

namespace app\service\platform;

/**
 * Keeps browser-process diagnostics useful without allowing credentials from a
 * child process to flow into sync-task payloads, cache state, or scheduler logs.
 */
final class BrowserProfileProcessOutputSanitizer
{
    private const REDACTED_LOG_LINE = '[redacted_sensitive_process_output]';
    private const REDACTED_SUMMARY = 'browser_profile_process_error_redacted';
    private const SENSITIVE_KEY = '(?:'
        . 'set[_-]?cookies?|cookies?(?:[_-]?(?:file|jar|store))?|'
        . 'proxy[_-]?authorization|authorization|'
        . 'x[_-]?api[_-]?key|api[_-]?key|access[_-]?token|refresh[_-]?token|'
        . 'spidertoken|spiderkey|usertoken|usersign|mtgsig|token|'
        . 'password|passwd|secret|session(?:id)?|sid|signature|ticket'
        . ')';

    public static function sanitizeLog(string $value, int $limit = 4000): string
    {
        $value = self::stripAnsi($value);
        if ($value === '') {
            return '';
        }

        $safeLines = [];
        foreach (preg_split('/\R+/u', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $safeLine = self::lineContainsSensitiveMaterial($line)
                ? self::REDACTED_LOG_LINE
                : $line;
            if ($safeLines === [] || end($safeLines) !== $safeLine) {
                $safeLines[] = $safeLine;
            }
        }

        $result = implode("\n", $safeLines);
        $limit = max(1, $limit);
        return mb_strlen($result) > $limit ? mb_substr($result, -$limit) : $result;
    }

    public static function sanitizeMessage(string $value, int $limit = 240): string
    {
        $value = self::stripAnsi($value);
        if ($value === '') {
            return '';
        }
        if (self::containsSensitiveMaterial($value)) {
            return self::REDACTED_SUMMARY;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (!is_string($value)) {
            return self::REDACTED_SUMMARY;
        }
        $limit = max(1, $limit);
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    public static function summarize(string $stderr, string $stdout): string
    {
        $text = trim($stderr) !== '' ? $stderr : $stdout;
        $text = self::stripAnsi($text);
        if ($text === '') {
            return '';
        }
        if (stripos($text, 'spawn EPERM') !== false) {
            return 'browser_runtime_error=spawn EPERM; check browser executable permission and scheduled-task runtime account.';
        }
        if (stripos($text, 'spawn EACCES') !== false) {
            return 'browser_runtime_error=spawn EACCES; check browser executable permission and scheduled-task runtime account.';
        }
        if (self::containsSensitiveMaterial($text)) {
            return self::REDACTED_SUMMARY;
        }

        $lines = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R+/u', $text) ?: []
        )));
        foreach ($lines as $line) {
            if (
                stripos($line, 'Error') !== false
                || stripos($line, 'Exception') !== false
                || stripos($line, 'failed') !== false
            ) {
                return mb_substr($line, 0, 240);
            }
        }

        return $lines === [] ? '' : mb_substr((string)end($lines), 0, 240);
    }

    /**
     * Shared fail-closed detector for process output and collector payload text.
     */
    public static function containsSensitiveMaterial(string $value): bool
    {
        foreach (preg_split('/\R+/u', $value) ?: [$value] as $line) {
            if (self::lineContainsSensitiveMaterial((string)$line)) {
                return true;
            }
        }
        return false;
    }

    private static function lineContainsSensitiveMaterial(string $line): bool
    {
        $detectionLine = self::normalizeDetectionView($line);
        $patterns = [
            '/\bbearer\s+[A-Za-z0-9._~+\/=:-]{4,}/iu',
            '/\bhttps?:\/\/[^\s\/@]+@/iu',
            '/(?<![A-Za-z0-9])["\']?(?:--)?' . self::SENSITIVE_KEY . '["\']?(?![A-Za-z0-9])\s*(?:=|:)\s*["\']?[^\s,;]+/iu',
            '/(?<![A-Za-z0-9])(?:--)' . self::SENSITIVE_KEY . '(?![A-Za-z0-9])\s+["\']?[^\s,;]+/iu',
        ];
        foreach ($patterns as $pattern) {
            $matched = preg_match($pattern, $detectionLine);
            if ($matched === false || $matched === 1) {
                return true;
            }
        }
        return false;
    }

    private static function normalizeDetectionView(string $line): string
    {
        $detectionLine = $line;
        do {
            $previous = $detectionLine;
            $decoded = preg_replace_callback(
                '/[\\\\]+u([0-9a-f]{4})/iu',
                static function (array $matches): string {
                    $codePoint = hexdec((string)($matches[1] ?? ''));
                    return $codePoint >= 0x20 && $codePoint <= 0x7e
                        ? chr($codePoint)
                        : (string)($matches[0] ?? '');
                },
                $detectionLine
            );
            if (!is_string($decoded)) {
                return $line;
            }
            $collapsed = preg_replace('/[\\\\]+(?=["\/])/', '', $decoded);
            if (!is_string($collapsed)) {
                return $line;
            }
            $detectionLine = $collapsed;
        } while ($detectionLine !== $previous);

        return $detectionLine;
    }

    private static function stripAnsi(string $value): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $clean = preg_replace('/\e\[[\d;]*m/u', '', $value);
        return is_string($clean) ? trim($clean) : '';
    }
}
