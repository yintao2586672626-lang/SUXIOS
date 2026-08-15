<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use RuntimeException;

/**
 * Server-side bridge to the loopback-only browser gateway. Raw login tickets
 * and the gateway control token never leave this process.
 */
final class CloudBrowserLoginGatewayService
{
    private const GATEWAY_URL = 'http://127.0.0.1:8787';
    private const CONTROL_TOKEN_FILE = '/etc/suxios-cloud-browser/control-token';
    private const VIEWER_URL = '/cloud-browser-viewer/vnc.html?autoconnect=true&resize=scale&path=cloud-browser-viewer%2Fwebsockify';

    private ?Closure $gatewayRequester;
    private ?Closure $controlTokenLoader;

    public function __construct(
        private readonly ?CloudBrowserProfileService $profiles = null,
        private readonly ?CloudBrowserViewerAuthorizationService $viewerAuthorizations = null,
        ?callable $gatewayRequester = null,
        ?callable $controlTokenLoader = null
    ) {
        $this->gatewayRequester = $gatewayRequester === null ? null : Closure::fromCallable($gatewayRequester);
        $this->controlTokenLoader = $controlTokenLoader === null ? null : Closure::fromCallable($controlTokenLoader);
    }

    /** @return array<string,mixed> */
    public function open(int $tenantId, int $hotelId, int $ownerUserId, string $platform): array
    {
        $platform = $this->platform($platform);
        $profiles = $this->profiles ?? new CloudBrowserProfileService();
        $authorizations = $this->viewerAuthorizations ?? new CloudBrowserViewerAuthorizationService();
        $entry = $profiles
            ->requestLoginEntry($hotelId, $ownerUserId, $platform);
        $profile = is_array($entry['profile'] ?? null) ? $entry['profile'] : [];
        $login = is_array($entry['login_entry'] ?? null) ? $entry['login_entry'] : [];
        $profileId = trim((string)($profile['profile_id'] ?? ''));
        $sessionId = trim((string)($login['session_id'] ?? ''));
        $ticket = trim((string)($login['ticket'] ?? ''));
        $expiresAt = trim((string)($login['expires_at'] ?? ''));
        $viewer = null;
        $gatewayOpenAttempted = false;
        try {
            if ((int)($profile['tenant_id'] ?? $tenantId) !== $tenantId
                || (int)($profile['hotel_id'] ?? $hotelId) !== $hotelId
                || (string)($profile['platform'] ?? '') !== $platform
                || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
                || preg_match('/^cbls_[A-Za-z0-9_-]{16,64}$/D', $sessionId) !== 1
                || preg_match('/^[A-Za-z0-9_-]{32,96}$/D', $ticket) !== 1
            ) {
                throw new RuntimeException('cloud_browser_login_entry_scope_invalid');
            }
            $viewer = $authorizations->issue([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'owner_user_id' => $ownerUserId,
                'platform' => $platform,
                'profile_id' => $profileId,
                'session_id' => $sessionId,
            ], $expiresAt);
            $gatewayOpenAttempted = true;
            $gateway = $this->request('/v1/login/open', [
                'profile_id' => $profileId,
                'session_id' => $sessionId,
                'ticket' => $ticket,
                'platform' => $platform,
            ], null);
            $body = $gateway['body'];
            if ($gateway['status'] !== 201
                || (string)($body['status'] ?? '') !== CloudBrowserProfileService::AWAITING_LOGIN
                || (string)($body['profile_id'] ?? '') !== $profileId
                || (string)($body['session_id'] ?? '') !== $sessionId
                || ($body['browser_started'] ?? false) !== true
            ) {
                $reason = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($body['reason'] ?? ''));
                throw new RuntimeException($reason !== '' ? $reason : 'cloud_browser_gateway_open_failed');
            }
        } catch (\Throwable $error) {
            if (is_array($viewer) && is_string($viewer['token'] ?? null)) {
                $authorizations->revoke($viewer['token']);
            }
            if ($gatewayOpenAttempted) {
                try {
                    $this->cleanupGatewayLogin($profileId, $sessionId, $platform);
                } catch (\Throwable $cleanupError) {
                    throw new RuntimeException('cloud_browser_login_gateway_cleanup_unverified', 0, $error);
                }
            }
            try {
                $profiles->cancelLoginEntry($profileId, $sessionId, 'gateway_open_failed');
            } catch (\Throwable $cleanupError) {
                throw new RuntimeException('cloud_browser_login_open_cleanup_failed', 0, $error);
            }
            throw $error;
        } finally {
            $ticket = str_repeat("\0", strlen($ticket));
        }

        return [
            'status' => CloudBrowserProfileService::AWAITING_LOGIN,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'profile_id' => $profileId,
            'session_id' => $sessionId,
            'expires_at' => (string)$viewer['expires_at'],
            'viewer_url' => self::VIEWER_URL,
            'browser_started' => true,
            'profile_encrypted_at_rest' => true,
            'credentials_stored_by_suxios' => false,
            '_viewer_token' => $viewer['token'],
        ];
    }

    /** @return array<string,mixed> */
    public function complete(
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $platform,
        string $profileId,
        string $sessionId,
        string $viewerToken
    ): array {
        $scope = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'owner_user_id' => $ownerUserId,
            'platform' => $this->platform($platform),
            'profile_id' => trim($profileId),
            'session_id' => trim($sessionId),
        ];
        $authorizations = $this->viewerAuthorizations ?? new CloudBrowserViewerAuthorizationService();
        $authorizations->authorize($viewerToken, $scope);

        $controlToken = $this->loadControlToken();
        try {
            $gateway = $this->request('/v1/login/complete', [
                'profile_id' => $scope['profile_id'],
                'session_id' => $scope['session_id'],
                'platform' => $scope['platform'],
            ], $controlToken);
            $body = $gateway['body'];
            if ($gateway['status'] !== 200
                || (string)($body['status'] ?? '') !== CloudBrowserProfileService::READY_TO_COLLECT
                || (string)($body['profile_id'] ?? '') !== $scope['profile_id']
                || ($body['browser_started'] ?? true) !== false
            ) {
                $reason = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($body['reason'] ?? ''));
                throw new RuntimeException($reason !== '' ? $reason : 'cloud_browser_gateway_complete_failed');
            }
        } catch (\Throwable $error) {
            try {
                $this->cleanupGatewayLogin(
                    $scope['profile_id'],
                    $scope['session_id'],
                    $scope['platform']
                );
                $reconciled = ($this->profiles ?? new CloudBrowserProfileService())
                    ->reconcileLoginCompletionFailure(
                        $scope['profile_id'],
                        $scope['session_id'],
                        'gateway_complete_failed'
                    );
            } catch (\Throwable $cleanupError) {
                throw new RuntimeException('cloud_browser_login_complete_cleanup_unverified', 0, $error);
            }
            $authorizations->revoke($viewerToken);
            if (($reconciled['outcome'] ?? '') === 'committed') {
                throw new RuntimeException(
                    'cloud_browser_login_complete_response_lost_profile_ready',
                    0,
                    $error
                );
            }
            throw $error;
        } finally {
            $controlToken = str_repeat("\0", strlen($controlToken));
        }

        $authorizations->revoke($viewerToken);
        return [
            'status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'hotel_id' => $hotelId,
            'platform' => $scope['platform'],
            'profile_id' => $scope['profile_id'],
            'session_id' => $scope['session_id'],
            'receipt_id' => (string)($body['receipt_id'] ?? ''),
            'browser_started' => false,
            'profile_encrypted_at_rest' => true,
            'credentials_stored_by_suxios' => false,
        ];
    }

    private function cleanupGatewayLogin(string $profileId, string $sessionId, string $platform): void
    {
        $controlToken = $this->loadControlToken();
        try {
            $cleanup = $this->request('/v1/login/cancel', [
                'profile_id' => $profileId,
                'session_id' => $sessionId,
                'platform' => $platform,
            ], $controlToken);
            $body = $cleanup['body'];
            if ($cleanup['status'] !== 200
                || ($body['cleanup_verified'] ?? false) !== true
                || !in_array((string)($body['status'] ?? ''), ['cancelled', 'no_active_login'], true)
                || (string)($body['profile_id'] ?? $profileId) !== $profileId
                || (string)($body['session_id'] ?? $sessionId) !== $sessionId
                || (string)($body['platform'] ?? $platform) !== $platform
            ) {
                throw new RuntimeException('cloud_browser_login_gateway_cleanup_unverified');
            }
        } finally {
            $controlToken = str_repeat("\0", strlen($controlToken));
        }
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function request(string $path, array $body, ?string $controlToken): array
    {
        if ($this->gatewayRequester !== null) {
            $result = ($this->gatewayRequester)($path, $body, $controlToken);
            if (is_array($result) && isset($result['status']) && is_array($result['body'] ?? null)) {
                return ['status' => (int)$result['status'], 'body' => $result['body']];
            }
            throw new RuntimeException('cloud_browser_gateway_response_invalid');
        }

        $headers = "Content-Type: application/json\r\n";
        if ($controlToken !== null) {
            $headers .= "Authorization: Bearer {$controlToken}\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents(self::GATEWAY_URL . $path, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string)$header, $match) === 1) {
                $status = (int)$match[1];
                break;
            }
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException('cloud_browser_gateway_unavailable');
        }
        return ['status' => $status, 'body' => $decoded];
    }

    private function loadControlToken(): string
    {
        if ($this->controlTokenLoader !== null) {
            $token = (string)($this->controlTokenLoader)();
        } else {
            $raw = @file_get_contents(self::CONTROL_TOKEN_FILE);
            $token = is_string($raw) ? trim($raw) : '';
        }
        if (strlen($token) < 32 || preg_match('/[\r\n]/', $token) === 1) {
            throw new RuntimeException('cloud_browser_control_token_unavailable');
        }
        return $token;
    }

    private function platform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan', 'dingdandao', 'meituan_cloud_pms'], true)) {
            throw new RuntimeException('cloud_browser_platform_invalid');
        }
        return $platform;
    }
}
