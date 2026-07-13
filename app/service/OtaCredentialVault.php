<?php
declare(strict_types=1);
namespace app\service;
use app\model\Hotel;
use app\model\OtaCredential;
use RuntimeException;

final class OtaCredentialVault
{
    private OtaCredentialEnvelope $envelope;
    private string $keyId;

    public function __construct(?OtaCredentialEnvelope $envelope = null, ?string $keyId = null)
    {
        $this->envelope = $envelope ?? new OtaCredentialEnvelope((string) env('OTA_CREDENTIAL_KEY_B64', ''), (string) env('OTA_CREDENTIAL_KEY_ID', ''));
        $this->keyId = $keyId ?? $this->envelope->keyId();
        if (trim($this->keyId) === '' || !hash_equals($this->envelope->keyId(), $this->keyId)) {
            throw new RuntimeException('OTA credential key identifier does not match envelope.');
        }
    }

    private function scope(int $t, int $h, string $p, string $c): string
    {
        $this->validate($p, $c);
        return "tenant:$t:hotel:$h:$p:$c";
    }

    private function validate(string $p, string $c): void
    {
        if (!in_array($p, ['ctrip', 'meituan'], true) || preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $c) !== 1) {
            throw new RuntimeException('Invalid credential locator.');
        }
    }

    private function locate(int $t, int $h, string $p, string $c, bool $allowRevoked = false, bool $lock = false): OtaCredential
    {
        $this->scope($t, $h, $p, $c);
        if (!Hotel::where('id', $h)->where('tenant_id', $t)->find()) {
            throw new RuntimeException('Hotel scope not found.');
        }
        $query = OtaCredential::where('tenant_id', $t)->where('system_hotel_id', $h)->where('platform', $p)->where('config_id', $c);
        $r = $query->lock($lock)->find();
        if (!$r) {
            throw new RuntimeException('Credential not found.');
        }
        if (!$allowRevoked && (string) $r->credential_status !== 'ready') {
            throw new RuntimeException('Credential is not ready for execution.');
        }
        return $r;
    }
    public function store(int $tenantId,int $hotelId,string $platform,string $configId,array $payload,int $actorId): array
    {
        $scope = $this->scope($tenantId, $hotelId, $platform, $configId);
        if (!Hotel::where('id', $hotelId)->where('tenant_id', $tenantId)->find()) {
            throw new RuntimeException('Hotel scope not found.');
        }
        $now = date('Y-m-d H:i:s');
        $mask = '';
        foreach (['cookies', 'cookie', 'token', 'spidertoken', 'mtgsig'] as $key) {
            if (!empty($payload[$key])) {
                $value = $payload[$key];
                $secret = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $length = strlen((string) $secret);
                $mask = $length <= 8 ? str_repeat('*', $length ?: 1) : substr((string) $secret, 0, 2) . '****' . substr((string) $secret, -2);
                break;
            }
        }
        $data = [
            'tenant_id' => $tenantId, 'system_hotel_id' => $hotelId, 'platform' => $platform, 'config_id' => $configId,
            'encrypted_payload' => $this->envelope->encrypt($payload, $scope), 'payload_version' => 1,
            'key_id' => $this->keyId, 'secret_mask' => $mask,
            'credential_status' => 'ready', 'created_by' => $actorId, 'rotated_at' => $now, 'update_time' => $now,
        ];
        $r = \think\facade\Db::transaction(function () use ($data, $tenantId, $hotelId, $platform, $configId, $now): OtaCredential {
            $record = OtaCredential::where('tenant_id', $tenantId)->where('system_hotel_id', $hotelId)->where('platform', $platform)->where('config_id', $configId)->lock(true)->find();
            if ($record) { $record->save($data); return $record; }
            $data['create_time'] = $now;
            return OtaCredential::create($data);
        });
        return $this->meta($r);
    }
    private function meta(OtaCredential $r): array
    {
        return [
            'credential_ref' => (int) $r->id,
            'tenant_id' => (int) $r->tenant_id,
            'system_hotel_id' => (int) $r->system_hotel_id,
            'platform' => (string) $r->platform,
            'config_id' => (string) $r->config_id,
            'payload_version' => (int) $r->payload_version,
            'key_id' => (string) $r->key_id,
            'secret_mask' => (string) $r->secret_mask,
            'credential_status' => (string) $r->credential_status,
            'rotated_at' => $r->rotated_at,
            'create_time' => $r->create_time,
            'update_time' => $r->update_time,
        ];
    }

    public function metadata(int $t, int $h, string $p, string $c): array
    {
        return $this->meta($this->locate($t, $h, $p, $c, true));
    }

    public function withPayloadForExecution(int $t, int $h, string $p, string $c, callable $consumer): mixed
    {
        $r = $this->locate($t, $h, $p, $c);
        return $consumer($this->payloadForExecution($r, $this->scope($t, $h, $p, $c)));
    }

    public function verifiedMetadataForExecution(int $t, int $h, string $p, string $c): array
    {
        $metadata = $this->metadata($t, $h, $p, $c);
        $this->withPayloadForExecution($t, $h, $p, $c, static fn(array $_payload): null => null);
        return $metadata;
    }

    /** @return array<string|int, mixed> */
    private function payloadForExecution(OtaCredential $record, string $scope): array
    {
        if ((int)$record->payload_version !== 1
            || !hash_equals($this->keyId, (string)$record->key_id)
        ) {
            throw new RuntimeException('Credential cryptographic metadata is not executable.');
        }
        return $this->envelope->decrypt((string)$record->encrypted_payload, $scope);
    }

    public function revoke(int $t, int $h, string $p, string $c): array
    {
        return \think\facade\Db::transaction(function () use ($t, $h, $p, $c): array {
            $r = $this->locate($t, $h, $p, $c, true, true);
            if ((string) $r->credential_status !== 'revoked') { $r->credential_status = 'revoked'; $r->save(); }
            return $this->meta($r);
        });
    }

    public function delete(int $t, int $h, string $p, string $c): bool
    {
        return \think\facade\Db::transaction(function () use ($t, $h, $p, $c): bool {
            $this->scope($t, $h, $p, $c);
            if (!Hotel::where('id', $h)->where('tenant_id', $t)->find()) { throw new RuntimeException('Hotel scope not found.'); }
            $r = OtaCredential::where('tenant_id', $t)->where('system_hotel_id', $h)->where('platform', $p)->where('config_id', $c)->lock(true)->find();
            return $r ? (bool) $r->delete() : false;
        });
    }
}



