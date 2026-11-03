#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

function joydoJson(mixed $value, bool $preserveZeroFraction = false): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        | ($preserveZeroFraction ? JSON_PRESERVE_ZERO_FRACTION : 0));
}
function joydoCheck(bool $ok, string $code): void
{
    if (!$ok) {
        throw new RuntimeException($code);
    }
}
/** Fail on conflicts; do not overwrite existing user edits. */
function joydoReadback(array $definitions, KnowledgeContentDigestService $digest): array
{
    $result = [];
    foreach ($definitions as $definition) {
        $expected = $definition['unit'];
        $units = Db::name('knowledge_units')->where('stable_key', $expected['stable_key'])->select()->toArray();
        joydoCheck(count($units) === 1, 'unit_identity_conflict');
        $unit = $units[0];
        if (isset($definition['correction'])) {
            joydoCheck((int)$unit['unit_id'] === $definition['correction']['unit_id'], 'correction_unit_identity_conflict');
        }
        foreach ($expected as $field => $value) {
            if (in_array($field, ['tags', 'known_knowns', 'known_unknowns'], true)) {
                $actual = is_array($unit[$field]) ? $unit[$field] : json_decode((string)$unit[$field], true, 512, JSON_THROW_ON_ERROR);
                joydoCheck($digest->digest($actual) === $digest->digest(json_decode($value, true, 512, JSON_THROW_ON_ERROR)), 'unit_metadata_conflict:' . $field);
            } else {
                joydoCheck((string)($unit[$field] ?? '') === (string)$value, 'unit_metadata_conflict:' . $field);
            }
        }
        $chunks = Db::name('knowledge_chunks')->where('unit_id', $unit['unit_id'])->select()->toArray();
        $history = null;
        if (isset($definition['previous_definition'])) {
            // Only the five pinned revisions permit one fixed superseded history row.
            joydoCheck(count($chunks) === 2, 'correction_chunk_count_conflict');
            $current = array_values(array_filter($chunks, static fn(array $row): bool => (int)$row['chunk_id'] === (int)$unit['current_chunk_id']));
            $old = array_values(array_filter($chunks, static fn(array $row): bool => (int)$row['chunk_id'] === $definition['correction']['previous_chunk_id']));
            joydoCheck(count($current) === 1 && count($old) === 1 && (int)$current[0]['chunk_id'] !== (int)$old[0]['chunk_id'], 'correction_chunk_identity_conflict');
            $chunk = $current[0];
            $history = $old[0];
            $oldContent = is_array($history['content']) ? $history['content'] : json_decode((string)$history['content'], true, 512, JSON_THROW_ON_ERROR);
            joydoCheck($digest->digest($oldContent) === $definition['correction']['expected_previous_content_digest']
                && $digest->digest($oldContent) === $digest->digest($definition['previous_definition']['content'])
                && $history['content_digest'] === $definition['correction']['expected_previous_content_digest']
                && $history['type'] === $definition['type'] && (int)$history['created_by'] === 0
                && $history['lifecycle_status'] === 'superseded' && (int)$history['superseded_by_chunk_id'] === (int)$chunk['chunk_id']
                && trim((string)($history['retired_at'] ?? '')) !== ''
                && (int)$chunk['version_no'] === 2 && (int)($chunk['superseded_by_chunk_id'] ?? 0) === 0, 'correction_history_conflict');
        } else {
            joydoCheck(count($chunks) === 1, 'chunk_count_conflict');
            $chunk = $chunks[0];
            if (isset($definition['correction'])) {
                joydoCheck((int)$chunk['chunk_id'] === $definition['correction']['previous_chunk_id']
                    && $chunk['content_digest'] === $definition['correction']['expected_previous_content_digest']
                    && (int)($chunk['superseded_by_chunk_id'] ?? 0) === 0, 'correction_previous_identity_conflict');
            }
        }
        $content = is_array($chunk['content']) ? $chunk['content'] : json_decode((string)$chunk['content'], true, 512, JSON_THROW_ON_ERROR);
        joydoCheck($digest->digest($content) === $digest->digest($definition['content']), 'content_conflict');
        joydoCheck($digest->matches((string)$chunk['content_digest'], $content), 'digest_conflict');
        joydoCheck((int)$unit['current_chunk_id'] === (int)$chunk['chunk_id'], 'current_chunk_conflict');
        joydoCheck($chunk['type'] === $definition['type'] && $chunk['lifecycle_status'] === 'active' && (int)$chunk['created_by'] === 0, 'chunk_metadata_conflict');
        $result[] = ['key' => $definition['key'], 'unit' => $unit, 'chunk' => $chunk, 'history' => $history];
    }
    return $result;
}

try {
    $options = getopt('', ['persist', 'verify', 'profile:']);
    joydoCheck(!(isset($options['persist']) && isset($options['verify'])), 'choose_one_mode');
    $root = dirname(__DIR__);
    $profile = $options['profile'] ?? 'overview';
    joydoCheck(is_string($profile) && in_array($profile, ['overview', 'cost', 'other', 'measurement', 'price_list', 'price_items'], true), 'unknown_profile');
    $isMeasurement = $profile === 'measurement';
    $isPriceItems = $profile === 'price_items';
    // Both quotation profiles use the same immutable source checks and authority boundaries.
    $isPriceList = $profile === 'price_list' || $isPriceItems;
    $isCost = $profile === 'cost';
    $isOther = $profile === 'other';
    $relative = $isOther ? 'docs/knowledge/joydo-jhira-other-20261001/' : ($isCost ? 'docs/knowledge/joydo-jhira-cost-20261001/' : 'docs/knowledge/joydo-jhira-v28-20261001/');
    $packVersion = $isOther ? 'joydo-jhira-other-reference-20261001-v1' : ($isCost ? 'joydo-jhira-cost-reference-20261001-v1' : 'joydo-jhira-v28-reference-20261001-v1');
    $allowedKeys = $isOther ? ['foundation_readiness', 'debt_service', 'target_contract', 'ota_comparability', 'report_snapshot', 'geo_evidence']
        : ($isCost ? ['consumption_basis', 'denominators_dedup', 'selection_review'] : ['cashflow_payback', 'ota_visibility', 'report_readiness']);
    $unitSource = $isOther ? 'joydo_jhira_other_modules_reference' : ($isCost ? 'joydo_jhira_hotel_cost_reference' : 'joydo_jhira_website_reference');
    $stablePrefix = $isOther ? 'global:joydo_jhira:other:v28:20261001:' : ($isCost ? 'global:joydo_jhira:cost:v28:20261001:' : 'global:joydo_jhira:v28:20261001:');
    $seedOwner = $isOther ? 'suxios.joydo_jhira_other_reference' : ($isCost ? 'suxios.joydo_jhira_cost_reference' : 'suxios.joydo_jhira_reference');
    $tag = $isOther ? '酒店板块深学' : ($isCost ? '酒店成本深学' : '经营初筛');
    $truthVersion = $isOther ? 'joydo-other-ref-20261001-v1' : ($isCost ? 'joydo-cost-ref-20261001-v1' : 'joydo-v28-ref-20261001-v1');
    $known = $isOther ? '可见基础、融资回本、OTA与报告及GEO公开页面；本地合同回放；源站保存和非零融资缺证，正式功能未吸纳。'
        : ($isCost ? '合作伙伴可见成本页面观察与本地条件回放；耗用基准及切换传播仍缺证；未正式集成。' : '合作伙伴角色可见页面观察；来源与保留证据指纹可追溯；未正式吸纳。');
    if ($isMeasurement) {
        $relative = 'docs/knowledge/consumables-measurement-20261001/';
        $packVersion = 'consumables-measurement-reference-20261001-v1';
        $allowedKeys = ['package_dilution_units', 'usage_measurement_protocol', 'service_event_conversion', 'loss_reason_evidence'];
        $unitSource = 'official_method_reference';
        $stablePrefix = 'global:consumables:measurement:20261001:';
        $seedOwner = 'suxios.consumables_measurement_reference';
        $tag = '易耗品计量方法';
        $truthVersion = 'cons-measure-ref-20261001-v1';
        $known = '官方及第一手计量材料已读；中文摘要与来源映射保留。事件换算和损耗分类为明确标注的应用推导，非酒店实耗或默认系数。';
    }
    if ($isPriceList) {
        $relative = 'docs/knowledge/consumables-price-list-20261001/';
        $packVersion = 'consumables-price-list-reference-20261001-v1';
        $allowedKeys = ['quote_catalog', 'tier_configuration', 'comparison_scope', 'adoption_gaps'];
        $unitSource = 'user_supplied_procurement_quote_reference';
        $stablePrefix = 'global:consumables:price:bf8aa1e0290b:20261001:';
        $seedOwner = 'suxios.consumables_price_list_reference';
        $tag = '采购推荐价参考';
        $truthVersion = 'cons-price-ref-20261001-v1';
        $known = '用户提供的采购价目及档位推荐；原工作簿与单元格溯源可核对。无供应商和报价有效期，不是已成交采购价、本店实耗或自动选型授权。';
    }
    if ($isPriceItems) {
        $packVersion = 'consumables-price-items-reference-20261001-v1';
        $allowedKeys = ['item_01', 'item_02', 'item_03', 'item_04', 'item_05', 'item_06', 'item_07', 'item_08', 'item_09',
            'item_10', 'item_11', 'item_12', 'item_13', 'item_14', 'item_15', 'item_16', 'item_17', 'item_18', 'item_19',
            'item_20', 'item_21', 'item_22', 'item_23', 'item_24', 'item_25', 'item_26', 'item_27'];
        $stablePrefix .= 'item:';
        $seedOwner = 'suxios.consumables_price_items_reference';
        $truthVersion = 'cons-price-item-ref-20261001-v1';
    }
    joydoCheck(strlen($truthVersion) <= 32, 'truth_version_length');
    $base = $root . '/' . $relative;
    $manifest = json_decode((string)file_get_contents($base . 'source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $pack = json_decode((string)file_get_contents($base . ($isPriceItems ? 'item-knowledge-pack.json' : 'knowledge-pack.json')), true, 512, JSON_THROW_ON_ERROR);
    $unitReviewedAt = $pack['reviewed_at'];
    $unitReviewDueAt = $pack['review_due_at'];
    if ($isPriceList) {
        // DATE-only source metadata remains unchanged in content; DATETIME columns need canonical readback values.
        foreach (['reviewed_at', 'review_due_at'] as $field) {
            $value = $pack[$field];
            $format = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 ? 'Y-m-d' : 'Y-m-d H:i:s';
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            joydoCheck($date !== false && $date->format($format) === $value, 'quote_review_date_invalid');
            if ($field === 'reviewed_at') {
                $unitReviewedAt = $date->format('Y-m-d H:i:s');
            } else {
                $unitReviewDueAt = $date->format('Y-m-d H:i:s');
            }
        }
    }
    if ($isMeasurement) {
        joydoCheck($manifest['source_kind'] === 'official_and_first_party_method_reference'
            && $manifest['extracted_on'] === '2026-10-01'
            && $manifest['version_label'] === $packVersion
            && $manifest['upstream_content_retained'] === false
            && $manifest['contains_current_hotel_fact'] === false, 'source_identity_conflict');
        $officialUrls = [
            'https://www.nist.gov/document/2026-hb-133-chapter-2',
            'https://www.nist.gov/document/2026-hb-133-chapter-3',
            'https://www.epa.gov/sites/default/files/2013-08/documents/ece_curriculum_final.pdf',
            'https://physics.nist.gov/cuu/Uncertainty/typea.html',
            'https://www.itl.nist.gov/div898/handbook/mpc/section5/mpc5311.htm',
            'https://sustainablehospitalityalliance.org/wp-content/uploads/2020/02/HWMIME1.pdf',
            'https://sustainablehospitalityalliance.org/resource/hwmm/',
            'https://www.epa.gov/sustainable-management-food/resources-assessing-wasted-food',
        ];
        joydoCheck(array_column($manifest['sources'], 'url') === $officialUrls, 'official_source_refs_conflict');
    } elseif ($isPriceList) {
        $quoteSha256 = 'BF8AA1E0290BCF7F6464D6FFA63BDB653C1E11745A816EA53F517E2FC3EBA4F0';
        joydoCheck($manifest['source_kind'] === 'user_supplied_procurement_price_recommendation'
            && $manifest['source_filename'] === '1001酒店易耗品采购价目表（各档位推荐）(1).xlsx'
            && $manifest['source_sha256'] === $quoteSha256
            && $manifest['extracted_on'] === '2026-10-01'
            && $manifest['version_label'] === ($isPriceItems ? 'consumables-price-list-reference-20261001-v1' : $packVersion)
            && array_key_exists('quote_effective_date', $manifest) && $manifest['quote_effective_date'] === null
            && array_key_exists('supplier', $manifest) && $manifest['supplier'] === null
            && $manifest['contains_current_hotel_fact'] === false, 'quote_source_identity_conflict');
    } else {
        joydoCheck($manifest['source_url'] === 'https://joydo.us.ci/index.full' && $manifest['version_label'] === 'V2.8 / JHIRA-100', 'source_identity_conflict');
    }
    joydoCheck($pack['version'] === $packVersion && array_column($pack['entries'], 'key') === $allowedKeys, 'pack_identity_conflict');
    $allowedFiles = $isOther ? ['sources/observations.md', 'sources/report-readiness.jpg', 'sources/geo-public.jpg', 'sources/replay-fixture.json', 'README.md', 'knowledge-pack.json']
        : ($isCost ? ['sources/observations.md', 'sources/cost-switch-observed.jpg', 'sources/replay-fixture.json', 'README.md', 'knowledge-pack.json']
        : ['sources/observations.md', 'sources/visible-page.jpg', 'README.md', 'knowledge-pack.json']);
    if ($isMeasurement) {
        $allowedFiles = ['sources/method-review.md', 'README.md', 'knowledge-pack.json'];
    }
    if ($isPriceList) {
        $allowedFiles = ['source.xlsx', 'extracted.json', 'catalog.json', 'README.md', 'knowledge-pack.json'];
    }
    joydoCheck(array_column($manifest['retained_files'], 'file') === $allowedFiles, 'source_paths_conflict');
    $refs = $isPriceList ? [] : ($isMeasurement ? $officialUrls : [$manifest['source_url']]);
    foreach ($manifest['retained_files'] as $source) {
        joydoCheck(is_file($base . $source['file']) && strtoupper((string)hash_file('sha256', $base . $source['file'])) === $source['sha256'], 'source_hash_conflict');
        $refs[] = 'repo://' . $relative . $source['file'] . '#sha256=' . $source['sha256'];
    }
    $catalog = null;
    if ($isPriceList) {
        joydoCheck($manifest['retained_files'][0]['sha256'] === $quoteSha256
            && strtoupper((string)hash_file('sha256', $base . 'source.xlsx')) === $quoteSha256, 'quote_workbook_hash_conflict');
        $catalog = json_decode((string)file_get_contents($base . 'catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        joydoCheck($catalog['schema_version'] === 'consumables-price-catalog.v1'
            && strtoupper($catalog['source_sha256']) === $quoteSha256
            && is_array($catalog['items']) && count($catalog['items']) === 27
            && is_array($catalog['tiers']) && count($catalog['tiers']) === 5
            && is_array($catalog['recommendations']) && count($catalog['recommendations']) === 12, 'quote_catalog_identity_conflict');
        $priceCells = 0;
        $seenQuoteCells = [];
        $catalogNames = [];
        $catalogQuotes = [];
        foreach ($catalog['items'] as $item) {
            joydoCheck(is_string($item['name']) && trim($item['name']) !== ''
                && preg_match('/^[A-Z]{1,3}[1-9][0-9]*$/D', $item['name_cell']) === 1
                && is_array($item['quotes']) && count($item['quotes']) === 5, 'quote_catalog_row_conflict');
            $catalogNames[] = $item['name'] . '(' . $item['name_cell'] . ')';
            foreach ($item['quotes'] as $quote) {
                joydoCheck(preg_match('/^[A-Z]{1,3}[1-9][0-9]*$/D', $quote['source_cell']) === 1
                    && !isset($seenQuoteCells[$quote['source_cell']])
                    && array_key_exists('raw_text', $quote)
                    && array_key_exists('currency', $quote) && $quote['currency'] === null
                    && array_key_exists('price_unit', $quote) && $quote['price_unit'] === null
                    && array_key_exists('package_quantity', $quote) && $quote['package_quantity'] === null
                    && array_key_exists('consumption_quantity', $quote) && $quote['consumption_quantity'] === null
                    && array_key_exists('quote_effective_date', $quote) && $quote['quote_effective_date'] === null, 'quote_catalog_scope_conflict');
                $seenQuoteCells[$quote['source_cell']] = $quote;
                ++$priceCells;
                $catalogQuotes[] = $item['name'] . ':' . $quote['source_cell'] . '=' . (string)$quote['raw_text'];
            }
        }
        joydoCheck($priceCells === 135, 'quote_cell_count_conflict');
        joydoCheck(($seenQuoteCells['C31']['quote_kind'] ?? null) === 'specification_only'
            && array_key_exists('amount', $seenQuoteCells['C31']) && $seenQuoteCells['C31']['amount'] === null,
            'quote_specification_as_price_conflict');
        foreach ($catalog['recommendations'] as $recommendation) {
            joydoCheck($recommendation['mapping_quality'] === 'layout_context_only_not_same_sku_quote'
                && is_array($recommendation['cells']) && $recommendation['cells'] !== [], 'quote_recommendation_scope_conflict');
        }
    }
    $corrections = [];
    $itemCatalog = [];
    $itemContexts = [];
    if ($isPriceItems) {
        $itemManifestHash = strtoupper((string)hash_file('sha256', $base . 'item-source-manifest.json'));
        joydoCheck($itemManifestHash === '82C839660963A85BB99C472454186F43D16E1697DDAA37285397E7FBF144E66D', 'item_manifest_hash_conflict');
        $itemManifest = json_decode((string)file_get_contents($base . 'item-source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        joydoCheck($itemManifest['version_label'] === $packVersion && $itemManifest['source_sha256'] === $quoteSha256
            && $itemManifest['source_manifest_sha256'] === strtoupper((string)hash_file('sha256', $base . 'source-manifest.json'))
            && $itemManifest['catalog_sha256'] === strtoupper((string)hash_file('sha256', $base . 'catalog.json'))
            && $itemManifest['pack_sha256'] === 'E24533560BC55C2CD532AF807CD80117AEC5F4ED46B998FD31825F09903C8347'
            && $itemManifest['pack_sha256'] === strtoupper((string)hash_file('sha256', $base . 'item-knowledge-pack.json'))
            && $itemManifest['items'] === 27 && $itemManifest['quoted_cells'] === 135
            && $itemManifest['original_catalog_modified'] === false && $itemManifest['automatic_application_authorized'] === false
            && $pack['source_sha256'] === $quoteSha256 && $pack['usage_policy'] === 'reference_only', 'item_manifest_identity_conflict');
        foreach (['item-source-manifest.json', 'item-knowledge-pack.json'] as $file) {
            $refs[] = 'repo://' . $relative . $file . '#sha256=' . strtoupper((string)hash_file('sha256', $base . $file));
        }
        joydoCheck(array_column($catalog['items'], 'id') === range(1, 27), 'item_catalog_identity_conflict');
        $tierMap = array_column($catalog['tiers'], null, 'id');
        $mappedCells = [];
        foreach ($pack['entries'] as $index => $entry) {
            $item = $catalog['items'][$index];
            joydoCheck($entry['item_id'] === $item['id'] && $entry['title'] === $item['name'] . '采购推荐价与规格参考'
                && $entry['query'] === $item['name'] . '采购推荐价', 'item_entry_identity_conflict');
            $expectedQuotes = [];
            foreach ($item['quotes'] as $quote) {
                joydoCheck(isset($tierMap[$quote['tier_id']]) && !isset($mappedCells[$quote['source_cell']]), 'item_quote_mapping_conflict');
                $expectedQuotes[] = 'Sheet1!' . $quote['source_cell'] . ' ' . $tierMap[$quote['tier_id']]['label'] . '：' . $quote['raw_text'];
                $mappedCells[$quote['source_cell']] = true;
            }
            $context = array_values(array_filter($catalog['recommendations'], static fn(array $row): bool => in_array($item['id'], $row['layout_context_item_ids'], true)));
            $expectedRecommendations = [];
            foreach ($context as $row) {
                foreach ($row['cells'] as $cell) {
                    joydoCheck(isset($tierMap[$cell['tier_id']]), 'item_recommendation_tier_conflict');
                    $expectedRecommendations[] = '布局上下文建议，非本SKU确认报价；Sheet1!' . $cell['source_cell'] . ' ' . $tierMap[$cell['tier_id']]['label'] . '：' . $cell['raw_text'];
                }
            }
            joydoCheck($entry['quotation_lines'] === $expectedQuotes && $entry['recommendation_lines'] === $expectedRecommendations
                && is_array($entry['interpretation_limits']) && count($entry['interpretation_limits']) >= 2, 'item_quote_lines_conflict');
            foreach (array_merge($expectedQuotes, $expectedRecommendations, $entry['interpretation_limits']) as $line) {
                joydoCheck(is_string($line) && trim($line) !== '' && mb_strlen($line) <= 600, 'item_excerpt_segment_conflict');
            }
            $itemCatalog[$entry['key']] = $item;
            $itemContexts[$entry['key']] = $context;
        }
        joydoCheck(count($mappedCells) === 135, 'item_quote_coverage_conflict');
        $correctionSha256 = '0A42D319057B08456514337A9B2C8DEECA2C1E3FB212FCA3695A0F8C93EF4CED';
        joydoCheck(strtoupper((string)hash_file('sha256', $base . 'item-corrections-v2.json')) === $correctionSha256, 'item_correction_hash_conflict');
        $correctionPack = json_decode((string)file_get_contents($base . 'item-corrections-v2.json'), true, 512, JSON_THROW_ON_ERROR);
        joydoCheck($correctionPack['version'] === 'consumables-price-item-corrections-20261001-v2'
            && $correctionPack['source_sha256'] === $quoteSha256 && $correctionPack['original_pack_sha256'] === $itemManifest['pack_sha256']
            && $correctionPack['reviewed_on'] === '2026-10-01' && $correctionPack['quotation_lines_changed'] === false
            && $correctionPack['recommendation_lines_changed'] === false && $correctionPack['automatic_application_authorized'] === false
            && array_column($correctionPack['changes'], 'key') === ['item_01', 'item_02', 'item_20', 'item_24', 'item_25'], 'item_correction_identity_conflict');
        $corrections = array_column($correctionPack['changes'], null, 'key');
    }
    $costReplay = null;
    if ($isCost) {
        $replayScript = $manifest['local_replay_script'];
        joydoCheck($replayScript['file'] === 'scripts/verify_joydo_hotel_cost_reference.php'
            && strtoupper((string)hash_file('sha256', $root . '/' . $replayScript['file'])) === $replayScript['sha256'], 'replay_script_hash_conflict');
        $refs[] = 'repo://' . $replayScript['file'] . '#sha256=' . $replayScript['sha256'];
        $supportingRefs = [
            'https://www.ifrs.org/issued-standards/list-of-standards/ias-2-inventories/',
            'https://www.costar.com/products/str-benchmark/resources/data-insights-blog/what-revenue-available-room-revpar-and-how',
        ];
        joydoCheck($manifest['supporting_definition_refs'] === $supportingRefs, 'supporting_refs_conflict');
        $refs = array_merge($refs, $supportingRefs);
        require_once $root . '/' . $replayScript['file'];
        $costReplay = joydoHotelCostReplay($root);
        joydoCheck($costReplay['status'] === 'passed' && !$costReplay['business_integration'] && !$costReplay['source_switch_propagation_verified'], 'cost_replay_scope_conflict');
    }
    $otherReplay = null;
    if ($isOther) {
        $replayScript = $manifest['local_replay_script'];
        joydoCheck($replayScript['file'] === 'scripts/verify_joydo_other_modules_reference.php'
            && strtoupper((string)hash_file('sha256', $root . '/' . $replayScript['file'])) === $replayScript['sha256'], 'replay_script_hash_conflict');
        $refs[] = 'repo://' . $replayScript['file'] . '#sha256=' . $replayScript['sha256'];
        $relatedRefs = ['https://geo.joydorms.com/', 'https://www.spglobal.com/ratings/en/regulatory/article/-/view/sourceId/11884994/revId/1'];
        joydoCheck($manifest['related_and_supporting_refs'] === $relatedRefs && $manifest['geo_access'] === 'public_landing_only', 'supporting_refs_conflict');
        $refs = array_merge($refs, $relatedRefs);
        require_once $root . '/' . $replayScript['file'];
        $otherReplay = joydoOtherModulesReplay($root);
        joydoCheck($otherReplay['status'] === 'passed' && !$otherReplay['business_integration'] && !$otherReplay['source_nonzero_finance_verified']
            && !$otherReplay['source_exports_verified'] && !$otherReplay['geo_backend_verified'], 'other_replay_scope_conflict');
    }
    if (!$isMeasurement && !$isPriceList) {
    // Equivalent local arithmetic only: no source inputs, pricing or facts are written.
    $available = 50 * 30;
    $sold = $available * 0.65;
    $contribution = 160 - 27.28;
    $profit = $sold * $contribution - 92000;
    joydoCheck($sold === 975.0 && round($sold * 160, 2) === 156000.0 && round($sold * 27.28, 2) === 26598.0 && round($profit, 2) === 37402.0, 'source_arithmetic_mismatch');
    joydoCheck(round(92000 / ($available * $contribution) * 100, 2) === 46.21 && round(3000000 / ($profit * 12), 2) === 6.68, 'source_arithmetic_mismatch');
    $targetThree = (92000 + 3000000 / 36) / ($available * $contribution);
    $targetOne = (92000 + 3000000 / 12) / ($available * $contribution);
    joydoCheck(round($targetThree * 100, 2) === 88.07 && round($targetOne * 100, 2) === 171.79 && $targetOne > 1, 'source_boundary_mismatch');
    }

    require $root . '/vendor/autoload.php';
    (new App())->initialize();
    $connectionName = (string)config('database.default', 'mysql');
    $connection = (array)config('database.connections.' . $connectionName, []);
    joydoCheck(in_array((string)($connection['hostname'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true) && ($connection['database'] ?? '') === 'hotelx', 'local_database_required');
    $digest = new KnowledgeContentDigestService();
    $gate = new KnowledgeDecisionGateService();
    $definitions = [];
    foreach ($pack['entries'] as $entry) {
        $key = $entry['key'];
        joydoCheck(in_array($key, $allowedKeys, true) && !isset($definitions[$key]), 'entry_identity_conflict');
        $content = [
            'title' => $entry['title'], 'summary' => $entry['summary'], 'method' => $entry['method'],
            'raw_text' => $entry['title'] . "\n" . $entry['summary'] . "\n" . implode("\n", $entry['method']),
            'scope' => 'global_hotel_knowledge_method_reference', 'evidence_level' => $isMeasurement ? 'official_method_reference' : 'user_authorized_website_observation_method_reference', 'evidence_grade' => 'C',
            'source_refs' => $refs, 'source_section' => $entry['source_section'], 'source_version' => $manifest['version_label'],
            'observation_fingerprint_scope' => $isMeasurement ? 'authored_chinese_methods_only_not_upstream_document' : 'local_paraphrase_and_screenshot_not_upstream_source',
            'reviewed_at' => $pack['reviewed_at'], 'review_due_at' => $pack['review_due_at'], 'lifecycle_status' => 'active',
            'usage_policy' => 'reference_only', 'decision_safe' => false, 'task_draft_safe' => false,
            'contains_current_hotel_fact' => false, 'contains_current_ota_fact' => false, 'external_write_authorized' => false,
            'disposition' => 'store_only', 'capability_disposition' => 'absorption_candidate', 'capability_maturity' => $entry['maturity'],
            'learning_gates' => $entry['gates'], 'known_unknowns' => $entry['known_unknowns'], 'platforms' => [],
            'blocked_uses' => ['hotel_fact_ingestion', 'automatic_grading', 'pricing_action', 'operation_task_creation', 'external_message', 'source_code_copy', 'third_party_training'],
            'seed_owner' => $seedOwner, 'seed_key' => $key, 'seed_version' => $pack['version'],
        ];
        if ($isMeasurement) {
            joydoCheck(is_array($entry['source_urls']) && $entry['source_urls'] !== []
                && array_diff($entry['source_urls'], $officialUrls) === [], 'entry_source_refs_conflict');
            $content['source_refs'] = array_merge($entry['source_urls'], array_slice($refs, count($officialUrls)));
            $content['extracted_on'] = $manifest['extracted_on'];
            $content['application_inference'] = $entry['application_inference'];
            $content['undefined_coefficients_defaulted'] = false;
            $content['financial_or_tax_policy_decision'] = false;
        }
        if ($isPriceList) {
            $content['evidence_level'] = 'user_supplied_procurement_quote_reference';
            $content['source_kind'] = $manifest['source_kind'];
            $content['source_workbook_sha256'] = $quoteSha256;
            $content['source_filename'] = $manifest['source_filename'];
            $content['source'] = '采购推荐价参考';
            $content['source_label'] = '采购推荐价参考';
            $content['source_quality'] = 'user_supplied_unverified_quote_reference';
            $content['quote_effective_date'] = null;
            $content['supplier'] = null;
            $content['currency'] = null;
            $content['extracted_on'] = $manifest['extracted_on'];
            $content['observation_fingerprint_scope'] = 'user_supplied_workbook_sha256_and_cell_provenance_not_vendor_verified_quote';
            $content['automatic_application_authorized'] = false;
            $content['inventory_write_authorized'] = false;
            $content['cash_write_authorized'] = false;
            $content['contains_executed_purchase'] = false;
            $content['undefined_coefficients_defaulted'] = false;
            $content['blocked_uses'] = array_merge($content['blocked_uses'], ['automatic_cost_adoption', 'supplier_contact', 'procurement_order', 'inventory_adjustment', 'cash_ledger_write', 'executed_purchase_price_claim']);
            $content['source_refs'][] = 'repo://' . $relative . 'source.xlsx#sha256=' . $quoteSha256
                . '&source_section=' . rawurlencode((string)$entry['source_section']);
            if ($key === 'quote_catalog') {
                $content['catalog'] = ['items' => $catalog['items'], 'tiers' => $catalog['tiers'], 'recommendations' => $catalog['recommendations']];
                // The retrieval service excludes raw_text and bounds excerpts. Keep all item names before detailed cells.
                $content = ['title' => $content['title'], 'summary' => $content['summary'],
                    'catalog_item_index' => implode('；', $catalogNames),
                    'quoted_cell_index' => implode('；', $catalogQuotes)] + $content;
            } elseif ($key === 'tier_configuration') {
                $content['catalog'] = ['tiers' => $catalog['tiers'], 'recommendations' => $catalog['recommendations']];
            }
        }
        if ($isPriceItems) {
            // These bounded source lines must precede metadata and the structured catalog.
            $content = ['title' => $entry['title'], 'summary' => $entry['summary'],
                'quotation_lines' => $entry['quotation_lines'], 'recommendation_lines' => $entry['recommendation_lines'],
                'interpretation_limits' => $entry['interpretation_limits']] + $content;
            $content['source_version'] = $packVersion;
            $content['catalog'] = ['item' => $itemCatalog[$key], 'tiers' => $catalog['tiers'], 'recommendations' => $itemContexts[$key]];
        }
        $unit = [
            'stable_key' => $stablePrefix . $key, 'hotel_id' => 0, 'created_by' => 0,
            'name' => $entry['title'], 'source' => $unitSource, 'status' => 'done', 'description' => $entry['summary'],
            'tags' => joydoJson($isPriceList ? ['易耗品', '采购推荐价', $tag, 'reference_only', 'global_reference'] : ($isMeasurement ? ['易耗品', '计量方法', $tag, 'reference_only', 'global_reference'] : ['JHIRA', '网站学习', $tag, 'reference_only', 'global_reference'])),
            'lifecycle_status' => 'active', 'lifecycle_reason' => $isPriceList ? 'user_authorized_procurement_quote_reference_ingestion' : ($isMeasurement ? 'user_authorized_official_method_reference_ingestion' : 'user_authorized_website_reference_ingestion'),
            'reviewed_at' => $unitReviewedAt, 'review_due_at' => $unitReviewDueAt, 'truth_profile_version' => $truthVersion,
            'known_knowns' => joydoJson([$known]),
            'known_unknowns' => joydoJson($entry['known_unknowns']),
        ];
        $assessed = $gate->assess($unit, $content);
        joydoCheck($assessed['status'] === 'reference_only' && $assessed['retrieval_safe'] && !$assessed['decision_safe'] && !$assessed['task_draft_safe'], 'reference_gate_failed');
        foreach ([['source_refs' => []], ['lifecycle_status' => 'archived'], ['valid_until' => '2026-09-30 23:59:59']] as $invalid) {
            joydoCheck(!$gate->assess($unit, array_replace($content, $invalid))['retrieval_safe'], 'invalid_reference_not_blocked');
        }
        $definitions[$key] = ['key' => $key, 'unit' => $unit, 'content' => $content, 'type' => ($isPriceList ? 'consumables_price_' : ($isMeasurement ? 'consumables_measurement_' : 'joydo_')) . $key . '_reference', 'query' => $entry['query']];
        if (isset($corrections[$key])) {
            $correction = $corrections[$key];
            joydoCheck($digest->digest($content) === $correction['expected_previous_content_digest'], 'correction_original_definition_conflict');
            $oldLimits = $content['interpretation_limits'];
            $lineIndex = array_search($correction['replaced_line'], $oldLimits, true);
            joydoCheck($lineIndex !== false, 'correction_original_line_missing');
            $oldLimits[$lineIndex] = $correction['replacement_line'];
            joydoCheck($oldLimits === $correction['interpretation_limits'], 'correction_limits_scope_conflict');
            $definitions[$key]['correction'] = $correction;
            $definitions[$key]['previous_definition'] = $definitions[$key];
            $content['interpretation_limits'] = $oldLimits;
            $content['correction_version'] = $correctionPack['version'];
            $content['correction_sha256'] = $correctionSha256;
            $content['correction_reason'] = $correction['reason'];
            $originalRefs = $content['source_refs'];
            $cellRef = array_pop($originalRefs);
            // Retrieval exposes eight refs: keep the revision and original workbook cell provenance first.
            $content['source_refs'] = array_merge(['repo://' . $relative . 'item-corrections-v2.json#sha256=' . $correctionSha256, $cellRef], $originalRefs);
            $unit['truth_profile_version'] = 'cons-price-items-20261001-v2';
            $definitions[$key]['content'] = $content;
            $definitions[$key]['unit'] = $unit;
            $correctedGate = $gate->assess($unit, $content);
            joydoCheck($correctedGate['status'] === 'reference_only' && $correctedGate['retrieval_safe']
                && !$correctedGate['decision_safe'] && !$correctedGate['task_draft_safe'], 'corrected_reference_gate_failed');
        }
        if ($isPriceItems) {
            $definitions[$key]['required_excerpt_lines'] = array_merge($content['quotation_lines'], $content['recommendation_lines'], $content['interpretation_limits']);
            $fixture = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows(
                [$unit + ['unit_id' => 1]], [['chunk_id' => 1, 'unit_id' => 1, 'type' => $definitions[$key]['type'], 'content' => $content]],
                ['hotel_id' => 1, 'user_id' => 0, 'question' => $entry['query']]);
            joydoCheck(count($fixture['items'] ?? []) === 1, 'item_preview_retrieval_failed');
            foreach ($definitions[$key]['required_excerpt_lines'] as $line) {
                joydoCheck(mb_strpos($fixture['items'][0]['excerpt'], $line) !== false, 'item_preview_excerpt_incomplete');
            }
        }
    }
    $baseResult = ['status' => 'validated', 'mode' => 'preview', 'profile' => $profile, 'entries' => count($definitions), 'retained_hashes_verified' => count($allowedFiles) + ($isCost || $isOther ? 1 : 0),
        'source_arithmetic' => $isPriceList ? 'not_applicable_procurement_quote_reference' : ($isMeasurement ? 'not_applicable_official_method_reference' : 'matched_visible_simplified_sample'), 'unreachable_target' => $isMeasurement || $isPriceList ? 'not_applicable' : 'matched_171_79_percent',
        'gate_cases_passed' => count($definitions) * 4, 'usage_policy' => 'reference_only', 'capability_disposition' => 'absorption_candidate', 'business_integration' => false];
    if ($isCost) {
        $baseResult['cost_replay'] = $costReplay;
    }
    if ($isOther) {
        $baseResult['other_replay'] = $otherReplay;
    }
    if ($isPriceList) {
        $baseResult['quote_catalog'] = ['items' => 27, 'tiers' => 5, 'source_price_cells' => 135,
            'recommendation_rows' => 12, 'quote_effective_date' => null, 'supplier' => null,
            'currency' => null, 'source' => '采购推荐价参考', 'automatic_application_authorized' => false];
    }
    if ($isPriceItems) {
        $baseResult['retained_hashes_verified'] += 3;
        $baseResult['item_source_manifest_sha256'] = $itemManifestHash;
        $baseResult['item_pack_sha256'] = $itemManifest['pack_sha256'];
        $baseResult['quoted_cells_mapped'] = 135;
        $baseResult['preview_complete_excerpts'] = 27;
        $baseResult['correction_version'] = $correctionPack['version'];
        $baseResult['correction_sha256'] = $correctionSha256;
        $pendingCorrections = [];
        foreach ($definitions as $definition) {
            $existing = Db::name('knowledge_units')->where('stable_key', $definition['unit']['stable_key'])->find();
            joydoCheck($existing !== null, 'correction_original_unit_missing');
            if (isset($definition['previous_definition']) && $existing['truth_profile_version'] === $definition['previous_definition']['unit']['truth_profile_version']) {
                joydoReadback([$definition['previous_definition']], $digest);
                $pendingCorrections[] = $definition['key'];
            } else {
                joydoReadback([$definition], $digest);
            }
        }
        $baseResult['pending_corrections'] = $pendingCorrections;
    }
    if (!isset($options['persist']) && !isset($options['verify'])) {
        $prior = Db::name('knowledge_units')->where('source', $unitSource)->field('unit_id,stable_key,name')->select()->toArray();
        echo joydoJson($baseResult + ['existing_same_source_units' => $prior]) . PHP_EOL;
        exit(0);
    }
    $actions = [];
    if (isset($options['persist'])) {
        $lockName = $isPriceItems ? 'suxios.consumables_price_items_reference.20261001' : ($isPriceList ? 'suxios.consumables_price_reference.20261001' : ($isMeasurement ? 'suxios.consumables_measurement_reference.20261001' : 'suxios.joydo_jhira_reference.20261001'));
        $lock = Db::query('SELECT GET_LOCK(?, 5) AS acquired', [$lockName]);
        joydoCheck((int)($lock[0]['acquired'] ?? 0) === 1, 'import_busy');
        try {
            $actions = Db::transaction(function () use ($definitions, $digest, $isPriceList, $isPriceItems): array {
                $actions = [];
                foreach ($definitions as $definition) {
                    $expected = $definition['unit'];
                    $existing = Db::name('knowledge_units')->where('stable_key', $expected['stable_key'])->lock(true)->find();
                    if ($existing) {
                        if (isset($definition['previous_definition'])
                            && $existing['truth_profile_version'] === $definition['previous_definition']['unit']['truth_profile_version']) {
                            // Exact original readback precedes the narrowly authorized explanation revision.
                            joydoReadback([$definition['previous_definition']], $digest);
                            $now = date('Y-m-d H:i:s');
                            $unitId = (int)$existing['unit_id'];
                            $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
                                'unit_id' => $unitId, 'type' => $definition['type'], 'content' => joydoJson($definition['content'], true),
                                'content_digest' => $digest->digest($definition['content']), 'version_no' => 2,
                                'lifecycle_status' => 'active', 'superseded_by_chunk_id' => null,
                                'created_by' => 0, 'created_at' => $now,
                            ]);
                            $changed = Db::name('knowledge_chunks')->where('unit_id', $unitId)
                                ->where('chunk_id', $definition['correction']['previous_chunk_id'])->where('lifecycle_status', 'active')
                                ->update(['lifecycle_status' => 'superseded', 'superseded_by_chunk_id' => $chunkId, 'retired_at' => $now]);
                            joydoCheck($changed === 1, 'correction_history_update_failed');
                            Db::name('knowledge_units')->where('unit_id', $unitId)->update([
                                'current_chunk_id' => $chunkId, 'truth_profile_version' => $expected['truth_profile_version'], 'updated_at' => $now]);
                            joydoReadback([$definition], $digest);
                            $actions[$definition['key']] = 'updated';
                            continue;
                        }
                        joydoReadback([$definition], $digest);
                        $actions[$definition['key']] = 'unchanged';
                        continue;
                    }
                    joydoCheck(!$isPriceItems && !isset($definition['correction']), 'correction_original_unit_missing');
                    $now = date('Y-m-d H:i:s');
                    $unitId = (int)Db::name('knowledge_units')->insertGetId($expected + ['created_at' => $now, 'updated_at' => $now]);
                    $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
                        'unit_id' => $unitId, 'type' => $definition['type'], 'content' => joydoJson($definition['content'], $isPriceList),
                        'content_digest' => $digest->digest($definition['content']), 'lifecycle_status' => 'active', 'created_by' => 0, 'created_at' => $now,
                    ]);
                    Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $chunkId]);
                    $actions[$definition['key']] = 'inserted';
                }
                joydoReadback($definitions, $digest);
                return $actions;
            });
        } finally {
            Db::query('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
    $rows = joydoReadback($definitions, $digest);
    $hotelId = (int)Db::name('hotels')->order('id', 'asc')->value('id');
    joydoCheck($hotelId > 0, 'retrieval_hotel_context_missing');
    $retrieval = new OperatingQuestionKnowledgeRetrievalService();
    $receipts = [];
    foreach ($rows as $row) {
        $definition = $definitions[$row['key']];
        $reply = $retrieval->retrieve($hotelId, 0, '', $definition['query']);
        $hits = array_values(array_filter($reply['items'] ?? [], static fn(array $hit): bool => (int)$hit['unit_id'] === (int)$row['unit']['unit_id']));
        joydoCheck(count($hits) === 1 && $hits[0]['usage_policy'] === 'reference_only' && $hits[0]['source_refs'] !== [], 'runtime_retrieval_failed');
        if ($isPriceItems) {
            foreach ($definition['required_excerpt_lines'] as $line) {
                joydoCheck(mb_strpos($hits[0]['excerpt'], $line) !== false, 'item_runtime_excerpt_incomplete');
            }
            if (isset($definition['correction'])) {
                joydoCheck(in_array('repo://' . $relative . 'item-corrections-v2.json#sha256=' . $correctionSha256, $hits[0]['source_refs'], true), 'correction_runtime_source_missing');
                foreach ($reply['items'] ?? [] as $hit) {
                    joydoCheck((int)$hit['chunk_id'] !== $definition['correction']['previous_chunk_id'], 'correction_old_chunk_retrieved');
                }
                $historical = $retrieval->buildFromRows([$row['unit']], [$row['history']],
                    ['hotel_id' => $hotelId, 'user_id' => 0, 'question' => $definition['query']]);
                joydoCheck(($historical['items'] ?? []) === [], 'correction_superseded_fixture_retrieved');
            }
        }
        $private = array_replace($row['unit'], ['hotel_id' => $hotelId, 'created_by' => 987654321]);
        $foreign = $retrieval->buildFromRows([$private], [$row['chunk']], ['hotel_id' => $hotelId + 1000000, 'user_id' => 987654321, 'question' => $definition['query']]);
        joydoCheck(($foreign['items'] ?? []) === [], 'cross_hotel_fixture_leaked');
        $receipts[] = ['key' => $row['key'], 'unit_id' => (int)$row['unit']['unit_id'], 'chunk_id' => (int)$row['chunk']['chunk_id'],
            'content_digest' => $row['chunk']['content_digest'], 'readback' => 'exact', 'retrieval' => 'matched',
            'decision_safe' => false, 'task_draft_safe' => false, 'external_write_authorized' => false];
        if ($isPriceItems) {
            $receipts[array_key_last($receipts)]['excerpt_lines_complete'] = count($definition['required_excerpt_lines']);
            $receipts[array_key_last($receipts)]['excerpt_characters'] = mb_strlen($hits[0]['excerpt']);
            $receipts[array_key_last($receipts)]['truth_profile_version'] = $row['unit']['truth_profile_version'];
            if ($row['history'] !== null) {
                $receipts[array_key_last($receipts)]['previous_chunk'] = [
                    'chunk_id' => (int)$row['history']['chunk_id'], 'content_digest' => $row['history']['content_digest'],
                    'lifecycle_status' => $row['history']['lifecycle_status'], 'superseded_by_chunk_id' => (int)$row['history']['superseded_by_chunk_id'],
                    'readback' => 'exact_original', 'retrieval' => 'excluded', 'correction_ref_returned' => true];
            }
        }
    }
    echo joydoJson(array_replace($baseResult, ['status' => 'success', 'mode' => isset($options['persist']) ? 'persist' : 'verify',
        'actions' => $actions, 'readback' => $receipts, 'cross_hotel_cases_passed' => count($definitions),
        'verification_scope' => 'local_reference_database_and_existing_retrieval_service_no_llm_or_ota_call'])) . PHP_EOL;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $safe = preg_match('/^[a-z_]+(?::[a-z_]+)?$/D', $message) === 1 ? $message : 'runtime_validation_failed';
    fwrite(STDERR, joydoJson(['status' => 'failed', 'code' => $safe, 'exception' => get_class($error)]) . PHP_EOL);
    exit(2);
}
