<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Collects public, no-login domestic industry metadata for the global
 * knowledge center. It never reads OTA, PMS or hotel operating data.
 */
final class DomesticPublicKnowledgeSourceService
{
    private const KNOWLEDGE_SOURCE = 'domestic_public_monitor';
    private const CHUNK_TYPE = 'domestic_public_source_snapshot';
    private const USER_AGENT = 'SUXIOS-Public-Knowledge-Monitor/1.0';
    private const MAX_RESPONSE_BYTES = 2_000_000;

    /** @var callable(string, int): array<string, mixed> */
    private $fetcher;

    /** @var array<string, array<string, mixed>> */
    private array $sources;

    /**
     * @param null|callable(string, int): array<string, mixed> $fetcher
     * @param null|array<string, array<string, mixed>> $sources
     */
    public function __construct(?callable $fetcher = null, ?array $sources = null)
    {
        $this->fetcher = $fetcher ?? fn(string $url, int $timeoutSeconds): array =>
            $this->fetchPublicHtml($url, $timeoutSeconds);
        $this->sources = $sources ?? self::defaultSources();
    }

    /** @return array<string, array<string, mixed>> */
    public static function defaultSources(): array
    {
        return [
            'mct_tourism_statistics' => [
                'name' => '文化和旅游部统计信息',
                'url' => 'https://zwgk.mct.gov.cn/zfxxgkml/447/465/index_3081.html',
                'parser' => 'link_list',
                'tier' => 'official_government',
                'discovery_mode' => 'feed',
                'ttl_days' => 120,
                'item_limit' => 10,
                'keywords' => ['旅游', '住宿', '饭店', '酒店', '出游'],
                'url_pattern' => '~t(?<date>\d{8})_\d+\.html~i',
            ],
            'nbs_domestic_demand_statistics' => [
                'name' => '国家统计局国内需求与服务业数据',
                'url' => 'https://www.stats.gov.cn/sj/zxfb/',
                'parser' => 'link_list',
                'tier' => 'official_government',
                'discovery_mode' => 'feed',
                'ttl_days' => 45,
                'item_limit' => 10,
                'keywords' => [
                    '住宿和餐饮',
                    '服务业',
                    '社会消费品',
                    '居民消费',
                    '国内生产总值',
                    '国民经济',
                    '旅游',
                ],
                'url_pattern' => '~t(?<date>\d{8})_\d+\.html~i',
            ],
            'china_hotel_association_reports' => [
                'name' => '中国饭店协会行业报告',
                'url' => 'https://www.chinahotel.org.cn/categories/35',
                'parser' => 'china_hotel_association',
                'tier' => 'national_industry_association',
                'discovery_mode' => 'feed',
                'ttl_days' => 60,
                'item_limit' => 10,
                'keywords' => ['住宿', '饭店', '酒店', '民宿', '客房', '星级'],
            ],
            'samr_platform_rules_2026' => [
                'name' => '市场监管总局网络交易平台规则',
                'url' => 'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/fgs/art/2026/art_85b474fc5a08494bb60ca6a280b98d7d.html',
                'parser' => 'single_article',
                'tier' => 'official_regulation',
                'discovery_mode' => 'version_watch',
                'ttl_days' => 45,
                'item_limit' => 1,
                'keywords' => ['网络交易平台', '平台规则'],
            ],
        ];
    }

    /**
     * @param array<int, string> $sourceKeys
     * @return array<string, mixed>
     */
    public function collect(array $sourceKeys = []): array
    {
        $selected = $this->selectSources($sourceKeys);
        $attemptedAt = $this->now();
        $results = [];

        foreach ($selected as $sourceKey => $source) {
            $results[] = $this->collectSource($sourceKey, $source, $attemptedAt);
        }

        $verified = count(array_filter(
            $results,
            static fn(array $item): bool => ($item['status'] ?? '') === 'verified'
        ));
        $failed = count($results) - $verified;

        return [
            'status' => $verified > 0
                ? ($failed > 0 ? 'partial_success' : 'success')
                : 'collection_failed',
            'attempted_at' => $attemptedAt,
            'verified_source_count' => $verified,
            'failed_source_count' => $failed,
            'sources' => $results,
        ];
    }

    /**
     * @param array<int, string> $sourceKeys
     * @return array<string, mixed>
     */
    public function sync(bool $persist = false, array $sourceKeys = []): array
    {
        $result = $this->collect($sourceKeys);
        $writes = [];

        if ($persist) {
            foreach ($result['sources'] as $sourceResult) {
                $writes[] = $this->persistSourceResult($sourceResult);
            }
        }

        $result['mode'] = $persist ? 'persist' : 'dry_run';
        $result['writes'] = $writes;
        $result['readback'] = $persist
            ? $this->readbackWrites($writes)
            : [
                'verified' => false,
                'reason' => 'dry_run_no_database_write',
                'verified_count' => 0,
                'expected_count' => 0,
                'items' => [],
            ];
        if ($persist && ($result['readback']['verified'] ?? false) !== true) {
            $result['status'] = $result['status'] === 'collection_failed'
                ? 'collection_failed'
                : 'partial_success';
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function collectSource(string $sourceKey, array $source, string $attemptedAt): array
    {
        $base = [
            'source_key' => $sourceKey,
            'source_name' => (string)($source['name'] ?? $sourceKey),
            'source_url' => (string)($source['url'] ?? ''),
            'source_tier' => (string)($source['tier'] ?? 'public_source'),
            'discovery_mode' => (string)($source['discovery_mode'] ?? 'feed'),
            'ttl_days' => max(1, (int)($source['ttl_days'] ?? 60)),
            'attempted_at' => $attemptedAt,
        ];

        try {
            $url = $this->validateSourceUrl((string)($source['url'] ?? ''));
            $response = ($this->fetcher)($url, 20);
            if (($response['ok'] ?? false) !== true) {
                return $base + [
                    'status' => 'collection_failed',
                    'reason' => $this->sanitizeFailureReason(
                        (string)($response['error'] ?? 'public_source_request_failed')
                    ),
                    'http_status' => (int)($response['http_status'] ?? 0),
                    'items' => [],
                ];
            }

            $body = (string)($response['body'] ?? '');
            if ($body === '') {
                throw new RuntimeException('empty_public_source_response');
            }
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('public_source_response_too_large');
            }

            $body = $this->normalizeHtmlEncoding(
                $body,
                (string)($response['content_type'] ?? '')
            );
            $items = match ((string)($source['parser'] ?? '')) {
                'china_hotel_association' => $this->parseChinaHotelAssociation($body, $source),
                'link_list' => $this->parseLinkList($body, $source),
                'single_article' => $this->parseSingleArticle($body, $source),
                default => throw new RuntimeException('unsupported_public_source_parser'),
            };
            if ($items === []) {
                throw new RuntimeException('no_relevant_public_source_items');
            }

            $fingerprintPayload = [
                'source_key' => $sourceKey,
                'items' => $items,
            ];
            $fingerprint = hash(
                'sha256',
                (string)json_encode(
                    $fingerprintPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );

            return $base + [
                'status' => 'verified',
                'reason' => '',
                'http_status' => (int)($response['http_status'] ?? 200),
                'retrieved_at' => $attemptedAt,
                'fingerprint_sha256' => $fingerprint,
                'item_count' => count($items),
                'items' => $items,
            ];
        } catch (Throwable $exception) {
            return $base + [
                'status' => 'collection_failed',
                'reason' => $this->sanitizeFailureReason($exception->getMessage()),
                'http_status' => 0,
                'items' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array<string, string>>
     */
    private function parseChinaHotelAssociation(string $html, array $source): array
    {
        if (preg_match(
            '~var\s+newsPage\s*=\s*(?<json>\{[\s\S]*?\})\s*;\s*var\s+articleCategory~u',
            $html,
            $match
        ) !== 1) {
            throw new RuntimeException('china_hotel_association_feed_not_found');
        }

        $decoded = json_decode((string)$match['json'], true);
        if (!is_array($decoded) || !is_array($decoded['content'] ?? null)) {
            throw new RuntimeException('china_hotel_association_feed_invalid');
        }

        $items = [];
        foreach ($decoded['content'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = $this->cleanText((string)($row['title'] ?? ''), 180);
            $summary = $this->cleanText((string)($row['summary'] ?? ''), 360);
            $titleMatches = $this->matchesKeywords($title, $source);
            $combinedDataReleaseMatches = str_contains($title, '数据发布')
                && $this->matchesKeywords($summary, $source);
            if ($title === '' || (!$titleMatches && !$combinedDataReleaseMatches)) {
                continue;
            }

            $articleUrl = trim((string)($row['outerLink'] ?? ''));
            if ($articleUrl === '') {
                $articleId = (int)($row['id'] ?? 0);
                if ($articleId <= 0) {
                    continue;
                }
                $articleUrl = '/articles/' . $articleId;
            }

            $items[] = [
                'title' => $title,
                'published_at' => $this->normalizePublishedAt(
                    (string)($row['publishDate'] ?? $row['specialDate'] ?? $row['updateDate'] ?? '')
                ),
                'url' => $this->absoluteUrl((string)$source['url'], $articleUrl),
                'summary' => $summary,
                'verification_status' => 'metadata_verified_content_not_interpreted',
            ];
        }

        return $this->finalizeItems($items, (int)($source['item_limit'] ?? 10));
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array<string, string>>
     */
    private function parseLinkList(string $html, array $source): array
    {
        $document = $this->loadHtml($html);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//a[@href]');
        if ($nodes === false) {
            throw new RuntimeException('public_source_link_list_not_found');
        }

        $pattern = (string)($source['url_pattern'] ?? '~t(?<date>\d{8})_\d+\.html~i');
        $items = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            $title = $this->cleanText($node->textContent, 180);
            if ($href === '' || $title === '' || preg_match($pattern, $href, $dateMatch) !== 1) {
                continue;
            }
            if (!$this->matchesKeywords($title, $source)) {
                continue;
            }

            $publishedAt = '';
            $compactDate = (string)($dateMatch['date'] ?? '');
            if (preg_match('/^\d{8}$/', $compactDate) === 1) {
                $publishedAt = substr($compactDate, 0, 4)
                    . '-' . substr($compactDate, 4, 2)
                    . '-' . substr($compactDate, 6, 2);
            }

            $items[] = [
                'title' => $title,
                'published_at' => $publishedAt,
                'url' => $this->absoluteUrl((string)$source['url'], $href),
                'summary' => '',
                'verification_status' => 'metadata_verified_content_not_interpreted',
            ];
        }

        return $this->finalizeItems($items, (int)($source['item_limit'] ?? 10));
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array<string, string>>
     */
    private function parseSingleArticle(string $html, array $source): array
    {
        $document = $this->loadHtml($html);
        $xpath = new DOMXPath($document);

        $title = $this->firstMetaContent($xpath, ['ArticleTitle', 'og:title']);
        if ($title === '') {
            $title = $this->firstNodeText($xpath, ['//h1', '//title']);
        }
        $title = $this->cleanText($title, 180);
        if ($title === '' || !$this->matchesKeywords($title, $source)) {
            throw new RuntimeException('public_source_article_title_not_relevant');
        }

        $publishedAt = $this->normalizePublishedAt(
            $this->firstMetaContent($xpath, ['PubDate', 'publishdate', 'date'])
        );
        if ($publishedAt === ''
            && preg_match('~t(?<date>\d{8})_\d+\.html~i', (string)$source['url'], $dateMatch) === 1
        ) {
            $compactDate = (string)($dateMatch['date'] ?? '');
            $publishedAt = substr($compactDate, 0, 4)
                . '-' . substr($compactDate, 4, 2)
                . '-' . substr($compactDate, 6, 2);
        }

        return [[
            'title' => $title,
            'published_at' => $publishedAt,
            'url' => (string)$source['url'],
            'summary' => $this->cleanText(
                $this->firstMetaContent($xpath, ['description', 'Description']),
                360
            ),
            'verification_status' => 'metadata_verified_content_not_interpreted',
        ]];
    }

    /** @param array<string, mixed> $sourceResult @return array<string, mixed> */
    private function persistSourceResult(array $sourceResult): array
    {
        $sourceKey = (string)($sourceResult['source_key'] ?? '');
        $sourceName = (string)($sourceResult['source_name'] ?? $sourceKey);
        $unitName = '国内公开资料更新｜' . $sourceName;
        $status = (string)($sourceResult['status'] ?? 'collection_failed');
        $now = (string)($sourceResult['attempted_at'] ?? $this->now());

        return Db::transaction(function () use (
            $sourceResult,
            $sourceKey,
            $sourceName,
            $unitName,
            $status,
            $now
        ): array {
            $unit = Db::name('knowledge_units')
                ->where('source', self::KNOWLEDGE_SOURCE)
                ->where('name', $unitName)
                ->order('unit_id', 'desc')
                ->lock(true)
                ->find();

            $unitId = (int)($unit['unit_id'] ?? 0);
            $chunk = $unitId > 0
                ? Db::name('knowledge_chunks')
                    ->where('unit_id', $unitId)
                    ->where('type', self::CHUNK_TYPE)
                    ->order('chunk_id', 'desc')
                    ->lock(true)
                    ->find()
                : null;
            $priorContent = $this->decodeContent($chunk['content'] ?? null);

            if ($status === 'verified') {
                $content = $this->successContent($sourceResult);
                $description = sprintf(
                    '已于%s读取%s条国内公开资料元数据；仅作行业与监管背景，不代表任何酒店经营事实。',
                    $now,
                    (int)($sourceResult['item_count'] ?? 0)
                );
                $unitData = [
                    'hotel_id' => 0,
                    'name' => $unitName,
                    'source' => self::KNOWLEDGE_SOURCE,
                    'status' => 'done',
                    'lifecycle_status' => 'active',
                    'lifecycle_reason' => null,
                    'description' => $description,
                    'tags' => $this->encodeJson([
                        '国内公开资料',
                        '行业背景',
                        '仅元数据',
                        '可重复同步',
                        $sourceName,
                    ]),
                    'created_by' => 0,
                    'reviewed_at' => $now,
                    'known_knowns' => $this->encodeJson([
                        sprintf(
                            '本次已从%s公开页面读取%d条标题、发布日期与链接。',
                            $sourceName,
                            (int)($sourceResult['item_count'] ?? 0)
                        ),
                        '只确认公开页面元数据与来源指纹，不把文章观点自动升级为酒店事实。',
                        '范围仅为中国行业、需求或监管背景，不含携程、美团、PMS及酒店真实经营数据。',
                    ]),
                    'known_unknowns' => $this->encodeJson([
                        '文章正文的方法、样本、统计口径与适用限制尚未逐篇人工复核。',
                        '资料对具体酒店的适用性和经营因果效果，仍需结合经核验的真实数据判断。',
                        (string)($sourceResult['discovery_mode'] ?? '') === 'version_watch'
                            ? '该来源为指定文件版本监测；主管部门发布新网址时仍需更新来源目录。'
                            : '来源页面结构未来可能变化；解析失败时不得用空值覆盖最近成功快照。',
                    ]),
                    'truth_profile_version' => 'public-' . date('Ymd') . '-'
                        . substr((string)($sourceResult['fingerprint_sha256'] ?? ''), 0, 8),
                ];
            } else {
                $failureReason = $this->sanitizeFailureReason(
                    (string)($sourceResult['reason'] ?? 'public_source_collection_failed')
                );
                $content = $priorContent;
                $content['schema_version'] = '1.0';
                $content['source_key'] = $sourceKey;
                $content['source_name'] = $sourceName;
                $content['source_url'] = (string)($sourceResult['source_url'] ?? '');
                $content['last_attempt_at'] = $now;
                $content['last_attempt_status'] = 'collection_failed';
                $content['last_failure_reason'] = $failureReason;
                $content['usage_boundary'] = $this->usageBoundary();
                $content['source_refs'] = is_array($content['source_refs'] ?? null)
                    ? $content['source_refs']
                    : array_values(array_filter([
                        (string)($sourceResult['source_url'] ?? ''),
                    ]));
                $content['module_id'] = 'domestic_public_source_monitor';
                $content['roles'] = ['owner', 'revenue_manager', 'operations_manager'];
                $content['scenes'] = ['industry_context', 'source_review', 'regulatory_watch'];
                $content['platforms'] = [];
                $content['seed_owner'] = 'suxios.domestic_public_source_monitor';
                $content['seed_key'] = 'domestic_public_source_monitor:' . $sourceKey;
                $content['seed_version'] = trim((string)($content['source_version_fingerprint'] ?? '')) !== ''
                    ? 'sha256:' . trim((string)$content['source_version_fingerprint'])
                    : 'collection_failed:' . substr(hash('sha256', $sourceKey . '|' . $now), 0, 16);
                $content['scope'] ??= 'domestic_hotel_industry_context_only';
                $content['evidence_level'] ??= 'collection_failed_no_fact';
                $content['lifecycle_status'] ??= 'stale';
                $content['source_manifest'] ??= [
                    $sourceKey => [
                        'publisher' => $sourceName,
                        'url' => (string)($sourceResult['source_url'] ?? ''),
                        'verification_status' => 'collection_failed',
                        'last_attempt_at' => $now,
                        'last_failure_reason' => $failureReason,
                    ],
                ];

                $lastSuccessAt = trim((string)($content['retrieved_at'] ?? ''));
                $isStale = $lastSuccessAt === ''
                    || $this->isOlderThanDays(
                        $lastSuccessAt,
                        max(1, (int)($sourceResult['ttl_days'] ?? 60))
                    );
                $description = $lastSuccessAt === ''
                    ? '本次国内公开来源抓取失败，未生成资料事实。'
                    : sprintf(
                        '最近一次更新失败；保留%s的已验证快照，未使用空值覆盖。',
                        $lastSuccessAt
                    );
                $unitData = [
                    'hotel_id' => 0,
                    'name' => $unitName,
                    'source' => self::KNOWLEDGE_SOURCE,
                    'status' => $lastSuccessAt === '' ? 'error' : 'done',
                    'lifecycle_status' => $isStale ? 'stale' : 'active',
                    'lifecycle_reason' => $isStale
                        ? 'domestic_public_source_refresh_failed_or_expired'
                        : null,
                    'description' => $description,
                    'tags' => $this->encodeJson([
                        '国内公开资料',
                        '行业背景',
                        '抓取失败已显式标记',
                        $sourceName,
                    ]),
                    'created_by' => 0,
                    'reviewed_at' => $now,
                    'known_knowns' => $this->encodeJson($lastSuccessAt === ''
                        ? ['本次抓取失败，未生成或补造任何公开资料事实。']
                        : [
                            '保留最近一次成功抓取的公开页面元数据快照。',
                            '本次失败状态已记录，未用空值覆盖旧快照。',
                        ]),
                    'known_unknowns' => $this->encodeJson([
                        '当前公开页面是否仍可访问或页面结构是否已经变化。',
                        '失败期间是否出现了尚未收录的新资料。',
                        '任何资料对具体酒店的适用性仍需真实数据验证。',
                    ]),
                    'truth_profile_version' => 'public-failed-' . date('Ymd'),
                ];
            }

            if ($unitId <= 0) {
                $unitData['created_at'] = $now;
                $unitData['updated_at'] = $now;
                $unitId = (int)Db::name('knowledge_units')->insertGetId($unitData);
                $unitAction = 'inserted';
            } else {
                $unitData['updated_at'] = $now;
                Db::name('knowledge_units')->where('unit_id', $unitId)->update($unitData);
                $unitAction = 'updated';
            }

            $chunkData = [
                'unit_id' => $unitId,
                'type' => self::CHUNK_TYPE,
                'content' => $this->encodeJson($content),
                'created_by' => 0,
            ];
            $chunkId = (int)($chunk['chunk_id'] ?? 0);
            if ($chunkId <= 0) {
                $chunkData['created_at'] = $now;
                $chunkId = (int)Db::name('knowledge_chunks')->insertGetId($chunkData);
                $chunkAction = 'inserted';
            } else {
                Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->update($chunkData);
                $chunkAction = 'updated';
            }

            $knowledgeBase = $this->persistKnowledgeBaseMirror(
                $unitName,
                $sourceName,
                $content,
                $status,
                $now
            );

            return [
                'source_key' => $sourceKey,
                'collection_status' => $status,
                'unit_id' => $unitId,
                'unit_action' => $unitAction,
                'chunk_id' => $chunkId,
                'chunk_action' => $chunkAction,
                'knowledge_base_id' => (int)($knowledgeBase['id'] ?? 0),
                'knowledge_base_action' => (string)($knowledgeBase['action'] ?? ''),
                'knowledge_base_title' => $unitName,
            ];
        });
    }

    /**
     * Keep the employee-facing mirror synchronized with the structured unit.
     * The mirror is a concise index, while knowledge_chunks remains the
     * traceable source of truth used by retrieval.
     *
     * @param array<string, mixed> $content
     * @return array{id:int,action:string}
     */
    private function persistKnowledgeBaseMirror(
        string $title,
        string $sourceName,
        array $content,
        string $collectionStatus,
        string $now
    ): array {
        $items = is_array($content['items'] ?? null) ? $content['items'] : [];
        $lines = [
            '# ' . $title,
            '',
            '## 当前状态',
            $collectionStatus === 'verified'
                ? sprintf(
                    '已核验公开页面元数据，共%d条；指纹：%s。',
                    (int)($content['item_count'] ?? 0),
                    (string)($content['fingerprint_sha256'] ?? '')
                )
                : '最近一次同步失败；未生成新事实，保留最近成功快照（如有）。',
            '',
            '## 资料索引',
        ];
        if ($items === []) {
            $lines[] = '- 暂无可用条目。';
        } else {
            foreach (array_slice($items, 0, 10) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $publishedAt = trim((string)($item['published_at'] ?? ''));
                $itemTitle = trim((string)($item['title'] ?? '未命名资料'));
                $url = trim((string)($item['url'] ?? ''));
                $lines[] = '- '
                    . ($publishedAt !== '' ? '[' . $publishedAt . '] ' : '')
                    . $itemTitle
                    . ($url !== '' ? ' — ' . $url : '');
            }
        }
        $lines = array_merge($lines, [
            '',
            '## 使用边界',
            '只用于国内行业、需求和监管背景；正文尚未逐篇复核的条目不得直接转成经营结论。',
            '不得替代携程、美团、PMS或目标酒店同店同日的已验证事实。',
        ]);

        $existing = Db::name('knowledge_base')
            ->where('hotel_id', 0)
            ->where('title', $title)
            ->order('id', 'asc')
            ->lock(true)
            ->find();
        $data = [
            'tenant_id' => 0,
            'hotel_id' => 0,
            'category_id' => 8,
            'title' => $title,
            'content' => implode("\n", $lines),
            'keywords' => implode(',', array_filter([
                '国内公开资料',
                '行业背景',
                '监管',
                $sourceName,
            ])),
            'tags' => $this->encodeJson([
                '国内公开资料',
                '行业背景',
                'metadata_only',
                'source_traceable',
            ]),
            'sort_order' => 0,
            'is_enabled' => 1,
            'update_time' => $now,
        ];

        $id = (int)($existing['id'] ?? 0);
        if ($id <= 0) {
            $data['view_count'] = 0;
            $data['like_count'] = 0;
            $data['create_time'] = $now;
            $id = (int)Db::name('knowledge_base')->insertGetId($data);
            return ['id' => $id, 'action' => 'inserted'];
        }

        Db::name('knowledge_base')->where('id', $id)->update($data);
        return ['id' => $id, 'action' => 'updated'];
    }

    /**
     * @param array<int, array<string, mixed>> $writes
     * @return array<string, mixed>
     */
    private function readbackWrites(array $writes): array
    {
        $items = [];
        $verifiedCount = 0;
        foreach ($writes as $write) {
            $unitId = (int)($write['unit_id'] ?? 0);
            $chunkId = (int)($write['chunk_id'] ?? 0);
            $knowledgeBaseId = (int)($write['knowledge_base_id'] ?? 0);
            $knowledgeBaseTitle = (string)($write['knowledge_base_title'] ?? '');
            $expectedSourceKey = (string)($write['source_key'] ?? '');
            $expectedAttemptStatus = (string)($write['collection_status'] ?? '') === 'verified'
                ? 'verified'
                : 'collection_failed';

            $row = $unitId > 0 && $chunkId > 0
                ? Db::name('knowledge_units')
                    ->alias('ku')
                    ->join('knowledge_chunks kc', 'kc.unit_id = ku.unit_id')
                    ->field(
                        'ku.unit_id,ku.hotel_id,ku.source,ku.status,ku.lifecycle_status,'
                        . 'kc.chunk_id,kc.type,kc.content'
                    )
                    ->where('ku.unit_id', $unitId)
                    ->where('kc.chunk_id', $chunkId)
                    ->find()
                : null;
            $knowledgeBase = $knowledgeBaseId > 0
                ? Db::name('knowledge_base')
                    ->field('id,hotel_id,title,is_enabled')
                    ->where('id', $knowledgeBaseId)
                    ->find()
                : null;
            $content = $this->decodeContent($row['content'] ?? null);
            $verified = is_array($row)
                && is_array($knowledgeBase)
                && (int)($row['unit_id'] ?? 0) === $unitId
                && (int)($row['chunk_id'] ?? 0) === $chunkId
                && (int)($row['hotel_id'] ?? -1) === 0
                && (string)($row['source'] ?? '') === self::KNOWLEDGE_SOURCE
                && (string)($row['type'] ?? '') === self::CHUNK_TYPE
                && (string)($content['source_key'] ?? '') === $expectedSourceKey
                && (string)($content['last_attempt_status'] ?? '') === $expectedAttemptStatus
                && (int)($knowledgeBase['id'] ?? 0) === $knowledgeBaseId
                && (int)($knowledgeBase['hotel_id'] ?? -1) === 0
                && (string)($knowledgeBase['title'] ?? '') === $knowledgeBaseTitle
                && (int)($knowledgeBase['is_enabled'] ?? 0) === 1;
            if ($verified) {
                $verifiedCount++;
            }

            $items[] = [
                'source_key' => $expectedSourceKey,
                'unit_id' => $unitId,
                'chunk_id' => $chunkId,
                'knowledge_base_id' => $knowledgeBaseId,
                'unit_status' => (string)($row['status'] ?? ''),
                'lifecycle_status' => (string)($row['lifecycle_status'] ?? ''),
                'last_attempt_status' => (string)($content['last_attempt_status'] ?? ''),
                'fingerprint_sha256' => (string)($content['fingerprint_sha256'] ?? ''),
                'item_count' => (int)($content['item_count'] ?? 0),
                'verified' => $verified,
            ];
        }

        $expectedCount = count($writes);
        return [
            'verified' => $expectedCount > 0 && $verifiedCount === $expectedCount,
            'reason' => $expectedCount === 0
                ? 'no_database_writes'
                : ($verifiedCount === $expectedCount ? '' : 'database_readback_mismatch'),
            'verified_count' => $verifiedCount,
            'expected_count' => $expectedCount,
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function successContent(array $result): array
    {
        $sourceUrl = (string)($result['source_url'] ?? '');
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $sourceRefs = array_values(array_unique(array_filter(array_merge(
            [$sourceUrl],
            array_map(
                static fn(array $item): string => trim((string)($item['url'] ?? '')),
                array_filter($items, 'is_array')
            )
        ), static fn(string $url): bool => $url !== '')));
        $sourceKey = (string)($result['source_key'] ?? '');
        $fingerprint = (string)($result['fingerprint_sha256'] ?? '');

        return [
            'schema_version' => '1.1',
            'source_key' => $sourceKey,
            'source_name' => (string)($result['source_name'] ?? ''),
            'source_url' => $sourceUrl,
            'source_host' => (string)parse_url($sourceUrl, PHP_URL_HOST),
            'source_tier' => (string)($result['source_tier'] ?? ''),
            'discovery_mode' => (string)($result['discovery_mode'] ?? ''),
            'scope' => 'domestic_hotel_industry_context_only',
            'evidence_level' => 'verified_public_page_metadata',
            'source_refs' => $sourceRefs,
            'source_manifest' => [
                $sourceKey => [
                    'publisher' => (string)($result['source_name'] ?? ''),
                    'url' => $sourceUrl,
                    'source_tier' => (string)($result['source_tier'] ?? ''),
                    'verification_status' => 'metadata_verified_content_not_interpreted',
                    'retrieved_at' => (string)($result['retrieved_at'] ?? ''),
                    'fingerprint_sha256' => $fingerprint,
                ],
            ],
            'module_id' => 'domestic_public_source_monitor',
            'roles' => ['owner', 'revenue_manager', 'operations_manager'],
            'scenes' => ['industry_context', 'source_review', 'regulatory_watch'],
            'platforms' => [],
            'lifecycle_status' => 'active',
            'reviewed_at' => (string)($result['retrieved_at'] ?? ''),
            'retrieved_at' => (string)($result['retrieved_at'] ?? ''),
            'last_attempt_at' => (string)($result['attempted_at'] ?? ''),
            'last_attempt_status' => 'verified',
            'last_failure_reason' => '',
            'fingerprint_sha256' => $fingerprint,
            'source_version_fingerprint' => $fingerprint,
            'seed_owner' => 'suxios.domestic_public_source_monitor',
            'seed_key' => 'domestic_public_source_monitor:' . $sourceKey,
            'seed_version' => $fingerprint !== '' ? 'sha256:' . $fingerprint : 'unverified',
            'item_count' => (int)($result['item_count'] ?? 0),
            'items' => $items,
            'usage_boundary' => $this->usageBoundary(),
            'blocked_uses' => [
                'current_hotel_operating_fact',
                'whole_hotel_revenue_conclusion',
                'automatic_price_or_inventory_write',
                'replace_ota_or_pms_verified_data',
            ],
        ];
    }

    /** @return array<int, string> */
    private function usageBoundary(): array
    {
        return [
            '仅用于国内行业趋势、需求背景、监管变化与待研究资料发现。',
            '不含也不读取携程、美团、PMS或酒店真实经营数据。',
            '标题和摘要不等于正文结论，进入决策前需复核原文、口径和发布时间。',
        ];
    }

    /** @param array<int, string> $sourceKeys @return array<string, array<string, mixed>> */
    private function selectSources(array $sourceKeys): array
    {
        if ($sourceKeys === []) {
            return $this->sources;
        }

        $selected = [];
        foreach (array_values(array_unique($sourceKeys)) as $sourceKey) {
            $sourceKey = trim($sourceKey);
            if ($sourceKey === '' || !isset($this->sources[$sourceKey])) {
                throw new RuntimeException('unknown_domestic_public_source:' . $sourceKey);
            }
            $selected[$sourceKey] = $this->sources[$sourceKey];
        }
        return $selected;
    }

    /** @return array<string, mixed> */
    private function fetchPublicHtml(string $url, int $timeoutSeconds): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'error' => 'curl_init_failed', 'http_status' => 0];
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Cache-Control: no-cache',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $body = curl_exec($curl);
        $errorNumber = curl_errno($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        if ($body === false || $errorNumber !== 0) {
            return [
                'ok' => false,
                'error' => 'network_error_' . $errorNumber,
                'http_status' => $httpStatus,
            ];
        }
        if ($httpStatus !== 200) {
            return [
                'ok' => false,
                'error' => 'http_status_' . $httpStatus,
                'http_status' => $httpStatus,
            ];
        }

        return [
            'ok' => true,
            'body' => (string)$body,
            'http_status' => $httpStatus,
            'content_type' => $contentType,
        ];
    }

    private function validateSourceUrl(string $url): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || trim((string)parse_url($url, PHP_URL_HOST)) === ''
        ) {
            throw new RuntimeException('invalid_domestic_public_source_url');
        }
        return $url;
    }

    private function normalizeHtmlEncoding(string $html, string $contentType): string
    {
        $encoding = '';
        if (preg_match('/charset\s*=\s*["\']?\s*([a-zA-Z0-9_-]+)/i', $contentType, $match) === 1) {
            $encoding = strtoupper((string)$match[1]);
        } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-zA-Z0-9_-]+)/i', $html, $match) === 1) {
            $encoding = strtoupper((string)$match[1]);
        }

        if ($encoding !== '' && !in_array($encoding, ['UTF-8', 'UTF8'], true)) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $converted = @mb_convert_encoding($html, 'UTF-8', 'GB18030,GBK,ISO-8859-1');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return $html;
    }

    private function loadHtml(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded !== true) {
            throw new RuntimeException('public_source_html_parse_failed');
        }
        return $document;
    }

    /** @param array<int, string> $names */
    private function firstMetaContent(DOMXPath $xpath, array $names): string
    {
        $nodes = $xpath->query('//meta[@content]');
        if ($nodes === false) {
            return '';
        }
        $wanted = array_map('strtolower', $names);
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $key = strtolower(trim(
                $node->getAttribute('name')
                ?: $node->getAttribute('property')
            ));
            if (in_array($key, $wanted, true)) {
                return trim($node->getAttribute('content'));
            }
        }
        return '';
    }

    /** @param array<int, string> $queries */
    private function firstNodeText(DOMXPath $xpath, array $queries): string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                return trim((string)$nodes->item(0)?->textContent);
            }
        }
        return '';
    }

    /** @param array<string, mixed> $source */
    private function matchesKeywords(string $text, array $source): bool
    {
        $keywords = is_array($source['keywords'] ?? null) ? $source['keywords'] : [];
        if ($keywords === []) {
            return true;
        }
        foreach ($keywords as $keyword) {
            $keyword = trim((string)$keyword);
            if ($keyword !== '' && mb_stripos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, string>> $items
     * @return array<int, array<string, string>>
     */
    private function finalizeItems(array $items, int $limit): array
    {
        $unique = [];
        foreach ($items as $item) {
            $url = trim((string)($item['url'] ?? ''));
            if ($url === '' || isset($unique[$url])) {
                continue;
            }
            $unique[$url] = $item;
        }
        $items = array_values($unique);
        usort($items, static function (array $left, array $right): int {
            $dateCompare = strcmp(
                (string)($right['published_at'] ?? ''),
                (string)($left['published_at'] ?? '')
            );
            return $dateCompare !== 0
                ? $dateCompare
                : strcmp((string)($left['url'] ?? ''), (string)($right['url'] ?? ''));
        });
        return array_slice($items, 0, max(1, $limit));
    }

    private function absoluteUrl(string $baseUrl, string $href): string
    {
        $href = trim($href);
        if (preg_match('~^https?://~i', $href) === 1) {
            return $href;
        }

        $scheme = (string)parse_url($baseUrl, PHP_URL_SCHEME);
        $host = (string)parse_url($baseUrl, PHP_URL_HOST);
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        $basePath = (string)parse_url($baseUrl, PHP_URL_PATH);
        $baseDirectory = str_ends_with($basePath, '/')
            ? rtrim($basePath, '/')
            : str_replace('\\', '/', dirname($basePath));
        $path = str_starts_with($href, '/')
            ? $href
            : rtrim($baseDirectory, '/') . '/' . $href;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return $scheme . '://' . $host . '/' . implode('/', $segments);
    }

    private function normalizePublishedAt(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable) {
            return preg_match('/(?<date>\d{4}-\d{2}-\d{2})/', $value, $match) === 1
                ? (string)$match['date']
                : '';
        }
    }

    private function cleanText(string $value, int $limit): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        return mb_substr($value, 0, max(1, $limit));
    }

    private function sanitizeFailureReason(string $reason): string
    {
        $reason = strtolower(trim($reason));
        $reason = (string)preg_replace('/[^a-z0-9:_-]+/', '_', $reason);
        return mb_substr($reason !== '' ? $reason : 'public_source_collection_failed', 0, 120);
    }

    /** @return array<string, mixed> */
    private function decodeContent(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(array $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return $encoded;
    }

    private function isOlderThanDays(string $dateTime, int $days): bool
    {
        try {
            $lastSuccess = new DateTimeImmutable($dateTime, new DateTimeZone('Asia/Shanghai'));
            return $lastSuccess < (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
                ->modify('-' . max(1, $days) . ' days');
        } catch (Throwable) {
            return true;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d H:i:s');
    }
}
