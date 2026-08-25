<?php
declare(strict_types=1);

namespace app\service;

/**
 * Selects the PMS envelope already present in a Revenue Fact Layer contract.
 * It never reloads data and never aliases one provider to another.
 */
final class RevenuePmsFactSelectorService
{
    private const PROVIDERS = [
        'dingdandao_pms' => [
            'label' => '订单来了 PMS',
            'table' => 'dingdandao_operating_target_captures',
        ],
        'meituan_cloud_pms' => [
            'label' => '美团云 PMS',
            'table' => 'meituan_cloud_pms_captures',
        ],
    ];

    /** @return array<string,mixed> */
    public function select(array $factLayer): array
    {
        $binding = is_array($factLayer['pms_binding'] ?? null)
            ? $factLayer['pms_binding']
            : [];
        $sources = is_array($factLayer['sources'] ?? null)
            ? $factLayer['sources']
            : [];
        $sourceCompleteness = is_array(
            $factLayer['source_completeness'] ?? null
        ) ? $factLayer['source_completeness'] : [];
        $dateAlignment = is_array($factLayer['date_alignment'] ?? null)
            ? $factLayer['date_alignment']
            : [];
        $dateSources = is_array($dateAlignment['sources'] ?? null)
            ? $dateAlignment['sources']
            : [];
        $factSources = is_array($factLayer['facts'] ?? null)
            ? $factLayer['facts']
            : [];

        $bindingDeclared = array_key_exists('effective_provider', $binding);
        $effectiveProvider = trim((string)(
            $binding['effective_provider'] ?? ''
        ));
        if ($bindingDeclared) {
            $sourceKey = isset(self::PROVIDERS[$effectiveProvider])
                ? $effectiveProvider
                : 'pms';
            $selectionBasis = isset(self::PROVIDERS[$effectiveProvider])
                ? 'binding_effective_provider'
                : 'binding_provider_not_selectable';
        } else {
            $present = [];
            foreach (array_keys(self::PROVIDERS) as $candidate) {
                if (array_key_exists($candidate, $sources)
                    || array_key_exists($candidate, $sourceCompleteness)
                    || array_key_exists($candidate, $dateSources)
                    || array_key_exists($candidate, $factSources)
                ) {
                    $present[] = $candidate;
                }
            }
            if ($present === ['dingdandao_pms']) {
                $sourceKey = 'dingdandao_pms';
                $selectionBasis = 'legacy_single_dingdandao_source';
            } elseif ($present === ['meituan_cloud_pms']) {
                $sourceKey = 'meituan_cloud_pms';
                $selectionBasis = 'single_meituan_source_without_binding';
            } else {
                $sourceKey = 'pms';
                $selectionBasis = $present === []
                    ? 'provider_unknown'
                    : 'multiple_pms_sources_without_binding';
            }
        }

        $source = is_array($sources[$sourceKey] ?? null)
            ? $sources[$sourceKey]
            : [];
        $facts = is_array($source['facts'] ?? null)
            ? $source['facts']
            : (
                is_array($factSources[$sourceKey] ?? null)
                    ? $factSources[$sourceKey]
                    : []
            );
        $sourceStatus = trim((string)($source['data_status'] ?? ''));
        $completenessStatus = trim((string)(
            $sourceCompleteness[$sourceKey] ?? ''
        ));
        $dataStatus = $sourceStatus !== '' ? $sourceStatus : $completenessStatus;
        if ($sourceStatus !== ''
            && $completenessStatus !== ''
            && $sourceStatus !== $completenessStatus
        ) {
            $dataStatus = 'conflict';
        }
        if ($dataStatus === '') {
            $dataStatus = 'missing';
        }

        $descriptor = self::PROVIDERS[$sourceKey] ?? [
            'label' => 'PMS',
            'table' => null,
        ];
        return [
            'source_key' => $sourceKey,
            'provider' => isset(self::PROVIDERS[$sourceKey])
                ? $sourceKey
                : null,
            'label' => $descriptor['label'],
            'expected_table' => $descriptor['table'],
            'source' => $source,
            'facts' => $facts,
            'data_status' => $dataStatus,
            'source_status' => $sourceStatus !== '' ? $sourceStatus : null,
            'source_completeness_status' => $completenessStatus !== ''
                ? $completenessStatus
                : null,
            'date_alignment' => is_array($dateSources[$sourceKey] ?? null)
                ? $dateSources[$sourceKey]
                : [],
            'binding' => $binding,
            'legacy_fixture' => !$bindingDeclared
                && $sourceKey === 'dingdandao_pms',
            'selection_basis' => $selectionBasis,
        ];
    }
}
