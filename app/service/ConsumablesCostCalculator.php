<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** Cost assumptions with optional immutable quote provenance; prices are always manually supplied. */
final class ConsumablesCostCalculator
{
    public const SCHEMA_VERSION = 'consumables-v1';
    private const NUMBER_FIELDS = ['package_price', 'package_quantity', 'usage_quantity', 'occurrences_per_occupied_night'];

    public function __construct(private ?ConsumablesProcurementReferenceService $references = null) {}

    public function normalize(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (array_key_exists('schema_version', $input) && $input['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('consumables.schema_version is unsupported');
        }
        $mode = $input['mode'] ?? null;
        if (!in_array($mode, ['manual', 'derived'], true)) {
            throw new InvalidArgumentException('consumables.mode must be manual or derived');
        }
        $rows = $input['items'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100 || (array_key_exists('items', $input) && $input['items'] === null)) {
            throw new InvalidArgumentException('consumables.items must be a list of at most 100 items');
        }
        $items = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('consumables.items entries must be objects');
            }
            $path = 'consumables.items.' . $index;
            $id = $this->text($row['id'] ?? null, $path . '.id', 64);
            if ($id === null || isset($seen[$id])) {
                throw new InvalidArgumentException($path . '.id must be nonempty and unique');
            }
            $seen[$id] = true;
            if (!array_key_exists('enabled', $row) || !is_bool($row['enabled'])) {
                throw new InvalidArgumentException($path . '.enabled must be boolean');
            }
            $unit = $row['unit'] ?? null;
            $reference = null;
            if (array_key_exists('procurement_reference', $row) && $row['procurement_reference'] !== null) {
                if (!class_exists(ConsumablesProcurementReferenceService::class)) {
                    throw new InvalidArgumentException('采购参考目录尚未接入，请解除引用后使用手工成本');
                }
                $this->references ??= new ConsumablesProcurementReferenceService();
                $reference = $this->references->normalizeReference($row['procurement_reference']);
                if (is_string($unit) && trim($unit) === '') $unit = null;
            }
            $basis = $row['usage_basis'] ?? null;
            if (!in_array($unit, ['piece', 'ml', 'g'], true) && !($reference !== null && $unit === null)) {
                throw new InvalidArgumentException($path . '.unit is unsupported');
            }
            if (!in_array($basis, ['occupied_room_night', 'guest_night', 'cleaning'], true)) {
                throw new InvalidArgumentException($path . '.usage_basis is unsupported');
            }
            $item = [
                'id' => $id, 'name' => $this->text($row['name'] ?? null, $path . '.name', 160), 'enabled' => $row['enabled'],
                'package_price' => $this->number($row['package_price'] ?? null, $path . '.package_price'),
                'package_quantity' => $this->number($row['package_quantity'] ?? null, $path . '.package_quantity', true),
                'unit' => $unit, 'usage_quantity' => $this->number($row['usage_quantity'] ?? null, $path . '.usage_quantity'),
                'usage_basis' => $basis,
                'occurrences_per_occupied_night' => $this->number($row['occurrences_per_occupied_night'] ?? null, $path . '.occurrences_per_occupied_night'),
                'source_label' => $this->text($row['source_label'] ?? null, $path . '.source_label', 300),
                'as_of' => $this->text($row['as_of'] ?? null, $path . '.as_of', 10),
            ];
            if ($reference !== null) $item['procurement_reference'] = $reference;
            if ($item['as_of'] !== null) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $item['as_of']);
                if ($date === false || $date->format('Y-m-d') !== $item['as_of']) {
                    throw new InvalidArgumentException($path . '.as_of must be a valid YYYY-MM-DD date');
                }
            }
            if ($basis === 'occupied_room_night') {
                $item['occurrences_per_occupied_night'] = 1.0;
            }
            $items[] = $item;
        }
        return [
            'schema_version' => self::SCHEMA_VERSION, 'mode' => $mode,
            'other_variable_cost_per_night' => $this->number($input['other_variable_cost_per_night'] ?? null, 'consumables.other_variable_cost_per_night'),
            'items' => $items,
        ];
    }

    public function evaluate(?array $normalized): array
    {
        // Re-normalizing keeps this public method safe for callers with an uncanonical draft.
        $input = $this->normalize($normalized);
        $items = [];
        $missing = [];
        $knownSubtotal = 0.0;
        $knownCount = 0;
        $enabledCount = 0;
        $incompleteCount = 0;
        foreach ($input['items'] ?? [] as $index => $item) {
            $itemMissing = [];
            foreach (self::NUMBER_FIELDS as $field) {
                if ($item[$field] === null) {
                    $itemMissing[] = $field;
                }
            }
            if (isset($item['procurement_reference'])) {
                if (!$item['procurement_reference']['confirmed_for_scenario']) $itemMissing[] = 'procurement_reference.confirmed_for_scenario';
                foreach (['unit', 'source_label', 'as_of'] as $field) {
                    if ($item[$field] === null) $itemMissing[] = $field;
                }
            }
            $unitCost = null;
            $cost = null;
            if ($item['enabled'] && $item['package_price'] !== null && $item['package_quantity'] !== null
                && (!isset($item['procurement_reference']) || $itemMissing === [])) {
                $unitCost = $this->finite($item['package_price'] / $item['package_quantity'], 'consumables.items.' . $index . '.unit_cost');
            }
            if ($item['enabled'] && $itemMissing === []) {
                $cost = $this->finite($unitCost * $item['usage_quantity'] * $item['occurrences_per_occupied_night'], 'consumables.items.' . $index . '.cost_per_occupied_night');
            }
            $items[] = $item + ['unit_cost' => $unitCost, 'cost_per_occupied_night' => $cost, 'missing_fields' => $itemMissing];
            if (!$item['enabled']) {
                continue;
            }
            ++$enabledCount;
            if ($cost === null) {
                ++$incompleteCount;
                foreach ($itemMissing as $field) {
                    $missing[] = 'consumables_cost.items.' . $index . '.' . $field;
                }
            } else {
                ++$knownCount;
                $knownSubtotal = $this->finite($knownSubtotal + $cost, 'consumables.known_subtotal_per_night');
            }
        }
        if ($enabledCount === 0) {
            $missing[] = 'consumables_cost.items';
        }
        $other = $input['other_variable_cost_per_night'] ?? null;
        if ($other === null) {
            $missing[] = 'consumables_cost.other_variable_cost_per_night';
        }
        $consumables = $enabledCount > 0 && $incompleteCount === 0 ? $knownSubtotal : null;
        $effective = $consumables !== null && $other !== null
            ? $this->finite($consumables + $other, 'consumables.effective_operating_cost_per_night') : null;
        return [
            'status' => $enabledCount === 0 ? 'empty' : ($effective === null ? 'partial' : 'ready'),
            'items' => $items, 'known_subtotal_per_night' => $knownCount > 0 ? $knownSubtotal : null,
            'consumables_per_night' => $consumables, 'other_variable_cost_per_night' => $other,
            'effective_operating_cost_per_night' => $effective, 'missing_fields' => $missing,
            'source_quality' => 'scenario_assumption',
        ];
    }

    private function text(mixed $value, string $field, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' must be a bounded string or null');
        }
        $value = trim($value);
        if (mb_strlen($value) > $limit) {
            throw new InvalidArgumentException($field . ' is too long');
        }
        return $value === '' ? null : $value;
    }

    private function number(mixed $value, string $field, bool $positive = false): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be a number or null');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || ($positive && $number <= 0)) {
            throw new InvalidArgumentException($field . ' must be finite and ' . ($positive ? 'positive' : 'nonnegative'));
        }
        return $number;
    }

    private function finite(float $value, string $field): float
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException($field . ' exceeds finite arithmetic');
        }
        return $value;
    }
}
