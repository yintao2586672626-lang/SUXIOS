<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use Throwable;

final class OutboundUrlGuard
{
    public const ERROR_HTTPS_REQUIRED = 'Outbound URL must use HTTPS.';
    public const ERROR_CREDENTIALS_NOT_ALLOWED = 'Outbound URL credentials are not allowed.';
    public const ERROR_PORT_NOT_ALLOWED = 'Outbound URL port is not allowed.';
    public const ERROR_HOST_NOT_ALLOWED = 'Outbound URL host is not allowed.';

    /** @var null|callable(string):array<int,string> */
    private $resolver;

    /**
     * @param null|callable(string):array<int,string> $resolver
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * @return array{
     *     url:string,
     *     host:string,
     *     port:int,
     *     addresses:array<int,string>,
     *     curl_resolve:array<int,string>
     * }
     */
    public function validate(string $url): array
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException(self::ERROR_HOST_NOT_ALLOWED);
        }

        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            $parts = false;
        }
        if (!is_array($parts)) {
            throw new InvalidArgumentException(self::ERROR_HOST_NOT_ALLOWED);
        }

        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidArgumentException(self::ERROR_HTTPS_REQUIRED);
        }
        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            throw new InvalidArgumentException(self::ERROR_CREDENTIALS_NOT_ALLOWED);
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($port !== 443) {
            throw new InvalidArgumentException(self::ERROR_PORT_NOT_ALLOWED);
        }

        $host = $this->normalizeHost((string)($parts['host'] ?? ''));
        if ($host === '' || $this->isLocalHostname($host)) {
            throw new InvalidArgumentException(self::ERROR_HOST_NOT_ALLOWED);
        }

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isIpLiteral && !$this->isValidDnsHostname($host)) {
            throw new InvalidArgumentException(self::ERROR_HOST_NOT_ALLOWED);
        }

        $addresses = $isIpLiteral ? [$host] : $this->resolveAllAddresses($host);
        if ($addresses === []) {
            throw new InvalidArgumentException(self::ERROR_HOST_NOT_ALLOWED);
        }

        $validated = [];
        foreach ($addresses as $address) {
            $address = $this->normalizeIp((string)$address);
            if (!$this->isPublicIpAddress($address)) {
                throw new InvalidArgumentException(self::ERROR_HOST_NOT_ALLOWED);
            }
            $validated[$address] = true;
        }
        $addresses = array_keys($validated);

        return [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'addresses' => $addresses,
            'curl_resolve' => $isIpLiteral ? [] : [$this->curlResolveEntry($host, $port, $addresses)],
        ];
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || str_ends_with($host, '.')) {
            return '';
        }
        return $host;
    }

    private function normalizeIp(string $address): string
    {
        $address = strtolower(trim($address));
        if (str_starts_with($address, '[') && str_ends_with($address, ']')) {
            $address = substr($address, 1, -1);
        }
        return $address;
    }

    private function isLocalHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === 'localhost.localdomain'
            || str_ends_with($host, '.localhost.localdomain');
    }

    private function isValidDnsHostname(string $host): bool
    {
        if (preg_match('/^(?:0x[0-9a-f]+|[0-9.]+)$/iD', $host) === 1) {
            return false;
        }
        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** @return array<int,string> */
    private function resolveAllAddresses(string $host): array
    {
        try {
            if ($this->resolver !== null) {
                $addresses = ($this->resolver)($host);
                return is_array($addresses) ? array_values($addresses) : [];
            }

            if (!function_exists('dns_get_record') || !defined('DNS_A') || !defined('DNS_AAAA')) {
                return [];
            }

            $ipv4Records = @dns_get_record($host, DNS_A);
            $ipv6Records = @dns_get_record($host, DNS_AAAA);
            if (!is_array($ipv4Records) || !is_array($ipv6Records)) {
                return [];
            }

            $addresses = [];
            foreach ($ipv4Records as $record) {
                if (is_array($record) && isset($record['ip'])) {
                    $addresses[] = (string)$record['ip'];
                }
            }
            foreach ($ipv6Records as $record) {
                if (is_array($record) && isset($record['ipv6'])) {
                    $addresses[] = (string)$record['ipv6'];
                }
            }
            return $addresses;
        } catch (Throwable) {
            return [];
        }
    }

    private function isPublicIpAddress(string $address): bool
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }

        $binary = @inet_pton($address);
        if (!is_string($binary)) {
            return false;
        }

        if (strlen($binary) === 4) {
            return !$this->matchesCidr($binary, '100.64.0.0', 10)
                && !$this->matchesCidr($binary, '192.0.0.0', 24)
                && !$this->matchesCidr($binary, '192.0.2.0', 24)
                && !$this->matchesCidr($binary, '192.88.99.0', 24)
                && !$this->matchesCidr($binary, '198.18.0.0', 15)
                && !$this->matchesCidr($binary, '198.51.100.0', 24)
                && !$this->matchesCidr($binary, '203.0.113.0', 24);
        }

        if (strlen($binary) !== 16 || !$this->matchesCidr($binary, '2000::', 3)) {
            return false;
        }

        return !$this->matchesCidr($binary, '2001::', 32)
            && !$this->matchesCidr($binary, '2001:2::', 48)
            && !$this->matchesCidr($binary, '2001:10::', 28)
            && !$this->matchesCidr($binary, '2001:20::', 28)
            && !$this->matchesCidr($binary, '2001:db8::', 32)
            && !$this->matchesCidr($binary, '2002::', 16);
    }

    private function matchesCidr(string $address, string $network, int $prefixLength): bool
    {
        $networkBinary = @inet_pton($network);
        if (!is_string($networkBinary) || strlen($networkBinary) !== strlen($address)) {
            return false;
        }

        $wholeBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($networkBinary, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($address[$wholeBytes]) & $mask) === (ord($networkBinary[$wholeBytes]) & $mask);
    }

    /** @param array<int,string> $addresses */
    private function curlResolveEntry(string $host, int $port, array $addresses): string
    {
        $pinnedAddress = (string)$addresses[0];
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $pinnedAddress = $address;
                break;
            }
        }
        if (str_contains($pinnedAddress, ':')) {
            $pinnedAddress = '[' . $pinnedAddress . ']';
        }
        return $host . ':' . $port . ':' . $pinnedAddress;
    }
}
