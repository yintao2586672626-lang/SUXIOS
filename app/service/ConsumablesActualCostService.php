<?php
declare(strict_types=1);
namespace app\service;

use InvalidArgumentException;

/** Manual inventory/issue evidence, never inferred from purchase cash or reference prices. */
final class ConsumablesActualCostService
{
    public function calculate(array $input): array
    {
        $nights = $this->number($input['occupied_room_nights'] ?? null);
        $nightsSource = $this->text($input['occupied_room_nights_source_ref'] ?? '',500);
        $denominator = (string)($input['denominator_scope'] ?? '');
        if ($denominator !== 'whole_hotel') throw new InvalidArgumentException('consumables_whole_hotel_denominator_required');
        $attested = ($input['operator_attested'] ?? false) === true;
        $rows = $input['items'] ?? [];
        if (!is_array($rows) || count($rows) > 100) throw new InvalidArgumentException('consumables_items_invalid');
        $normalized = []; $missing = []; $known = 0.0; $knownCount = 0; $loss = 0.0; $complete = true;
        foreach ($rows as $index => $raw) {
            if (!is_array($raw) || !is_bool($raw['enabled'] ?? null)) throw new InvalidArgumentException('consumables_enabled_required');
            $item = ['id' => $this->text($raw['id'] ?? (string)$index, 100), 'name' => $this->text($raw['name'] ?? '', 160),
                'enabled' => $raw['enabled'], 'unit' => (string)($raw['unit'] ?? ''),
                'source_ref' => $this->text($raw['source_ref'] ?? '', 500), 'source_date' => $this->text($raw['source_date'] ?? '', 10),
                'valuation_method' => (string)($raw['valuation_method'] ?? 'confirmed_unit_cost')];
            if (!in_array($item['unit'], ['piece', 'ml', 'g'], true) || $item['valuation_method'] !== 'confirmed_unit_cost') throw new InvalidArgumentException('consumables_unit_or_valuation_invalid');
            foreach (['opening_quantity','purchased_quantity','transfer_in_quantity','closing_quantity','transfer_out_quantity','returned_quantity','written_off_quantity','unit_price','budget_unit_price','budget_usage_per_room_night'] as $key) $item[$key] = $this->number($raw[$key] ?? null);
            $gaps = [];
            foreach (['opening_quantity','purchased_quantity','transfer_in_quantity','closing_quantity','transfer_out_quantity','returned_quantity','written_off_quantity','unit_price'] as $key) if ($item[$key] === null) $gaps[] = $key;
            if ($item['source_ref'] === '' || !$this->validDate($item['source_date'])) $gaps[] = 'source_evidence';
            $quantity = $gaps === [] ? $this->inventoryBalance($item) : null;
            if ($quantity !== null && $quantity < 0) { $gaps[] = 'inventory_balance_negative'; $quantity = null; }
            $cost = $quantity !== null ? round($quantity * $item['unit_price'], 2) : null;
            $lossCost = $item['written_off_quantity'] !== null && $item['unit_price'] !== null ? round($item['written_off_quantity'] * $item['unit_price'], 2) : null;
            $budgetQuantity = $nights !== null && $item['budget_usage_per_room_night'] !== null ? $nights * $item['budget_usage_per_room_night'] : null;
            $budgetCost = $budgetQuantity !== null && $item['budget_unit_price'] !== null ? round($budgetQuantity * $item['budget_unit_price'], 2) : null;
            $item += ['consumed_quantity' => $quantity, 'consumed_cost' => $cost, 'loss_cost' => $lossCost,
                'budget_cost_at_actual_volume' => $budgetCost,
                'price_variance' => $cost !== null && $item['budget_unit_price'] !== null ? round(($item['unit_price'] - $item['budget_unit_price']) * $quantity, 2) : null,
                'usage_variance' => $quantity !== null && $budgetQuantity !== null && $item['budget_unit_price'] !== null ? round(($quantity - $budgetQuantity) * $item['budget_unit_price'], 2) : null,
                'total_variance' => $cost !== null && $budgetCost !== null ? round($cost - $budgetCost, 2) : null,
                'missing_items' => $gaps, 'status' => !$item['enabled'] ? 'excluded' : ($gaps ? 'partial' : 'calculated')];
            if ($item['enabled']) {
                if ($gaps) { $complete = false; foreach ($gaps as $gap) $missing[] = $item['id'] . ':' . $gap; }
                else { $known += $cost; ++$knownCount; $loss += $lossCost; }
            }
            foreach (['consumed_quantity','consumed_cost','loss_cost','budget_cost_at_actual_volume','price_variance','usage_variance','total_variance'] as $key) {
                if ($item[$key] !== null && (!is_finite($item[$key]) || abs($item[$key]) > 1e12)) throw new InvalidArgumentException('consumables_calculated_amount_out_of_range');
            }
            $normalized[] = $item;
        }
        if (!array_filter($normalized, static fn(array $row): bool => $row['enabled'])) { $missing[] = 'enabled_items_missing'; $complete = false; }
        if ($nights === null || $nights <= 0) $missing[] = 'whole_hotel_room_nights_missing_or_zero';
        if ($nightsSource === '') $missing[] = 'whole_hotel_room_nights_source_missing';
        $total = $complete ? round($known, 2) : null;
        if (!is_finite($known) || $known > 1e12 || !is_finite($loss) || $loss > 1e12) throw new InvalidArgumentException('consumables_calculated_amount_out_of_range');
        $perNight = $total !== null && $nights !== null && $nights > 0 && $nightsSource !== '' ? round($total / $nights, 6) : null;
        if ($perNight !== null && (!is_finite($perNight) || abs($perNight) > 1e12)) throw new InvalidArgumentException('consumables_calculated_unit_cost_out_of_range');
        return ['status' => $complete && $nights !== null && $nights > 0 && $nightsSource !== '' ? 'calculated' : 'partial',
            'source_quality' => $attested ? 'operator_attested' : 'unverified', 'currency' => 'CNY',
            'inputs' => ['occupied_room_nights' => $nights, 'occupied_room_nights_source_ref' => $nightsSource, 'denominator_scope' => $denominator, 'operator_attested' => $attested, 'items' => $normalized],
            'actual_consumed_cost' => $total, 'known_consumed_cost' => $knownCount > 0 ? round($known, 2) : null, 'separate_loss_cost' => $complete ? round($loss, 2) : null,
            'actual_consumables_cost_per_room_night' => $perNight,
            'missing_items' => array_values(array_unique($missing)), 'items' => $normalized,
            'formulas' => ['consumed_quantity' => 'opening + purchased + transfer_in - closing - transfer_out - returns - written_off',
                'consumed_cost' => 'consumed_quantity * confirmed_unit_cost', 'loss_cost' => 'written_off * confirmed_unit_cost (separate)',
                'price_variance' => '(actual_unit_cost - budget_unit_cost) * actual_consumption',
                'usage_variance' => '(actual_consumption - budget_usage_at_actual_volume) * budget_unit_cost'],
            'boundaries' => ['source_independently_verified' => false, 'procurement_is_not_consumption' => true,
                'automatic_scenario_adoption' => false, 'automatic_purchase_or_writeoff' => false, 'staff_waste_causality_established' => false]];
    }
    /** Compensated addition preserves small movements beside large opening/closing counts. */
    private function inventoryBalance(array $item): float
    {
        $terms = [$item['opening_quantity'], $item['purchased_quantity'], $item['transfer_in_quantity'],
            -$item['closing_quantity'], -$item['transfer_out_quantity'], -$item['returned_quantity'], -$item['written_off_quantity']];
        $sum = 0.0; $correction = 0.0;
        foreach ($terms as $term) {
            $next = $sum + $term;
            $correction += abs($sum) >= abs($term) ? ($sum - $next) + $term : ($term - $next) + $sum;
            $sum = $next;
        }
        $balance = $sum + $correction;
        // Noise is zero only if the normalized decimal inputs cancel exactly; a tolerance cannot erase a real deficit.
        $noise = PHP_FLOAT_EPSILON * array_sum(array_map('abs', $terms)) * count($terms);
        return $balance !== 0.0 && abs($balance) <= $noise && $this->decimalBalanceIsZero($terms) ? 0.0 : $balance;
    }
    private function decimalBalanceIsZero(array $terms): bool
    {
        $columns = [];
        foreach ($terms as $term) {
            if ($term == 0.0) continue;
            preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D',
                json_encode($term, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $parts);
            $fraction = $parts[3] ?? '';
            $digits = $parts[2] . $fraction;
            $power = (int)($parts[4] ?? 0) - strlen($fraction);
            $sign = $parts[1] === '-' ? -1 : 1;
            for ($index = strlen($digits) - 1; $index >= 0; --$index, ++$power) {
                $columns[$power] = ($columns[$power] ?? 0) + $sign * (int)$digits[$index];
            }
        }
        if ($columns === []) return true;
        $carry = 0;
        for ($power = min(array_keys($columns)), $last = max(array_keys($columns)); $power <= $last; ++$power) {
            $value = ($columns[$power] ?? 0) + $carry;
            $carry = (int)floor($value / 10);
            if ($value - $carry * 10 !== 0) return false;
        }
        return $carry === 0;
    }
    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 1e12) throw new InvalidArgumentException('consumables_number_invalid');
        return (float)$value;
    }
    private function text(mixed $value, int $limit): string
    {
        if (!is_scalar($value) || is_bool($value) || mb_strlen((string)$value) > $limit) throw new InvalidArgumentException('consumables_text_invalid');
        return trim((string)$value);
    }
    private function validDate(string $date): bool { $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date); return $d !== false && $d->format('Y-m-d') === $date; }
}
