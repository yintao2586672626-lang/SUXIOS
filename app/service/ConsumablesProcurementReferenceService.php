<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

/** Immutable, user-supplied reference material. No inventory, procurement or hotel facts. */
final class ConsumablesProcurementReferenceService
{
    public const CATALOG_ID = 'consumables-price-list-20261001';
    public const SOURCE_SHA256 = 'BF8AA1E0290BCF7F6464D6FFA63BDB653C1E11745A816EA53F517E2FC3EBA4F0';
    private ?array $resolved = null;

    public function __construct(private ?string $directory = null) {}

    public function catalog(): array
    {
        if ($this->resolved !== null) return $this->resolved;
        $base = $this->directory ?? dirname(__DIR__, 2) . '/docs/knowledge/' . self::CATALOG_ID;
        try {
            $manifest = $this->read($base, 'source-manifest.json', '732DDCE9174B0883E370A7D87228BB915727097C5964F08EF2EEF8E921AF605C');
            $files = ['source.xlsx', 'extracted.json', 'catalog.json', 'README.md', 'knowledge-pack.json'];
            if (array_column($manifest['retained_files'], 'file') !== $files || $manifest['source_sha256'] !== self::SOURCE_SHA256) {
                throw new RuntimeException('source identity');
            }
            foreach ($manifest['retained_files'] as $file) $this->checkHash($base . '/' . $file['file'], $file['sha256']);
            $this->checkHash($base . '/source.xlsx', self::SOURCE_SHA256);
            $catalog = $this->read($base, 'catalog.json');
            $itemManifest = $this->read($base, 'item-source-manifest.json', '82C839660963A85BB99C472454186F43D16E1697DDAA37285397E7FBF144E66D');
            $pack = $this->read($base, 'item-knowledge-pack.json', $itemManifest['pack_sha256']);
            $corrections = $this->read($base, 'item-corrections-v2.json', '0A42D319057B08456514337A9B2C8DEECA2C1E3FB212FCA3695A0F8C93EF4CED');
            if ($itemManifest['source_sha256'] !== self::SOURCE_SHA256 || $catalog['source_sha256'] !== self::SOURCE_SHA256
                || $pack['source_sha256'] !== self::SOURCE_SHA256 || $corrections['source_sha256'] !== self::SOURCE_SHA256
                || $corrections['original_pack_sha256'] !== $itemManifest['pack_sha256']
                || array_column($catalog['items'], 'id') !== range(1, 27)
                || array_column($catalog['tiers'], 'id') !== ['c', 'd', 'e', 'f', 'g']) {
                throw new RuntimeException('catalog identity');
            }
            $entries = array_column($pack['entries'], null, 'item_id');
            $changes = array_column($corrections['changes'], null, 'key');
            $tiers = array_map(static fn(array $tier): array => ['id' => $tier['id'], 'label' => $tier['label']], $catalog['tiers']);
            $items = [];
            foreach ($catalog['items'] as $item) {
                $entry = $entries[$item['id']];
                if (array_column($item['quotes'], 'tier_id') !== ['c', 'd', 'e', 'f', 'g']) throw new RuntimeException('tier identity');
                $recommendations = [];
                foreach ($catalog['recommendations'] as $row) {
                    if (in_array($item['id'], $row['layout_context_item_ids'], true)) {
                        foreach ($row['cells'] as $cell) $recommendations[] = $cell;
                    }
                }
                $items[] = ['id' => $item['id'], 'name' => $item['name'],
                    'quotes' => array_map(static fn(array $quote): array => array_intersect_key($quote, array_flip([
                        'tier_id', 'source_cell', 'raw_text', 'quote_kind', 'amount', 'lower', 'upper', 'annotation',
                    ])), $item['quotes']),
                    'recommendations' => $recommendations,
                    'recommendation_mapping_quality' => 'layout_context_only_not_same_sku_quote',
                    'interpretation_limits' => $changes[$entry['key']]['interpretation_limits'] ?? $entry['interpretation_limits'],
                ];
            }
            return $this->resolved = [
                'schema_version' => 'consumables-procurement-reference-v1', 'catalog_id' => self::CATALOG_ID,
                'source_sha256' => self::SOURCE_SHA256, 'source_filename' => $manifest['source_filename'],
                'source_label' => '采购推荐价参考', 'source_kind' => $manifest['source_kind'],
                'extracted_on' => $manifest['extracted_on'], 'evidence_grade' => 'C',
                'source_quality' => 'user_supplied_unverified_quote_reference',
                'interpretation_correction_sha256' => '0A42D319057B08456514337A9B2C8DEECA2C1E3FB212FCA3695A0F8C93EF4CED',
                'quote_effective_date' => null, 'supplier' => null, 'currency' => null,
                'usage_policy' => 'reference_only', 'automatic_application_authorized' => false,
                'contains_current_hotel_fact' => false, 'tiers' => $tiers, 'items' => $items,
            ];
        } catch (\Throwable $error) {
            throw new RuntimeException('采购推荐价参考资料不可用，请核对来源文件', 503, $error);
        }
    }

    /** Client descriptions or claimed quality never become the canonical reference snapshot. */
    public function normalizeReference(mixed $input): array
    {
        if (!is_array($input) || ($input['catalog_id'] ?? null) !== self::CATALOG_ID
            || ($input['source_sha256'] ?? null) !== self::SOURCE_SHA256
            || !is_int($input['item_id'] ?? null) || !in_array($input['tier_id'] ?? null, ['c', 'd', 'e', 'f', 'g'], true)
            || !is_bool($input['confirmed_for_scenario'] ?? null)) {
            throw new InvalidArgumentException('procurement_reference identity and confirmation are invalid');
        }
        $catalog = $this->catalog();
        $items = array_values(array_filter($catalog['items'], static fn(array $item): bool => $item['id'] === $input['item_id']));
        if (count($items) !== 1) throw new InvalidArgumentException('procurement_reference item is unknown');
        $item = $items[0];
        $quote = array_values(array_filter($item['quotes'], static fn(array $q): bool => $q['tier_id'] === $input['tier_id']))[0];
        $tier = array_values(array_filter($catalog['tiers'], static fn(array $t): bool => $t['id'] === $input['tier_id']))[0];
        return [
            'catalog_id' => self::CATALOG_ID, 'source_sha256' => self::SOURCE_SHA256,
            'item_id' => $item['id'], 'tier_id' => $tier['id'], 'confirmed_for_scenario' => $input['confirmed_for_scenario'],
            'item_name' => $item['name'], 'tier_label' => $tier['label'],
            'source_cell' => $quote['source_cell'], 'raw_text' => $quote['raw_text'], 'quote_kind' => $quote['quote_kind'],
            'amount' => $quote['amount'], 'lower' => $quote['lower'], 'upper' => $quote['upper'], 'annotation' => $quote['annotation'],
            'recommendations' => array_values(array_filter($item['recommendations'], static fn(array $r): bool => $r['tier_id'] === $tier['id'])),
            'recommendation_mapping_quality' => $item['recommendation_mapping_quality'],
            'interpretation_limits' => $item['interpretation_limits'],
            'source_filename' => $catalog['source_filename'], 'source_label' => $catalog['source_label'], 'source_kind' => $catalog['source_kind'],
            'extracted_on' => $catalog['extracted_on'], 'evidence_grade' => 'C', 'source_quality' => $catalog['source_quality'],
            'interpretation_correction_sha256' => $catalog['interpretation_correction_sha256'],
            'supplier' => null, 'quote_effective_date' => null, 'currency' => null, 'price_unit' => null,
            'package_quantity' => null, 'consumption_quantity' => null,
            'usage_policy' => 'reference_only', 'automatic_application_authorized' => false,
        ];
    }

    private function checkHash(string $path, string $expected): void
    {
        if (!is_file($path) || !hash_equals($expected, strtoupper((string)hash_file('sha256', $path)))) {
            throw new RuntimeException('source hash');
        }
    }

    private function read(string $base, string $file, ?string $hash = null): array
    {
        if ($hash !== null) $this->checkHash($base . '/' . $file, $hash);
        $content = file_get_contents($base . '/' . $file);
        if ($content === false) throw new RuntimeException('source missing');
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
