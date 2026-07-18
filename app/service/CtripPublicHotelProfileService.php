<?php
declare(strict_types=1);

namespace app\service;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;
use think\facade\Db;

/**
 * Collects static, publicly visible Ctrip hotel facts.
 *
 * Room count from this source is a property profile fact. It is never treated
 * as date-specific sellable inventory.
 */
final class CtripPublicHotelProfileService
{
    public const SOURCE_METHOD = 'ctrip_public_page';
    public const CAPTURE_SECTION = 'public_hotel_profile';
    public const ENDPOINT_ID = 'ctrip_public_hotel_page';
    public const ENTITY_TYPE = 'public_hotel_profile';
    public const ROOM_COUNT_SEMANTICS = 'static_total_guest_rooms_not_date_specific_sellable_inventory';
    public const PROFILE_SCHEMA_VERSION = 2;

    private const PUBLIC_HOST = 'hotels.ctrip.com';
    private const PUBLIC_BINDING_CONFIG_KEY = 'ctrip_public_hotel_bindings';
    private const MAX_RESPONSE_BYTES = 3_000_000;
    private const MAX_REDIRECTS = 3;
    private const FRESH_SECONDS = 604800;
    private const MAX_STATIC_ITEMS = 200;
    private const MAX_STATIC_TEXT_LENGTH = 2000;

    /** @var null|\Closure(string):array<string,mixed>|string */
    private ?\Closure $fetcher;

    /** @var null|\Closure():string */
    private ?\Closure $clock;

    /** @var null|\Closure(string):array<int,string> */
    private ?\Closure $hostResolver;

    /** @var null|\Closure(string,array<string,mixed>):array<string,mixed> */
    private ?\Closure $httpRequester;

    /** @var array<string,array<string,bool>> */
    private array $tableColumnCache = [];

    public function __construct(
        ?callable $fetcher = null,
        ?callable $clock = null,
        ?callable $hostResolver = null,
        ?callable $httpRequester = null
    ) {
        $this->fetcher = $fetcher !== null ? \Closure::fromCallable($fetcher) : null;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : null;
        $this->hostResolver = $hostResolver !== null ? \Closure::fromCallable($hostResolver) : null;
        $this->httpRequester = $httpRequester !== null ? \Closure::fromCallable($httpRequester) : null;
    }

    public static function publicUrl(string $otaHotelId): string
    {
        $otaHotelId = self::normalizeHotelId($otaHotelId);
        if ($otaHotelId === '') {
            throw new \InvalidArgumentException('Ctrip hotel ID must be a positive integer.');
        }

        return 'https://' . self::PUBLIC_HOST . '/hotels/' . $otaHotelId . '.html';
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchProfile(string $otaHotelId): array
    {
        $otaHotelId = self::normalizeHotelId($otaHotelId);
        if ($otaHotelId === '') {
            throw new \InvalidArgumentException('Ctrip hotel ID must be a positive integer.');
        }

        $url = self::publicUrl($otaHotelId);
        $collectedAt = $this->now();
        try {
            $response = $this->fetcher !== null
                ? ($this->fetcher)($url)
                : $this->requestPublicPage($url, $otaHotelId);
        } catch (\Throwable $exception) {
            return $this->failedProfile(
                $otaHotelId,
                $url,
                'network_failure',
                $collectedAt,
                0,
                get_debug_type($exception)
            );
        }

        if (is_string($response)) {
            $response = ['http_status' => 200, 'body' => $response, 'final_url' => $url];
        }
        if (!is_array($response)) {
            return $this->failedProfile($otaHotelId, $url, 'invalid_fetch_result', $collectedAt);
        }

        $httpStatus = (int)($response['http_status'] ?? $response['status'] ?? 0);
        $finalUrl = trim((string)($response['final_url'] ?? $url));
        $body = is_string($response['body'] ?? null) ? (string)$response['body'] : '';
        $error = trim((string)($response['error'] ?? ''));
        $failureReason = trim((string)($response['failure_reason'] ?? ''));
        if ($failureReason !== '') {
            return $this->failedProfile(
                $otaHotelId,
                $url,
                $failureReason,
                $collectedAt,
                $httpStatus,
                $error
            );
        }
        if ($error !== '' || $httpStatus < 200 || $httpStatus >= 300) {
            return $this->failedProfile(
                $otaHotelId,
                $url,
                $error !== '' ? 'network_failure' : 'http_failure',
                $collectedAt,
                $httpStatus,
                $error !== '' ? $error : ('http_' . $httpStatus)
            );
        }
        if (!$this->isAllowedFinalUrl($finalUrl, $otaHotelId)) {
            return $this->failedProfile($otaHotelId, $url, 'unexpected_redirect', $collectedAt, $httpStatus);
        }
        if ($body === '') {
            return $this->failedProfile($otaHotelId, $url, 'empty_response', $collectedAt, $httpStatus);
        }
        if ($this->looksBlocked($body)) {
            return $this->failedProfile($otaHotelId, $url, 'public_page_blocked', $collectedAt, $httpStatus);
        }

        $profile = $this->parseHtml($body, $otaHotelId, $url, $collectedAt);
        $profile['http_status'] = $httpStatus;
        $profile['final_url'] = $finalUrl;

        return $profile;
    }

    /**
     * @return array<string,mixed>
     */
    public function parseHtml(
        string $html,
        string $otaHotelId,
        ?string $sourceUrl = null,
        ?string $collectedAt = null
    ): array {
        $otaHotelId = self::normalizeHotelId($otaHotelId);
        if ($otaHotelId === '') {
            throw new \InvalidArgumentException('Ctrip hotel ID must be a positive integer.');
        }
        $sourceUrl = $sourceUrl !== null && trim($sourceUrl) !== ''
            ? trim($sourceUrl)
            : self::publicUrl($otaHotelId);
        $collectedAt = $this->normalizeDateTime($collectedAt) ?? $this->now();

        $fields = $this->emptyFields();
        $evidencePaths = [];
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded) {
            $xpath = new DOMXPath($document);
            $structuredDetails = $this->extractNextFlightHotelDetails($document);
            if ($structuredDetails !== []) {
                $this->applyStructuredHotelDetails($structuredDetails, $fields, $evidencePaths);
            }

            $name = $this->firstXPathValue($xpath, [
                '//h1[@aria-label][1]/@aria-label',
                '//h1[1]',
            ]);
            if ($fields['name'] === null && $name !== null) {
                $fields['name'] = $name;
                $evidencePaths['name'] = 'html:h1';
            }

            $address = $this->firstXPathValue($xpath, [
                '//*[contains(@class,"headInit-address") and @aria-label][1]/@aria-label',
                '//*[contains(@class,"headInit-address")]//*[@aria-label][1]/@aria-label',
                '//*[@data-test-id="hotel-address" and @aria-label][1]/@aria-label',
                '//*[@itemprop="address"][1]',
            ]);
            if ($fields['address'] === null && $address !== null) {
                $fields['address'] = $address;
                $evidencePaths['address'] = 'html:hotel_address';
            }

            $gradeLabel = $this->firstXPathValue($xpath, [
                '//*[@role="img" and @aria-label and (contains(translate(@aria-label,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"diamond") or contains(translate(@aria-label,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"star"))][1]/@aria-label',
            ]);
            if ($fields['star_level'] === null
                && $fields['diamond_level'] === null
                && $gradeLabel !== null
                && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*out\s+of\s+([0-9]+(?:\.[0-9]+)?)\s*(diamonds?|stars?)/i', $gradeLabel, $matches) === 1
            ) {
                $level = (float)$matches[1];
                $scale = (float)$matches[2];
                $type = str_starts_with(strtolower($matches[3]), 'diamond') ? 'diamond' : 'star';
                $fields[$type === 'diamond' ? 'diamond_level' : 'star_level'] = $this->integerOrFloat($level);
                $fields['grade_scale'] = $this->integerOrFloat($scale);
                $fields['grade_type'] = $type;
                $fields['grade_label'] = $gradeLabel;
                $evidencePaths['platform_grade'] = 'html:hotel_level_aria_label';
            }

            $ratingLabel = $this->firstXPathValue($xpath, [
                '//*[contains(@class,"reviewTop-score-container") and @aria-label][1]/@aria-label',
                '//*[@data-test-id="hotel-score" and @aria-label][1]/@aria-label',
            ]);
            if ($ratingLabel !== null
                && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*out\s+of\s+([0-9]+(?:\.[0-9]+)?)/i', $ratingLabel, $matches) === 1
            ) {
                $fields['rating'] = (float)$matches[1];
                $fields['rating_scale'] = (float)$matches[2];
                $fields['rating_label'] = $ratingLabel;
                $evidencePaths['rating'] = 'html:review_score_aria_label';
            } else {
                $ratingText = $this->firstXPathValue($xpath, [
                    '//*[contains(@class,"reviewTop-score-ctrip")][1]',
                ]);
                if ($ratingText !== null && is_numeric($ratingText)) {
                    $fields['rating'] = (float)$ratingText;
                    $fields['rating_label'] = $ratingText;
                    $evidencePaths['rating'] = 'html:review_score_text';
                }
            }

            $overviewTexts = $this->xpathTexts($xpath, [
                '//*[@data-test-id="hotelOverview-label"]',
                '//*[contains(@class,"hotelOverview-label")]',
            ]);
            foreach ($overviewTexts as $overviewText) {
                if ($fields['room_count'] === null
                    && preg_match('/(?:客房(?:总)?数|房间(?:总)?数|房量)\s*[：:]\s*([0-9]{1,6})/u', $overviewText, $matches) === 1
                ) {
                    $roomCount = (int)$matches[1];
                    if ($roomCount > 0) {
                        $fields['room_count'] = $roomCount;
                        $evidencePaths['room_count'] = 'html:hotel_overview_label';
                    }
                }
                if ($fields['opening_year'] === null
                    && preg_match('/(?:开业|开幕)(?:时间|年份)?\s*[：:]\s*((?:18|19|20)\d{2})/u', $overviewText, $matches) === 1
                ) {
                    $fields['opening_year'] = (int)$matches[1];
                    $evidencePaths['opening_year'] = 'html:hotel_overview_label';
                }
                if ($fields['renovation_year'] === null
                    && preg_match('/(?:装修|翻新)(?:时间|年份)?\s*[：:]\s*((?:18|19|20)\d{2})/u', $overviewText, $matches) === 1
                ) {
                    $fields['renovation_year'] = (int)$matches[1];
                    $evidencePaths['renovation_year'] = 'html:hotel_overview_label';
                }
            }

            $facilities = [];
            foreach (is_array($fields['facilities']) ? $fields['facilities'] : [] as $facility) {
                if (is_string($facility) && $facility !== '') {
                    $facilities[$facility] = true;
                }
            }
            $facilityNodes = $xpath->query('//*[@id and starts-with(@id,"fac_") and @aria-label]/@aria-label');
            if ($facilityNodes !== false) {
                foreach ($facilityNodes as $facilityNode) {
                    $facility = $this->normalizeText((string)$facilityNode->nodeValue);
                    if ($facility !== null && mb_strlen($facility) <= 120) {
                        $facilities[$facility] = true;
                    }
                }
            }
            if ($facilities !== []) {
                $fields['facilities'] = array_slice(array_keys($facilities), 0, self::MAX_STATIC_ITEMS);
                $evidencePaths['facilities'] = isset($evidencePaths['facilities'])
                    ? $evidencePaths['facilities'] . '+html:facility_aria_labels'
                    : 'html:facility_aria_labels';
            }
        }

        $fieldStatuses = $this->buildFieldStatuses($fields);
        $missingFields = array_keys(array_filter(
            $fieldStatuses,
            static fn(string $status): bool => $status === 'missing'
        ));
        $availableCount = count($fieldStatuses) - count($missingFields);
        $coreStatusKeys = [
            'name', 'address', 'platform_grade', 'rating', 'facilities',
            'opening_year', 'renovation_year', 'room_count',
        ];
        $coreMissing = array_filter(
            $coreStatusKeys,
            static fn(string $key): bool => ($fieldStatuses[$key] ?? 'missing') === 'missing'
        );
        $captureStatus = $availableCount === 0
            ? 'collection_failed'
            : ($coreMissing === [] ? 'available' : 'partial');

        return [
            'platform' => 'ctrip',
            'ota_hotel_id' => $otaHotelId,
            'source_method' => self::SOURCE_METHOD,
            'source_url' => $sourceUrl,
            'collected_at' => $collectedAt,
            'capture_status' => $captureStatus,
            'failure_reason' => $availableCount === 0 ? 'parse_failed' : '',
            'profile_schema_version' => self::PROFILE_SCHEMA_VERSION,
            'fields' => $fields,
            'field_statuses' => $fieldStatuses,
            'evidence_paths' => $evidencePaths,
            'missing_fields' => $missingFields,
            'content_hash' => hash('sha256', $html),
            'source_scope' => 'public_ctrip_hotel_page_static_profile',
            'room_count_semantics' => self::ROOM_COUNT_SEMANTICS,
            'scope_notice' => '仅采集公开酒店页可稳定识别的静态档案；不含动态价格、指定日期库存、订单或流量，客房总数也不等于可售库存。',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveOwnHotelBinding(int $systemHotelId): array
    {
        if ($systemHotelId <= 0) {
            throw new \InvalidArgumentException('System hotel ID must be positive.');
        }

        $candidates = [];
        $this->collectPublicBindingCandidates($systemHotelId, $candidates);
        $this->collectPlatformSourceBindingCandidates($systemHotelId, $candidates);
        $this->collectConfigBindingCandidates($systemHotelId, $candidates);
        $this->collectCompetitionSelfBindingCandidates($systemHotelId, $candidates);

        if ($candidates === []) {
            return [
                'status' => 'binding_missing',
                'ota_hotel_id' => null,
                'candidates' => [],
                'warnings' => [],
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $scoreCompare = (int)$right['score'] <=> (int)$left['score'];
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
            return strcmp((string)$right['updated_at'], (string)$left['updated_at']);
        });
        $deduplicated = [];
        foreach ($candidates as $candidate) {
            $id = (string)$candidate['ota_hotel_id'];
            if (!isset($deduplicated[$id])) {
                $deduplicated[$id] = $candidate;
            }
        }
        $candidates = array_values($deduplicated);
        $selected = $candidates[0];

        return [
            'status' => 'bound',
            'ota_hotel_id' => (string)$selected['ota_hotel_id'],
            'source' => (string)$selected['source'],
            'candidates' => array_map(static fn(array $candidate): array => [
                'ota_hotel_id' => (string)$candidate['ota_hotel_id'],
                'source' => (string)$candidate['source'],
                'updated_at' => (string)$candidate['updated_at'],
            ], $candidates),
            'warnings' => count($candidates) > 1 ? ['multiple_binding_candidates_latest_verified_selected'] : [],
        ];
    }

    /**
     * Bind a public Ctrip hotel ID and immediately collect its static profile.
     *
     * @return array<string,mixed>
     */
    public function addByHotelId(
        int $systemHotelId,
        string $otaHotelId,
        string $role,
        int $actorId = 0,
        bool $replace = false
    ): array {
        if ($systemHotelId <= 0) {
            throw new \InvalidArgumentException('System hotel ID must be positive.');
        }
        $otaHotelId = self::normalizeHotelId($otaHotelId);
        if ($otaHotelId === '') {
            throw new \InvalidArgumentException('携程公开酒店ID必须是正整数');
        }
        $role = strtolower(trim($role));
        if (!in_array($role, ['self', 'competitor'], true)) {
            throw new \InvalidArgumentException('角色必须是本店或竞品');
        }

        $hotel = Db::name('hotels')->where('id', $systemHotelId)->find();
        if (!$hotel) {
            throw new RuntimeException('System hotel not found.');
        }

        $binding = $this->resolveOwnHotelBinding($systemHotelId);
        $ownHotelId = self::normalizeHotelId((string)($binding['ota_hotel_id'] ?? ''));
        if ($role === 'self') {
            $binding = $this->saveOwnHotelBinding($systemHotelId, $otaHotelId, $actorId, $replace);
            $this->disableOwnHotelInCompetitorIndex($systemHotelId, $otaHotelId);
        } elseif ($ownHotelId !== '' && hash_equals($ownHotelId, $otaHotelId)) {
            throw new RuntimeException('本店携程酒店ID不能同时添加为竞品');
        }

        $profile = $this->fetchProfile($otaHotelId);
        $profile['role'] = $role;
        $profile['known_name'] = $role === 'self' ? trim((string)($hotel['name'] ?? '')) : '';
        $persistence = $this->persistProfile($systemHotelId, $profile, $role);
        $profile['persistence'] = $persistence;
        $captureStatus = (string)($profile['capture_status'] ?? 'collection_failed');

        return [
            'status' => $captureStatus === 'collection_failed'
                ? 'binding_saved_collection_failed'
                : $captureStatus,
            'system_hotel_id' => $systemHotelId,
            'role' => $role,
            'ota_hotel_id' => $otaHotelId,
            'binding' => $role === 'self' ? $this->resolveOwnHotelBinding($systemHotelId) : $binding,
            'profile' => $profile,
            'profiles' => $this->listProfiles($systemHotelId),
            'profile_schema_version' => self::PROFILE_SCHEMA_VERSION,
            'room_count_semantics' => self::ROOM_COUNT_SEMANTICS,
            'scope_notice' => '携程公开酒店ID已保存；公开页仅补静态档案，客房总数不等于指定日期可售库存。',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function syncForHotel(
        int $systemHotelId,
        string $scope = 'own',
        int $limit = 10,
        bool $force = false
    ): array {
        if (!in_array($scope, ['own', 'competitors', 'all'], true)) {
            throw new \InvalidArgumentException('Profile sync scope must be own, competitors, or all.');
        }
        $limit = max(1, min(30, $limit));
        $hotel = Db::name('hotels')->where('id', $systemHotelId)->find();
        if (!$hotel) {
            throw new RuntimeException('System hotel not found.');
        }

        $binding = $this->resolveOwnHotelBinding($systemHotelId);
        $ownHotelId = is_string($binding['ota_hotel_id'] ?? null) ? (string)$binding['ota_hotel_id'] : '';
        if ($scope === 'own' && $ownHotelId === '') {
            return [
                'status' => 'binding_missing',
                'system_hotel_id' => $systemHotelId,
                'binding' => $binding,
                'requested_count' => 0,
                'fetched_count' => 0,
                'cached_count' => 0,
                'saved_count' => 0,
                'failed_count' => 0,
                'profiles' => [],
                'scope_notice' => '未找到本店携程酒店ID绑定，未发起公开页采集。',
            ];
        }

        $targets = [];
        if (($scope === 'own' || $scope === 'all') && $ownHotelId !== '') {
            $targets[] = [
                'ota_hotel_id' => $ownHotelId,
                'role' => 'self',
                'known_name' => trim((string)($hotel['name'] ?? '')),
            ];
        }
        if ($scope === 'competitors' || $scope === 'all') {
            $competitorLimit = $scope === 'all' ? max(0, $limit - count($targets)) : $limit;
            $targets = array_merge(
                $targets,
                $this->competitionTargets($systemHotelId, $ownHotelId, $competitorLimit)
            );
        }
        $targets = array_slice($targets, 0, $limit);

        $profiles = [];
        $fetchedCount = 0;
        $cachedCount = 0;
        $savedCount = 0;
        $failedCount = 0;
        $partialCount = 0;
        foreach ($targets as $target) {
            $otaHotelId = (string)$target['ota_hotel_id'];
            $role = (string)$target['role'];
            if ($role === 'competitor') {
                $this->upsertCompetitorIndex(
                    $systemHotelId,
                    $otaHotelId,
                    trim((string)($target['known_name'] ?? ''))
                );
            }

            $cached = !$force ? $this->freshProfile($systemHotelId, $otaHotelId) : null;
            if ($cached !== null) {
                $cached['sync_action'] = 'cached';
                $profiles[] = $cached;
                $cachedCount++;
                if (($cached['capture_status'] ?? '') === 'partial') {
                    $partialCount++;
                }
                continue;
            }

            $profile = $this->fetchProfile($otaHotelId);
            $profile['role'] = $role;
            $profile['known_name'] = trim((string)($target['known_name'] ?? ''));
            $persistence = $this->persistProfile($systemHotelId, $profile, $role);
            $profile['persistence'] = $persistence;
            $profile['sync_action'] = 'fetched';
            $profiles[] = $profile;
            $fetchedCount++;
            if (!empty($persistence['readback_verified'])) {
                $savedCount++;
            }
            if (($profile['capture_status'] ?? '') === 'collection_failed') {
                $failedCount++;
            } elseif (($profile['capture_status'] ?? '') === 'partial') {
                $partialCount++;
            }
        }

        $status = $failedCount >= count($profiles) && $profiles !== []
            ? 'collection_failed'
            : (($failedCount > 0 || $partialCount > 0) ? 'partial' : 'available');

        return [
            'status' => $status,
            'system_hotel_id' => $systemHotelId,
            'binding' => $binding,
            'scope' => $scope,
            'requested_count' => count($targets),
            'fetched_count' => $fetchedCount,
            'cached_count' => $cachedCount,
            'saved_count' => $savedCount,
            'failed_count' => $failedCount,
            'partial_count' => $partialCount,
            'profiles' => $profiles,
            'profile_schema_version' => self::PROFILE_SCHEMA_VERSION,
            'room_count_semantics' => self::ROOM_COUNT_SEMANTICS,
            'scope_notice' => '公开资料补充酒店身份、位置、等级、设施、特色、政策、周边与图片链接等静态档案；不采集动态价格或指定日期库存。',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listProfiles(int $systemHotelId, bool $includeHistory = false): array
    {
        if ($systemHotelId <= 0) {
            return [];
        }
        $rows = Db::name('ota_ctrip_entity_snapshots')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'ctrip')
            ->where('entity_type', self::ENTITY_TYPE)
            ->order('data_date', 'desc')
            ->order('last_seen_at', 'desc')
            ->order('id', 'desc')
            ->limit(1000)
            ->select()
            ->toArray();

        $profiles = [];
        foreach ($rows as $row) {
            $otaHotelId = self::normalizeHotelId((string)($row['entity_key'] ?? $row['ota_hotel_id'] ?? ''));
            $captureStatus = strtolower(trim((string)($row['capture_status'] ?? '')));
            if ($otaHotelId === ''
                || in_array($captureStatus, ['collection_failed', 'failed', 'error'], true)
                || (!$includeHistory && isset($profiles[$otaHotelId]))
            ) {
                continue;
            }
            $attributes = json_decode((string)($row['attributes_json'] ?? ''), true);
            if (!is_array($attributes)) {
                $attributes = [];
            }
            if (($attributes['role'] ?? '') === 'archived_self') {
                continue;
            }
            $attributes['snapshot_id'] = (int)($row['id'] ?? 0);
            $attributes['system_hotel_id'] = $systemHotelId;
            $attributes['ota_hotel_id'] = $otaHotelId;
            $attributes['entity_name'] = (string)($row['entity_name'] ?? '');
            $attributes['capture_status'] = (string)($row['capture_status'] ?? ($attributes['capture_status'] ?? ''));
            $attributes['data_date'] = (string)($row['data_date'] ?? '');
            $attributes['last_seen_at'] = (string)($row['last_seen_at'] ?? '');
            $attributes['saved_at'] = (string)($row['update_time'] ?? $row['create_time'] ?? $row['last_seen_at'] ?? '');
            $attributes['persistence_readback_verified'] = true;
            $attributes['persistence_readback_status'] = 'readback_verified';
            $attributes['source_validation_status'] = $this->publicProfileSourceValidationStatus(
                $attributes,
                (string)$attributes['capture_status']
            );
            $attributes['response_ref'] = 'ota_ctrip_entity_snapshots#' . (int)($row['id'] ?? 0);
            $profiles[$includeHistory ? (string)$attributes['snapshot_id'] : $otaHotelId] = $attributes;
        }

        return array_values($profiles);
    }

    private function publicProfileSourceValidationStatus(array $attributes, string $captureStatus): string
    {
        $captureStatus = strtolower(trim($captureStatus));
        $explicit = strtolower(trim((string)($attributes['source_validation_status'] ?? $attributes['validation_status'] ?? '')));
        if (in_array($captureStatus, ['collection_failed', 'failed', 'error'], true)
            || $explicit === 'collection_failed'
        ) {
            return 'collection_failed';
        }
        if ($captureStatus === 'stale' || $explicit === 'stale') {
            return 'stale';
        }
        if ($captureStatus === 'partial' || $explicit === 'partial') {
            return 'partial';
        }
        if ($captureStatus === 'available' && in_array($explicit, ['verified', 'source_verified'], true)) {
            return 'source_verified';
        }
        if ($captureStatus === 'available') {
            if ($explicit === 'unverified') {
                return 'unverified';
            }
            return 'source_observed';
        }

        return in_array($explicit, ['source_observed', 'unverified'], true) ? $explicit : 'unverified';
    }

    /**
     * @return array<string,mixed>
     */
    private function persistProfile(int $systemHotelId, array $profile, string $role): array
    {
        $otaHotelId = self::normalizeHotelId((string)($profile['ota_hotel_id'] ?? ''));
        if ($otaHotelId === '') {
            throw new RuntimeException('Profile persistence requires a Ctrip hotel ID.');
        }
        $collectedAt = $this->normalizeDateTime((string)($profile['collected_at'] ?? '')) ?? $this->now();
        $dataDate = substr($collectedAt, 0, 10);
        $tenantId = (int)(Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id') ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('Profile persistence requires a valid hotel tenant binding.');
        }
        $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : $this->emptyFields();
        $entityName = trim((string)($fields['name'] ?? $profile['known_name'] ?? ''));
        $attributes = $profile;
        unset($attributes['persistence']);
        $attributes['role'] = $role;
        $attributes['room_count_semantics'] = self::ROOM_COUNT_SEMANTICS;
        $attributesJson = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = $this->now();

        $preservedSuccessfulSnapshot = false;
        $snapshotId = Db::transaction(function () use (
            $systemHotelId,
            $tenantId,
            $otaHotelId,
            $dataDate,
            $entityName,
            $attributesJson,
            $profile,
            $role,
            $fields,
            $now,
            &$preservedSuccessfulSnapshot
        ): int {
            $existing = Db::name('ota_ctrip_entity_snapshots')
                ->where('system_hotel_id', $systemHotelId)
                ->where('source', 'ctrip')
                ->where('entity_type', self::ENTITY_TYPE)
                ->where('entity_key', $otaHotelId)
                ->where('data_date', $dataDate)
                ->lock(true)
                ->find();
            $data = [
                'tenant_id' => $tenantId > 0 ? $tenantId : null,
                'system_hotel_id' => $systemHotelId,
                'ota_hotel_id' => $otaHotelId,
                'data_date' => $dataDate,
                'source' => 'ctrip',
                'capture_section' => self::CAPTURE_SECTION,
                'endpoint_id' => self::ENDPOINT_ID,
                'entity_type' => self::ENTITY_TYPE,
                'entity_key' => $otaHotelId,
                'entity_name' => $entityName,
                'attributes_json' => $attributesJson,
                'capture_status' => (string)($profile['capture_status'] ?? 'collection_failed'),
                'last_seen_at' => $now,
                'update_time' => $now,
            ];
            if ($existing) {
                $incomingStatus = strtolower(trim((string)$data['capture_status']));
                $existingStatus = strtolower(trim((string)($existing['capture_status'] ?? '')));
                if (in_array($incomingStatus, ['collection_failed', 'failed', 'error'], true)
                    && in_array($existingStatus, ['available', 'partial', 'verified', 'ok'], true)
                ) {
                    $preservedSuccessfulSnapshot = true;
                    return (int)$existing['id'];
                }
                Db::name('ota_ctrip_entity_snapshots')->where('id', (int)$existing['id'])->update($data);
                $snapshotId = (int)$existing['id'];
            } else {
                $data['first_seen_at'] = $now;
                $data['create_time'] = $now;
                $snapshotId = (int)Db::name('ota_ctrip_entity_snapshots')->insertGetId($data);
            }

            if ($role === 'self') {
                $this->fillEmptySystemHotelFields($systemHotelId, $fields);
            } elseif ($role === 'competitor') {
                $this->upsertCompetitorIndex($systemHotelId, $otaHotelId, $entityName);
            }

            return $snapshotId;
        });

        $readback = Db::name('ota_ctrip_entity_snapshots')->where('id', $snapshotId)->find();
        $readbackAttributes = is_array($readback)
            ? json_decode((string)($readback['attributes_json'] ?? ''), true)
            : null;
        $verified = !$preservedSuccessfulSnapshot
            && is_array($readback)
            && (int)($readback['tenant_id'] ?? 0) === $tenantId
            && (int)($readback['system_hotel_id'] ?? 0) === $systemHotelId
            && (string)($readback['data_date'] ?? '') === $dataDate
            && (string)($readback['source'] ?? '') === 'ctrip'
            && (string)($readback['entity_key'] ?? '') === $otaHotelId
            && (string)($readback['capture_status'] ?? '') === (string)($profile['capture_status'] ?? '')
            && hash_equals($attributesJson, (string)($readback['attributes_json'] ?? ''))
            && is_array($readbackAttributes)
            && (string)($readbackAttributes['ota_hotel_id'] ?? '') === $otaHotelId
            && (int)($readbackAttributes['profile_schema_version'] ?? 0) === self::PROFILE_SCHEMA_VERSION
            && is_array($readbackAttributes['fields'] ?? null);

        return [
            'snapshot_id' => $snapshotId,
            'readback_verified' => $verified,
            'persistence_status' => $preservedSuccessfulSnapshot
                ? 'latest_success_preserved'
                : ($verified ? 'readback_verified' : 'readback_failed'),
            'latest_success_preserved' => $preservedSuccessfulSnapshot,
        ];
    }

    /** @return array<string,mixed>|null */
    private function freshProfile(int $systemHotelId, string $otaHotelId): ?array
    {
        $threshold = date('Y-m-d H:i:s', strtotime($this->now()) - self::FRESH_SECONDS);
        $row = Db::name('ota_ctrip_entity_snapshots')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'ctrip')
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_key', $otaHotelId)
            ->where('capture_status', 'in', ['available', 'partial'])
            ->where('last_seen_at', '>=', $threshold)
            ->order('last_seen_at', 'desc')
            ->find();
        if (!$row) {
            return null;
        }
        $attributes = json_decode((string)($row['attributes_json'] ?? ''), true);
        if (!is_array($attributes)) {
            return null;
        }
        if ((int)($attributes['profile_schema_version'] ?? 0) < self::PROFILE_SCHEMA_VERSION) {
            return null;
        }
        $attributes['snapshot_id'] = (int)$row['id'];
        $attributes['last_seen_at'] = (string)($row['last_seen_at'] ?? '');
        return $attributes;
    }

    /** @return array<int,array<string,string>> */
    private function competitionTargets(int $systemHotelId, string $ownHotelId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $targets = [];
        try {
            $indexedRows = Db::name('competitor_hotel')
                ->where('store_id', $systemHotelId)
                ->where('platform', 'xc')
                ->where('status', 1)
                ->order('update_time', 'desc')
                ->order('id', 'desc')
                ->limit($limit * 3)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            $indexedRows = [];
        }
        foreach ($indexedRows as $row) {
            $otaHotelId = self::normalizeHotelId((string)($row['hotel_code'] ?? ''));
            if ($otaHotelId === '' || $otaHotelId === $ownHotelId || isset($targets[$otaHotelId])) {
                continue;
            }
            $targets[$otaHotelId] = [
                'ota_hotel_id' => $otaHotelId,
                'role' => 'competitor',
                'known_name' => trim((string)($row['hotel_name'] ?? '')),
            ];
            if (count($targets) >= $limit) {
                return array_values($targets);
            }
        }

        $rows = Db::name('online_daily_data')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'ctrip')
            ->where('data_type', 'competitor')
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit(3000)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $otaHotelId = self::normalizeHotelId((string)($row['hotel_id'] ?? ''));
            if ($otaHotelId === '' || $otaHotelId === $ownHotelId || isset($targets[$otaHotelId])) {
                continue;
            }
            $raw = json_decode((string)($row['raw_data'] ?? ''), true);
            $raw = is_array($raw) ? $raw : [];
            $compareType = strtolower(trim((string)($row['compare_type'] ?? $raw['compareType'] ?? $raw['compare_type'] ?? '')));
            $hotelName = trim((string)($row['hotel_name'] ?? $raw['hotelName'] ?? $raw['hotel_name'] ?? ''));
            $normalizedName = strtolower(preg_replace('/\s+/u', '', $hotelName) ?? '');
            if ($compareType === 'self'
                || !empty($raw['isSelf'])
                || !empty($raw['is_self'])
                || in_array($normalizedName, ['我的酒店', '本店', 'myhotel', 'currenthotel'], true)
            ) {
                continue;
            }
            $targets[$otaHotelId] = [
                'ota_hotel_id' => $otaHotelId,
                'role' => 'competitor',
                'known_name' => $hotelName,
            ];
            if (count($targets) >= $limit) {
                break;
            }
        }

        return array_values($targets);
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private function collectPublicBindingCandidates(int $systemHotelId, array &$candidates): void
    {
        $raw = Db::name('system_configs')
            ->where('config_key', self::PUBLIC_BINDING_CONFIG_KEY)
            ->value('config_value');
        if ($raw === null || trim((string)$raw) === '') {
            return;
        }
        $bindings = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($bindings)) {
            throw new RuntimeException('Stored Ctrip public hotel bindings are invalid.');
        }
        $binding = $bindings[(string)$systemHotelId] ?? null;
        if (!is_array($binding)) {
            return;
        }
        $id = self::normalizeHotelId((string)($binding['ota_hotel_id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Stored Ctrip public hotel binding ID is invalid.');
        }
        $storedSystemHotelId = (int)($binding['system_hotel_id'] ?? 0);
        if ($storedSystemHotelId !== $systemHotelId) {
            throw new RuntimeException('Stored Ctrip public hotel binding scope is invalid.');
        }
        $hotelTenantId = (int)(Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id') ?? 0);
        $storedTenantId = (int)($binding['tenant_id'] ?? 0);
        if ($hotelTenantId > 0 && $storedTenantId > 0 && $storedTenantId !== $hotelTenantId) {
            throw new RuntimeException('Stored Ctrip public hotel binding tenant scope is invalid.');
        }
        $candidates[] = [
            'ota_hotel_id' => $id,
            'source' => 'ctrip_public_binding',
            'score' => 120,
            'updated_at' => (string)($binding['updated_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function saveOwnHotelBinding(
        int $systemHotelId,
        string $otaHotelId,
        int $actorId,
        bool $replace
    ): array {
        $tenantId = (int)(Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id') ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('System hotel tenant scope is missing.');
        }
        $now = $this->now();

        Db::transaction(function () use ($systemHotelId, $otaHotelId, $actorId, $replace, $tenantId, $now): void {
            $row = Db::name('system_configs')
                ->where('config_key', self::PUBLIC_BINDING_CONFIG_KEY)
                ->lock(true)
                ->find();
            $bindings = [];
            if ($row && trim((string)($row['config_value'] ?? '')) !== '') {
                $bindings = json_decode((string)$row['config_value'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($bindings)) {
                    throw new RuntimeException('Stored Ctrip public hotel bindings are invalid.');
                }
            }

            foreach ($bindings as $hotelId => $candidate) {
                if (!is_array($candidate)) {
                    throw new RuntimeException('Stored Ctrip public hotel binding entry is invalid.');
                }
                $candidateHotelId = (int)($candidate['system_hotel_id'] ?? $hotelId);
                $candidateOtaHotelId = self::normalizeHotelId((string)($candidate['ota_hotel_id'] ?? ''));
                if ($candidateHotelId !== $systemHotelId
                    && $candidateOtaHotelId !== ''
                    && hash_equals($candidateOtaHotelId, $otaHotelId)
                ) {
                    throw new RuntimeException('该携程公开酒店ID已绑定到另一家系统门店');
                }
            }

            $existing = $bindings[(string)$systemHotelId] ?? null;
            $existingId = is_array($existing)
                ? self::normalizeHotelId((string)($existing['ota_hotel_id'] ?? ''))
                : '';
            if ($existingId !== '' && !hash_equals($existingId, $otaHotelId) && !$replace) {
                throw new RuntimeException('本店已绑定另一个携程公开酒店ID，请确认替换后重试');
            }

            $bindings[(string)$systemHotelId] = [
                'system_hotel_id' => $systemHotelId,
                'tenant_id' => $tenantId,
                'ota_hotel_id' => $otaHotelId,
                'bound_by' => $actorId > 0 ? $actorId : null,
                'updated_at' => $now,
            ];
            $payload = [
                'config_value' => json_encode($bindings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'description' => '携程公开酒店ID与系统门店绑定（不含登录凭据）',
                'update_time' => $now,
            ];
            if ($row) {
                Db::name('system_configs')->where('id', (int)$row['id'])->update($payload);
            } else {
                $payload['config_key'] = self::PUBLIC_BINDING_CONFIG_KEY;
                $payload['create_time'] = $now;
                Db::name('system_configs')->insert($payload);
            }
            if ($existingId !== '' && !hash_equals($existingId, $otaHotelId)) {
                $this->archivePreviousOwnProfile($systemHotelId, $existingId, $otaHotelId, $now);
            }
        });

        return [
            'status' => 'bound',
            'ota_hotel_id' => $otaHotelId,
            'source' => 'ctrip_public_binding',
            'updated_at' => $now,
        ];
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private function collectPlatformSourceBindingCandidates(int $systemHotelId, array &$candidates): void
    {
        try {
            $rows = Db::name('platform_data_sources')
                ->where('system_hotel_id', $systemHotelId)
                ->where('platform', 'ctrip')
                ->where('enabled', 1)
                ->order('update_time', 'desc')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return;
        }
        foreach ($rows as $row) {
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            if (!is_array($config)) {
                continue;
            }
            $id = $this->firstHotelId($config, ['platform_hotel_id', 'ota_hotel_id', 'ctrip_hotel_id', 'ctripHotelId']);
            if ($id === '') {
                continue;
            }
            $candidates[] = [
                'ota_hotel_id' => $id,
                'source' => 'platform_data_sources',
                'score' => in_array((string)($row['status'] ?? ''), ['ready', 'success'], true) ? 110 : 100,
                'updated_at' => (string)($row['update_time'] ?? ''),
            ];
        }
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private function collectConfigBindingCandidates(int $systemHotelId, array &$candidates): void
    {
        try {
            $raw = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
        } catch (\Throwable) {
            return;
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return;
        }
        $list = array_is_list($decoded) ? $decoded : array_values($decoded);
        foreach ($list as $config) {
            if (!is_array($config)) {
                continue;
            }
            $configId = strtolower(trim((string)($config['config_id'] ?? $config['id'] ?? '')));
            if ($configId !== '' && str_contains($configId, '__history')) {
                continue;
            }
            if (trim((string)($config['deleted_at'] ?? '')) !== ''
                || in_array(strtolower(trim((string)($config['credential_status'] ?? ''))), ['revoked', 'deleted'], true)
            ) {
                continue;
            }
            $configuredSystemHotelId = (int)($config['system_hotel_id'] ?? $config['hotel_id'] ?? 0);
            if ($configuredSystemHotelId !== $systemHotelId) {
                continue;
            }
            $id = $this->firstHotelId($config, ['ota_hotel_id', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id']);
            if ($id === '') {
                continue;
            }
            $verified = !empty($config['configuration_verified'])
                || strtolower((string)($config['verification_status'] ?? '')) === 'verified';
            $candidates[] = [
                'ota_hotel_id' => $id,
                'source' => 'ctrip_config_metadata',
                'score' => $verified ? 105 : 95,
                'updated_at' => (string)($config['update_time'] ?? $config['verified_at'] ?? $config['created_at'] ?? ''),
            ];
        }
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private function collectCompetitionSelfBindingCandidates(int $systemHotelId, array &$candidates): void
    {
        try {
            $rows = Db::name('online_daily_data')
                ->where('system_hotel_id', $systemHotelId)
                ->where('source', 'ctrip')
                ->where('data_type', 'competitor')
                ->order('data_date', 'desc')
                ->order('id', 'desc')
                ->limit(300)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return;
        }
        foreach ($rows as $row) {
            $raw = json_decode((string)($row['raw_data'] ?? ''), true);
            $raw = is_array($raw) ? $raw : [];
            $compareType = strtolower(trim((string)($row['compare_type'] ?? $raw['compareType'] ?? $raw['compare_type'] ?? '')));
            $name = trim((string)($row['hotel_name'] ?? $raw['hotelName'] ?? $raw['hotel_name'] ?? ''));
            $normalizedName = strtolower(preg_replace('/\s+/u', '', $name) ?? '');
            $isSelf = $compareType === 'self'
                || !empty($raw['isSelf'])
                || !empty($raw['is_self'])
                || in_array($normalizedName, ['我的酒店', '本店', 'myhotel', 'currenthotel'], true);
            if (!$isSelf) {
                continue;
            }
            $id = self::normalizeHotelId((string)($row['hotel_id'] ?? $raw['hotelId'] ?? $raw['hotel_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $candidates[] = [
                'ota_hotel_id' => $id,
                'source' => 'competition_circle_self_row',
                'score' => 85,
                'updated_at' => (string)($row['update_time'] ?? $row['data_date'] ?? ''),
            ];
            break;
        }
    }

    private function fillEmptySystemHotelFields(int $systemHotelId, array $fields): void
    {
        $hotel = Db::name('hotels')->where('id', $systemHotelId)->lock(true)->find();
        if (!$hotel) {
            return;
        }
        $updates = [];
        foreach (['name', 'address'] as $field) {
            $incoming = trim((string)($fields[$field] ?? ''));
            $existing = trim((string)($hotel[$field] ?? ''));
            if ($incoming !== '' && $existing === '') {
                $updates[$field] = $incoming;
            }
        }
        if ($updates !== []) {
            $updates['update_time'] = $this->now();
            Db::name('hotels')->where('id', $systemHotelId)->update($updates);
        }
    }

    private function disableOwnHotelInCompetitorIndex(int $systemHotelId, string $otaHotelId): void
    {
        try {
            $payload = array_intersect_key([
                'status' => 0,
                'update_time' => $this->now(),
                'updated_at' => $this->now(),
            ], $this->tableColumns('competitor_hotel'));
            if ($payload === []) {
                return;
            }
            Db::name('competitor_hotel')
                ->where('store_id', $systemHotelId)
                ->where('platform', 'xc')
                ->where('hotel_code', $otaHotelId)
                ->update($payload);
        } catch (\Throwable) {
            // Older installations may not have the optional competitor index yet.
        }
    }

    private function archivePreviousOwnProfile(
        int $systemHotelId,
        string $previousOtaHotelId,
        string $replacementOtaHotelId,
        string $archivedAt
    ): void {
        $rows = Db::name('ota_ctrip_entity_snapshots')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'ctrip')
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_key', $previousOtaHotelId)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $attributes = json_decode((string)($row['attributes_json'] ?? ''), true);
            if (!is_array($attributes) || ($attributes['role'] ?? '') !== 'self') {
                continue;
            }
            $attributes['role'] = 'archived_self';
            $attributes['archived_at'] = $archivedAt;
            $attributes['replacement_ota_hotel_id'] = $replacementOtaHotelId;
            Db::name('ota_ctrip_entity_snapshots')
                ->where('id', (int)$row['id'])
                ->update([
                    'attributes_json' => json_encode(
                        $attributes,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'update_time' => $archivedAt,
                ]);
        }
    }

    private function upsertCompetitorIndex(int $systemHotelId, string $otaHotelId, string $hotelName): void
    {
        $otaHotelId = self::normalizeHotelId($otaHotelId);
        if ($otaHotelId === '') {
            return;
        }
        $columns = $this->tableColumns('competitor_hotel');
        if ($columns === [] || !isset($columns['store_id'], $columns['platform'], $columns['hotel_code'])) {
            return;
        }
        $existing = Db::name('competitor_hotel')
            ->where('store_id', $systemHotelId)
            ->where('platform', 'xc')
            ->where('hotel_code', $otaHotelId)
            ->find();
        $now = $this->now();
        $data = [
            'store_id' => $systemHotelId,
            'platform' => 'xc',
            'hotel_code' => $otaHotelId,
            'status' => 1,
            'update_time' => $now,
            'updated_at' => $now,
        ];
        if ($hotelName !== '') {
            $data['hotel_name'] = $hotelName;
        }
        $tenantId = (int)(Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id') ?? 0);
        if (isset($columns['tenant_id']) && $tenantId > 0) {
            $data['tenant_id'] = $tenantId;
        }
        $data = array_intersect_key($data, $columns);
        if ($existing) {
            if ($hotelName === '' && trim((string)($existing['hotel_name'] ?? '')) !== '') {
                unset($data['hotel_name']);
            }
            Db::name('competitor_hotel')->where('id', (int)$existing['id'])->update($data);
            return;
        }
        $data['hotel_name'] = trim((string)($data['hotel_name'] ?? ''));
        $data['city'] = '';
        $data['create_time'] = $now;
        $data['created_at'] = $now;
        Db::name('competitor_hotel')->insert(array_intersect_key($data, $columns));
    }

    /** @return array<string,bool> */
    private function tableColumns(string $table): array
    {
        if (isset($this->tableColumnCache[$table])) {
            return $this->tableColumnCache[$table];
        }
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            $columns = [];
            foreach ($rows as $row) {
                $name = (string)($row['Field'] ?? $row['field'] ?? '');
                if ($name !== '') {
                    $columns[$name] = true;
                }
            }
        } catch (\Throwable) {
            try {
                $rows = Db::query('PRAGMA table_info(`' . str_replace('`', '``', $table) . '`)');
                $columns = [];
                foreach ($rows as $row) {
                    $name = (string)($row['name'] ?? '');
                    if ($name !== '') {
                        $columns[$name] = true;
                    }
                }
            } catch (\Throwable) {
                $columns = [];
            }
        }
        return $this->tableColumnCache[$table] = $columns;
    }

    /** @return array<string,mixed> */
    private function requestPublicPage(string $url, string $expectedOtaHotelId): array
    {
        if ($this->httpRequester === null && !function_exists('curl_init')) {
            return ['http_status' => 0, 'body' => '', 'final_url' => $url, 'error' => 'curl_extension_missing'];
        }

        $currentUrl = $url;
        for ($redirectCount = 0; $redirectCount <= self::MAX_REDIRECTS; $redirectCount++) {
            $validation = $this->validatePublicRequestUrl($currentUrl, $expectedOtaHotelId);
            if (!$validation['allowed']) {
                return [
                    'http_status' => 0,
                    'body' => '',
                    'final_url' => $currentUrl,
                    'error' => $validation['reason'],
                    'failure_reason' => $redirectCount > 0 ? 'unexpected_redirect' : 'network_failure',
                ];
            }

            $response = $this->requestSinglePublicPage($currentUrl, $validation['addresses']);
            $response['final_url'] = $currentUrl;
            if (trim((string)($response['error'] ?? '')) !== '') {
                return $response;
            }

            $httpStatus = (int)($response['http_status'] ?? 0);
            if ($httpStatus < 300 || $httpStatus >= 400) {
                return $response;
            }

            if ($redirectCount >= self::MAX_REDIRECTS) {
                return [
                    'http_status' => $httpStatus,
                    'body' => '',
                    'final_url' => $currentUrl,
                    'error' => 'too_many_redirects',
                    'failure_reason' => 'unexpected_redirect',
                ];
            }

            $location = trim((string)($response['location'] ?? ''));
            $nextUrl = $location !== '' ? $this->resolveRedirectUrl($currentUrl, $location) : null;
            if ($nextUrl === null) {
                return [
                    'http_status' => $httpStatus,
                    'body' => '',
                    'final_url' => $currentUrl,
                    'error' => $location === '' ? 'redirect_location_missing' : 'redirect_location_invalid',
                    'failure_reason' => 'unexpected_redirect',
                ];
            }
            $currentUrl = $nextUrl;
        }

        return [
            'http_status' => 0,
            'body' => '',
            'final_url' => $currentUrl,
            'error' => 'too_many_redirects',
            'failure_reason' => 'unexpected_redirect',
        ];
    }

    /**
     * @param array<int,string> $resolvedAddresses
     * @return array<string,mixed>
     */
    private function requestSinglePublicPage(string $url, array $resolvedAddresses): array
    {
        $resolvedIp = (string)($resolvedAddresses[0] ?? '');
        $body = '';
        $tooLarge = false;
        $location = '';
        $resolveAddress = str_contains($resolvedIp, ':') ? '[' . $resolvedIp . ']' : $resolvedIp;
        $curlOptions = [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [self::PUBLIC_HOST . ':443:' . $resolveAddress],
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: zh-CN,zh;q=0.9',
            ],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                $length = strlen($header);
                $separator = strpos($header, ':');
                if ($separator !== false
                    && strtolower(trim(substr($header, 0, $separator))) === 'location') {
                    $location = trim(substr($header, $separator + 1));
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if ($this->httpRequester !== null) {
            $response = ($this->httpRequester)($url, [
                'resolved_ip' => $resolvedIp,
                'follow_redirects' => false,
                'allowed_protocols' => ['https'],
                'curl_options' => $curlOptions,
            ]);
            if (!is_array($response)) {
                return [
                    'http_status' => 0,
                    'body' => '',
                    'final_url' => $url,
                    'error' => 'invalid_http_requester_result',
                ];
            }
            return [
                'http_status' => (int)($response['http_status'] ?? $response['status'] ?? 0),
                'body' => is_string($response['body'] ?? null) ? (string)$response['body'] : '',
                'final_url' => $url,
                'location' => trim((string)($response['location'] ?? '')),
                'error' => trim((string)($response['error'] ?? '')),
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['http_status' => 0, 'body' => '', 'final_url' => $url, 'error' => 'curl_init_failed'];
        }
        curl_setopt_array($ch, $curlOptions);
        $executed = curl_exec($ch);
        $error = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($tooLarge) {
            $error = 'response_too_large';
        } elseif ($executed === false && $error === '') {
            $error = 'curl_execution_failed';
        }
        return [
            'http_status' => $httpStatus,
            'body' => $body,
            'final_url' => $url,
            'location' => $location,
            'error' => $error,
        ];
    }

    private function isAllowedFinalUrl(string $url, string $expectedOtaHotelId): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($scheme !== 'https'
            || $host !== self::PUBLIC_HOST
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || preg_match('#^/hotels/([1-9][0-9]{0,19})\.html/?$#D', $path, $matches) !== 1
        ) {
            return false;
        }
        return hash_equals($expectedOtaHotelId, (string)$matches[1]);
    }

    /** @return array{allowed:bool,addresses:array<int,string>,reason:string} */
    private function validatePublicRequestUrl(string $url, string $expectedOtaHotelId): array
    {
        if (!$this->isAllowedFinalUrl($url, $expectedOtaHotelId)) {
            return ['allowed' => false, 'addresses' => [], 'reason' => 'public_url_not_allowed'];
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $addresses = $this->resolvePublicHostAddresses($host);
        if ($addresses === []) {
            return ['allowed' => false, 'addresses' => [], 'reason' => 'public_host_resolution_not_public'];
        }

        return ['allowed' => true, 'addresses' => $addresses, 'reason' => ''];
    }

    /** @return array<int,string> */
    private function resolvePublicHostAddresses(string $host): array
    {
        try {
            if ($this->hostResolver !== null) {
                $addresses = ($this->hostResolver)($host);
            } else {
                $addresses = [];
                if (function_exists('dns_get_record')) {
                    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
                    foreach (is_array($records) ? $records : [] as $record) {
                        if (is_array($record) && isset($record['ip'])) {
                            $addresses[] = (string)$record['ip'];
                        }
                        if (is_array($record) && isset($record['ipv6'])) {
                            $addresses[] = (string)$record['ipv6'];
                        }
                    }
                }
                if ($addresses === [] && function_exists('gethostbynamel')) {
                    $ipv4Addresses = @gethostbynamel($host);
                    if (is_array($ipv4Addresses)) {
                        array_push($addresses, ...$ipv4Addresses);
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($addresses) || $addresses === []) {
            return [];
        }
        $validated = [];
        foreach ($addresses as $address) {
            $address = trim((string)$address);
            if (!$this->isPublicIpAddress($address)) {
                return [];
            }
            $validated[$address] = true;
        }
        return array_keys($validated);
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
        if (defined('FILTER_FLAG_GLOBAL_RANGE')
            && filter_var($address, FILTER_VALIDATE_IP, constant('FILTER_FLAG_GLOBAL_RANGE')) === false) {
            return false;
        }

        $ipv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($ipv4 !== false) {
            $packed = inet_pton($address);
            if (is_string($packed)
                && strlen($packed) === 4
                && ord($packed[0]) === 100
                && (ord($packed[1]) & 0xC0) === 0x40) {
                return false;
            }
        }
        return true;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location) === 1) {
            return null;
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        $locationParts = parse_url($location);
        if ($locationParts === false) {
            return null;
        }
        if (isset($locationParts['scheme']) || isset($locationParts['host'])) {
            return $location;
        }

        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts)) {
            return null;
        }
        $basePath = (string)($baseParts['path'] ?? '/');
        $relative = explode('#', $location, 2)[0];
        $query = '';
        $queryPosition = strpos($relative, '?');
        if ($queryPosition !== false) {
            $query = substr($relative, $queryPosition);
            $relative = substr($relative, 0, $queryPosition);
        }
        if ($relative === '') {
            $path = $basePath;
        } elseif (str_starts_with($relative, '/')) {
            $path = $relative;
        } else {
            $directory = str_replace('\\', '/', dirname($basePath));
            $path = rtrim($directory, '/') . '/' . $relative;
        }
        $path = $this->normalizeRedirectPath($path);
        if ($path === null) {
            return null;
        }

        return 'https://' . self::PUBLIC_HOST . $path . $query;
    }

    private function normalizeRedirectPath(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    private function looksBlocked(string $html): bool
    {
        $sample = mb_strtolower(mb_substr(strip_tags($html), 0, 30000));
        foreach (['访问过于频繁', '安全验证', '请输入验证码', 'captcha', 'access denied'] as $marker) {
            if (str_contains($sample, mb_strtolower($marker))) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function failedProfile(
        string $otaHotelId,
        string $sourceUrl,
        string $reason,
        string $collectedAt,
        int $httpStatus = 0,
        string $detail = ''
    ): array {
        $fields = $this->emptyFields();
        return [
            'platform' => 'ctrip',
            'ota_hotel_id' => $otaHotelId,
            'source_method' => self::SOURCE_METHOD,
            'source_url' => $sourceUrl,
            'collected_at' => $collectedAt,
            'http_status' => $httpStatus,
            'capture_status' => 'collection_failed',
            'failure_reason' => $reason,
            'failure_detail' => mb_substr($detail, 0, 160),
            'profile_schema_version' => self::PROFILE_SCHEMA_VERSION,
            'fields' => $fields,
            'field_statuses' => $this->buildFieldStatuses($fields),
            'evidence_paths' => [],
            'missing_fields' => array_keys($this->buildFieldStatuses($fields)),
            'content_hash' => '',
            'source_scope' => 'public_ctrip_hotel_page_static_profile',
            'room_count_semantics' => self::ROOM_COUNT_SEMANTICS,
            'scope_notice' => '公开页采集失败，未以旧值或默认值伪装本次成功。',
        ];
    }

    /** @return array<string,mixed> */
    private function emptyFields(): array
    {
        return [
            'name' => null,
            'name_en' => null,
            'address' => null,
            'city_name' => null,
            'city_id' => null,
            'province_id' => null,
            'country_id' => null,
            'latitude' => null,
            'longitude' => null,
            'map_type' => null,
            'brand_name' => null,
            'hotel_type' => null,
            'star_level' => null,
            'diamond_level' => null,
            'grade_scale' => null,
            'grade_type' => null,
            'grade_label' => null,
            'rating' => null,
            'rating_scale' => null,
            'rating_label' => null,
            'facilities' => null,
            'facility_groups' => null,
            'facility_total_count' => null,
            'facility_free_count' => null,
            'facility_fee_count' => null,
            'highlights' => null,
            'description' => null,
            'description_sections' => null,
            'policies' => null,
            'check_in_time' => null,
            'check_out_time' => null,
            'minimum_check_in_age' => null,
            'nearby_places' => null,
            'cover_image_url' => null,
            'gallery_image_urls' => null,
            'image_count' => null,
            'opening_year' => null,
            'renovation_year' => null,
            'room_count' => null,
        ];
    }

    /** @return array<string,string> */
    private function buildFieldStatuses(array $fields): array
    {
        $gradeAvailable = $fields['star_level'] !== null || $fields['diamond_level'] !== null;
        return [
            'name' => $fields['name'] !== null ? 'available' : 'missing',
            'name_en' => $fields['name_en'] !== null ? 'available' : 'missing',
            'address' => $fields['address'] !== null ? 'available' : 'missing',
            'location' => $fields['city_name'] !== null || ($fields['latitude'] !== null && $fields['longitude'] !== null)
                ? 'available'
                : 'missing',
            'brand_name' => $fields['brand_name'] !== null ? 'available' : 'missing',
            'hotel_type' => $fields['hotel_type'] !== null ? 'available' : 'missing',
            'platform_grade' => $gradeAvailable ? 'available' : 'missing',
            'rating' => $fields['rating'] !== null ? 'available' : 'missing',
            'facilities' => is_array($fields['facilities']) && $fields['facilities'] !== [] ? 'available' : 'missing',
            'facility_summary' => $fields['facility_total_count'] !== null || (is_array($fields['facility_groups']) && $fields['facility_groups'] !== [])
                ? 'available'
                : 'missing',
            'highlights' => is_array($fields['highlights']) && $fields['highlights'] !== [] ? 'available' : 'missing',
            'description' => $fields['description'] !== null ? 'available' : 'missing',
            'policies' => is_array($fields['policies']) && $fields['policies'] !== [] ? 'available' : 'missing',
            'nearby_places' => is_array($fields['nearby_places']) && $fields['nearby_places'] !== [] ? 'available' : 'missing',
            'images' => $fields['cover_image_url'] !== null || (is_array($fields['gallery_image_urls']) && $fields['gallery_image_urls'] !== [])
                ? 'available'
                : 'missing',
            'opening_year' => $fields['opening_year'] !== null ? 'available' : 'missing',
            'renovation_year' => $fields['renovation_year'] !== null ? 'available' : 'missing',
            'room_count' => $fields['room_count'] !== null ? 'available' : 'missing',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function extractNextFlightHotelDetails(DOMDocument $document): array
    {
        $details = [];
        $seen = [];
        foreach ($document->getElementsByTagName('script') as $script) {
            $body = trim((string)$script->textContent);
            if ($body === '' || !str_starts_with($body, 'self.__next_f.push(')) {
                continue;
            }
            if (preg_match('/^self\.__next_f\.push\((.*)\);?$/s', $body, $matches) !== 1) {
                continue;
            }
            $arguments = json_decode($matches[1], true);
            if (!is_array($arguments) || !is_string($arguments[1] ?? null)) {
                continue;
            }
            $payload = preg_replace('/^[A-Za-z0-9]+:/', '', (string)$arguments[1], 1);
            $decoded = json_decode((string)$payload, true);
            if (!is_array($decoded)) {
                continue;
            }
            $this->collectHotelDetailResponses($decoded, $details, $seen);
            if (count($details) >= 20) {
                break;
            }
        }
        return $details;
    }

    /**
     * @param array<int,array<string,mixed>> $details
     * @param array<string,bool> $seen
     */
    private function collectHotelDetailResponses(mixed $node, array &$details, array &$seen, int $depth = 0): void
    {
        if (!is_array($node) || $depth > 16 || count($details) >= 20) {
            return;
        }
        $detail = $node['hotelDetailResponse'] ?? null;
        if (is_array($detail)) {
            $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $identity = is_string($encoded) ? hash('sha256', $encoded) : hash('sha256', serialize(array_keys($detail)));
            if (!isset($seen[$identity])) {
                $seen[$identity] = true;
                $details[] = $detail;
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectHotelDetailResponses($child, $details, $seen, $depth + 1);
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $details
     * @param array<string,mixed> $fields
     * @param array<string,string> $evidencePaths
     */
    private function applyStructuredHotelDetails(array $details, array &$fields, array &$evidencePaths): void
    {
        $facilities = [];
        foreach (is_array($fields['facilities']) ? $fields['facilities'] : [] as $facility) {
            if (is_string($facility) && $facility !== '') {
                $facilities[$facility] = true;
            }
        }
        $facilityGroups = [];
        $highlights = [];
        $descriptionSections = [];
        $policyGroups = [];
        $nearbyPlaces = [];
        $galleryUrls = [];

        foreach ($details as $detail) {
            $base = is_array($detail['hotelBaseInfo'] ?? null) ? $detail['hotelBaseInfo'] : [];
            $position = is_array($detail['hotelPositionInfo'] ?? null) ? $detail['hotelPositionInfo'] : [];
            $description = is_array($detail['hotelDescriptionInfo'] ?? null) ? $detail['hotelDescriptionInfo'] : [];

            $this->setTextFieldIfMissing(
                $fields,
                $evidencePaths,
                'name',
                $this->firstStructuredText($base, [['nameInfo', 'name'], ['hotelName'], ['name']]),
                'next_flight:hotelDetailResponse.hotelBaseInfo.nameInfo.name'
            );
            $this->setTextFieldIfMissing(
                $fields,
                $evidencePaths,
                'name_en',
                $this->firstStructuredText($base, [['nameInfo', 'nameEn'], ['hotelNameEn'], ['nameEn']]),
                'next_flight:hotelDetailResponse.hotelBaseInfo.nameInfo.nameEn'
            );
            $this->setTextFieldIfMissing(
                $fields,
                $evidencePaths,
                'address',
                $this->firstStructuredText($position, [['address']]),
                'next_flight:hotelDetailResponse.hotelPositionInfo.address'
            );
            $this->setTextFieldIfMissing(
                $fields,
                $evidencePaths,
                'city_name',
                $this->firstStructuredText($base, [['cityName']]),
                'next_flight:hotelDetailResponse.hotelBaseInfo.cityName'
            );

            foreach (['city_id' => 'cityId', 'province_id' => 'provinceId', 'country_id' => 'countryId'] as $field => $sourceKey) {
                if ($fields[$field] === null && is_numeric($base[$sourceKey] ?? null) && (int)$base[$sourceKey] > 0) {
                    $fields[$field] = (int)$base[$sourceKey];
                    $evidencePaths[$field] = 'next_flight:hotelDetailResponse.hotelBaseInfo.' . $sourceKey;
                }
            }
            $latitude = is_numeric($position['lat'] ?? null) ? (float)$position['lat'] : null;
            $longitude = is_numeric($position['lng'] ?? null) ? (float)$position['lng'] : null;
            if ($fields['latitude'] === null && $latitude !== null && $latitude >= -90 && $latitude <= 90) {
                $fields['latitude'] = $latitude;
                $evidencePaths['coordinates'] = 'next_flight:hotelDetailResponse.hotelPositionInfo.lat,lng';
            }
            if ($fields['longitude'] === null && $longitude !== null && $longitude >= -180 && $longitude <= 180) {
                $fields['longitude'] = $longitude;
                $evidencePaths['coordinates'] = 'next_flight:hotelDetailResponse.hotelPositionInfo.lat,lng';
            }
            $this->setTextFieldIfMissing(
                $fields,
                $evidencePaths,
                'map_type',
                $this->firstStructuredText($position, [['mapType']]),
                'next_flight:hotelDetailResponse.hotelPositionInfo.mapType'
            );

            $brandName = $this->firstStructuredText($base, [
                ['brandInfo', 'brandName'], ['brandInfo', 'name'], ['brandName'], ['chainInfo', 'brandName'], ['chainInfo', 'name'],
            ]);
            $this->setTextFieldIfMissing(
                $fields,
                $evidencePaths,
                'brand_name',
                $brandName,
                'next_flight:hotelDetailResponse.hotelBaseInfo.brandInfo'
            );
            $hotelType = $this->firstStructuredText($base, [
                ['hotelTypeName'], ['propertyTypeName'], ['hotelCategoryName'], ['hotelType'], ['propertyType'],
            ]);
            if ($hotelType !== null && preg_match('/^[0-9]+$/D', $hotelType) === 1) {
                $hotelType = null;
            }
            $this->setTextFieldIfMissing(
                $fields,
                $evidencePaths,
                'hotel_type',
                $hotelType,
                'next_flight:hotelDetailResponse.hotelBaseInfo.hotelType'
            );

            $starInfo = is_array($base['starInfo'] ?? null) ? $base['starInfo'] : [];
            $gradeLevel = is_numeric($starInfo['level'] ?? null) ? (float)$starInfo['level'] : null;
            $gradeType = strtolower(trim((string)($starInfo['type'] ?? '')));
            if ($gradeLevel !== null && $gradeLevel > 0 && in_array($gradeType, ['star', 'diamond'], true)) {
                $gradeField = $gradeType === 'diamond' ? 'diamond_level' : 'star_level';
                if ($fields[$gradeField] === null) {
                    $fields[$gradeField] = $this->integerOrFloat($gradeLevel);
                    $fields['grade_type'] = $gradeType;
                    $evidencePaths['platform_grade'] = 'next_flight:hotelDetailResponse.hotelBaseInfo.starInfo';
                }
            }

            $highlightList = is_array($base['newHighlights']['list'] ?? null) ? $base['newHighlights']['list'] : [];
            foreach ($highlightList as $highlight) {
                if (!is_array($highlight)) {
                    continue;
                }
                $title = $this->sanitizeStaticText((string)($highlight['tagTitle'] ?? $highlight['title'] ?? ''), 120);
                if ($title !== null) {
                    $highlights[$title] = true;
                }
            }

            $descriptionText = $this->sanitizeStaticText((string)($description['description'] ?? ''), self::MAX_STATIC_TEXT_LENGTH);
            if ($fields['description'] === null && $descriptionText !== null) {
                $fields['description'] = $descriptionText;
                $evidencePaths['description'] = 'next_flight:hotelDetailResponse.hotelDescriptionInfo.description';
            }
            foreach (is_array($description['sectionList'] ?? null) ? $description['sectionList'] : [] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $sectionTitle = $this->sanitizeStaticText((string)($section['title'] ?? ''), 160);
                $sectionDescription = $this->sanitizeStaticText((string)($section['desc'] ?? $section['description'] ?? ''), self::MAX_STATIC_TEXT_LENGTH);
                if ($sectionTitle === null && $sectionDescription === null) {
                    continue;
                }
                $key = hash('sha256', ($sectionTitle ?? '') . '|' . ($sectionDescription ?? ''));
                $descriptionSections[$key] = ['title' => $sectionTitle, 'description' => $sectionDescription];
            }
            foreach (is_array($description['detailDescPopTags'] ?? null) ? $description['detailDescPopTags'] : [] as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $tagType = strtolower(trim((string)($tag['type'] ?? '')));
                if (preg_match('/tel|phone|contact|email/', $tagType) === 1) {
                    continue;
                }
                $texts = [];
                if (is_scalar($tag['value'] ?? null)) {
                    $texts[] = (string)$tag['value'];
                }
                foreach (is_array($tag['values'] ?? null) ? $tag['values'] : [] as $value) {
                    if (is_scalar($value)) {
                        $texts[] = (string)$value;
                    }
                }
                foreach ($texts as $text) {
                    $this->applyOverviewTextFacts(
                        $fields,
                        $evidencePaths,
                        $text,
                        'next_flight:hotelDetailResponse.hotelDescriptionInfo.detailDescPopTags'
                    );
                }
            }

            $facilitySources = [
                $detail['hotelFacilityBelt'] ?? [],
                $detail['hotelFacilityPop'] ?? [],
                $detail['hotelFacilityPopV2'] ?? [],
            ];
            foreach ($facilitySources as $facilitySource) {
                if (!is_array($facilitySource)) {
                    continue;
                }
                $this->collectExactKeyTexts($facilitySource, 'facilityDesc', $facilities, 120);
                $this->collectFacilityGroups($facilitySource, $facilityGroups);
            }
            $facilityBelt = is_array($detail['hotelFacilityBelt'] ?? null) ? $detail['hotelFacilityBelt'] : [];
            $beltTitle = $this->sanitizeStaticText((string)($facilityBelt['title'] ?? ''), 120);
            $beltItems = [];
            $this->collectExactKeyTexts($facilityBelt['facilityList'] ?? [], 'facilityDesc', $beltItems, 120);
            if ($beltTitle !== null && $beltItems !== []) {
                foreach (array_keys($beltItems) as $item) {
                    $facilityGroups[$beltTitle][$item] = true;
                }
            }
            $facilityV2 = is_array($detail['hotelFacilityPopV2'] ?? null) ? $detail['hotelFacilityPopV2'] : [];
            $ubtData = is_array($facilityV2['ubtData'] ?? null) ? $facilityV2['ubtData'] : [];
            $totalFacilityCount = is_numeric($ubtData['totalFacilityCount'] ?? null)
                ? (int)$ubtData['totalFacilityCount']
                : null;
            if ($totalFacilityCount !== null && $totalFacilityCount > 0) {
                foreach (['facility_total_count' => 'totalFacilityCount', 'facility_free_count' => 'freeFacilityCount', 'facility_fee_count' => 'feeFacilityCount'] as $field => $sourceKey) {
                    if (!is_numeric($ubtData[$sourceKey] ?? null) || (int)$ubtData[$sourceKey] < 0) {
                        continue;
                    }
                    $observed = (int)$ubtData[$sourceKey];
                    $fields[$field] = $fields[$field] === null ? $observed : max((int)$fields[$field], $observed);
                    $evidencePaths['facility_summary'] = 'next_flight:hotelDetailResponse.hotelFacilityPopV2.ubtData';
                }
            }

            $policyInfo = is_array($detail['hotelPolicyInfo'] ?? null) ? $detail['hotelPolicyInfo'] : [];
            foreach ($policyInfo as $policyCode => $policy) {
                $policyCode = trim((string)$policyCode);
                if (!is_array($policy)
                    || $policyCode === 'recommendKeys'
                    || preg_match('/tel|phone|contact|email/i', $policyCode) === 1
                ) {
                    continue;
                }
                $title = $this->sanitizeStaticText((string)($policy['title'] ?? $policyCode), 120);
                $items = [];
                $this->collectPolicyTexts($policy, $items);
                if ($title !== null) {
                    unset($items[$title]);
                }
                if ($title === null && $items === []) {
                    continue;
                }
                $policyKey = $policyCode !== '' ? $policyCode : ($title ?? hash('sha256', json_encode($policy)));
                $policyGroups[$policyKey] ??= ['code' => $policyCode, 'title' => $title, 'items' => []];
                foreach (array_keys($items) as $item) {
                    $policyGroups[$policyKey]['items'][$item] = true;
                    if ($fields['check_in_time'] === null
                        && preg_match('/(?:入住|check[ -]?in)[^0-9]{0,20}([0-2]?[0-9]:[0-5][0-9])/iu', $item, $matches) === 1
                    ) {
                        $fields['check_in_time'] = $matches[1];
                        $evidencePaths['check_in_time'] = 'next_flight:hotelDetailResponse.hotelPolicyInfo.checkInAndOut';
                    }
                    if ($fields['check_out_time'] === null
                        && preg_match('/(?:退房|check[ -]?out)[^0-9]{0,20}([0-2]?[0-9]:[0-5][0-9])/iu', $item, $matches) === 1
                    ) {
                        $fields['check_out_time'] = $matches[1];
                        $evidencePaths['check_out_time'] = 'next_flight:hotelDetailResponse.hotelPolicyInfo.checkInAndOut';
                    }
                    if ($fields['minimum_check_in_age'] === null
                        && preg_match('/(?:年满|minimum age[^0-9]{0,8})([0-9]{1,2})\s*(?:岁|years?)/iu', $item, $matches) === 1
                    ) {
                        $age = (int)$matches[1];
                        if ($age > 0 && $age <= 99) {
                            $fields['minimum_check_in_age'] = $age;
                            $evidencePaths['minimum_check_in_age'] = 'next_flight:hotelDetailResponse.hotelPolicyInfo';
                        }
                    }
                }
            }

            $placeInfo = is_array($position['placeInfo'] ?? null) ? $position['placeInfo'] : [];
            $placeRows = is_array($placeInfo['wholePoiInfoList'] ?? null) && $placeInfo['wholePoiInfoList'] !== []
                ? $placeInfo['wholePoiInfoList']
                : (is_array($placeInfo['placeList'] ?? null) ? $placeInfo['placeList'] : []);
            foreach ($placeRows as $place) {
                if (!is_array($place)) {
                    continue;
                }
                $placeName = $this->sanitizeStaticText((string)($place['poiName'] ?? $place['desc'] ?? ''), 160);
                if ($placeName === null) {
                    continue;
                }
                $normalizedPlace = [
                    'name' => $placeName,
                    'type' => $this->sanitizeStaticText((string)($place['type'] ?? ''), 60),
                    'distance' => $this->sanitizeStaticText((string)($place['distance'] ?? ''), 60),
                    'distance_meters' => is_numeric($place['walkDriveDistance'] ?? null)
                        ? round((float)$place['walkDriveDistance'], 2)
                        : null,
                    'travel_mode' => $this->sanitizeStaticText((string)($place['distType'] ?? ''), 60),
                ];
                $nearbyKey = hash('sha256', implode('|', array_map(static fn($value): string => (string)$value, $normalizedPlace)));
                $nearbyPlaces[$nearbyKey] = $normalizedPlace;
                if (count($nearbyPlaces) >= self::MAX_STATIC_ITEMS) {
                    break;
                }
            }

            $coverUrl = $this->normalizePublicAssetUrl((string)($description['image'] ?? ''));
            if ($fields['cover_image_url'] === null && $coverUrl !== null) {
                $fields['cover_image_url'] = $coverUrl;
                $evidencePaths['images'] = 'next_flight:hotelDetailResponse.hotelDescriptionInfo.image';
            }
            $topImages = is_array($detail['hotelTopImage'] ?? null) ? $detail['hotelTopImage'] : [];
            foreach (is_array($topImages['imgUrlList'] ?? null) ? $topImages['imgUrlList'] : [] as $imageItem) {
                $imageValue = is_array($imageItem) ? ($imageItem['imgUrl'] ?? null) : $imageItem;
                if (!is_string($imageValue)) {
                    continue;
                }
                $imageUrl = $this->normalizePublicAssetUrl($imageValue);
                if ($imageUrl !== null) {
                    $galleryUrls[$imageUrl] = true;
                }
            }
            if (is_numeric($topImages['total'] ?? null) && (int)$topImages['total'] > 0) {
                $observedImageCount = (int)$topImages['total'];
                $fields['image_count'] = $fields['image_count'] === null
                    ? $observedImageCount
                    : max((int)$fields['image_count'], $observedImageCount);
                $evidencePaths['images'] = 'next_flight:hotelDetailResponse.hotelTopImage';
            }
        }

        if ($facilities !== []) {
            $fields['facilities'] = array_slice(array_keys($facilities), 0, self::MAX_STATIC_ITEMS);
            $evidencePaths['facilities'] = 'next_flight:hotelDetailResponse.hotelFacility*';
        }
        if ($facilityGroups !== []) {
            $normalizedGroups = [];
            foreach ($facilityGroups as $name => $items) {
                if (!is_array($items) || $items === []) {
                    continue;
                }
                $normalizedGroups[] = [
                    'name' => $name,
                    'facilities' => array_slice(array_keys($items), 0, self::MAX_STATIC_ITEMS),
                ];
                if (count($normalizedGroups) >= 30) {
                    break;
                }
            }
            if ($normalizedGroups !== []) {
                $fields['facility_groups'] = $normalizedGroups;
                $evidencePaths['facility_summary'] = 'next_flight:hotelDetailResponse.hotelFacility*';
            }
        }
        if ($highlights !== []) {
            $fields['highlights'] = array_slice(array_keys($highlights), 0, self::MAX_STATIC_ITEMS);
            $evidencePaths['highlights'] = 'next_flight:hotelDetailResponse.hotelBaseInfo.newHighlights.list';
        }
        if ($descriptionSections !== []) {
            $fields['description_sections'] = array_slice(array_values($descriptionSections), 0, 20);
            $evidencePaths['description_sections'] = 'next_flight:hotelDetailResponse.hotelDescriptionInfo.sectionList';
        }
        if ($policyGroups !== []) {
            $normalizedPolicies = [];
            foreach ($policyGroups as $group) {
                $group['items'] = array_slice(array_keys(is_array($group['items'] ?? null) ? $group['items'] : []), 0, 30);
                $normalizedPolicies[] = $group;
                if (count($normalizedPolicies) >= 30) {
                    break;
                }
            }
            $fields['policies'] = $normalizedPolicies;
            $evidencePaths['policies'] = 'next_flight:hotelDetailResponse.hotelPolicyInfo';
        }
        if ($nearbyPlaces !== []) {
            $fields['nearby_places'] = array_slice(array_values($nearbyPlaces), 0, self::MAX_STATIC_ITEMS);
            $evidencePaths['nearby_places'] = 'next_flight:hotelDetailResponse.hotelPositionInfo.placeInfo';
        }
        if ($galleryUrls !== []) {
            $fields['gallery_image_urls'] = array_slice(array_keys($galleryUrls), 0, 30);
            $fields['image_count'] ??= count($fields['gallery_image_urls']);
            $fields['cover_image_url'] ??= $fields['gallery_image_urls'][0] ?? null;
            $evidencePaths['images'] = 'next_flight:hotelDetailResponse.hotelTopImage';
        }
    }

    /** @param array<string,mixed> $payload */
    private function firstStructuredText(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $payload;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (!is_scalar($value)) {
                continue;
            }
            $normalized = $this->sanitizeStaticText((string)$value, 300);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,string> $evidencePaths
     */
    private function setTextFieldIfMissing(
        array &$fields,
        array &$evidencePaths,
        string $field,
        ?string $value,
        string $evidencePath
    ): void {
        if (($fields[$field] ?? null) === null && $value !== null) {
            $fields[$field] = $value;
            $evidencePaths[$field] = $evidencePath;
        }
    }

    /** @param array<string,bool> $values */
    private function collectExactKeyTexts(mixed $node, string $targetKey, array &$values, int $maxLength, int $depth = 0): void
    {
        if (!is_array($node) || $depth > 12 || count($values) >= self::MAX_STATIC_ITEMS) {
            return;
        }
        foreach ($node as $key => $child) {
            if ((string)$key === $targetKey && is_scalar($child)) {
                $text = $this->sanitizeStaticText((string)$child, $maxLength);
                if ($text !== null) {
                    $values[$text] = true;
                }
            }
            if (is_array($child)) {
                $this->collectExactKeyTexts($child, $targetKey, $values, $maxLength, $depth + 1);
            }
        }
    }

    /** @param array<string,array<string,bool>> $groups */
    private function collectFacilityGroups(mixed $node, array &$groups, int $depth = 0): void
    {
        if (!is_array($node) || $depth > 10 || count($groups) >= 30) {
            return;
        }
        $candidate = null;
        foreach (['facilityInfo', 'facilityList', 'facilities'] as $listKey) {
            if (is_array($node[$listKey] ?? null)) {
                $candidate = $node[$listKey];
                break;
            }
        }
        if (is_array($candidate)) {
            $items = [];
            $this->collectExactKeyTexts($candidate, 'facilityDesc', $items, 120);
            $title = null;
            foreach (['facilityTitle', 'facilityTypeName', 'groupName', 'categoryName', 'title', 'name'] as $titleKey) {
                if (!is_scalar($node[$titleKey] ?? null)) {
                    continue;
                }
                $title = $this->sanitizeStaticText((string)$node[$titleKey], 120);
                if ($title !== null) {
                    break;
                }
            }
            if ($title !== null && $items !== []) {
                foreach (array_keys($items) as $item) {
                    $groups[$title][$item] = true;
                }
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectFacilityGroups($child, $groups, $depth + 1);
            }
        }
    }

    /** @param array<string,bool> $values */
    private function collectPolicyTexts(mixed $node, array &$values, int $depth = 0): void
    {
        if (!is_array($node) || $depth > 10 || count($values) >= 80) {
            return;
        }
        foreach ($node as $key => $child) {
            $key = (string)$key;
            if (preg_match('/phone|tel|contact|email|license|image|img|url|icon|token|trace/i', $key) === 1) {
                continue;
            }
            if (is_scalar($child)
                && preg_match('/description|describe|title|name|text|label|content/i', $key) === 1
            ) {
                $text = $this->sanitizeStaticText((string)$child, 500);
                if ($text !== null) {
                    $values[$text] = true;
                }
            }
            if (is_array($child)) {
                $this->collectPolicyTexts($child, $values, $depth + 1);
            }
        }
    }

    private function normalizePublicAssetUrl(string $value): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        } elseif (str_starts_with($value, 'http://')) {
            $value = 'https://' . substr($value, 7);
        }
        if (!str_starts_with($value, 'https://') || strlen($value) > 1000) {
            return null;
        }
        $host = strtolower((string)parse_url($value, PHP_URL_HOST));
        $allowed = $host === 'tripcdn.com'
            || str_ends_with($host, '.tripcdn.com')
            || $host === 'c-ctrip.com'
            || str_ends_with($host, '.c-ctrip.com')
            || $host === 'ctrip.com'
            || str_ends_with($host, '.ctrip.com')
            || $host === 'ctripcorp.com'
            || str_ends_with($host, '.ctripcorp.com');
        return $allowed ? $value : null;
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,string> $evidencePaths
     */
    private function applyOverviewTextFacts(array &$fields, array &$evidencePaths, string $text, string $path): void
    {
        $text = $this->sanitizeStaticText($text, 300);
        if ($text === null) {
            return;
        }
        if ($fields['room_count'] === null
            && preg_match('/(?:(?:客房|房间)(?:总)?数|房量|rooms?)\s*[：:]?\s*([0-9]{1,6})/iu', $text, $matches) === 1
        ) {
            $roomCount = (int)$matches[1];
            if ($roomCount > 0) {
                $fields['room_count'] = $roomCount;
                $evidencePaths['room_count'] = $path;
            }
        }
        if ($fields['opening_year'] === null
            && preg_match('/(?:开业|开幕|opened?)\D{0,12}((?:18|19|20)\d{2})/iu', $text, $matches) === 1
        ) {
            $fields['opening_year'] = (int)$matches[1];
            $evidencePaths['opening_year'] = $path;
        }
        if ($fields['renovation_year'] === null
            && preg_match('/(?:装修|翻新|renovated?)\D{0,12}((?:18|19|20)\d{2})/iu', $text, $matches) === 1
        ) {
            $fields['renovation_year'] = (int)$matches[1];
            $evidencePaths['renovation_year'] = $path;
        }
    }

    private function sanitizeStaticText(string $value, int $maxLength): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[邮箱已省略]', $value) ?? $value;
        $value = preg_replace('/(?<!\d)(?:\+?86[- ]?)?1[3-9]\d{9}(?!\d)/u', '[联系电话已省略]', $value) ?? $value;
        $value = preg_replace('/(?<!\d)(?:0\d{2,3}[- ]?)?\d{7,8}(?!\d)/u', '[联系电话已省略]', $value) ?? $value;
        $value = $this->normalizeText($value);
        if ($value === null || in_array($value, ['[联系电话已省略]', '[邮箱已省略]'], true)) {
            return null;
        }
        return mb_substr($value, 0, max(1, min($maxLength, self::MAX_STATIC_TEXT_LENGTH)));
    }

    private function firstXPathValue(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }
            foreach ($nodes as $node) {
                $value = $node instanceof DOMNode ? $this->normalizeText((string)$node->nodeValue) : null;
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @return array<int,string> */
    private function xpathTexts(DOMXPath $xpath, array $queries): array
    {
        $texts = [];
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                $value = $node instanceof DOMNode ? $this->normalizeText((string)$node->textContent) : null;
                if ($value !== null) {
                    $texts[$value] = true;
                }
            }
        }
        return array_keys($texts);
    }

    private function normalizeText(string $value): ?string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{0000}-\x{001F}\x{007F}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function firstHotelId(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $id = self::normalizeHotelId((string)($payload[$key] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    private static function normalizeHotelId(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1 ? $value : '';
    }

    private function normalizeDateTime(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function integerOrFloat(float $value): int|float
    {
        return floor($value) === $value ? (int)$value : $value;
    }

    private function now(): string
    {
        $value = $this->clock !== null ? (string)($this->clock)() : date('Y-m-d H:i:s');
        return $this->normalizeDateTime($value) ?? date('Y-m-d H:i:s');
    }
}
