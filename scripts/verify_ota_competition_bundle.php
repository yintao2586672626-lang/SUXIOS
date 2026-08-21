<?php
declare(strict_types=1);

use app\service\OtaCompetitionAnalysisBundleService;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/service/OtaCompetitionAnalysisBundleService.php';

function assertContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Resolve an RFC 6901 JSON Pointer against the canonical bundle root. */
function resolveBundlePointer(array $bundle, string $pointer): mixed
{
    if ($pointer === '') {
        return $bundle;
    }
    assertContract(str_starts_with($pointer, '/'), 'bundle source ref must be a JSON Pointer: ' . $pointer);
    $current = $bundle;
    foreach (explode('/', substr($pointer, 1)) as $encodedToken) {
        $token = str_replace(['~1', '~0'], ['/', '~'], $encodedToken);
        assertContract(
            is_array($current) && array_key_exists($token, $current),
            'bundle source ref does not resolve: ' . $pointer . ' (missing ' . $token . ')'
        );
        $current = $current[$token];
    }
    return $current;
}

function expectDenied(string $edition): void
{
    try {
        OtaCompetitionAnalysisBundleService::assertGenerationAllowed($edition, false);
    } catch (RuntimeException $exception) {
        assertContract(
            $exception->getMessage() === 'flagship_generation_requires_admin',
            $edition . ' must use the stable permission error'
        );
        return;
    }
    throw new RuntimeException($edition . ' must be denied for ordinary users');
}

/** @param class-string<Throwable> $expectedClass */
function expectBuildThrowable(callable $callback, string $expectedClass, string $expectedMessage): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertContract($error instanceof $expectedClass, 'unexpected technical exception type: ' . get_debug_type($error));
        assertContract($error->getMessage() === $expectedMessage, 'unexpected technical exception message');
        return;
    }
    throw new RuntimeException('technical exception must not be disguised as collection_failed');
}

$date = '2026-07-23';
$ctrip = [
    'status' => 'available',
    'context' => [
        'system_hotel_id' => 80,
        'binding_status' => 'bound',
        'latest_fetched_at' => '2026-07-24 08:00:00',
        'platform_hotel_identifier_present' => true,
        'platform_hotel_identifier_source' => 'hotel_id_family',
        'source_evidence' => [
            'status' => 'verified',
            'row_count' => 3,
            'complete_row_count' => 3,
            'source_row_ids' => [101, 102, 103],
            'source_trace_ids' => ['trace-ctrip-business'],
            'source_methods' => ['browser_profile'],
            'latest_collected_at' => '2026-07-24T08:00:00+08:00',
            'missing_fields' => [],
        ],
    ],
    'business_comparison' => [
        'status' => 'available',
        'latest_date' => $date,
        'self' => [
            'amount' => 30000,
            'room_nights' => 100,
            'orders' => 50,
            'adr' => 300,
            'detail_visitors' => 1000,
            'conversion_rate' => 0.05,
        ],
        'competitor_average' => [
            'amount' => 30800,
            'room_nights' => 110,
            'orders' => 55,
            'adr' => 280,
            'detail_visitors' => 1100,
            'conversion_rate' => 0.05,
        ],
        'gaps' => [],
        'competitor_count' => 2,
        'hotels' => [
            [
                'ota_hotel_id' => '00123',
                'hotel_name' => '模拟本店',
                'compare_type' => 'self',
                'adr' => 300,
                'room_nights' => 100,
                'orders' => 50,
                'detail_visitors' => 1000,
                'conversion_rate' => 0.05,
                'quality_status' => 'verified',
            ],
            [
                'ota_hotel_id' => '00456',
                'hotel_name' => '模拟竞品A',
                'compare_type' => 'competitor',
                'adr' => 280,
                'room_nights' => 120,
                'orders' => 60,
                'detail_visitors' => 1300,
                'conversion_rate' => 0.046,
                'quality_status' => 'verified',
            ],
            [
                'ota_hotel_id' => '00789',
                'hotel_name' => '模拟竞品B',
                'compare_type' => 'competitor',
                'adr' => 320,
                'room_nights' => 100,
                'orders' => 50,
                'detail_visitors' => 900,
                'conversion_rate' => 0.056,
                'quality_status' => 'verified',
            ],
        ],
    ],
    'data_coverage' => [
        'decision_eligible_row_count' => 6,
        'excluded_from_decision_count' => 0,
    ],
];
$meituan = [
    'data_status' => 'ok',
    'latest_data_date' => $date,
    'latest_fetched_at' => '2026-07-24 08:05:00',
    'hotel_count' => 3,
    'rank_status' => 'ok',
    'self_position_text' => '第2',
    'top_hotel_name' => '模拟美团TOP1',
    'top_rank' => 1,
    'gap_to_previous_text' => '排名差 1 名；平台未返回指标差额',
    'top1_gap_text' => '落后TOP1 1 名；平台未返回指标差额',
    'rank_trend_text' => '近两次排名持平',
    'platform_tag_text' => '平台标签已返回',
    'target_poi_bound' => true,
    'source_evidence' => [
        'status' => 'verified',
        'row_count' => 3,
        'complete_row_count' => 3,
        'source_row_ids' => [201, 202, 203],
        'source_trace_ids' => ['trace-meituan-rank'],
        'source_methods' => ['browser_profile'],
        'missing_fields' => [],
    ],
];

$service = new OtaCompetitionAnalysisBundleService();
$syntheticLite = $service->buildFromInputs(80, $date, $ctrip, $meituan, [
    'edition' => 'lite',
    'dataset_kind' => 'synthetic',
    'readback_verified' => true,
    'actor_is_admin' => false,
]);
$syntheticFlagship = $service->buildFromInputs(80, $date, $ctrip, $meituan, [
    'edition' => 'flagship',
    'dataset_kind' => 'synthetic',
    'readback_verified' => true,
    'actor_is_admin' => true,
]);

assertContract(
    ($syntheticLite['quality']['status'] ?? '') === 'synthetic',
    'synthetic fixture must remain explicitly labeled'
);
assertContract(
    ($syntheticLite['quality']['decision_eligible'] ?? true) === false,
    'synthetic fixture must never be decision eligible'
);
assertContract(
    ($syntheticLite['recommendations']['items'] ?? ['unexpected']) === [],
    'synthetic fixture must withhold execution recommendations'
);
assertContract(
    ($syntheticLite['recommendations']['auto_write_ota'] ?? true) === false,
    'OTA auto write must stay disabled'
);
assertContract(
    ($syntheticLite['report_document']['status'] ?? '') === 'blocked'
        && ($syntheticLite['content_drafts']['xiaohongshu']['status'] ?? '') === 'withheld',
    'synthetic evidence must not create a publishable report or content draft: '
        . json_encode([
            'report_status' => $syntheticLite['report_document']['status'] ?? null,
            'draft_status' => $syntheticLite['content_drafts']['xiaohongshu']['status'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
assertContract(
    ($syntheticLite['source_fingerprint'] ?? '') === ($syntheticFlagship['source_fingerprint'] ?? 'different'),
    'lite and flagship must read the same calculation fingerprint'
);
assertContract(
    ($syntheticLite['candidate_competitors']['ctrip']['direct'][0]['ota_hotel_id'] ?? '') === '00456',
    'leading-zero OTA hotel IDs must remain strings'
);
assertContract(
    ($syntheticFlagship['render_contract']['generated_by_admin'] ?? false) === true,
    'flagship render contract must record admin generation'
);

$live = $service->buildFromInputs(80, $date, $ctrip, $meituan, [
    'edition' => 'lite',
    'dataset_kind' => 'live',
    'readback_verified' => true,
    'actor_is_admin' => false,
]);
assertContract(
    ($live['quality']['status'] ?? '') === 'available',
    'fully traced live Ctrip and Meituan inputs must be available'
);
assertContract(
    ($live['quality']['eligible_platforms'] ?? []) === ['ctrip', 'meituan'],
    'fully traced live inputs must keep platform-specific eligibility'
);
assertContract(
    count((array)($live['recommendations']['items'] ?? [])) === 2,
    'fully traced live inputs must produce at most one manual action per platform'
);
assertContract(
    ($live['report_document']['status'] ?? '') === 'ready_for_review',
    'verified live evidence must create one interactive report document'
);
assertContract(
    ($live['report_document']['render_contract']['source_fingerprint'] ?? '')
        === ($live['source_fingerprint'] ?? 'different'),
    'report rendering must keep the shared bundle fingerprint'
);
assertContract(
    ($live['report_document']['render_contract']['bundle_id'] ?? '')
        === ($live['bundle_id'] ?? 'different'),
    'report rendering must keep the shared bundle ID'
);
assertContract(
    ($live['report_document']['render_contract']['commercial_release_ready'] ?? true) === false,
    'interactive report must not claim the commercial DOCX/HTML page gates'
);
foreach (['ctrip', 'meituan'] as $platform) {
    $refs = (array)($live['report_document']['platform_sections'][$platform]['source_refs'] ?? []);
    assertContract(
        array_keys($refs) === ['facts', 'derived_metrics', 'analysis', 'candidates', 'quality'],
        $platform . ' report section must expose the complete evidence-ref set'
    );
    foreach ($refs as $kind => $pointer) {
        $resolved = resolveBundlePointer($live, (string)$pointer);
        assertContract(
            is_array($resolved),
            $platform . ' ' . $kind . ' source ref must resolve to a bundle object'
        );
    }
    assertContract(
        resolveBundlePointer($live, (string)$refs['facts']) === ($live['facts'][$platform] ?? null),
        $platform . ' facts source ref must resolve to the exact returned facts object'
    );
    assertContract(
        resolveBundlePointer($live, (string)$refs['analysis']) === ($live['analysis'][$platform] ?? null),
        $platform . ' analysis source ref must resolve to the exact returned analysis object'
    );
    assertContract(
        resolveBundlePointer($live, (string)$refs['quality']) === ($live['quality'] ?? null),
        $platform . ' quality source ref must resolve to the exact returned quality object'
    );
}
foreach ((array)($live['recommendations']['items'] ?? []) as $item) {
    assertContract(is_array($item), 'every recommendation must be an object');
    $platform = (string)($item['platform'] ?? '');
    assertContract(in_array($platform, ['ctrip', 'meituan'], true), 'recommendation platform is invalid');
    $refs = array_values((array)($item['source_refs'] ?? []));
    assertContract(
        $refs === ['/analysis/' . $platform, '/facts/' . $platform, '/quality'],
        $platform . ' recommendation must reference its analysis, facts and shared quality objects'
    );
    foreach ($refs as $pointer) {
        assertContract(
            is_array(resolveBundlePointer($live, (string)$pointer)),
            $platform . ' recommendation source ref must resolve: ' . $pointer
        );
    }
}

$snapshotWithVerifiedMeituan = [
    'input_trust' => ['readback_verified' => true],
    'operation' => ['competitors' => ['meituan_rank_summary' => $meituan]],
];
$knownUnavailable = (new OtaCompetitionAnalysisBundleService(
    null,
    static fn(int $hotelId, string $startDate, string $endDate): array => [
        'status' => 'data_missing',
        'context' => [
            'system_hotel_id' => $hotelId,
            'binding_status' => 'binding_missing',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ],
        'business_comparison' => [],
        'data_coverage' => ['decision_eligible_row_count' => 0],
    ]
))->build(80, $date, $snapshotWithVerifiedMeituan);
assertContract(
    ($knownUnavailable['quality']['status'] ?? '') === 'partial'
        && ($knownUnavailable['quality']['eligible_platforms'] ?? []) === ['meituan']
        && in_array(
            'ctrip_source_missing',
            array_column((array)($knownUnavailable['quality']['data_gaps'] ?? []), 'code'),
            true
        ),
    'explicit Ctrip data_missing must remain a partial bundle with Meituan-only eligibility'
);

expectBuildThrowable(
    static fn(): array => (new OtaCompetitionAnalysisBundleService(
        null,
        static fn(): array => throw new TypeError('ctrip_schema_type_error')
    ))->build(80, $date, $snapshotWithVerifiedMeituan),
    TypeError::class,
    'ctrip_schema_type_error'
);
expectBuildThrowable(
    static fn(): array => (new OtaCompetitionAnalysisBundleService(
        null,
        static fn(): array => throw new RuntimeException('unexpected_ctrip_storage_bug')
    ))->build(80, $date, $snapshotWithVerifiedMeituan),
    RuntimeException::class,
    'unexpected_ctrip_storage_bug'
);
assertContract(
    ($live['content_drafts']['xiaohongshu']['status'] ?? '') === 'ready_for_human_review'
        && count((array)($live['content_drafts']['xiaohongshu']['titles_10'] ?? [])) === 10
        && count((array)($live['content_drafts']['xiaohongshu']['pages_8'] ?? [])) === 8
        && ($live['content_drafts']['xiaohongshu']['auto_publish'] ?? true) === false,
    'verified report must create one complete draft-only Xiaohongshu content packet'
);

$missingCompetitorCount = $ctrip;
unset($missingCompetitorCount['business_comparison']['competitor_count']);
$missingCompetitorCountBundle = $service->buildFromInputs(80, $date, $missingCompetitorCount, $meituan, [
    'edition' => 'lite',
    'dataset_kind' => 'live',
    'readback_verified' => true,
]);
assertContract(
    array_key_exists('competitor_count', $missingCompetitorCountBundle['facts']['ctrip'])
        && $missingCompetitorCountBundle['facts']['ctrip']['competitor_count'] === null,
    'missing competitor count must remain null instead of becoming zero'
);

OtaCompetitionAnalysisBundleService::assertGenerationAllowed('lite', false);
OtaCompetitionAnalysisBundleService::assertGenerationAllowed('flagship', true);
OtaCompetitionAnalysisBundleService::assertGenerationAllowed('both', true);
expectDenied('flagship');
expectDenied('both');

$missingDenominator = $ctrip;
$missingDenominator['business_comparison']['self']['room_nights'] = null;
$missingDenominator['business_comparison']['self']['adr'] = null;
$blocked = $service->buildFromInputs(80, $date, $missingDenominator, [], [
    'edition' => 'lite',
    'dataset_kind' => 'live',
    'readback_verified' => true,
]);
$blockedCodes = array_column((array)($blocked['quality']['data_gaps'] ?? []), 'code');
assertContract(
    in_array('ctrip_price_denominator_missing', $blockedCodes, true),
    'missing denominator must be explicit'
);
assertContract(
    ($blocked['quality']['decision_eligible'] ?? true) === false,
    'missing denominator and Meituan source must block executable advice'
);
assertContract(
    ($blocked['report_document']['status'] ?? '') === 'blocked'
        && ($blocked['content_drafts']['xiaohongshu']['status'] ?? '') === 'withheld'
        && !array_key_exists('post_text', (array)($blocked['content_drafts']['xiaohongshu'] ?? [])),
    'blocked evidence must withhold both report actions and Xiaohongshu copy'
);

echo json_encode([
    'status' => 'passed',
    'fixture' => 'synthetic',
    'ordinary_user_editions' => ['lite'],
    'admin_editions' => ['lite', 'flagship', 'both'],
    'source_fingerprint' => $syntheticLite['source_fingerprint'],
    'candidate_groups' => array_keys($syntheticLite['candidate_competitors']['ctrip']),
    'synthetic_actions' => count($syntheticLite['recommendations']['items']),
    'live_actions' => count($live['recommendations']['items']),
    'live_report_status' => $live['report_document']['status'],
    'live_xiaohongshu_status' => $live['content_drafts']['xiaohongshu']['status'],
    'blocked_report_status' => $blocked['report_document']['status'],
    'blocked_xiaohongshu_status' => $blocked['content_drafts']['xiaohongshu']['status'],
    'blocked_codes' => $blockedCodes,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
