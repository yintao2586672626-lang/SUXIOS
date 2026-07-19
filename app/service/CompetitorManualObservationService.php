<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Persists an authenticated operator's observation of a public OTA card/page.
 *
 * Public starting prices can prove an OTA-channel availability observation
 * only when the selected competitor has a verified numeric OTA identity.
 * They never become comparable revenue-pricing evidence because exact room,
 * rate-plan, benefit, cancellation, payment and tax terms are not disclosed.
 */
final class CompetitorManualObservationService
{
    private const PLATFORM_ALIASES = [
        'ctrip' => ['ctrip', 'xc'],
        'meituan' => ['meituan', 'mt'],
    ];

    private const SOURCE_SURFACES = ['public_nearby_card', 'public_hotel_page'];
    private const AVAILABILITY_STATUSES = ['available', 'bookable', 'unavailable', 'sold_out'];
    private const BOOKABLE_STATUSES = ['available', 'bookable'];

    /**
     * @return array{id:int,idempotent_replay:bool,readback_verified:bool,record:array<string,mixed>,canonical_platform:string}
     */
    public function persist(
        int $systemHotelId,
        int $competitorHotelId,
        int $actorUserId,
        array $input
    ): array {
        if ($systemHotelId <= 0 || $competitorHotelId <= 0 || $actorUserId <= 0) {
            throw new InvalidArgumentException('门店、竞品和操作人必须有效');
        }

        return Db::transaction(function () use ($systemHotelId, $competitorHotelId, $actorUserId, $input): array {
            $target = Db::name('competitor_hotel')
                ->where('id', $competitorHotelId)
                ->where('store_id', $systemHotelId)
                ->where('status', 1)
                ->lock(true)
                ->find();
            if (!is_array($target)) {
                throw new InvalidArgumentException('竞品不存在、未启用或不属于当前门店');
            }

            $normalized = self::normalizePublicObservation($target, $input);
            $record = $normalized['record'];
            $record['device_id'] = 'manual:user:' . $actorUserId;
            $record['fetch_time'] = date('Y-m-d H:i:s');

            // The content-hash index makes this an exact indexed range lock on
            // MySQL/InnoDB, preventing a concurrent replay from creating a
            // second event while preserving legitimate later observations.
            $existing = Db::name('competitor_price_log')
                ->where('store_id', $systemHotelId)
                ->where('hotel_id', $competitorHotelId)
                ->where('platform', (string)$record['platform'])
                ->where('content_hash', (string)$record['content_hash'])
                ->lock(true)
                ->find();

            if (is_array($existing)) {
                if (!$this->readbackMatches($existing, $record, false)) {
                    throw new RuntimeException('已存在观测与内容哈希不一致');
                }
                if ((int)($existing['readback_verified'] ?? 0) !== 1) {
                    Db::name('competitor_price_log')
                        ->where('id', (int)$existing['id'])
                        ->update(['readback_verified' => 1]);
                    $existing['readback_verified'] = 1;
                }

                return [
                    'id' => (int)$existing['id'],
                    'idempotent_replay' => true,
                    'readback_verified' => true,
                    'record' => $existing,
                    'canonical_platform' => $normalized['canonical_platform'],
                ];
            }

            $record['readback_verified'] = 0;
            $id = (int)Db::name('competitor_price_log')->insertGetId($record);
            $readback = Db::name('competitor_price_log')
                ->where('id', $id)
                ->where('store_id', $systemHotelId)
                ->where('hotel_id', $competitorHotelId)
                ->find();
            if (!is_array($readback) || !$this->readbackMatches($readback, $record, true)) {
                throw new RuntimeException('观测保存后数据库回读不一致');
            }

            Db::name('competitor_price_log')
                ->where('id', $id)
                ->update(['readback_verified' => 1]);
            $readback['readback_verified'] = 1;

            return [
                'id' => $id,
                'idempotent_replay' => false,
                'readback_verified' => true,
                'record' => $readback,
                'canonical_platform' => $normalized['canonical_platform'],
            ];
        });
    }

    /**
     * @return array{record:array<string,mixed>,canonical_platform:string}
     */
    public static function normalizePublicObservation(array $target, array $input): array
    {
        $systemHotelId = (int)($target['store_id'] ?? 0);
        $competitorHotelId = (int)($target['id'] ?? 0);
        $canonicalPlatform = self::canonicalPlatform((string)($target['platform'] ?? ''));
        if ($systemHotelId <= 0 || $competitorHotelId <= 0 || $canonicalPlatform === null) {
            throw new InvalidArgumentException('竞品目标缺少有效门店或携程/美团平台身份');
        }

        $requestedPlatform = trim((string)($input['platform'] ?? ''));
        if ($requestedPlatform !== '') {
            $requestedCanonical = self::canonicalPlatform($requestedPlatform);
            if ($requestedCanonical === null || $requestedCanonical !== $canonicalPlatform) {
                throw new InvalidArgumentException('提交平台与竞品目标平台不一致');
            }
        }

        $availability = strtolower(trim((string)($input['availability'] ?? '')));
        if (!in_array($availability, self::AVAILABILITY_STATUSES, true)) {
            throw new InvalidArgumentException('可售状态仅支持 available/bookable/unavailable/sold_out');
        }

        $price = self::normalizePrice($input['price'] ?? $input['price_text'] ?? null);
        if (in_array($availability, self::BOOKABLE_STATUSES, true) && $price === null) {
            throw new InvalidArgumentException('可订观测必须填写页面可见的正价格');
        }
        if (!in_array($availability, self::BOOKABLE_STATUSES, true)) {
            $price = null;
        }

        $checkInDate = self::normalizeDate((string)($input['check_in_date'] ?? ''), '入住日期');
        $checkOutDate = self::normalizeDate((string)($input['check_out_date'] ?? ''), '离店日期');
        $checkIn = new \DateTimeImmutable($checkInDate);
        $checkOut = new \DateTimeImmutable($checkOutDate);
        if ($checkOut <= $checkIn) {
            throw new InvalidArgumentException('离店日期必须晚于入住日期');
        }
        $nights = (int)$checkIn->diff($checkOut)->days;
        $collectedAt = self::normalizeDateTime((string)($input['collected_at'] ?? ''));
        $adults = self::boundedInteger($input['adults'] ?? 2, 1, 20, '成人数');
        $children = self::boundedInteger($input['children'] ?? 0, 0, 20, '儿童数');

        $currency = strtoupper(trim((string)($input['currency'] ?? 'CNY')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('币种必须为三位 ISO 代码');
        }

        $sourceSurface = strtolower(trim((string)($input['source_surface'] ?? 'public_nearby_card')));
        if (!in_array($sourceSurface, self::SOURCE_SURFACES, true)) {
            throw new InvalidArgumentException('公开来源页面类型不受支持');
        }
        $sourceRef = self::sanitizeSourceReference((string)($input['source_ref'] ?? ''), $canonicalPlatform);
        $targetOtaHotelId = self::numericOtaHotelId((string)($target['hotel_code'] ?? ''));
        $requestedOtaHotelId = self::limitText((string)($input['ota_hotel_id'] ?? ''), 80);
        if (str_starts_with(strtolower($requestedOtaHotelId), 'public-name:')) {
            $requestedOtaHotelId = '';
        }
        if ($requestedOtaHotelId !== '' && preg_match('/^[1-9][0-9]{0,19}$/D', $requestedOtaHotelId) !== 1) {
            throw new InvalidArgumentException('OTA 酒店 ID 必须为平台公开的数字标识');
        }
        if ($requestedOtaHotelId !== '' && $targetOtaHotelId === null) {
            throw new InvalidArgumentException('竞品目标尚未绑定 OTA 酒店 ID，不能用请求参数临时提升身份');
        }
        if ($requestedOtaHotelId !== '' && $requestedOtaHotelId !== $targetOtaHotelId) {
            throw new InvalidArgumentException('提交 OTA 酒店 ID 与竞品目标绑定不一致');
        }
        $otaHotelId = $targetOtaHotelId ?? '';
        if ($sourceSurface === 'public_hotel_page') {
            if ($otaHotelId === '') {
                throw new InvalidArgumentException('酒店公开详情页观测需要先绑定竞品 OTA 酒店 ID');
            }
            $sourceHotelId = self::sourceHotelId($sourceRef, $canonicalPlatform);
            if ($sourceHotelId === null || $sourceHotelId !== $otaHotelId) {
                throw new InvalidArgumentException('公开详情页 URL 与竞品目标 OTA 酒店 ID 不一致');
            }
        }

        $sourceMethod = 'manual_' . $canonicalPlatform . '_public_observation';
        $roomTypeKey = 'hotel_lowest_visible_rate';
        $priceBasis = 'visible_starting_price';
        $availabilityScopeFields = [
            $canonicalPlatform,
            $otaHotelId,
            $sourceMethod,
            $sourceRef,
            $checkInDate,
            $checkOutDate,
            $roomTypeKey,
            '',
            $sourceSurface,
            $priceBasis,
            $currency,
            $adults,
            $children,
        ];
        $availabilityScopeKey = $otaHotelId === ''
            ? ''
            : hash('sha256', implode('|', array_map(
                static fn(mixed $value): string => strtolower(trim((string)$value)),
                $availabilityScopeFields
            )));

        $partialReasons = ['exact_room_rate_terms_not_disclosed'];
        if ($otaHotelId === '') {
            array_unshift($partialReasons, 'target_binding_missing', 'ota_hotel_id_missing');
        }

        $targetTenantId = (int)($target['tenant_id'] ?? 0);
        $record = [
            'tenant_id' => $targetTenantId > 0 ? $targetTenantId : $systemHotelId,
            'store_id' => $systemHotelId,
            'hotel_id' => $competitorHotelId,
            'ota_hotel_id' => $otaHotelId !== '' ? $otaHotelId : null,
            'platform' => (string)$target['platform'],
            'city' => self::limitText((string)($target['city'] ?? ''), 80),
            'price' => $price,
            'screenshot' => '',
            'collected_at' => $collectedAt,
            'source_method' => $sourceMethod,
            'source_ref' => $sourceRef,
            'validation_status' => 'incomplete',
            'failure_reason' => 'partial_public_observation:' . implode(',', $partialReasons),
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'room_type_key' => $roomTypeKey,
            'ota_product_id' => '',
            'rate_plan_key' => $sourceSurface,
            'package_name' => '',
            'breakfast' => 'not_disclosed',
            'cancellation_policy' => 'not_disclosed',
            'payment_mode' => 'not_disclosed',
            'tax_fee_included' => null,
            'price_basis' => $priceBasis,
            'currency' => $currency,
            'availability' => $availability,
            'availability_scope_key' => $availabilityScopeKey,
            // Public starting prices are intentionally excluded from the
            // comparable-rate decision path even when a numeric price exists.
            'comparison_key' => '',
        ];
        $record['content_hash'] = hash('sha256', json_encode(
            [
                'system_hotel_id' => $systemHotelId,
                'competitor_hotel_id' => $competitorHotelId,
                'canonical_platform' => $canonicalPlatform,
                'record' => $record,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        return [
            'record' => $record,
            'canonical_platform' => $canonicalPlatform,
        ];
    }

    private function readbackMatches(array $actual, array $expected, bool $includePersistenceMetadata): bool
    {
        foreach ([
            'tenant_id', 'store_id', 'hotel_id', 'ota_hotel_id', 'platform', 'city', 'screenshot',
            'collected_at', 'source_method', 'source_ref', 'validation_status', 'failure_reason',
            'check_in_date', 'check_out_date', 'nights', 'adults', 'children', 'room_type_key',
            'ota_product_id', 'rate_plan_key', 'package_name', 'breakfast', 'cancellation_policy',
            'payment_mode', 'tax_fee_included', 'price_basis', 'currency', 'availability',
            'availability_scope_key', 'comparison_key', 'content_hash',
        ] as $field) {
            $expectedValue = $expected[$field] ?? null;
            $actualValue = $actual[$field] ?? null;
            if ($expectedValue === null ? $actualValue !== null : (string)$actualValue !== (string)$expectedValue) {
                return false;
            }
        }

        if ($includePersistenceMetadata) {
            foreach (['device_id', 'fetch_time', 'readback_verified'] as $field) {
                if ((string)($actual[$field] ?? '') !== (string)($expected[$field] ?? '')) {
                    return false;
                }
            }
        }

        $actualPrice = $actual['price'] ?? null;
        $expectedPrice = $expected['price'] ?? null;
        if ($expectedPrice === null) {
            return $actualPrice === null;
        }

        return is_numeric($actualPrice) && abs((float)$actualPrice - (float)$expectedPrice) < 0.001;
    }

    private static function numericOtaHotelId(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1 ? $value : null;
    }

    private static function sourceHotelId(string $sourceRef, string $platform): ?string
    {
        $path = (string)(parse_url($sourceRef, PHP_URL_PATH) ?? '');
        $pattern = $platform === 'ctrip'
            ? '~(?:^|/)hotels/([1-9][0-9]{0,19})\.html(?:/|$)~i'
            : '~(?:^|/)(?:hotel|poi)(?:/detail)?/([1-9][0-9]{0,19})(?:\.html)?(?:/|$)~i';
        return preg_match($pattern, $path, $matches) === 1 ? (string)$matches[1] : null;
    }

    private static function canonicalPlatform(string $platform): ?string
    {
        $platform = strtolower(trim($platform));
        foreach (self::PLATFORM_ALIASES as $canonical => $aliases) {
            if (in_array($platform, $aliases, true)) {
                return $canonical;
            }
        }
        return null;
    }

    private static function normalizePrice(mixed $value): ?float
    {
        if ($value === null || (is_scalar($value) && trim((string)$value) === '')) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('价格必须为页面可见的正数');
        }
        $normalized = trim(strtr((string)$value, ['，' => ',', '．' => '.', '￥' => '¥']));
        $normalized = preg_replace('/^¥\s*/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*(?:元|起|\/\s*(?:晚|夜))\s*$/u', '', $normalized) ?? $normalized;
        if (preg_match('/^(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)$/D', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException('价格必须为页面可见的正数');
        }
        $price = (float)str_replace(',', '', $matches[1]);
        if ($price <= 0 || $price > 99999999.99) {
            throw new InvalidArgumentException('价格必须为页面可见的正数');
        }
        return round($price, 2);
    }

    private static function normalizeDate(string $value, string $label): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException($label . '必须为 YYYY-MM-DD');
        }
        return $value;
    }

    private static function normalizeDateTime(string $value): string
    {
        $value = trim($value);
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('采集时间必须为有效本地日期时间');
    }

    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $label): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^\d+$/D', $raw) !== 1) {
            throw new InvalidArgumentException($label . '必须为整数');
        }
        $number = (int)$raw;
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException($label . "必须在 {$minimum}-{$maximum} 之间");
        }
        return $number;
    }

    private static function sanitizeSourceReference(string $value, string $platform): string
    {
        $parts = parse_url(trim($value));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('来源必须为公开 OTA 页面 URL');
        }
        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('来源必须为 http/https 页面');
        }
        $allowedHosts = $platform === 'ctrip'
            ? ['ctrip.com']
            : ['meituan.com', 'dianping.com'];
        $hostAllowed = false;
        foreach ($allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                $hostAllowed = true;
                break;
            }
        }
        if (!$hostAllowed) {
            throw new InvalidArgumentException('来源域名与竞品平台不一致');
        }

        $safe = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $safe .= ':' . (int)$parts['port'];
        }
        $safe .= (string)($parts['path'] ?? '');
        return self::limitText($safe, 500);
    }

    private static function limitText(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return mb_strlen($value, 'UTF-8') > $length
            ? mb_substr($value, 0, $length, 'UTF-8')
            : $value;
    }
}
