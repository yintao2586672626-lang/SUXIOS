<?php
declare(strict_types=1);

namespace app\service;

use app\model\SystemConfig;
use Throwable;
use think\facade\Db;

final class SensitiveStorageMigrationService
{
    private SensitiveValueCipher $cipher;
    private WechatRobotWebhookSecret $webhookSecret;

    public function __construct(?SensitiveValueCipher $cipher = null)
    {
        $this->cipher = $cipher ?? SensitiveValueCipher::fromEnvironment();
        $this->webhookSecret = new WechatRobotWebhookSecret($this->cipher);
    }

    /** @return array<string, mixed> */
    public function run(bool $execute = false): array
    {
        if (!$execute) {
            return $this->runSources(false);
        }

        $preflight = $this->runSources(false);
        if ((int)$preflight['failed_count'] > 0) {
            $preflight['mode'] = 'execute';
            $preflight['status'] = 'blocked';
            $preflight['reason_code'] = 'preflight_failed';
            return $preflight;
        }

        $attempt = null;
        try {
            Db::transaction(function () use (&$attempt): void {
                $attempt = $this->runSources(true);
                if ((int)($attempt['failed_count'] ?? 0) > 0) {
                    throw new \RuntimeException('Sensitive storage migration failed.');
                }
            });
        } catch (Throwable) {
            $attempt = is_array($attempt) ? $attempt : $preflight;
            if (isset($attempt['sources']) && is_array($attempt['sources'])) {
                foreach ($attempt['sources'] as &$source) {
                    if (!is_array($source) || (int)($source['migrated_count'] ?? 0) <= 0) {
                        continue;
                    }
                    $source['migrated_count'] = 0;
                    $source['status'] = 'rolled_back';
                }
                unset($source);
            }
            $attempt['mode'] = 'execute';
            $attempt['status'] = 'rolled_back';
            $attempt['migrated_count'] = 0;
            $attempt['reason_code'] = 'transaction_rolled_back';
            return $attempt;
        }

        return $attempt ?? $preflight;
    }

    /** @return array<string, mixed> */
    private function runSources(bool $execute): array
    {
        $sources = [
            'system_config' => $this->migrateSystemConfig($execute),
            'competitor_wechat_robot' => $this->migrateWechatRobots($execute),
        ];

        $totals = [
            'scanned_count' => 0,
            'pending_count' => 0,
            'migrated_count' => 0,
            'already_protected_count' => 0,
            'failed_count' => 0,
        ];
        foreach ($sources as $source) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int)($source[$key] ?? 0);
            }
        }

        $status = $totals['failed_count'] > 0
            ? ($execute ? 'partial' : 'blocked')
            : ($execute ? 'completed' : ($totals['pending_count'] > 0 ? 'migration_required' : 'ready'));

        return [
            'mode' => $execute ? 'execute' : 'dry-run',
            'status' => $status,
            ...$totals,
            'sources' => $sources,
        ];
    }

    /** @return array<string, int|bool|string> */
    private function migrateSystemConfig(bool $execute): array
    {
        $summary = $this->emptySourceSummary('system_config');
        if (!$this->tableAvailable('system_config')) {
            // system_config is a core application table. Missing access must
            // block the migration instead of being reported as a clean no-op.
            $summary['status'] = 'scan_failed';
            $summary['failed_count'] = 1;
            return $summary;
        }
        $summary['installed'] = true;

        try {
            $rows = Db::name('system_config')
                ->whereIn('config_key', [
                    SystemConfig::KEY_WECHAT_MINI_SECRET,
                    SystemConfig::KEY_NOTIFY_EMAIL_PASS,
                    SystemConfig::KEY_AMAP_WEB_API_KEY,
                ])
                ->field('id,config_key,config_value')
                ->select()
                ->toArray();
        } catch (Throwable) {
            $summary['status'] = 'scan_failed';
            $summary['failed_count'] = 1;
            return $summary;
        }

        foreach ($rows as $row) {
            $summary['scanned_count']++;
            $key = (string)($row['config_key'] ?? '');
            $stored = (string)($row['config_value'] ?? '');
            if ($stored === '') {
                continue;
            }
            try {
                if ($this->cipher->isEncrypted($stored)) {
                    SystemConfig::decodeValueFromStorage($key, $stored, $this->cipher);
                    $summary['already_protected_count']++;
                    continue;
                }

                $protected = (string)SystemConfig::encodeValueForStorage($key, $stored, $this->cipher);
                $summary['pending_count']++;
                if ($execute) {
                    $updated = Db::name('system_config')
                        ->where('id', (int)$row['id'])
                        ->where('config_value', $stored)
                        ->update(['config_value' => $protected]);
                    if ($updated !== 1) {
                        throw new \RuntimeException('Concurrent system config update detected.');
                    }
                    $summary['migrated_count']++;
                }
            } catch (Throwable) {
                $summary['failed_count']++;
            }
        }

        $summary['status'] = $summary['failed_count'] > 0 ? 'partial' : 'ok';
        return $summary;
    }

    /** @return array<string, int|bool|string> */
    private function migrateWechatRobots(bool $execute): array
    {
        $summary = $this->emptySourceSummary('competitor_wechat_robot');
        if (!$this->tableAvailable('competitor_wechat_robot')) {
            $summary['status'] = 'scan_failed';
            $summary['failed_count'] = 1;
            return $summary;
        }
        $summary['installed'] = true;

        try {
            $rows = Db::name('competitor_wechat_robot')
                ->field('id,webhook')
                ->select()
                ->toArray();
        } catch (Throwable) {
            $summary['status'] = 'scan_failed';
            $summary['failed_count'] = 1;
            return $summary;
        }

        foreach ($rows as $row) {
            $summary['scanned_count']++;
            $robotId = (int)($row['id'] ?? 0);
            $stored = trim((string)($row['webhook'] ?? ''));
            if ($stored === '') {
                continue;
            }
            try {
                if ($this->webhookSecret->isProtected($stored)) {
                    $this->webhookSecret->reveal($stored, $robotId);
                    $summary['already_protected_count']++;
                    continue;
                }

                $protected = $this->webhookSecret->protect($stored, $robotId);
                $summary['pending_count']++;
                if ($execute) {
                    $updated = Db::name('competitor_wechat_robot')
                        ->where('id', (int)$row['id'])
                        ->where('webhook', $stored)
                        ->update(['webhook' => $protected]);
                    if ($updated !== 1) {
                        throw new \RuntimeException('Concurrent webhook update detected.');
                    }
                    $summary['migrated_count']++;
                }
            } catch (Throwable) {
                $summary['failed_count']++;
            }
        }

        $summary['status'] = $summary['failed_count'] > 0 ? 'partial' : 'ok';
        return $summary;
    }

    /** @return array<string, int|bool|string> */
    private function emptySourceSummary(string $table): array
    {
        return [
            'table' => $table,
            'installed' => false,
            'status' => 'not_installed',
            'scanned_count' => 0,
            'pending_count' => 0,
            'migrated_count' => 0,
            'already_protected_count' => 0,
            'failed_count' => 0,
        ];
    }

    private function tableAvailable(string $table): bool
    {
        try {
            Db::name($table)->field('id')->limit(1)->select();
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
