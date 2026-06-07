<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$env = loadEnv($root . DIRECTORY_SEPARATOR . '.env');
$execute = in_array('--execute', $argv, true);
$limit = readIntArg($argv, '--limit');
$hotelId = readStringArg($argv, '--hotel-id');

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $env['DB_HOST'] ?? '127.0.0.1',
    $env['DB_PORT'] ?? '3306',
    $env['DB_NAME'] ?? 'hotelx',
    $env['DB_CHARSET'] ?? 'utf8mb4'
);

$pdo = new PDO($dsn, $env['DB_USER'] ?? 'root', $env['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$where = "(source = 'meituan' OR platform = 'Meituan') AND data_type = 'business' AND raw_data IS NOT NULL AND raw_data <> ''";
$params = [];
if ($hotelId !== '') {
    $where .= ' AND system_hotel_id = :hotel_id';
    $params[':hotel_id'] = $hotelId;
}

$sql = "SELECT id, raw_data, update_time FROM online_daily_data WHERE {$where} ORDER BY id";
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$update = $pdo->prepare('UPDATE online_daily_data SET raw_data = :raw_data, update_time = :update_time WHERE id = :id');
$checked = 0;
$invalidJson = 0;
$changed = 0;
$written = 0;
$returned = 0;
$returnedEmpty = 0;
$notReturned = 0;
$vip = 0;

if ($execute) {
    $pdo->beginTransaction();
}

try {
    foreach ($rows as $row) {
        $checked++;
        $raw = json_decode((string)$row['raw_data'], true);
        if (!is_array($raw)) {
            $invalidJson++;
            continue;
        }

        $before = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tagInfo = extractMeituanPlatformTagInfo($raw);
        $raw['platformTags'] = $tagInfo['tags'];
        $raw['platformTagStatus'] = $tagInfo['status'];
        $raw['platformTagText'] = !empty($tagInfo['tags']) ? implode(' / ', $tagInfo['tags']) : '未返回';
        $raw['hasVipTag'] = hasMeituanVipPlatformTag($tagInfo['tags']);

        if ($tagInfo['status'] === 'returned') {
            $returned++;
        } elseif ($tagInfo['status'] === 'returned_empty') {
            $returnedEmpty++;
        } else {
            $notReturned++;
        }
        if ($raw['hasVipTag']) {
            $vip++;
        }

        $after = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($before === $after) {
            continue;
        }
        $changed++;
        if (!$execute) {
            continue;
        }

        $update->execute([
            ':raw_data' => $after,
            ':update_time' => $row['update_time'],
            ':id' => (int)$row['id'],
        ]);
        $written++;
    }

    if ($execute) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($execute && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[backfill:meituan-vip-tags] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode([
    'mode' => $execute ? 'execute' : 'dry-run',
    'checked' => $checked,
    'invalid_json' => $invalidJson,
    'changed' => $changed,
    'written' => $written,
    'tag_status_counts' => [
        'returned' => $returned,
        'returned_empty' => $returnedEmpty,
        'not_returned' => $notReturned,
    ],
    'vip_count' => $vip,
    'privacy_scope' => 'platform hotel tags only; no guest phone, order detail, room status, or room-source mapping',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

function loadEnv(string $path): array
{
    $result = [];
    if (!is_file($path)) {
        return $result;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $result[trim($key)] = trim($value);
    }
    return $result;
}

function readIntArg(array $argv, string $name): int
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name && isset($argv[$index + 1])) {
            return max(0, (int)$argv[$index + 1]);
        }
        if (str_starts_with($arg, $name . '=')) {
            return max(0, (int)substr($arg, strlen($name) + 1));
        }
    }
    return 0;
}

function readStringArg(array $argv, string $name): string
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name && isset($argv[$index + 1])) {
            return trim((string)$argv[$index + 1]);
        }
        if (str_starts_with($arg, $name . '=')) {
            return trim(substr($arg, strlen($name) + 1));
        }
    }
    return '';
}

function extractMeituanPlatformTagInfo(array $item): array
{
    $existingStatus = isset($item['platformTagStatus']) && is_scalar($item['platformTagStatus'])
        ? (string)$item['platformTagStatus']
        : '';
    $existingPlatformTags = is_array($item['platformTags'] ?? null)
        ? mergeStringList([], array_map('strval', $item['platformTags']))
        : [];
    if (!empty($existingPlatformTags)) {
        return [
            'tags' => $existingPlatformTags,
            'status' => 'returned',
        ];
    }

    $tagKeys = [
        'tags', 'tagList', 'tag_list', 'labels', 'labelList', 'label_list',
        'hotelTags', 'hotelTagList', 'poiTagList', 'rightsTags', 'rightsTagList',
        'badgeList', 'benefitTags', 'titleTags', 'identityTags',
    ];
    $singleTagKeys = [
        'vipTag', 'memberTag', 'rightsTag', 'platformTag', 'crownLevel', 'crownTag',
        'brandTag', 'brandName', 'chainName', 'hotelBrand', 'groupName', 'starTag',
    ];
    $booleanVipKeys = ['isVip', 'isVIP', 'vip', 'vipFlag', 'memberFlag', 'isMemberHotel'];

    $tags = [];
    $returned = false;
    foreach ($tagKeys as $key) {
        if (array_key_exists($key, $item)) {
            $returned = true;
            $tags = mergeStringList($tags, collectMeituanTagTokens($item[$key]));
        }
    }
    foreach ($singleTagKeys as $key) {
        if (array_key_exists($key, $item)) {
            $returned = true;
            $tokens = collectMeituanTagTokens($item[$key]);
            if (in_array($key, ['crownLevel', 'crownTag'], true)) {
                $tokens = array_map(static function ($token): string {
                    $text = trim((string)$token);
                    return preg_match('/^\d+$/', $text) ? ('冠级' . $text) : $text;
                }, $tokens);
            }
            $tags = mergeStringList($tags, $tokens);
        }
    }
    foreach ($booleanVipKeys as $key) {
        if (array_key_exists($key, $item)) {
            $returned = true;
            if (isExplicitTruthy($item[$key])) {
                $tags = mergeStringList($tags, ['VIP']);
            }
        }
    }

    $tags = array_values(array_filter(array_map('normalizeMeituanPlatformTag', $tags), static fn($tag): bool => $tag !== ''));
    $tags = mergeStringList([], $tags);
    if (empty($tags) && !$returned && in_array($existingStatus, ['returned_empty', 'not_returned'], true)) {
        return [
            'tags' => [],
            'status' => $existingStatus,
        ];
    }
    return [
        'tags' => $tags,
        'status' => !empty($tags) ? 'returned' : ($returned ? 'returned_empty' : 'not_returned'),
    ];
}

function collectMeituanTagTokens($value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (is_scalar($value)) {
        return [(string)$value];
    }
    if (!is_array($value)) {
        return [];
    }

    $tokens = [];
    $preferredKeys = ['name', 'tagName', 'tag_name', 'label', 'text', 'title', 'value', 'displayName', 'rightsName'];
    foreach ($preferredKeys as $key) {
        if (array_key_exists($key, $value) && is_scalar($value[$key]) && trim((string)$value[$key]) !== '') {
            $tokens[] = (string)$value[$key];
        }
    }
    if (!empty($tokens)) {
        return $tokens;
    }
    foreach ($value as $child) {
        $tokens = mergeStringList($tokens, collectMeituanTagTokens($child));
    }
    return $tokens;
}

function normalizeMeituanPlatformTag(string $tag): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $tag) ?: $tag);
    if ($value === '') {
        return '';
    }
    if (preg_match('/\bvip\b/i', $value)) {
        return 'VIP';
    }
    if (preg_match('/^(?:0|1|true|false|yes|no)$/i', $value) || preg_match('/^\d+$/', $value)) {
        return '';
    }
    return mb_strlen($value, 'UTF-8') > 24 ? mb_substr($value, 0, 24, 'UTF-8') : $value;
}

function hasMeituanVipPlatformTag(array $tags): bool
{
    foreach ($tags as $tag) {
        if (preg_match('/\bvip\b/i', (string)$tag)) {
            return true;
        }
    }
    return false;
}

function mergeStringList(array $base, array $incoming): array
{
    $seen = [];
    $result = [];
    foreach (array_merge($base, $incoming) as $value) {
        $text = trim((string)$value);
        if ($text === '') {
            continue;
        }
        $key = strtolower($text);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $text;
    }
    return $result;
}

function isExplicitTruthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (float)$value > 0;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'y', 'vip', 'member'], true);
}
