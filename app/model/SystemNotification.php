<?php
declare(strict_types=1);

namespace app\model;

use think\Model;
use think\facade\Db;
use Throwable;

class SystemNotification extends Model
{
    protected $name = 'system_notifications';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'user_id' => 'integer',
        'recipient_user_id' => 'integer',
        'is_read' => 'integer',
        'is_cleared' => 'integer',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public static function tableReady(): bool
    {
        try {
            self::where('id', 0)->count();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function recipientTargetingReady(): bool
    {
        try {
            $rows = Db::query("SHOW COLUMNS FROM `system_notifications` LIKE 'recipient_user_id'");
            if (!empty($rows)) {
                return true;
            }
        } catch (Throwable) {
            // SQLite and other local test connections do not support SHOW COLUMNS.
        }

        try {
            foreach (Db::query('PRAGMA table_info(`system_notifications`)') as $row) {
                if (strtolower((string)($row['name'] ?? '')) === 'recipient_user_id') {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public static function recordEvent(array $data): self
    {
        if (!self::tableReady()) {
            throw new \RuntimeException('system_notifications table does not exist, run database migration first');
        }

        $recipientUserId = self::nullablePositiveInt($data['recipient_user_id'] ?? null);
        $recipientTargetingReady = self::recipientTargetingReady();
        if ($recipientUserId !== null && !$recipientTargetingReady) {
            throw new \RuntimeException('system_notifications.recipient_user_id does not exist, run notification recipient migration first');
        }

        $sourceKey = self::normalizeSourceKey((string)($data['source_key'] ?? ''));
        $payload = [
            'hotel_id' => self::nullablePositiveInt($data['hotel_id'] ?? null),
            'user_id' => self::nullablePositiveInt($data['user_id'] ?? null),
            'platform' => self::safeIdentifier($data['platform'] ?? 'ota', 'ota'),
            'category' => self::safeIdentifier($data['category'] ?? 'general', 'general'),
            'severity' => self::safeSeverity($data['severity'] ?? 'info'),
            'title' => self::safeText($data['title'] ?? '系统通知', 120),
            'message' => self::safeText($data['message'] ?? '', 500),
            'action_type' => self::safeIdentifier($data['action_type'] ?? '', ''),
            'action_payload' => self::encodeActionPayload($data['action_payload'] ?? []),
            'source_module' => self::safeIdentifier($data['source_module'] ?? 'system', 'system'),
            'source_key' => $sourceKey,
            'is_read' => 0,
            'is_cleared' => 0,
            'read_time' => null,
            'clear_time' => null,
        ];
        if ($recipientTargetingReady) {
            $payload['recipient_user_id'] = $recipientUserId;
        }

        $existing = self::where('source_key', $sourceKey)->find();
        if ($existing) {
            $existing->save($payload);
            return $existing;
        }

        return self::create($payload);
    }

    private static function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = (int)$value;
        return $number > 0 ? $number : null;
    }

    private static function safeSeverity($value): string
    {
        $severity = strtolower(trim((string)$value));
        return in_array($severity, ['info', 'warning', 'error', 'success'], true) ? $severity : 'info';
    }

    private static function safeIdentifier($value, string $fallback): string
    {
        $text = strtolower(trim((string)$value));
        $text = preg_replace('/[^a-z0-9_\-]/', '_', $text) ?: '';
        return $text !== '' ? substr($text, 0, 64) : $fallback;
    }

    private static function normalizeSourceKey(string $sourceKey): string
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return 'system:' . sha1((string)microtime(true));
        }
        if (strlen($sourceKey) <= 160) {
            return $sourceKey;
        }
        return substr($sourceKey, 0, 120) . ':' . substr(sha1($sourceKey), 0, 32);
    }

    private static function encodeActionPayload($payload): ?string
    {
        if (!is_array($payload) || empty($payload)) {
            return null;
        }

        return json_encode(self::sanitizePayload($payload), JSON_UNESCAPED_UNICODE);
    }

    private static function sanitizePayload(array $payload, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }

        $sensitiveKeys = [
            'authorization',
            'auth_data',
            'cookie',
            'cookies',
            'headers',
            'password',
            'spidertoken',
            'token',
        ];
        $safe = [];

        foreach ($payload as $key => $value) {
            $keyText = (string)$key;
            if (in_array(strtolower($keyText), $sensitiveKeys, true)) {
                $safe[$keyText] = '***';
                continue;
            }
            if (is_array($value)) {
                $safe[$keyText] = self::sanitizePayload($value, $depth + 1);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$keyText] = self::safeText((string)$value, 160);
                continue;
            }
            $safe[$keyText] = '[object]';
        }

        return $safe;
    }

    private static function safeText($value, int $limit): string
    {
        $text = trim((string)$value);
        $text = preg_replace('/(1[3-9]\d)\d{4}(\d{4})/u', '$1****$2', $text) ?: '';
        $text = preg_replace('/\b\d{8,}\b/u', '[编号已隐藏]', $text) ?: '';
        $text = preg_replace('/(cookie|token|authorization|spidertoken)\s*[:=]\s*[^;\s,]+/iu', '$1=****', $text) ?: '';
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';

        return mb_substr($text, 0, $limit);
    }
}
