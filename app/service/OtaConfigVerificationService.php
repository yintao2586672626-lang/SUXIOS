<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class OtaConfigVerificationService
{
    private const TIMEZONE = 'Asia/Shanghai';

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly ?OtaProfileSessionProofService $proofService = null)
    {
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function statusForConfig(array $config, string $platform): array
    {
        $platform = strtolower(trim($platform));
        $hotelId = (int)($config['system_hotel_id'] ?? $config['hotel_id'] ?? 0);
        $savedAt = trim((string)($config['update_time'] ?? $config['updated_at'] ?? $config['created_at'] ?? $config['create_time'] ?? ''));
        $configurationSaved = $hotelId > 0
            && in_array($platform, ['ctrip', 'meituan'], true)
            && (string)($config['credential_status'] ?? '') === 'ready'
            && ($config['has_cookies'] ?? false) === true;

        if (!$configurationSaved) {
            return $this->state('not_saved', '未保存可用配置', false, false);
        }

        $cacheKey = $platform . ':' . $hotelId . ':' . $savedAt;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        $pendingLabel = $platform === 'meituan'
            ? 'Cookie 已保存，待采集验证'
            : '已保存，待授权验证';

        $savedTimestamp = $this->timestamp($savedAt);
        if ($savedTimestamp === null) {
            return $this->cache[$cacheKey] = $this->state('saved_pending_verification', $pendingLabel, true, false);
        }

        try {
            $sources = Db::name('platform_data_sources')
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->where('enabled', 1)
                ->order('update_time', 'desc')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            $sources = [];
        }

        $proofService = $this->proofService ?? new OtaProfileSessionProofService();
        foreach ($sources as $source) {
            if (!is_array($source) || !$proofService->isCurrentVerified($source)) {
                continue;
            }
            $configJson = json_decode((string)($source['config_json'] ?? ''), true);
            if (!is_array($configJson)) {
                continue;
            }
            $verifiedAt = trim((string)($configJson['current_session_probe_at'] ?? ''));
            $verifiedTimestamp = $this->timestamp($verifiedAt);
            if ($verifiedTimestamp !== null && $verifiedTimestamp >= $savedTimestamp) {
                return $this->cache[$cacheKey] = $this->state(
                    'verified_current',
                    '验证成功，当前使用',
                    true,
                    true,
                    $verifiedAt
                );
            }
        }

        return $this->cache[$cacheKey] = $this->state('saved_pending_verification', $pendingLabel, true, false);
    }

    /** @return array<string, mixed> */
    private function state(
        string $status,
        string $label,
        bool $saved,
        bool $verified,
        string $verifiedAt = ''
    ): array {
        return [
            'verification_status' => $status,
            'verification_status_label' => $label,
            'configuration_saved' => $saved,
            'configuration_verified' => $verified,
            'verified_at' => $verifiedAt,
        ];
    }

    private function timestamp(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone(self::TIMEZONE)))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
