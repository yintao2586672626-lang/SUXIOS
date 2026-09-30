<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\OtaStandardEtlService;
use think\facade\Db;

trait OnlineDataAnalysisEvidenceConcern
{
    private function buildOnlineDataAggregationGate(array $rows): array
    {
        $groups = [];
        $canonicalGrains = [];
        $identityGaps = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawPlatform = trim((string)($row['platform'] ?? ''));
            if ($rawPlatform === '') {
                $rawPlatform = trim((string)($row['source'] ?? ''));
            }
            $platform = OtaStandardEtlService::canonicalPlatformKey($rawPlatform);
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            $metricDimension = trim((string)($row['dimension'] ?? ''));
            $hotelKey = $this->onlineDataHotelKey($row);
            $date = trim((string)($row['data_date'] ?? ''));
            $rowIdentityGaps = array_keys(array_filter([
                'platform_identity_missing' => !in_array($platform, ['ctrip', 'meituan', 'qunar'], true),
                'data_type_missing' => $dataType === '',
                'metric_dimension_missing' => $metricDimension === '',
                'business_date_missing' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1,
                'system_hotel_identity_missing' => (int)($row['system_hotel_id'] ?? 0) <= 0,
                'platform_hotel_identity_missing' => trim((string)($row['hotel_id'] ?? '')) === '',
                'hotel_identity_missing' => $hotelKey === '',
                'data_source_identity_missing' => (int)($row['data_source_id'] ?? 0) <= 0,
                'sync_task_identity_missing' => (int)($row['sync_task_id'] ?? 0) <= 0,
            ]));
            foreach ($rowIdentityGaps as $gap) {
                $identityGaps[$gap] = ($identityGaps[$gap] ?? 0) + 1;
            }
            $groupKey = implode('|', [$platform, $dataType, $metricDimension]);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'platform' => $platform !== '' ? $platform : 'unknown',
                    'data_type' => $dataType !== '' ? $dataType : 'untyped',
                    'dimension' => $metricDimension !== '' ? $metricDimension : 'unscoped_dimension',
                    'record_count' => 0,
                ];
            }
            $groups[$groupKey]['record_count']++;

            $canonicalGrain = implode('|', [
                $date,
                is_int($hotelKey) ? 'system:' . $hotelKey : 'ota:' . (string)$hotelKey,
                $groupKey,
            ]);
            $canonicalGrains[$canonicalGrain] = ($canonicalGrains[$canonicalGrain] ?? 0) + 1;
        }

        ksort($groups);
        $metricGroups = array_values($groups);
        $duplicateGrains = array_filter(
            $canonicalGrains,
            static fn(int $count): bool => $count > 1
        );
        $groupCount = count($metricGroups);
        $blocker = '';
        if (($identityGaps ?? []) !== []) {
            $blocker = 'aggregation_identity_incomplete';
        } elseif ($groupCount > 1) {
            $blocker = 'heterogeneous_metric_scope';
        } elseif ($duplicateGrains !== []) {
            $blocker = 'duplicate_canonical_grain';
        }

        return [
            'allowed' => $blocker === '',
            'status' => $rows === [] ? 'empty' : ($blocker === '' ? 'ready' : 'blocked'),
            'blocker' => $blocker,
            'blocked_reason' => match ($blocker) {
                'heterogeneous_metric_scope' => '当前筛选包含多个平台或指标维度，已阻断不可加总额；请缩小到单一指标范围。',
                'duplicate_canonical_grain' => '当前筛选在同一酒店、平台、业务日和指标维度下存在多条记录，已阻断重复快照相加。',
                'aggregation_identity_incomplete' => '当前筛选缺少平台、门店、业务日、指标维度或采集运行身份，已阻断数值汇总。',
                default => '',
            },
            'canonical_grain' => 'data_date + hotel + platform + data_type + dimension',
            'group_count' => $groupCount,
            'duplicate_grain_count' => count($duplicateGrains),
            'identity_gap_count' => array_sum($identityGaps ?? []),
            'identity_gap_codes' => array_keys($identityGaps ?? []),
            'blockers' => $blocker !== '' ? [$blocker] : [],
            'metric_groups' => $metricGroups,
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function buildOnlineDataSourceOwnershipGate(array $rows): array
    {
        if ($rows === []) {
            return ['allowed' => true, 'status' => 'empty', 'mismatch_count' => 0];
        }
        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => max(0, (int)($row['data_source_id'] ?? 0)),
            $rows
        ))));
        try {
            $sources = $sourceIds !== []
                ? Db::name('platform_data_sources')->field('id,tenant_id,system_hotel_id,platform,data_type')->whereIn('id', $sourceIds)->select()->toArray()
                : [];
        } catch (\Throwable) {
            return [
                'allowed' => false,
                'status' => 'blocked',
                'mismatch_count' => count($rows),
                'reason_code' => 'source_ownership_readback_unavailable',
            ];
        }
        $sourceMap = [];
        foreach ($sources as $source) {
            if (is_array($source) && (int)($source['id'] ?? 0) > 0) {
                $sourceMap[(int)$source['id']] = $source;
            }
        }
        $mismatches = 0;
        foreach ($rows as $row) {
            $source = $sourceMap[(int)($row['data_source_id'] ?? 0)] ?? null;
            $rowPlatform = OtaStandardEtlService::canonicalPlatformKey((string)(
                trim((string)($row['platform'] ?? '')) !== ''
                    ? $row['platform']
                    : ($row['source'] ?? '')
            ));
            $rowSourcePlatform = OtaStandardEtlService::canonicalPlatformKey((string)($row['source'] ?? ''));
            $sourcePlatform = is_array($source)
                ? OtaStandardEtlService::canonicalPlatformKey((string)($source['platform'] ?? ''))
                : '';
            // Ctrip owns collected Qunar facts while their metric channel stays Qunar.
            $ctripQunarSubchannel = $rowSourcePlatform === 'ctrip' && $rowPlatform === 'qunar' && $sourcePlatform === 'ctrip';
            if (!is_array($source)
                || (int)($source['system_hotel_id'] ?? 0) !== (int)($row['system_hotel_id'] ?? 0)
                || (int)($row['tenant_id'] ?? 0) <= 0
                || (int)($source['tenant_id'] ?? 0) !== (int)($row['tenant_id'] ?? 0)
                || $rowPlatform === ''
                || (!$ctripQunarSubchannel && ($rowSourcePlatform !== $rowPlatform || $sourcePlatform !== $rowPlatform))
                || (trim((string)($source['data_type'] ?? '')) !== ''
                    && strtolower(trim((string)$source['data_type']))
                        !== strtolower(trim((string)($row['data_type'] ?? ''))))
            ) {
                $mismatches++;
            }
        }
        return [
            'allowed' => $mismatches === 0,
            'status' => $mismatches === 0 ? 'ready' : 'blocked',
            'source_count' => count($sourceMap),
            'mismatch_count' => $mismatches,
            'reason_code' => $mismatches === 0 ? '' : 'source_ownership_mismatch',
        ];
    }

    private function buildOnlineDataCombinedAggregationGate(array $rows, bool $strictContractAvailable): array
    {
        $gate = $this->buildOnlineDataAggregationGate($rows);
        $ownership = $this->buildOnlineDataSourceOwnershipGate($rows);
        $gate['source_ownership_gate'] = $ownership;
        foreach ([
            'strict_evidence_contract_missing' => !$strictContractAvailable,
            'source_ownership_unverified' => ($ownership['allowed'] ?? false) !== true,
        ] as $blocker => $blocked) {
            if (!$blocked) continue;
            $gate['allowed'] = false;
            $gate['status'] = 'blocked';
            $gate['blockers'] = array_values(array_unique(array_merge($gate['blockers'], [$blocker])));
            if ($gate['blocker'] === '') {
                $gate['blocker'] = $blocker;
                $gate['blocked_reason'] = $blocker === 'strict_evidence_contract_missing'
                    ? '当前数据结构缺少完整采集与回读证据字段，已阻断数值汇总。'
                    : '当前筛选包含未通过数据源门店归属校验的记录，已阻断数值汇总。';
            }
        }
        return $gate;
    }

    private function applyStrictOnlineDataAnalysisEvidenceFilter($query, array $columns): bool
    {
        foreach ([
            'history_status', 'validation_status', 'readback_verified', 'data_period', 'is_final',
            'platform', 'source', 'dimension', 'system_hotel_id', 'hotel_id', 'data_source_id', 'sync_task_id',
            'compare_type', 'ingestion_method',
        ] as $requiredColumn) {
            if (!isset($columns[$requiredColumn])) {
                return false;
            }
        }

        $query
            ->where('history_status', 'success')
            ->where('validation_status', 'verified')
            ->where('readback_verified', 1)
            ->where('data_period', 'historical_daily')
            ->where('is_final', 1)
            ->where('compare_type', 'self')
            ->where('system_hotel_id', '>', 0)
            ->where('data_source_id', '>', 0)
            ->where('sync_task_id', '>', 0)
            ->where('platform', '<>', '')
            ->where('source', '<>', '')
            ->where('hotel_id', '<>', '')
            ->where('dimension', '<>', '')
            ->where('ingestion_method', '<>', '');
        return true;
    }
}
