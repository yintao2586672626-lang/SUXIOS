<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** Optional issue/count/cleaning evidence beside the existing inventory balance. */
final class ConsumablesOperationalReconciliationService
{
    public function calculate(array $input): array
    {
        $result = (new ConsumablesActualCostService())->calculate($input);
        $this->assertInventoryArithmetic($result['items']);
        $rawRows = array_values($input['items'] ?? []);
        $cleaningCount = $this->number($input['cleaning_count'] ?? null);
        if ($cleaningCount !== null && floor($cleaningCount) !== $cleaningCount) throw new InvalidArgumentException('consumables_cleaning_count_must_be_integer');
        $cleaningSource = $this->source($input['cleaning_count_source_ref'] ?? '');
        $recorded = $cleaningCount !== null || $cleaningSource !== '';
        $enabled = 0;
        $issueReadyCount = 0;
        $countReadyCount = 0;
        $knownIssuedCents = 0;
        $knownIssueDifferenceCents = 0;
        $knownCountDifferenceCents = 0;
        $issueDifferenceReadyCount = 0;
        $missing = [];

        foreach ($result['items'] as $index => &$row) {
            $raw = $rawRows[$index];
            $issued = $this->number($raw['issued_quantity'] ?? null);
            $issuedSource = $this->source($raw['issued_quantity_source_ref'] ?? '');
            $bookClosing = $this->number($raw['book_closing_quantity'] ?? null);
            $bookSource = $this->source($raw['book_closing_quantity_source_ref'] ?? '');
            $row += [
                'issued_quantity' => $issued,
                'issued_quantity_source_ref' => $issuedSource,
                'book_closing_quantity' => $bookClosing,
                'book_closing_quantity_source_ref' => $bookSource,
            ];
            $gaps = [];
            $inventorySourceReady = $row['source_ref'] !== '' && $this->validDate($row['source_date']);
            $issuedEvidenceReady = $issued !== null && $issuedSource !== '';
            $countEvidenceReady = $bookClosing !== null && $bookSource !== '' && $row['closing_quantity'] !== null && $inventorySourceReady;
            $issuedCost = $row['enabled'] && $issuedEvidenceReady && $inventorySourceReady && $row['unit_price'] !== null
                ? $this->valuedAmount($issued, $row['unit_price']) : null;
            $issueDifference = $row['enabled'] && $issuedEvidenceReady && $row['consumed_quantity'] !== null
                ? $this->quantity($row['consumed_quantity'] - $issued) : null;
            $issueDifferenceCost = $issueDifference !== null && $row['unit_price'] !== null
                ? $this->valuedAmount($issueDifference, $row['unit_price']) : null;
            $countDifference = $row['enabled'] && $countEvidenceReady ? $this->quantity($row['closing_quantity'] - $bookClosing) : null;
            $countDifferenceCost = $countDifference !== null && $row['unit_price'] !== null
                ? $this->valuedAmount($countDifference, $row['unit_price']) : null;

            if ($row['enabled']) {
                ++$enabled;
                $recorded = $recorded || $issued !== null || $issuedSource !== '' || $bookClosing !== null || $bookSource !== '';
                foreach (['issued_quantity' => $issued === null, 'issued_quantity_source_ref' => $issuedSource === '',
                    'book_closing_quantity' => $bookClosing === null, 'book_closing_quantity_source_ref' => $bookSource === '',
                    'inventory_source_evidence' => !$inventorySourceReady, 'confirmed_unit_price' => $row['unit_price'] === null,
                    'counted_closing_quantity' => $row['closing_quantity'] === null, 'inventory_balance_consumption' => $row['consumed_quantity'] === null] as $field => $absent) {
                    if ($absent) $gaps[] = $field;
                }
                if ($issuedCost !== null) {
                    ++$issueReadyCount;
                    $knownIssuedCents += (int)round($issuedCost * 100);
                }
                if ($issueDifferenceCost !== null) {
                    ++$issueDifferenceReadyCount;
                    $knownIssueDifferenceCents += (int)round($issueDifferenceCost * 100);
                }
                if ($countDifferenceCost !== null) {
                    ++$countReadyCount;
                    $knownCountDifferenceCents += (int)round($countDifferenceCost * 100);
                }
                foreach ($gaps as $gap) $missing[] = $row['id'] . ':' . $gap;
            }
            $row += [
                'issued_cost' => $row['enabled'] ? $issuedCost : null,
                'inventory_balance_minus_issued_quantity' => $row['enabled'] ? $issueDifference : null,
                'inventory_balance_minus_issued_cost' => $row['enabled'] ? $issueDifferenceCost : null,
                'counted_minus_book_closing_quantity' => $row['enabled'] ? $countDifference : null,
                'counted_minus_book_closing_cost' => $row['enabled'] ? $countDifferenceCost : null,
                'reconciliation_status' => !$row['enabled'] ? 'excluded' : ($gaps === [] ? 'calculated' : 'partial'),
                'reconciliation_missing_items' => $gaps,
            ];
        }
        unset($row);

        // Validate the final signed totals so an intermediate subtotal cannot depend on row order.
        $knownIssuedCost = $this->amount($knownIssuedCents / 100);
        $knownIssueDifference = $this->amount($knownIssueDifferenceCents / 100);
        $knownCountDifference = $this->amount($knownCountDifferenceCents / 100);

        if ($enabled === 0) $missing[] = 'enabled_items_missing';
        if ($cleaningCount === null || $cleaningCount <= 0) $missing[] = 'cleaning_count_missing_or_zero';
        if ($cleaningSource === '') $missing[] = 'cleaning_count_source_missing';
        $perCleaning = $result['actual_consumed_cost'] !== null && $cleaningCount !== null && $cleaningCount > 0 && $cleaningSource !== ''
            ? $this->unitCost($result['actual_consumed_cost'] / $cleaningCount) : null;
        $nights = $result['inputs']['occupied_room_nights'];
        $nightsSource = $result['inputs']['occupied_room_nights_source_ref'];
        $issuedTotal = $enabled > 0 && $issueReadyCount === $enabled ? $knownIssuedCost : null;

        $result['inputs']['cleaning_count'] = $cleaningCount;
        $result['inputs']['cleaning_count_source_ref'] = $cleaningSource;
        $result['inputs']['items'] = $result['items'];
        $result['reconciliation'] = [
            'contract_version' => 'consumables_operational_reconciliation.v1',
            'status' => !$recorded ? 'missing' : ($missing === [] && $result['status'] === 'calculated' ? 'calculated' : 'partial'),
            'source_quality' => $result['source_quality'],
            'currency' => 'CNY',
            'inventory_balance_status' => $result['status'],
            'inventory_balance_missing_items' => $result['missing_items'],
            'coverage' => ['enabled_item_count' => $enabled, 'issued_cost_ready_count' => $issueReadyCount,
                'issue_difference_ready_count' => $issueDifferenceReadyCount, 'inventory_count_comparison_ready_count' => $countReadyCount,
                'cleaning_denominator_ready' => $cleaningCount !== null && $cleaningCount > 0 && $cleaningSource !== '',
                'whole_hotel_room_night_denominator_ready' => $nights !== null && $nights > 0 && $nightsSource !== '',
                'inventory_balance_cost_ready' => $result['actual_consumed_cost'] !== null],
            'issued_cost' => $issuedTotal,
            'known_issued_cost' => $issueReadyCount > 0 ? $knownIssuedCost : null,
            'inventory_balance_minus_issued_cost' => $enabled > 0 && $issueDifferenceReadyCount === $enabled ? $knownIssueDifference : null,
            'counted_minus_book_closing_cost' => $enabled > 0 && $countReadyCount === $enabled ? $knownCountDifference : null,
            'inventory_balance_cost_per_cleaning' => $perCleaning,
            'issued_cost_per_room_night' => $issuedTotal !== null && $nights !== null && $nights > 0 && $nightsSource !== ''
                ? $this->unitCost($issuedTotal / $nights) : null,
            'missing_items' => array_values(array_unique($missing)),
            'formulas' => ['issued_cost' => 'issued_quantity * confirmed_unit_cost',
                'inventory_balance_minus_issued_quantity' => 'inventory_balance_consumption - issued_quantity',
                'counted_minus_book_closing_quantity' => 'counted_closing_quantity - book_closing_quantity',
                'inventory_balance_cost_per_cleaning' => 'inventory_balance_consumed_cost / sourced_cleaning_count'],
            'boundaries' => ['source_independently_verified' => false, 'issue_is_not_consumption' => true,
                'inventory_count_difference_is_not_automatic_loss' => true, 'purchase_cash_is_not_issued_cost' => true,
                'quantities_across_units_are_not_summed' => true, 'additional_quantities_use_item_base_unit' => true,
                'inventory_pool_cost_is_not_cleaning_only_cost' => true, 'automatic_scenario_adoption' => false,
                'automatic_inventory_or_writeoff_change' => false, 'staff_waste_causality_established' => false],
        ];
        return $result;
    }

    /** Do not publish a precise-looking zero when the shared inventory calculator lost quantity precision. */
    private function assertInventoryArithmetic(array $items): void
    {
        foreach ($items as $item) {
            if (!$item['enabled']) continue;
            foreach (['opening_quantity','purchased_quantity','transfer_in_quantity','closing_quantity','transfer_out_quantity','returned_quantity','written_off_quantity'] as $field) {
                if ($item[$field] === null) continue 2;
            }
            $terms = [$item['opening_quantity'], $item['purchased_quantity'], $item['transfer_in_quantity'],
                -$item['closing_quantity'], -$item['transfer_out_quantity'], -$item['returned_quantity'], -$item['written_off_quantity']];
            $sum = 0.0; $correction = 0.0;
            foreach ($terms as $term) {
                $next = $sum + $term;
                $correction += abs($sum) >= abs($term) ? ($sum - $next) + $term : ($term - $next) + $sum;
                $sum = $next;
            }
            $stable = $sum + $correction;
            $noise = PHP_FLOAT_EPSILON * array_sum(array_map('abs', $terms)) * count($terms);
            $naive = 0.0;
            foreach ($terms as $term) $naive += $term;
            $moneyImpact = $item['unit_price'] === null ? null : round(abs($stable) * $item['unit_price'], 2);
            if ($stable < 0 && round($naive, 6) >= 0 && (abs($stable) > $noise || $moneyImpact !== null && $moneyImpact > 0)) throw new InvalidArgumentException('库存负余额被数量精度掩盖，请核对库存和单位');
            if (round($stable, 6) !== round($naive, 6)) throw new InvalidArgumentException('库存数量跨度过大，当前精度无法可靠核算，请拆分后核对');
        }
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || is_string($value) && trim($value) === '') return null;
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 1e12) {
            throw new InvalidArgumentException('consumables_reconciliation_number_invalid');
        }
        if (is_string($value) && (float)$value === 0.0 && strpbrk(preg_split('/[eE]/', trim($value))[0], '123456789') !== false) throw new InvalidArgumentException('consumables_reconciliation_number_precision_loss');
        return (float)$value;
    }

    private function source(mixed $value): string
    {
        if (!is_scalar($value) || is_bool($value) || mb_strlen((string)$value) > 500) {
            throw new InvalidArgumentException('consumables_reconciliation_source_invalid');
        }
        return trim((string)$value);
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function quantity(float $value): float { return $this->bounded($value, null); }
    private function valuedAmount(float $quantity, float $price): float
    {
        $value = $quantity * $price;
        if ($quantity !== 0.0 && $price !== 0.0 && $value === 0.0) throw new InvalidArgumentException('consumables_reconciliation_calculated_amount_precision_loss');
        return $this->amount($value);
    }
    private function amount(float $value): float { return $this->bounded($value, 2); }
    private function unitCost(float $value): float { return $this->bounded($value, null); }
    private function bounded(float $value, ?int $precision): float
    {
        if (!is_finite($value) || abs($value) > 1e12) {
            throw new InvalidArgumentException('consumables_reconciliation_calculated_amount_out_of_range');
        }
        return $precision === null ? ($value == 0.0 ? 0.0 : $value) : round($value, $precision);
    }
}
