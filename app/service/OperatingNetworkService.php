<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Controlled cross-hotel operating network.
 *
 * Hotel profiles are immutable versions. Matching is deterministic and
 * explainable: each declared applicability dimension becomes matched,
 * missing, conflicting, or source-undeclared. Every result remains a draft;
 * this service never executes an action, writes OTA, or sends a message.
 */
final class OperatingNetworkService
{
    public const PROFILE_TABLE = 'hotel_operating_profiles';
    public const REVIEW_TABLE = 'hotel_operating_sop_replication_reviews';
    public const CONTRACT_VERSION = 'controlled_operating_network.v1';
    public const EXECUTION_SOURCE_MODULE = 'operating_network_replication';
    public const PROFILE_PREVIEW_SOURCE_METHOD = 'system_evidence_draft_preview_v1';
    private const EFFECT_REVIEW_TABLE = 'operation_effect_reviews';
    private const EXECUTION_INTENT_TABLE = 'operation_execution_intents';
    private const EXECUTION_TASK_TABLE = 'operation_execution_tasks';
    private const EXECUTION_EVIDENCE_TABLE = 'operation_execution_evidence';
    private const OPERATING_CYCLE_TABLE = 'hotel_operating_cycles';
    private const OPERATING_CYCLE_EVENT_TABLE = 'hotel_operating_cycle_events';
    private const OPERATING_CYCLE_EVIDENCE_TABLE = 'hotel_operating_cycle_evidence';
    private const COMPLETED_LOOP_STAGE = 'review_experience_promotion';

    /** @var array<string,array<string,bool>> */
    private array $tableColumnCache = [];

    /** @var null|\Closure(int,int,array<int,int>):array<string,mixed> */
    private ?\Closure $replicationReadResolver;

    public function __construct(?callable $replicationReadResolver = null)
    {
        $this->replicationReadResolver = $replicationReadResolver === null
            ? null
            : \Closure::fromCallable($replicationReadResolver);
    }

    /** @var array<string,string> */
    public const PROFILE_DIMENSIONS = [
        'hotel_type_and_scale' => '酒店类型和体量',
        'city_district_demand' => '城市、商圈和需求结构',
        'price_band' => '价格带',
        'room_type_structure' => '房型结构',
        'platform_channel_structure' => '平台与渠道结构',
        'seasonality' => '淡旺季',
        'data_quality' => '数据质量',
        'pre_action_state' => '执行前状态',
    ];

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveProfile(
        int $tenantId,
        int $hotelId,
        array $input,
        int $createdBy
    ): array {
        $this->assertProfileTableReady();
        $this->assertHotelIdentity($tenantId, $hotelId);

        $dimensions = self::normalizeProfileDimensions($input['profile'] ?? $input['dimensions'] ?? []);
        $qualityStatus = strtolower(trim((string)($input['quality_status'] ?? 'unverified')));
        if (!in_array($qualityStatus, ['verified', 'partial', 'unverified'], true)) {
            throw new InvalidArgumentException('酒店经营画像质量状态必须是 verified、partial 或 unverified');
        }
        $effectiveDate = $this->date((string)($input['effective_date'] ?? ''), '画像生效日期');
        $validUntil = $this->optionalDate((string)($input['evidence_valid_until'] ?? ''), '画像证据有效期');
        if ($validUntil !== null && $validUntil < $effectiveDate) {
            throw new InvalidArgumentException('画像证据有效期不能早于生效日期');
        }
        $sourceMethod = mb_substr(trim((string)($input['source_method'] ?? '')), 0, 80);
        if ($sourceMethod === '') {
            throw new InvalidArgumentException('酒店经营画像必须声明来源方法');
        }
        $evidenceRefs = self::textItems($input['evidence_refs'] ?? [], 100, 300);
        $onboarding = $this->normalizeOnboardingConfirmations($input['onboarding'] ?? []);
        $missingDimensions = array_keys(array_filter(
            $dimensions,
            static fn(array $values): bool => $values === []
        ));
        if ($qualityStatus === 'verified') {
            if ($missingDimensions !== []) {
                throw new InvalidArgumentException(
                    'verified 酒店经营画像缺少：' . implode('、', array_map(
                        static fn(string $key): string => self::PROFILE_DIMENSIONS[$key] ?? $key,
                        $missingDimensions
                    ))
                );
            }
            if ($evidenceRefs === [] || $validUntil === null) {
                throw new InvalidArgumentException('verified 酒店经营画像必须保存证据引用和证据有效期');
            }
        }

        $profile = [
            'contract_version' => self::CONTRACT_VERSION,
            'dimensions' => $dimensions,
            'onboarding_confirmations' => $onboarding,
            'notes' => mb_substr(trim((string)($input['notes'] ?? '')), 0, 1000),
        ];
        $content = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'profile' => $profile,
            'quality_status' => $qualityStatus,
            'effective_date' => $effectiveDate,
            'evidence_valid_until' => $validUntil,
            'evidence_refs' => $evidenceRefs,
            'source_method' => $sourceMethod,
        ];
        $digest = $this->digest($content);

        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $profile,
            $qualityStatus,
            $effectiveDate,
            $validUntil,
            $evidenceRefs,
            $sourceMethod,
            $digest,
            $createdBy
        ): array {
            $current = Db::name(self::PROFILE_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('is_current', 1)
                ->whereNull('deleted_at')
                ->order('version_no', 'desc')
                ->find();
            if (is_array($current) && hash_equals((string)($current['content_digest'] ?? ''), $digest)) {
                return [
                    'profile' => $this->normalizeProfile($current),
                    'created' => false,
                    'persistence_status' => 'readback_verified',
                    'write_boundaries' => $this->boundaries(),
                ];
            }

            $versionNo = is_array($current) ? ((int)$current['version_no'] + 1) : 1;
            $previousVersionId = is_array($current) ? (int)$current['id'] : null;
            if (is_array($current)) {
                Db::name(self::PROFILE_TABLE)->where('id', (int)$current['id'])->update([
                    'is_current' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $now = date('Y-m-d H:i:s');
            $id = (int)Db::name(self::PROFILE_TABLE)->insertGetId([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'version_no' => $versionNo,
                'previous_version_id' => $previousVersionId,
                'profile_json' => $this->encode($profile),
                'quality_status' => $qualityStatus,
                'effective_date' => $effectiveDate,
                'evidence_valid_until' => $validUntil,
                'evidence_refs_json' => $this->encode($evidenceRefs),
                'source_method' => $sourceMethod,
                'content_digest' => $digest,
                'is_current' => 1,
                'created_by' => max(0, $createdBy),
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            if ($id <= 0) {
                throw new RuntimeException('酒店经营画像保存失败：未取得记录ID');
            }
            $saved = $this->readProfileVersion($id, $tenantId, [$hotelId]);
            if ((int)$saved['hotel_id'] !== $hotelId
                || (int)$saved['version_no'] !== $versionNo
                || (string)$saved['content_digest'] !== $digest
                || ($saved['is_current'] ?? false) !== true
            ) {
                throw new RuntimeException('酒店经营画像已写入但严格回读校验失败');
            }
            return [
                'profile' => $saved,
                'created' => true,
                'persistence_status' => 'readback_verified',
                'write_boundaries' => $this->boundaries(),
            ];
        });
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function overview(int $tenantId, int $hotelId, array $hotelIds): array
    {
        $hotelIds = $this->ids($hotelIds);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权查看该酒店经营网络');
        }
        $this->assertHotelIdentity($tenantId, $hotelId);
        if (!$this->tableExists(self::PROFILE_TABLE)) {
            return [
                'data_status' => 'migration_required',
                'profile' => null,
                'onboarding' => $this->missingOnboarding('operating_profile_table_missing'),
                'comparable_hotels' => [],
                'verified_sops' => [],
                'replications' => $this->emptyReplicationList('migration_required'),
                'network_asset_summary' => $this->emptyNetworkAssetSummary('migration_required'),
                'hotel_options' => $this->hotelOptions($tenantId, $hotelIds),
                'data_gaps' => [['code' => 'operating_profile_table_missing', 'message' => '酒店经营画像表尚未启用。']],
                'boundaries' => $this->boundaries(),
            ];
        }

        $profile = $this->currentProfile($tenantId, $hotelId);
        $operatingLoop = $this->firstOperatingLoop($tenantId, $hotelId);
        $comparables = $operatingLoop === null
            ? []
            : $this->comparableHotels($tenantId, $hotelId, $hotelIds, $profile);
        $onboarding = $this->onboarding($tenantId, $hotelId, $profile, $comparables, $operatingLoop);
        $verifiedSops = $this->verifiedSops($tenantId, $hotelIds, $hotelId);
        $replications = $this->targetReplicationList($tenantId, $hotelId, $hotelIds);
        $dataGaps = $this->profileDataGaps($profile);
        $dataStatus = 'ok';
        if (($replications['data_status'] ?? '') === 'migration_required') {
            $dataStatus = 'migration_required';
            $dataGaps[] = [
                'code' => 'operating_sop_replication_table_missing',
                'message' => 'SOP复制草稿表尚未启用，复制与恢复入口保持阻塞。',
            ];
        } elseif (($replications['data_status'] ?? '') !== 'ok') {
            $dataStatus = 'partial';
            $dataGaps[] = [
                'code' => 'operating_sop_replication_readback_partial',
                'message' => '部分复制草稿因来源店权限变化、截断或回读失败而未返回。',
            ];
        }
        return [
            'data_status' => $dataStatus,
            'profile' => $profile,
            'onboarding' => $onboarding,
            'comparable_hotels' => $comparables,
            'verified_sops' => $verifiedSops,
            'replications' => $replications,
            'network_asset_summary' => $this->networkAssetSummary($tenantId, $hotelIds),
            'hotel_options' => $this->hotelOptions($tenantId, $hotelIds),
            'data_gaps' => $dataGaps,
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    private function targetReplicationList(int $tenantId, int $targetHotelId, array $hotelIds): array
    {
        if (!$this->tableExists(OperatingSopService::REPLICATION_TABLE)) {
            return $this->emptyReplicationList('migration_required');
        }
        $baseQuery = Db::name(OperatingSopService::REPLICATION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('target_hotel_id', $targetHotelId)
            ->whereNull('deleted_at');
        $matchedTotal = (int)(clone $baseQuery)->count();
        $query = (clone $baseQuery)->whereIn('source_hotel_id', $hotelIds);
        $accessibleTotal = (int)(clone $query)->count();
        $limit = 50;
        $rows = $query->order('id', 'desc')->limit($limit)->select()->toArray();
        $list = [];
        $failures = [];
        foreach ($rows as $row) {
            $replicationId = (int)($row['id'] ?? 0);
            if ($replicationId <= 0) {
                continue;
            }
            try {
                $list[] = $this->readReplicationForOverview($replicationId, $tenantId, $hotelIds);
            } catch (RuntimeException $exception) {
                if (!$this->isDegradableReplicationReadbackFailure($exception)) {
                    throw $exception;
                }
                $failures[] = [
                    'replication_id' => $replicationId,
                    'status' => 'unavailable',
                    'reason_code' => 'replication_exact_readback_failed',
                ];
            }
        }
        $unavailableCount = max(0, $matchedTotal - $accessibleTotal) + count($failures);
        $truncated = $accessibleTotal > count($rows);
        return [
            'data_status' => $unavailableCount > 0 || $truncated ? 'partial' : 'ok',
            'list' => $list,
            'matched_total' => $matchedTotal,
            'accessible_total' => $accessibleTotal,
            'returned_count' => count($list),
            'unavailable_count' => $unavailableCount,
            'unavailable_rows' => $failures,
            'truncated' => $truncated,
        ];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    private function readReplicationForOverview(int $replicationId, int $tenantId, array $hotelIds): array
    {
        if ($this->replicationReadResolver !== null) {
            return ($this->replicationReadResolver)($replicationId, $tenantId, $hotelIds);
        }

        return (new OperatingSopService())->readReplication($replicationId, $tenantId, $hotelIds);
    }

    private function isDegradableReplicationReadbackFailure(RuntimeException $exception): bool
    {
        return $exception->getMessage() === 'operating SOP replication not found';
    }

    /** @return array<string,mixed> */
    private function emptyReplicationList(string $status): array
    {
        return [
            'data_status' => $status,
            'list' => [],
            'matched_total' => 0,
            'accessible_total' => 0,
            'returned_count' => 0,
            'unavailable_count' => 0,
            'unavailable_rows' => [],
            'truncated' => false,
        ];
    }

    /**
     * Build a read-only, evidence-scoped profile draft. Missing facts remain
     * empty and the result can never become a verified profile automatically.
     *
     * @param list<int> $hotelIds
     * @return array<string,mixed>
     */
    public function previewProfileDraft(int $tenantId, int $hotelId, array $hotelIds): array
    {
        $hotelIds = $this->ids($hotelIds);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权生成该酒店经营画像草稿');
        }
        $this->assertHotelIdentity($tenantId, $hotelId);

        $dimensions = self::normalizeProfileDimensions([]);
        $dimensionEvidence = [];
        foreach (self::PROFILE_DIMENSIONS as $key => $label) {
            $dimensionEvidence[$key] = [
                'label' => $label,
                'status' => 'missing',
                'values' => [],
                'evidence_refs' => [],
                'source_scopes' => [],
                'confirmation_gaps' => [],
            ];
        }

        $evidenceRefs = [];
        $metadataUpdatedDates = [];
        $master = $this->profileDraftHotelMaster($tenantId, $hotelId);
        $city = trim((string)($master['city'] ?? ''));
        if ($city !== '') {
            $this->addProfileDraftEvidence(
                $dimensionEvidence,
                'city_district_demand',
                ['城市：' . $city],
                ['hotels#' . $hotelId],
                ['suxios_hotel_master'],
                ['business_district_requires_human_confirmation', 'demand_structure_requires_human_confirmation']
            );
            $masterUpdatedAt = trim((string)($master['update_time'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/D', $masterUpdatedAt, $matches) === 1) {
                $metadataUpdatedDates[] = $matches[0];
            }
        }

        $roomFacts = $this->profileDraftRoomFacts($tenantId, $hotelId);
        if ($roomFacts['physical_room_count'] > 0) {
            $this->addProfileDraftEvidence(
                $dimensionEvidence,
                'hotel_type_and_scale',
                ['物理房量：' . $roomFacts['physical_room_count'] . '间'],
                $roomFacts['evidence_refs'],
                ['suxios_room_type_master'],
                ['hotel_type_requires_human_confirmation']
            );
        }
        if ($roomFacts['room_type_values'] !== []) {
            $this->addProfileDraftEvidence(
                $dimensionEvidence,
                'room_type_structure',
                $roomFacts['room_type_values'],
                $roomFacts['evidence_refs'],
                ['suxios_room_type_master'],
                ['room_rate_mapping_requires_human_confirmation']
            );
        }
        if ($roomFacts['price_band_value'] !== null) {
            $this->addProfileDraftEvidence(
                $dimensionEvidence,
                'price_band',
                [$roomFacts['price_band_value']],
                $roomFacts['evidence_refs'],
                ['suxios_room_type_master'],
                ['configured_price_band_requires_human_confirmation']
            );
        }
        foreach ($roomFacts['evidence_dates'] as $date) {
            $metadataUpdatedDates[] = $date;
        }

        $bindings = $this->profileDraftDataSourceBindings($tenantId, $hotelId);
        $bindingPlatforms = [];
        $bindingRefs = [];
        foreach ($bindings as $binding) {
            $platform = $this->platformLabel((string)($binding['platform'] ?? ''));
            if ($platform !== '') {
                $bindingPlatforms[$platform] = true;
            }
            $bindingId = (int)($binding['id'] ?? 0);
            if ($bindingId > 0) {
                $bindingRefs[] = 'platform_data_sources#' . $bindingId;
            }
        }
        if ($bindingPlatforms !== []) {
            $this->addProfileDraftEvidence(
                $dimensionEvidence,
                'platform_channel_structure',
                array_map(static fn(string $platform): string => '已绑定渠道：' . $platform, array_keys($bindingPlatforms)),
                $bindingRefs,
                ['platform_data_source_binding'],
                []
            );
        }

        $truthFacts = $this->profileDraftTruthFacts($tenantId, $hotelId);
        if ($truthFacts['verified_count'] > 0) {
            $verifiedPlatformValues = array_map(
                static fn(string $platform): string => '有完整真值门证据的渠道：' . $platform,
                $truthFacts['verified_platforms']
            );
            if ($verifiedPlatformValues !== []) {
                $this->addProfileDraftEvidence(
                    $dimensionEvidence,
                    'platform_channel_structure',
                    $verifiedPlatformValues,
                    $truthFacts['verified_evidence_refs'],
                    ['ota_channel_verified_truth_envelope'],
                    []
                );
            }
        }
        if ($truthFacts['candidate_count'] > 0) {
            $statusCounts = $truthFacts['status_counts'];
            $qualityValues = [sprintf(
                'OTA保存回读候选真值核验（已评估%d/%d）：已验证%d条、部分%d条、未验证%d条、采集失败%d条',
                $truthFacts['evaluated_candidate_count'],
                $truthFacts['candidate_count'],
                $statusCounts['verified'],
                $statusCounts['partial'],
                $statusCounts['unverified'],
                $statusCounts['collection_failed']
            )];
            $qualityGaps = [];
            if ($truthFacts['verified_count'] > 0) {
                $qualityValues[] = '完整真值门事实：' . $truthFacts['verified_count'] . '条';
                $qualityGaps[] = 'evidence_validity_requires_human_confirmation';
            } else {
                $qualityGaps[] = 'strict_ota_fact_verification_missing';
            }
            $this->addProfileDraftEvidence(
                $dimensionEvidence,
                'data_quality',
                $qualityValues,
                $truthFacts['candidate_evidence_refs'],
                ['ota_channel_truth_status'],
                $qualityGaps
            );
        }

        $dataGaps = [];
        foreach ($dimensionEvidence as $key => &$evidence) {
            $evidence['values'] = self::textItems($evidence['values'], 30, 120);
            $evidence['evidence_refs'] = self::textItems($evidence['evidence_refs'], 100, 300);
            $evidence['source_scopes'] = self::textItems($evidence['source_scopes'], 20, 120);
            $evidence['confirmation_gaps'] = self::textItems($evidence['confirmation_gaps'], 20, 120);
            $evidence['status'] = $evidence['values'] === [] ? 'missing' : 'derived_unverified';
            $dimensions[$key] = $evidence['values'];
            foreach ($evidence['evidence_refs'] as $ref) {
                $evidenceRefs[$ref] = true;
            }
            if ($evidence['values'] === []) {
                $dataGaps[] = [
                    'code' => 'profile_draft_' . $key . '_missing',
                    'dimension' => $key,
                    'message' => self::PROFILE_DIMENSIONS[$key] . '缺少可直接使用的当前证据，保持空白。',
                ];
            }
            foreach ($evidence['confirmation_gaps'] as $gap) {
                $dataGaps[] = [
                    'code' => $gap,
                    'dimension' => $key,
                    'message' => $this->profileDraftGapMessage($gap),
                ];
            }
        }
        unset($evidence);

        $filledDimensionCount = count(array_filter($dimensions, static fn(array $values): bool => $values !== []));
        // Profile effective dates are business dates. Hotel/room master update
        // timestamps are metadata only and must never advance this value.
        $effectiveDate = $truthFacts['verified_business_date_end'] ?? '';
        $draft = [
            'hotel_id' => $hotelId,
            'profile' => [
                'contract_version' => self::CONTRACT_VERSION,
                'dimensions' => $dimensions,
                'onboarding_confirmations' => [
                    'room_rate_mapping' => ['status' => 'missing', 'evidence_refs' => []],
                    'metric_definition' => ['status' => 'missing', 'evidence_refs' => []],
                ],
                'notes' => '',
            ],
            'quality_status' => 'unverified',
            'effective_date' => $effectiveDate,
            'evidence_valid_until' => null,
            'evidence_refs' => array_keys($evidenceRefs),
            'source_method' => self::PROFILE_PREVIEW_SOURCE_METHOD,
        ];
        $summary = [
            'filled_dimension_count' => $filledDimensionCount,
            'missing_dimension_count' => count(self::PROFILE_DIMENSIONS) - $filledDimensionCount,
            'confirmation_gap_count' => count($dataGaps),
            'active_binding_count' => count($bindings),
            'verified_fact_count' => $truthFacts['verified_count'],
            'verified_platforms' => $truthFacts['verified_platforms'],
            'verified_business_date_start' => $truthFacts['verified_business_date_start'],
            'verified_business_date_end' => $truthFacts['verified_business_date_end'],
            'readback_candidate_count' => $truthFacts['candidate_count'],
            'evaluated_readback_candidate_count' => $truthFacts['evaluated_candidate_count'],
            'readback_candidate_status_counts' => $truthFacts['status_counts'],
            'readback_candidate_evaluation_truncated' => $truthFacts['evaluation_truncated'],
            'metadata_updated_date' => $metadataUpdatedDates === [] ? null : max($metadataUpdatedDates),
            'room_type_count' => $roomFacts['room_type_count'],
        ];
        $previewStatus = $filledDimensionCount === 0
            ? 'unavailable'
            : ($filledDimensionCount === count(self::PROFILE_DIMENSIONS) ? 'ready' : 'partial');
        $digestPayload = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'draft' => $draft,
            'dimension_evidence' => $dimensionEvidence,
            'data_gaps' => $dataGaps,
            'summary' => $summary,
        ];

        return [
            'data_status' => 'ok',
            'preview_status' => $previewStatus,
            'preview_only' => true,
            'persistence_status' => 'not_persisted',
            'automatic_verification' => false,
            'hotel_id' => $hotelId,
            'draft' => $draft,
            'dimension_evidence' => $dimensionEvidence,
            'data_gaps' => $dataGaps,
            'summary' => $summary,
            'preview_digest' => $this->digest($digestPayload),
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    private function networkAssetSummary(int $tenantId, array $hotelIds): array
    {
        if (!$this->tableExists(self::PROFILE_TABLE)) {
            return $this->emptyNetworkAssetSummary('migration_required');
        }
        $profileRows = Db::name(self::PROFILE_TABLE)
            ->where('tenant_id', $tenantId)
            ->whereIn('hotel_id', $hotelIds)
            ->where('is_current', 1)
            ->whereNull('deleted_at')
            ->select()
            ->toArray();
        $verifiedProfileCount = 0;
        $networkReadyProfileCount = 0;
        $operatingLoopHotelIds = [];
        foreach ($hotelIds as $candidateHotelId) {
            $loop = $this->firstOperatingLoop($tenantId, $candidateHotelId);
            if ($loop !== null) {
                $operatingLoopHotelIds[$candidateHotelId] = true;
            }
        }
        foreach ($profileRows as $row) {
            $profile = $this->normalizeProfile($row);
            if ($this->isUsableProfile($profile)) {
                $verifiedProfileCount++;
                if (isset($operatingLoopHotelIds[(int)$profile['hotel_id']])) {
                    $networkReadyProfileCount++;
                }
            }
        }

        $allSops = $this->verifiedSops($tenantId, $hotelIds, 0);
        $eligibleSopCount = count(array_filter(
            $allSops,
            static fn(array $sop): bool => ($sop['replication_eligibility'] ?? '') === 'eligible_for_validation_draft'
        ));

        $replicationCount = 0;
        $validationReadyDraftCount = 0;
        $blockedDraftCount = 0;
        if ($this->tableExists(OperatingSopService::REPLICATION_TABLE)) {
            $replications = Db::name(OperatingSopService::REPLICATION_TABLE)
                ->where('tenant_id', $tenantId)
                ->whereIn('source_hotel_id', $hotelIds)
                ->whereIn('target_hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->select()
                ->toArray();
            $replicationCount = count($replications);
            foreach ($replications as $replication) {
                if ((string)($replication['status'] ?? '') === 'draft_pending_target_validation') {
                    $validationReadyDraftCount++;
                } else {
                    $blockedDraftCount++;
                }
            }
        }

        $reviewCount = 0;
        $verifiedReviewCount = 0;
        $successCount = 0;
        $failedCount = 0;
        $stoppedCount = 0;
        $inconclusiveCount = 0;
        $unverifiedReviewCount = 0;
        $reviewedTargetHotelIds = [];
        if ($this->tableExists(self::REVIEW_TABLE)) {
            $reviews = Db::name(self::REVIEW_TABLE)
                ->where('tenant_id', $tenantId)
                ->whereIn('source_hotel_id', $hotelIds)
                ->whereIn('target_hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->select()
                ->toArray();
            $reviewCount = count($reviews);
            foreach ($reviews as $row) {
                $outcome = (string)($row['outcome'] ?? '');
                $review = $this->decode($row['review_json'] ?? null);
                if ($outcome === 'inconclusive') {
                    $inconclusiveCount++;
                    continue;
                }
                if ((string)($review['evidence_verification']['status'] ?? '') !== 'verified'
                    || (string)($review['evidence_verification']['lineage_status'] ?? '') !== 'verified'
                ) {
                    $unverifiedReviewCount++;
                    continue;
                }
                $verifiedReviewCount++;
                $reviewedTargetHotelIds[(int)($row['target_hotel_id'] ?? 0)] = true;
                if ($outcome === 'success') {
                    $successCount++;
                } elseif ($outcome === 'failed') {
                    $failedCount++;
                } elseif ($outcome === 'stopped') {
                    $stoppedCount++;
                }
            }
        }
        unset($reviewedTargetHotelIds[0]);
        $reviewedTargetHotelCount = count($reviewedTargetHotelIds);
        $fieldEvidenceStatus = $verifiedReviewCount === 0
            ? 'none'
            : (($verifiedReviewCount >= 3 && $reviewedTargetHotelCount >= 2) ? 'repeated' : 'emerging');

        if ($networkReadyProfileCount < 2) {
            $learningStatus = 'awaiting_comparable_profiles';
            $nextGap = '至少两家酒店需要完整、当前有效的核验画像和权威经营闭环。';
        } elseif ($eligibleSopCount === 0) {
            $learningStatus = 'awaiting_eligible_sops';
            $nextGap = '尚无同时具备完整适用画像、动作条件和有效证据的正式 SOP。';
        } elseif ($replicationCount === 0) {
            $learningStatus = 'awaiting_validation_drafts';
            $nextGap = '尚未保存跨店待验证草稿。';
        } elseif ($verifiedReviewCount === 0) {
            $learningStatus = 'awaiting_verified_replication_reviews';
            $nextGap = '尚无绑定权威经营闭环的成功、失败或停止复盘。';
        } else {
            $learningStatus = 'learning_active';
            $nextGap = $fieldEvidenceStatus === 'repeated'
                ? '继续积累跨酒店复盘并保留相反样本，仍不授权自动执行。'
                : '继续积累至少两家目标酒店的多轮成功与失败复盘。';
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'hotel_count' => count($this->hotelNameMap($tenantId, $hotelIds)),
            'current_profile_count' => count($profileRows),
            'verified_current_profile_count' => $verifiedProfileCount,
            'network_ready_profile_count' => $networkReadyProfileCount,
            'authoritative_operating_loop_hotel_count' => count($operatingLoopHotelIds),
            'formal_sop_count' => count($allSops),
            'eligible_sop_count' => $eligibleSopCount,
            'replication_draft_count' => $replicationCount,
            'validation_ready_draft_count' => $validationReadyDraftCount,
            'blocked_draft_count' => $blockedDraftCount,
            'replication_review_count' => $reviewCount,
            'verified_replication_review_count' => $verifiedReviewCount,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'stopped_count' => $stoppedCount,
            'inconclusive_count' => $inconclusiveCount,
            'unverified_review_count' => $unverifiedReviewCount,
            'reviewed_target_hotel_count' => $reviewedTargetHotelCount,
            'field_evidence_status' => $fieldEvidenceStatus,
            'network_learning_status' => $learningStatus,
            'next_gap' => $nextGap,
            'field_validated' => false,
            'automatic_execution' => false,
            'ota_write' => false,
            'external_message' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyNetworkAssetSummary(string $status): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'hotel_count' => 0,
            'current_profile_count' => 0,
            'verified_current_profile_count' => 0,
            'network_ready_profile_count' => 0,
            'authoritative_operating_loop_hotel_count' => 0,
            'formal_sop_count' => 0,
            'eligible_sop_count' => 0,
            'replication_draft_count' => 0,
            'validation_ready_draft_count' => 0,
            'blocked_draft_count' => 0,
            'replication_review_count' => 0,
            'verified_replication_review_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'stopped_count' => 0,
            'inconclusive_count' => 0,
            'unverified_review_count' => 0,
            'reviewed_target_hotel_count' => 0,
            'field_evidence_status' => 'none',
            'network_learning_status' => $status,
            'next_gap' => '经营网络表尚未就绪。',
            'field_validated' => false,
            'automatic_execution' => false,
            'ota_write' => false,
            'external_message' => false,
        ];
    }

    /**
     * @param array<string,mixed> $sourceSop
     * @param list<int> $accessibleHotelIds
     * @return array<string,mixed>
     */
    public function assessApplicability(
        array $sourceSop,
        int $tenantId,
        int $targetHotelId,
        array $accessibleHotelIds
    ): array {
        $accessibleHotelIds = $this->ids($accessibleHotelIds);
        if (!in_array($targetHotelId, $accessibleHotelIds, true)) {
            throw new RuntimeException('目标酒店不在当前可访问范围');
        }
        $scope = is_array($sourceSop['scope'] ?? null) ? $sourceSop['scope'] : [];
        $sourceDimensions = self::normalizeProfileDimensions($scope['applicability_profile'] ?? []);
        $sourceGaps = [];
        foreach (self::PROFILE_DIMENSIONS as $key => $label) {
            if (($sourceDimensions[$key] ?? []) === []) {
                $sourceGaps[] = [
                    'code' => 'source_applicability_' . $key . '_missing',
                    'dimension' => $key,
                    'label' => $label,
                    'message' => '来源经验未声明' . $label . '。',
                ];
            }
        }
        foreach ([
            'action_parameters' => '动作参数',
            'success_conditions' => '成功条件',
            'failure_samples' => '失败样本',
            'stop_conditions' => '停止条件',
        ] as $field => $label) {
            $values = $field === 'stop_conditions'
                ? array_values((array)($sourceSop['stop_conditions'] ?? []))
                : array_values((array)($scope[$field] ?? []));
            if ($values === []) {
                $sourceGaps[] = [
                    'code' => 'source_' . $field . '_missing',
                    'dimension' => $field,
                    'label' => $label,
                    'message' => '来源经验未声明' . $label . '。',
                ];
            }
        }
        $sourceValidUntil = $this->optionalDate((string)($scope['evidence_valid_until'] ?? ''), '来源经验有效期');
        $sourceExpired = $sourceValidUntil === null || $sourceValidUntil < date('Y-m-d');
        if ($sourceExpired) {
            $sourceGaps[] = [
                'code' => $sourceValidUntil === null ? 'source_evidence_expiry_missing' : 'source_evidence_expired',
                'dimension' => 'evidence_valid_until',
                'label' => '证据有效期',
                'message' => $sourceValidUntil === null ? '来源经验未声明证据有效期。' : '来源经验的证据已过期。',
            ];
        }
        $sourceHotelId = (int)($sourceSop['hotel_id'] ?? 0);
        $sourceOperatingLoop = $sourceHotelId > 0
            ? $this->firstOperatingLoop($tenantId, $sourceHotelId)
            : null;
        if ($sourceOperatingLoop === null) {
            $sourceGaps[] = [
                'code' => 'source_authoritative_operating_loop_missing',
                'dimension' => 'source_operating_loop',
                'label' => '来源酒店首次经营闭环',
                'message' => '来源酒店尚无完成并可回读的权威经营闭环。',
            ];
        }

        $targetProfile = $this->tableExists(self::PROFILE_TABLE)
            ? $this->currentProfile($tenantId, $targetHotelId)
            : null;
        $targetDimensions = self::normalizeProfileDimensions(
            is_array($targetProfile['profile']['dimensions'] ?? null)
                ? $targetProfile['profile']['dimensions']
                : []
        );
        $comparison = $this->compareDimensions($sourceDimensions, $targetDimensions, true);
        $dimensionResults = $comparison['results'];
        $matched = $comparison['matched'];
        $missing = $comparison['missing'];
        $conflicts = $comparison['conflicts'];

        $targetProfileMissing = $targetProfile === null;
        $targetProfileExpired = !$targetProfileMissing
            && (trim((string)($targetProfile['evidence_valid_until'] ?? '')) === ''
                || (string)$targetProfile['evidence_valid_until'] < date('Y-m-d'));
        $targetProfileUnverified = !$targetProfileMissing
            && (string)($targetProfile['quality_status'] ?? '') !== 'verified';
        $targetOperatingLoop = $this->firstOperatingLoop($tenantId, $targetHotelId);
        $replicationEvidence = $this->replicationEvidence(
            $tenantId,
            (int)($sourceSop['id'] ?? 0),
            $targetHotelId,
            $targetProfile,
            $accessibleHotelIds
        );
        $counterexamples = $replicationEvidence['counterexamples'];
        $dataGaps = $sourceGaps;
        if ($targetProfileMissing) {
            $dataGaps[] = [
                'code' => 'target_operating_profile_missing',
                'dimension' => 'target_profile',
                'label' => '目标酒店经营画像',
                'message' => '目标酒店尚未保存经营画像。',
            ];
        } elseif ($targetProfileExpired) {
            $dataGaps[] = [
                'code' => 'target_operating_profile_expired',
                'dimension' => 'target_profile',
                'label' => '目标酒店经营画像',
                'message' => '目标酒店经营画像证据已过期。',
            ];
        } elseif ($targetProfileUnverified) {
            $dataGaps[] = [
                'code' => 'target_operating_profile_unverified',
                'dimension' => 'target_profile',
                'label' => '目标酒店经营画像',
                'message' => '目标酒店经营画像尚未核验。',
            ];
        }
        if ($targetOperatingLoop === null) {
            $dataGaps[] = [
                'code' => 'target_authoritative_operating_loop_missing',
                'dimension' => 'target_operating_loop',
                'label' => '目标酒店首次经营闭环',
                'message' => '目标酒店尚未完成可回读的权威经营闭环，不能进入跨店复制验证。',
            ];
        }
        foreach ($missing as $key) {
            $dataGaps[] = [
                'code' => 'target_applicability_' . $key . '_missing',
                'dimension' => $key,
                'label' => self::PROFILE_DIMENSIONS[$key],
                'message' => '目标酒店缺少' . self::PROFILE_DIMENSIONS[$key] . '。',
            ];
        }
        foreach ($conflicts as $key) {
            $result = array_values(array_filter(
                $dimensionResults,
                static fn(array $item): bool => ($item['dimension'] ?? '') === $key
            ))[0] ?? [];
            $unmet = array_values((array)($result['unmet_source_values'] ?? []));
            $dataGaps[] = [
                'code' => 'target_applicability_' . $key . '_conflict',
                'dimension' => $key,
                'label' => self::PROFILE_DIMENSIONS[$key],
                'message' => '目标酒店的' . self::PROFILE_DIMENSIONS[$key]
                    . '未满足来源要求' . ($unmet === [] ? '。' : '：' . implode('、', $unmet) . '。'),
            ];
        }

        $confidence = ($sourceGaps !== []
            || $targetProfileMissing
            || $targetProfileExpired
            || $targetProfileUnverified
            || $targetOperatingLoop === null)
            ? 'blocked'
            : (($missing !== [] || $conflicts !== [] || $counterexamples !== []) ? 'low' : 'medium');
        $summary = sprintf(
            '来源经验8项适用条件中，目标酒店满足%d项、缺少%d项、冲突%d项；相关历史复盘成功%d条、失败或停止反例%d条，另有%d条因画像不相近或证据未验证未纳入。仅生成待验证草稿。',
            count($matched),
            count($missing),
            count($conflicts),
            (int)$replicationEvidence['success_count'],
            count($counterexamples),
            (int)$replicationEvidence['ignored_review_count']
        );
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'recommendation' => 'validation_draft_only',
            'confidence' => $confidence,
            'summary' => $summary,
            'matched_count' => count($matched),
            'missing_count' => count($missing),
            'conflict_count' => count($conflicts),
            'counterexample_count' => count($counterexamples),
            'success_count' => (int)$replicationEvidence['success_count'],
            'dimension_results' => $dimensionResults,
            'matched_dimensions' => $matched,
            'missing_dimensions' => $missing,
            'conflicting_dimensions' => $conflicts,
            'counterexamples' => $counterexamples,
            'success_samples' => $replicationEvidence['success_samples'],
            'replication_evidence' => $replicationEvidence,
            'source_profile_gaps' => $sourceGaps,
            'target_profile' => $targetProfile,
            'source_operating_loop' => $sourceOperatingLoop,
            'target_operating_loop' => $targetOperatingLoop,
            'data_gaps' => $dataGaps,
            'hard_gates' => [
                'source_profile_complete' => $sourceGaps === [],
                'source_evidence_current' => !$sourceExpired,
                'source_authoritative_operating_loop_complete' => $sourceOperatingLoop !== null,
                'target_profile_present' => !$targetProfileMissing,
                'target_profile_verified' => !$targetProfileMissing && !$targetProfileUnverified,
                'target_profile_current' => !$targetProfileMissing && !$targetProfileExpired,
                'target_authoritative_operating_loop_complete' => $targetOperatingLoop !== null,
                'human_target_validation_required' => true,
            ],
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @param list<int> $hotelIds @param array<string,mixed> $input @return array<string,mixed> */
    public function createReplicationExecutionIntent(
        int $replicationId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $createdBy
    ): array {
        $hotelIds = $this->ids($hotelIds);
        $replication = (new OperatingSopService())->readReplication(
            $replicationId,
            $tenantId,
            $hotelIds
        );
        if ((string)($replication['status'] ?? '') !== 'draft_pending_target_validation'
            || (string)($replication['target_validation_status'] ?? '') !== 'facts_available_review_required'
        ) {
            throw new InvalidArgumentException('只有资料完整且适用性未被阻塞的待验证草稿才能生成待审批执行任务');
        }
        $replicationDigest = strtolower(trim((string)($replication['content_digest'] ?? '')));
        if (!$this->isDigest($replicationDigest)) {
            throw new RuntimeException('复制草稿缺少有效的不变内容摘要');
        }
        $draft = is_array($replication['draft'] ?? null) ? $replication['draft'] : [];
        $boundaries = is_array($draft['boundaries'] ?? null) ? $draft['boundaries'] : [];
        foreach (['automatic_execution', 'ota_write', 'external_message'] as $field) {
            if (($boundaries[$field] ?? null) !== false) {
                throw new RuntimeException('复制草稿写入边界异常：' . $field);
            }
        }
        if (($boundaries['human_target_validation_required'] ?? null) !== true
            || ($boundaries['target_verified'] ?? null) !== false
        ) {
            throw new RuntimeException('复制草稿缺少人工目标店验证边界');
        }

        $comparison = is_array($draft['target_fact_comparison_contract'] ?? null)
            ? $draft['target_fact_comparison_contract']
            : [];
        $platform = strtolower(trim((string)($input['platform'] ?? $comparison['platform'] ?? '')));
        $expectedPlatform = strtolower(trim((string)($comparison['platform'] ?? '')));
        if ($platform === '' || $expectedPlatform === '' || $platform !== $expectedPlatform) {
            throw new InvalidArgumentException('待审批任务平台必须与复制草稿的目标事实平台完全一致');
        }
        $dateStart = $this->date(
            (string)($input['date_start'] ?? $input['effective_date'] ?? ''),
            '复制验证动作开始日期'
        );
        $dateEnd = $this->date(
            (string)($input['date_end'] ?? $dateStart),
            '复制验证动作结束日期'
        );
        if ($dateEnd < $dateStart) {
            throw new InvalidArgumentException('复制验证动作结束日期不能早于开始日期');
        }
        $targetFactDateEnd = trim((string)($comparison['date_end'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $targetFactDateEnd) !== 1
            || $dateStart <= $targetFactDateEnd
        ) {
            throw new InvalidArgumentException('复制验证动作必须晚于目标店已回读的执行前事实日期');
        }

        $objectType = strtolower(trim((string)($input['object_type'] ?? '')));
        if (!in_array($objectType, ['price', 'inventory', 'campaign', 'room_product'], true)) {
            throw new InvalidArgumentException('复制验证动作对象必须是 price、inventory、campaign 或 room_product');
        }
        $actionType = trim((string)($input['action_type'] ?? ''));
        if ($actionType === '' || mb_strlen($actionType) > 50) {
            throw new InvalidArgumentException('复制验证动作类型不能为空且不能超过50个字符');
        }
        $expectedMetric = strtolower(trim((string)($input['expected_metric'] ?? '')));
        if ($expectedMetric === '' || mb_strlen($expectedMetric) > 50) {
            throw new InvalidArgumentException('复制验证动作必须声明不超过50个字符的预期指标');
        }
        $currentValue = is_array($input['current_value'] ?? null) ? $input['current_value'] : [];
        $targetValue = is_array($input['target_value'] ?? null) ? $input['target_value'] : [];
        if ($currentValue === [] || $targetValue === []) {
            throw new InvalidArgumentException('复制验证动作必须保存执行前状态和待验证动作参数');
        }
        if (!array_key_exists($expectedMetric, $currentValue)
            || !is_numeric($currentValue[$expectedMetric])
        ) {
            throw new InvalidArgumentException('复制验证执行前状态必须包含待验证指标的数值基准');
        }
        $targetValue['target_metric'] = trim((string)($targetValue['target_metric'] ?? $expectedMetric));
        if ($objectType === 'campaign') {
            $targetValue['campaign_type'] = trim((string)($targetValue['campaign_type'] ?? $actionType));
        }
        $expectedDelta = null;
        if (array_key_exists('expected_delta', $input) && $input['expected_delta'] !== null && $input['expected_delta'] !== '') {
            if (!is_numeric($input['expected_delta'])) {
                throw new InvalidArgumentException('复制验证动作预期变化量必须是数值');
            }
            $expectedDelta = round((float)$input['expected_delta'], 6);
        }
        $riskLevel = strtolower(trim((string)($input['risk_level'] ?? 'medium')));
        if (!in_array($riskLevel, ['low', 'medium', 'high'], true)) {
            throw new InvalidArgumentException('复制验证动作风险等级必须是 low、medium 或 high');
        }

        $executionContract = [
            'platform' => $platform,
            'object_type' => $objectType,
            'action_type' => $actionType,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'current_value' => $currentValue,
            'target_value' => $targetValue,
            'expected_metric' => $expectedMetric,
            'expected_delta' => $expectedDelta,
            'risk_level' => $riskLevel,
        ];
        $executionContractDigest = $this->digest($executionContract);
        $assessment = is_array($draft['applicability_assessment'] ?? null)
            ? $draft['applicability_assessment']
            : [];
        $targetProfile = is_array($assessment['target_profile'] ?? null)
            ? $assessment['target_profile']
            : [];
        $sourceSop = Db::name(OperatingSopService::VERSION_TABLE)
            ->where('id', (int)$replication['source_sop_version_id'])
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', (int)$replication['source_hotel_id'])
            ->where('validation_status', 'verified')
            ->where('lifecycle_status', 'active')
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($sourceSop) || !$this->isDigest((string)($sourceSop['content_digest'] ?? ''))) {
            throw new InvalidArgumentException('复制草稿来源SOP已失效，请重新生成适用性草稿');
        }
        $lineage = [
            'contract_version' => self::CONTRACT_VERSION,
            'source_module' => self::EXECUTION_SOURCE_MODULE,
            'replication_id' => $replicationId,
            'replication_content_digest' => $replicationDigest,
            'source_sop_version_id' => (int)($replication['source_sop_version_id'] ?? 0),
            'source_sop_content_digest' => strtolower(trim((string)$sourceSop['content_digest'])),
            'source_hotel_id' => (int)($replication['source_hotel_id'] ?? 0),
            'target_hotel_id' => (int)($replication['target_hotel_id'] ?? 0),
            'target_profile_id' => (int)($targetProfile['id'] ?? 0),
            'target_profile_content_digest' => strtolower(trim((string)($targetProfile['content_digest'] ?? ''))),
            'target_fact_refs' => array_values((array)($replication['target_fact_refs'] ?? [])),
            'action_parameters' => array_values((array)($draft['experience_applicability']['action_parameters'] ?? [])),
            'execution_contract' => $executionContract,
            'execution_contract_digest' => $executionContractDigest,
            'human_approval_required' => true,
            'automatic_execution' => false,
            'ota_write' => false,
            'external_message' => false,
        ];
        $evidence = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];
        $evidence['operating_network_replication'] = $lineage;
        $targetValue['operating_network_replication_digest'] = $replicationDigest;

        $idempotencyKey = 'operating_network_replication_' . md5(
            $tenantId . ':' . $replicationId . ':' . $replicationDigest . ':' . $executionContractDigest
        );
        $intent = (new OperationManagementService())->createExecutionIntent(
            $hotelIds,
            (int)$replication['target_hotel_id'],
            [
                'source_module' => self::EXECUTION_SOURCE_MODULE,
                'source_record_id' => $replicationId,
                'hotel_id' => (int)$replication['target_hotel_id'],
                'platform' => $platform,
                'object_type' => $objectType,
                'action_type' => $actionType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'current_value' => $currentValue,
                'target_value' => $targetValue,
                'evidence' => $evidence,
                'expected_metric' => $expectedMetric,
                'expected_delta' => $expectedDelta,
                'risk_level' => $riskLevel,
                'status' => 'pending_approval',
            ],
            max(0, $createdBy),
            false,
            $idempotencyKey,
            true
        );
        $this->assertReplicationExecutionIntentCurrent($intent);
        $storedLineage = is_array($intent['evidence']['operating_network_replication'] ?? null)
            ? $intent['evidence']['operating_network_replication']
            : [];
        if ((string)($intent['status'] ?? '') !== 'pending_approval'
            || !hash_equals($executionContractDigest, (string)($storedLineage['execution_contract_digest'] ?? ''))
        ) {
            $issues = [];
            if ((string)($intent['status'] ?? '') !== 'pending_approval') {
                $issues[] = 'status=' . (string)($intent['status'] ?? 'missing');
            }
            if (!hash_equals($executionContractDigest, (string)($storedLineage['execution_contract_digest'] ?? ''))) {
                $issues[] = 'execution_contract_digest_mismatch';
            }
            throw new RuntimeException('复制验证待审批任务已写入但严格回读校验失败：' . implode(',', $issues));
        }

        return [
            'replication_id' => $replicationId,
            'replication_content_digest' => $replicationDigest,
            'execution_intent' => $intent,
            'persistence_status' => 'readback_verified',
            'write_boundaries' => [
                'status_is_pending_approval' => true,
                'human_approval_required' => true,
                'automatic_execution' => false,
                'ota_write' => false,
                'external_message' => false,
                'causality_claimed' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $intent */
    public function assertReplicationExecutionIntentCurrent(array $intent): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $replicationId = (int)($intent['source_record_id'] ?? 0);
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : $this->decode($intent['evidence_json'] ?? null);
        $lineage = is_array($evidence['operating_network_replication'] ?? null)
            ? $evidence['operating_network_replication']
            : [];
        $executionContract = is_array($lineage['execution_contract'] ?? null)
            ? $lineage['execution_contract']
            : [];
        $executionContractDigest = strtolower(trim((string)($lineage['execution_contract_digest'] ?? '')));
        $intentCurrentValue = is_array($intent['current_value'] ?? null)
            ? $intent['current_value']
            : $this->decode($intent['current_value_json'] ?? null);
        $intentTargetValue = is_array($intent['target_value'] ?? null)
            ? $intent['target_value']
            : $this->decode($intent['target_value_json'] ?? null);
        $lineageIssues = [];
        if ($sourceModule !== self::EXECUTION_SOURCE_MODULE) $lineageIssues[] = 'source_module_mismatch';
        if ($replicationId <= 0) $lineageIssues[] = 'replication_id_missing';
        if ($tenantId <= 0) $lineageIssues[] = 'tenant_id_missing';
        if ($hotelId <= 0) $lineageIssues[] = 'target_hotel_id_missing';
        if (($lineage['contract_version'] ?? '') !== self::CONTRACT_VERSION) $lineageIssues[] = 'contract_version_mismatch';
        if (($lineage['source_module'] ?? '') !== self::EXECUTION_SOURCE_MODULE) $lineageIssues[] = 'lineage_source_module_mismatch';
        if ((int)($lineage['replication_id'] ?? 0) !== $replicationId) $lineageIssues[] = 'lineage_replication_id_mismatch';
        if ((int)($lineage['target_hotel_id'] ?? 0) !== $hotelId) $lineageIssues[] = 'lineage_target_hotel_id_mismatch';
        if (!$this->isDigest((string)($lineage['replication_content_digest'] ?? ''))) $lineageIssues[] = 'replication_digest_invalid';
        if (!$this->isDigest($executionContractDigest)) $lineageIssues[] = 'execution_contract_digest_invalid';
        if ($executionContract === []) $lineageIssues[] = 'execution_contract_missing';
        if ($executionContract !== []
            && $this->isDigest($executionContractDigest)
            && !hash_equals($executionContractDigest, $this->digest($executionContract))
        ) {
            $lineageIssues[] = 'execution_contract_digest_mismatch';
        }
        foreach (['platform', 'object_type', 'action_type', 'date_start', 'date_end', 'expected_metric', 'risk_level'] as $field) {
            $actual = strtolower(trim((string)($intent[$field] ?? '')));
            $expected = strtolower(trim((string)($executionContract[$field] ?? '')));
            if ($expected === '' || $actual !== $expected) {
                $lineageIssues[] = 'execution_contract_' . $field . '_mismatch';
            }
        }
        if ($executionContract !== []
            && !hash_equals(
                $this->digest((array)($executionContract['current_value'] ?? [])),
                $this->digest($intentCurrentValue)
            )
        ) {
            $lineageIssues[] = 'execution_contract_current_value_mismatch';
        }
        foreach ((array)($executionContract['target_value'] ?? []) as $field => $expected) {
            if (!array_key_exists($field, $intentTargetValue)
                || !hash_equals($this->digest($expected), $this->digest($intentTargetValue[$field]))
            ) {
                $lineageIssues[] = 'execution_contract_target_value_' . (string)$field . '_mismatch';
            }
        }
        if (($lineage['human_approval_required'] ?? null) !== true) $lineageIssues[] = 'human_approval_boundary_missing';
        if (($lineage['automatic_execution'] ?? null) !== false) $lineageIssues[] = 'automatic_execution_boundary_invalid';
        if (($lineage['ota_write'] ?? null) !== false) $lineageIssues[] = 'ota_write_boundary_invalid';
        if (($lineage['external_message'] ?? null) !== false) $lineageIssues[] = 'external_message_boundary_invalid';
        if ($lineageIssues !== []) {
            throw new InvalidArgumentException('复制草稿执行血缘不完整或已被修改：' . implode(',', $lineageIssues));
        }
        $replication = Db::name(OperatingSopService::REPLICATION_TABLE)
            ->where('id', $replicationId)
            ->where('tenant_id', $tenantId)
            ->where('target_hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->find();
        $sourceSop = Db::name(OperatingSopService::VERSION_TABLE)
            ->where('id', (int)($lineage['source_sop_version_id'] ?? 0))
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', (int)($lineage['source_hotel_id'] ?? 0))
            ->where('validation_status', 'verified')
            ->where('lifecycle_status', 'active')
            ->whereNull('deleted_at')
            ->find();
        $targetProfile = $this->currentProfile($tenantId, $hotelId);
        if (!is_array($replication)
            || (string)($replication['status'] ?? '') !== 'draft_pending_target_validation'
            || (string)($replication['target_validation_status'] ?? '') !== 'facts_available_review_required'
            || !hash_equals(
                strtolower(trim((string)($replication['content_digest'] ?? ''))),
                strtolower(trim((string)$lineage['replication_content_digest']))
            )
            || (int)($replication['source_sop_version_id'] ?? 0) !== (int)($lineage['source_sop_version_id'] ?? 0)
            || (int)($replication['source_hotel_id'] ?? 0) !== (int)($lineage['source_hotel_id'] ?? 0)
            || !is_array($sourceSop)
            || !hash_equals(
                strtolower(trim((string)($sourceSop['content_digest'] ?? ''))),
                strtolower(trim((string)($lineage['source_sop_content_digest'] ?? '')))
            )
            || !is_array($targetProfile)
            || (int)($targetProfile['id'] ?? 0) !== (int)($lineage['target_profile_id'] ?? 0)
            || !hash_equals(
                strtolower(trim((string)($targetProfile['content_digest'] ?? ''))),
                strtolower(trim((string)($lineage['target_profile_content_digest'] ?? '')))
            )
            || $this->firstOperatingLoop($tenantId, (int)($lineage['source_hotel_id'] ?? 0)) === null
            || $this->firstOperatingLoop($tenantId, $hotelId) === null
        ) {
            throw new InvalidArgumentException('复制草稿执行血缘已漂移，请重新生成适用性草稿');
        }
    }

    /** @param list<int> $hotelIds @param array<string,mixed> $input @return array<string,mixed> */
    public function recordReplicationReview(
        int $replicationId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $createdBy
    ): array {
        $this->assertReviewTableReady();
        $hotelIds = $this->ids($hotelIds);
        $replication = Db::name(OperatingSopService::REPLICATION_TABLE)
            ->where('id', $replicationId)
            ->where('tenant_id', $tenantId)
            ->whereIn('source_hotel_id', $hotelIds)
            ->whereIn('target_hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($replication)) {
            throw new RuntimeException('operating SOP replication not found');
        }
        $outcome = strtolower(trim((string)($input['outcome'] ?? '')));
        if (!in_array($outcome, ['success', 'failed', 'stopped', 'inconclusive'], true)) {
            throw new InvalidArgumentException('复制复盘结果必须是 success、failed、stopped 或 inconclusive');
        }
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 1000);
        if ($note === '') {
            throw new InvalidArgumentException('复制复盘必须填写人工说明');
        }
        $evidenceRefs = self::textItems($input['evidence_refs'] ?? [], 100, 300);
        if ($outcome !== 'inconclusive' && $evidenceRefs === []) {
            throw new InvalidArgumentException('成功、失败或停止的复制复盘必须保存证据引用');
        }
        $observedConditions = self::textItems($input['observed_conditions'] ?? [], 50, 500);
        $failureConditions = self::textItems($input['failure_conditions'] ?? [], 50, 500);
        $stopTriggered = self::textItems($input['stop_triggered'] ?? [], 50, 500);
        if ($outcome === 'success' && $observedConditions === []) {
            throw new InvalidArgumentException('成功复盘必须保存达到成功条件的实际观察');
        }
        if ($outcome === 'failed' && $failureConditions === []) {
            throw new InvalidArgumentException('失败复盘必须保存实际失败条件');
        }
        if ($outcome === 'stopped' && $stopTriggered === []) {
            throw new InvalidArgumentException('停止复盘必须保存已触发的停止条件');
        }
        $reviewedBusinessDate = $this->optionalDate(
            (string)($input['reviewed_business_date'] ?? ''),
            '复制复盘业务日期'
        );
        if ($outcome !== 'inconclusive' && $reviewedBusinessDate === null) {
            throw new InvalidArgumentException('成功、失败或停止的复制复盘必须保存业务日期');
        }
        $evidenceVerification = $this->verifyReplicationReviewEvidence(
            $outcome,
            $evidenceRefs,
            $tenantId,
            (int)$replication['target_hotel_id'],
            $reviewedBusinessDate,
            $replication
        );
        $review = [
            'contract_version' => self::CONTRACT_VERSION,
            'outcome' => $outcome,
            'note' => $note,
            'observed_conditions' => $observedConditions,
            'failure_conditions' => $failureConditions,
            'stop_triggered' => $stopTriggered,
            'evidence_refs' => $evidenceRefs,
            'evidence_verification' => $evidenceVerification,
            'reviewed_business_date' => $reviewedBusinessDate,
            'target_profile_snapshot' => $this->currentProfile($tenantId, (int)$replication['target_hotel_id']),
            'draft_snapshot' => $this->decode($replication['draft_json'] ?? null),
            'causality_claimed' => false,
        ];

        return Db::transaction(function () use ($replication, $replicationId, $tenantId, $outcome, $review, $createdBy): array {
            $reviewNo = (int)Db::name(self::REVIEW_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('replication_id', $replicationId)
                ->whereNull('deleted_at')
                ->max('review_no') + 1;
            $digest = $this->digest([
                'tenant_id' => $tenantId,
                'replication_id' => $replicationId,
                'review_no' => $reviewNo,
                'review' => $review,
            ]);
            $id = (int)Db::name(self::REVIEW_TABLE)->insertGetId([
                'tenant_id' => $tenantId,
                'replication_id' => $replicationId,
                'review_no' => $reviewNo,
                'source_sop_version_id' => (int)$replication['source_sop_version_id'],
                'source_hotel_id' => (int)$replication['source_hotel_id'],
                'target_hotel_id' => (int)$replication['target_hotel_id'],
                'outcome' => $outcome,
                'review_json' => $this->encode($review),
                'content_digest' => $digest,
                'created_by' => max(0, $createdBy),
                'created_at' => date('Y-m-d H:i:s'),
                'deleted_at' => null,
            ]);
            if ($id <= 0) {
                throw new RuntimeException('复制复盘保存失败：未取得记录ID');
            }
            $saved = $this->readReview($id, $tenantId);
            if ((int)$saved['replication_id'] !== $replicationId
                || (int)$saved['review_no'] !== $reviewNo
                || (string)$saved['content_digest'] !== $digest
            ) {
                throw new RuntimeException('复制复盘已写入但严格回读校验失败');
            }
            return [
                'review' => $saved,
                'created' => true,
                'persistence_status' => 'readback_verified',
                'write_boundaries' => $this->boundaries(),
            ];
        });
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function listReplicationReviews(int $replicationId, int $tenantId, array $hotelIds): array
    {
        if (!$this->tableExists(self::REVIEW_TABLE)) {
            return ['data_status' => 'migration_required', 'list' => [], 'count' => 0];
        }
        $hotelIds = $this->ids($hotelIds);
        $replication = Db::name(OperatingSopService::REPLICATION_TABLE)
            ->where('id', $replicationId)
            ->where('tenant_id', $tenantId)
            ->whereIn('source_hotel_id', $hotelIds)
            ->whereIn('target_hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($replication)) {
            throw new RuntimeException('operating SOP replication not found');
        }
        $rows = Db::name(self::REVIEW_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('replication_id', $replicationId)
            ->whereNull('deleted_at')
            ->order('review_no', 'asc')
            ->select()
            ->toArray();
        return [
            'data_status' => 'ok',
            'replication_id' => $replicationId,
            'list' => array_map([$this, 'normalizeReview'], $rows),
            'count' => count($rows),
            'append_only' => true,
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @return array<string,list<string>> */
    public static function normalizeProfileDimensions(mixed $value): array
    {
        if (!is_array($value)) {
            $value = [];
        }
        $normalized = [];
        foreach (self::PROFILE_DIMENSIONS as $key => $_label) {
            $normalized[$key] = self::textItems($value[$key] ?? [], 30, 120);
        }
        return $normalized;
    }

    /** @return list<string> */
    public static function textItems(mixed $value, int $limit = 30, int $maxLength = 500): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,，;；]+/u', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }
            $text = mb_substr(trim((string)$item), 0, $maxLength);
            if ($text === '') {
                continue;
            }
            $items[$text] = true;
            if (count($items) >= $limit) {
                break;
            }
        }
        return array_keys($items);
    }

    /** @return array<string,mixed>|null */
    private function currentProfile(int $tenantId, int $hotelId): ?array
    {
        if (!$this->tableExists(self::PROFILE_TABLE)) {
            return null;
        }
        $row = Db::name(self::PROFILE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('is_current', 1)
            ->whereNull('deleted_at')
            ->order('version_no', 'desc')
            ->find();
        return is_array($row) ? $this->normalizeProfile($row) : null;
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    private function readProfileVersion(int $id, int $tenantId, array $hotelIds): array
    {
        $row = Db::name(self::PROFILE_TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('hotel operating profile not found');
        }
        return $this->normalizeProfile($row);
    }

    /** @param array<string,mixed>|null $profile @param list<array<string,mixed>> $comparables @return array<string,mixed> */
    private function onboarding(
        int $tenantId,
        int $hotelId,
        ?array $profile,
        array $comparables,
        ?array $operatingLoop
    ): array
    {
        $stages = [];
        $stages[] = $this->stage('identity_confirmation', '身份确认', 'complete', ['hotels#' . $hotelId], []);

        $bindings = $this->dataSourceBindings($tenantId, $hotelId);
        $stages[] = $this->stage(
            'data_source_binding',
            '数据源绑定',
            $bindings === [] ? 'missing' : 'complete',
            array_map(static fn(array $row): string => 'platform_data_sources#' . (int)$row['id'], $bindings),
            $bindings === [] ? ['verified_platform_data_source_binding_missing'] : []
        );

        $confirmations = is_array($profile['profile']['onboarding_confirmations'] ?? null)
            ? $profile['profile']['onboarding_confirmations']
            : [];
        foreach ([
            'room_rate_mapping' => '房型价型映射',
            'metric_definition' => '指标口径确认',
        ] as $key => $label) {
            $confirmation = is_array($confirmations[$key] ?? null) ? $confirmations[$key] : [];
            $status = (string)($confirmation['status'] ?? 'missing');
            $stages[] = $this->stage(
                $key,
                $label,
                $status === 'verified' ? 'complete' : ($status === 'partial' ? 'partial' : 'missing'),
                array_values((array)($confirmation['evidence_refs'] ?? [])),
                $status === 'verified' ? [] : [$key . '_verification_missing']
            );
        }

        $trusted = $this->trustedCollection($tenantId, $hotelId);
        $stages[] = $this->stage(
            'first_trusted_collection',
            '首次可信采集',
            $trusted['count'] > 0 ? 'complete' : 'missing',
            $trusted['refs'],
            $trusted['count'] > 0 ? [] : ['strict_readback_fact_missing']
        );
        $stages[] = $this->stage(
            'first_operating_loop',
            '首次经营闭环',
            $operatingLoop === null ? 'missing' : 'complete',
            $operatingLoop === null ? [] : ['hotel_operating_cycles#' . (int)$operatingLoop['id']],
            $operatingLoop === null ? ['authoritative_completed_operating_cycle_missing'] : []
        );

        $prerequisitesReady = count(array_filter(
            $stages,
            static fn(array $stage): bool => ($stage['status'] ?? '') !== 'complete'
        )) === 0;
        $profileUsable = $this->isUsableProfile($profile);
        $comparableStatus = !$prerequisitesReady || !$profileUsable
            ? 'blocked'
            : ($comparables === [] ? 'missing' : 'review_required');
        $stages[] = $this->stage(
            'comparable_hotel_identification',
            '可比酒店识别',
            $comparableStatus,
            array_map(static fn(array $row): string => 'hotel_operating_profiles#' . (int)$row['profile_id'], $comparables),
            $comparableStatus === 'review_required'
                ? []
                : (!$profileUsable ? ['verified_current_operating_profile_missing'] : ['comparable_profile_candidate_missing'])
        );

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'stages' => $stages,
            'current_stage' => (string)(array_values(array_filter(
                $stages,
                static fn(array $stage): bool => ($stage['status'] ?? '') !== 'complete'
            ))[0]['key'] ?? 'comparable_hotel_identification'),
            'ready_for_comparable_review' => $comparableStatus === 'review_required',
        ];
    }

    /** @return array<string,mixed> */
    private function missingOnboarding(string $gap): array
    {
        $labels = [
            'identity_confirmation' => '身份确认',
            'data_source_binding' => '数据源绑定',
            'room_rate_mapping' => '房型价型映射',
            'metric_definition' => '指标口径确认',
            'first_trusted_collection' => '首次可信采集',
            'first_operating_loop' => '首次经营闭环',
            'comparable_hotel_identification' => '可比酒店识别',
        ];
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'stages' => array_map(
                fn(string $key, string $label): array => $this->stage($key, $label, 'blocked', [], [$gap]),
                array_keys($labels),
                array_values($labels)
            ),
            'current_stage' => 'identity_confirmation',
            'ready_for_comparable_review' => false,
        ];
    }

    /** @param array<string,mixed>|null $sourceProfile @return list<array<string,mixed>> */
    private function comparableHotels(int $tenantId, int $hotelId, array $hotelIds, ?array $sourceProfile): array
    {
        if (!$this->isUsableProfile($sourceProfile) || !$this->tableExists(self::PROFILE_TABLE)) {
            return [];
        }
        $rows = Db::name(self::PROFILE_TABLE)
            ->where('tenant_id', $tenantId)
            ->whereIn('hotel_id', $hotelIds)
            ->where('hotel_id', '<>', $hotelId)
            ->where('is_current', 1)
            ->where('quality_status', 'verified')
            ->where('evidence_valid_until', '>=', date('Y-m-d'))
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $names = $this->hotelNameMap($tenantId, $hotelIds);
        $sourceDimensions = self::normalizeProfileDimensions($sourceProfile['profile']['dimensions'] ?? []);
        $items = [];
        foreach ($rows as $row) {
            $target = $this->normalizeProfile($row);
            if (!$this->isUsableProfile($target)
                || $this->firstOperatingLoop($tenantId, (int)$target['hotel_id']) === null
            ) {
                continue;
            }
            $comparison = $this->compareDimensions(
                $sourceDimensions,
                self::normalizeProfileDimensions($target['profile']['dimensions'] ?? [])
            );
            $items[] = [
                'hotel_id' => (int)$target['hotel_id'],
                'hotel_name' => $names[(int)$target['hotel_id']] ?? ('酒店 #' . (int)$target['hotel_id']),
                'profile_id' => (int)$target['id'],
                'profile_quality_status' => (string)$target['quality_status'],
                'profile_valid_until' => $target['evidence_valid_until'],
                'matched_count' => count($comparison['matched']),
                'missing_count' => count($comparison['missing']),
                'conflict_count' => count($comparison['conflicts']),
                'dimension_results' => $comparison['results'],
                'status' => 'candidate_review_required',
            ];
        }
        usort($items, static fn(array $a, array $b): int => [
            -(int)$a['matched_count'],
            (int)$a['conflict_count'],
            (int)$a['missing_count'],
            (int)$a['hotel_id'],
        ] <=> [
            -(int)$b['matched_count'],
            (int)$b['conflict_count'],
            (int)$b['missing_count'],
            (int)$b['hotel_id'],
        ]);
        return array_slice($items, 0, 20);
    }

    /** @return array{results:list<array<string,mixed>>,matched:list<string>,missing:list<string>,conflicts:list<string>,source_undeclared:list<string>} */
    private function compareDimensions(array $source, array $target, bool $preserveSourceUndeclared = false): array
    {
        $results = [];
        $matched = [];
        $missing = [];
        $conflicts = [];
        $sourceUndeclared = [];
        foreach (self::PROFILE_DIMENSIONS as $key => $label) {
            $expected = array_values((array)($source[$key] ?? []));
            $actual = array_values((array)($target[$key] ?? []));
            $matchedValues = $this->tagIntersection($expected, $actual);
            $unmetSourceValues = $this->tagDifference($expected, $actual);
            $targetOnlyValues = $this->tagDifference($actual, $expected);
            if ($expected === [] && $preserveSourceUndeclared) {
                $status = 'source_undeclared';
                $sourceUndeclared[] = $key;
            } elseif ($expected === [] || $actual === []) {
                $status = 'missing';
                $missing[] = $key;
            } elseif ($unmetSourceValues === []) {
                $status = 'matched';
                $matched[] = $key;
            } else {
                $status = 'conflict';
                $conflicts[] = $key;
            }
            $results[] = [
                'dimension' => $key,
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'source_values' => $expected,
                'target_values' => $actual,
                'expected' => $expected,
                'actual' => $actual,
                'matched_values' => $matchedValues,
                'unmet_source_values' => $unmetSourceValues,
                'target_only_values' => $targetOnlyValues,
            ];
        }
        return [
            'results' => $results,
            'matched' => $matched,
            'missing' => $missing,
            'conflicts' => $conflicts,
            'source_undeclared' => $sourceUndeclared,
        ];
    }

    /** @return list<string> */
    private function tagIntersection(array $left, array $right): array
    {
        $leftMap = [];
        foreach ($left as $value) {
            $leftMap[$this->tagKey((string)$value)] = (string)$value;
        }
        $rightMap = [];
        foreach ($right as $value) {
            $rightMap[$this->tagKey((string)$value)] = true;
        }
        return array_values(array_filter(
            $leftMap,
            static fn(string $display, string $key): bool => $key !== '' && isset($rightMap[$key]),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /** @return list<string> */
    private function tagDifference(array $left, array $right): array
    {
        $rightMap = [];
        foreach ($right as $value) {
            $key = $this->tagKey((string)$value);
            if ($key !== '') {
                $rightMap[$key] = true;
            }
        }
        $difference = [];
        foreach ($left as $value) {
            $display = (string)$value;
            $key = $this->tagKey($display);
            if ($key !== '' && !isset($rightMap[$key])) {
                $difference[$key] = $display;
            }
        }
        return array_values($difference);
    }

    private function tagKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return preg_replace('/[\s\-_\/\\|，,；;]+/u', '', $value) ?? '';
    }

    /** @return array<string,mixed> */
    private function replicationEvidence(
        int $tenantId,
        int $sourceVersionId,
        int $targetHotelId,
        ?array $targetProfile,
        array $hotelIds
    ): array
    {
        if ($sourceVersionId <= 0 || !$this->tableExists(self::REVIEW_TABLE)) {
            return $this->emptyReplicationEvidence();
        }
        $rows = Db::name(self::REVIEW_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('source_sop_version_id', $sourceVersionId)
            ->whereIn('target_hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->limit(200)
            ->select()
            ->toArray();

        $successSamples = [];
        $counterexamples = [];
        $inconclusiveCount = 0;
        $otherProfileReviewCount = 0;
        $unverifiedReviewCount = 0;
        $relevantConclusiveCount = 0;
        $relevantTargetHotels = [];
        foreach ($rows as $row) {
            $review = $this->decode($row['review_json'] ?? null);
            $outcome = (string)($row['outcome'] ?? $review['outcome'] ?? '');
            $verificationStatus = (string)($review['evidence_verification']['status'] ?? 'unverified_legacy');
            $lineageStatus = (string)($review['evidence_verification']['lineage_status'] ?? 'unverified_legacy');
            if ($outcome !== 'inconclusive'
                && ($verificationStatus !== 'verified' || $lineageStatus !== 'verified')
            ) {
                $unverifiedReviewCount++;
                continue;
            }
            $relevance = $this->replicationReviewRelevance(
                $targetHotelId,
                $targetProfile,
                (int)($row['target_hotel_id'] ?? 0),
                is_array($review['target_profile_snapshot'] ?? null) ? $review['target_profile_snapshot'] : null
            );
            if (!$relevance['relevant']) {
                $otherProfileReviewCount++;
                continue;
            }
            $sample = [
                'ref' => self::REVIEW_TABLE . '#' . (int)$row['id'],
                'outcome' => $outcome,
                'target_hotel_id' => (int)$row['target_hotel_id'],
                'note' => (string)($review['note'] ?? ''),
                'failure_conditions' => array_values((array)($review['failure_conditions'] ?? [])),
                'stop_triggered' => array_values((array)($review['stop_triggered'] ?? [])),
                'observed_conditions' => array_values((array)($review['observed_conditions'] ?? [])),
                'reviewed_business_date' => $review['reviewed_business_date'] ?? null,
                'relevance_reason' => $relevance['reason'],
                'profile_match' => $relevance['comparison'],
            ];
            if ($outcome === 'success') {
                $successSamples[] = $sample;
                $relevantConclusiveCount++;
                $relevantTargetHotels[(int)$row['target_hotel_id']] = true;
            } elseif (in_array($outcome, ['failed', 'stopped'], true)) {
                $counterexamples[] = $sample;
                $relevantConclusiveCount++;
                $relevantTargetHotels[(int)$row['target_hotel_id']] = true;
            } elseif ($outcome === 'inconclusive') {
                $inconclusiveCount++;
            }
        }

        $reviewedTargetHotelCount = count($relevantTargetHotels);
        $evidenceStrength = $relevantConclusiveCount === 0
            ? 'none'
            : (($relevantConclusiveCount >= 3 && $reviewedTargetHotelCount >= 2) ? 'repeated' : 'emerging');
        $signal = $successSamples !== [] && $counterexamples !== []
            ? 'mixed'
            : ($successSamples !== [] ? 'success_only' : ($counterexamples !== [] ? 'counterexamples_only' : 'none'));
        return [
            'review_count' => count($rows),
            'relevant_review_count' => $relevantConclusiveCount + $inconclusiveCount,
            'conclusive_review_count' => $relevantConclusiveCount,
            'success_count' => count($successSamples),
            'failed_count' => count(array_filter($counterexamples, static fn(array $item): bool => $item['outcome'] === 'failed')),
            'stopped_count' => count(array_filter($counterexamples, static fn(array $item): bool => $item['outcome'] === 'stopped')),
            'inconclusive_count' => $inconclusiveCount,
            'counterexample_count' => count($counterexamples),
            'other_profile_review_count' => $otherProfileReviewCount,
            'unverified_review_count' => $unverifiedReviewCount,
            'ignored_review_count' => $otherProfileReviewCount + $unverifiedReviewCount,
            'reviewed_target_hotel_count' => $reviewedTargetHotelCount,
            'evidence_strength' => $evidenceStrength,
            'evidence_signal' => $signal,
            'success_samples' => array_slice($successSamples, 0, 20),
            'counterexamples' => array_slice($counterexamples, 0, 20),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyReplicationEvidence(): array
    {
        return [
            'review_count' => 0,
            'relevant_review_count' => 0,
            'conclusive_review_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'stopped_count' => 0,
            'inconclusive_count' => 0,
            'counterexample_count' => 0,
            'other_profile_review_count' => 0,
            'unverified_review_count' => 0,
            'ignored_review_count' => 0,
            'reviewed_target_hotel_count' => 0,
            'evidence_strength' => 'none',
            'evidence_signal' => 'none',
            'success_samples' => [],
            'counterexamples' => [],
        ];
    }

    /** @return array{relevant:bool,reason:string,comparison:array<string,mixed>} */
    private function replicationReviewRelevance(
        int $targetHotelId,
        ?array $targetProfile,
        int $reviewTargetHotelId,
        ?array $reviewProfile
    ): array {
        if ($targetProfile === null || $reviewProfile === null) {
            return ['relevant' => false, 'reason' => 'profile_snapshot_missing', 'comparison' => []];
        }
        $targetProfileId = (int)($targetProfile['id'] ?? 0);
        $reviewProfileId = (int)($reviewProfile['id'] ?? 0);
        if ($reviewTargetHotelId === $targetHotelId
            && $targetProfileId > 0
            && $targetProfileId === $reviewProfileId
        ) {
            return [
                'relevant' => true,
                'reason' => 'same_target_profile_version',
                'comparison' => [
                    'matched_count' => count(array_filter(
                        self::normalizeProfileDimensions($targetProfile['profile']['dimensions'] ?? []),
                        static fn(array $values): bool => $values !== []
                    )),
                    'missing_count' => count(array_filter(
                        self::normalizeProfileDimensions($targetProfile['profile']['dimensions'] ?? []),
                        static fn(array $values): bool => $values === []
                    )),
                    'conflict_count' => 0,
                ],
            ];
        }
        if ((string)($targetProfile['freshness_status'] ?? '') !== 'current'
            || !$this->isUsableProfile($reviewProfile)
        ) {
            return ['relevant' => false, 'reason' => 'profile_not_verified_current', 'comparison' => []];
        }
        $comparison = $this->compareDimensions(
            self::normalizeProfileDimensions($targetProfile['profile']['dimensions'] ?? []),
            self::normalizeProfileDimensions($reviewProfile['profile']['dimensions'] ?? [])
        );
        $summary = [
            'matched_count' => count($comparison['matched']),
            'missing_count' => count($comparison['missing']),
            'conflict_count' => count($comparison['conflicts']),
            'dimension_results' => $comparison['results'],
        ];
        $relevant = count($comparison['matched']) >= 6
            && count($comparison['missing']) <= 2
            && $comparison['conflicts'] === [];
        return [
            'relevant' => $relevant,
            'reason' => $relevant ? 'similar_verified_profile' : 'profile_not_comparable',
            'comparison' => $summary,
        ];
    }

    /** @param list<string> $evidenceRefs @return array<string,mixed> */
    private function verifyReplicationReviewEvidence(
        string $outcome,
        array $evidenceRefs,
        int $tenantId,
        int $targetHotelId,
        ?string $reviewedBusinessDate,
        array $replication
    ): array {
        if ($outcome === 'inconclusive') {
            return [
                'status' => 'not_required',
                'lineage_status' => 'not_required',
                'verified_refs' => [],
                'scope' => ['tenant_id' => $tenantId, 'target_hotel_id' => $targetHotelId],
            ];
        }
        if (in_array($outcome, ['success', 'failed'], true)) {
            if (!$this->tableExists(self::EFFECT_REVIEW_TABLE)) {
                throw new RuntimeException('正式效果复盘表尚未启用，不能保存成功或失败结论');
            }
            $ids = $this->evidenceRefIds($evidenceRefs, self::EFFECT_REVIEW_TABLE);
            if ($ids === []) {
                throw new InvalidArgumentException('成功或失败复盘必须引用 operation_effect_reviews#ID');
            }
            $verifiedRefs = [];
            $verifiedCycleRefs = [];
            $scopeMismatch = false;
            $outcomeMismatch = false;
            $dateMismatch = false;
            $integrityMismatch = false;
            $cycleMissing = false;
            $lineageMismatch = false;
            $formalReviewMismatch = false;
            $verifiedIntentRefs = [];
            $verifiedTaskRefs = [];
            $verifiedExecutionEvidenceRefs = [];
            $verifiedEffectReviewDigests = [];
            foreach ($ids as $id) {
                $row = Db::name(self::EFFECT_REVIEW_TABLE)->where('id', $id)->find();
                if (!is_array($row)) {
                    continue;
                }
                if ((int)($row['tenant_id'] ?? 0) !== $tenantId
                    || (int)($row['hotel_id'] ?? 0) !== $targetHotelId
                ) {
                    $scopeMismatch = true;
                    continue;
                }
                if ((int)($row['causality_claimed'] ?? 1) !== 0) {
                    throw new InvalidArgumentException('复制复盘只能引用未宣称因果的正式效果复盘');
                }
                if (!$this->isDigest((string)($row['content_digest'] ?? ''))
                    || !$this->isDigest((string)($row['approval_target_digest'] ?? ''))
                ) {
                    $integrityMismatch = true;
                    continue;
                }
                if ((string)($row['result_status'] ?? '') !== $outcome) {
                    $outcomeMismatch = true;
                    continue;
                }
                if ($reviewedBusinessDate === null
                    || trim((string)($row['review_business_date'] ?? '')) !== $reviewedBusinessDate
                ) {
                    $dateMismatch = true;
                    continue;
                }
                try {
                    $lineage = $this->verifyReplicationExecutionLineage(
                        $replication,
                        $tenantId,
                        $targetHotelId,
                        (int)($row['intent_id'] ?? 0),
                        (int)($row['task_id'] ?? 0),
                        (int)($row['source_readback_evidence_id'] ?? 0),
                        ['executed'],
                        $outcome
                    );
                } catch (InvalidArgumentException|RuntimeException) {
                    $lineageMismatch = true;
                    continue;
                }
                try {
                    $formalReview = (new \app\service\operation\OperationEffectReviewService())->readVerified(
                        $id,
                        $tenantId,
                        $targetHotelId,
                        (int)$lineage['intent_id'],
                        (int)$lineage['task_id']
                    );
                } catch (InvalidArgumentException|RuntimeException) {
                    $formalReviewMismatch = true;
                    continue;
                }
                $cycle = $this->completedCycleForEvidence(
                    $tenantId,
                    $targetHotelId,
                    self::EFFECT_REVIEW_TABLE,
                    $id,
                    'comparable_outcome_readback',
                    'outcome_readback'
                );
                if ($cycle === null) {
                    $cycleMissing = true;
                    continue;
                }
                $verifiedRefs[] = self::EFFECT_REVIEW_TABLE . '#' . $id;
                $verifiedCycleRefs[] = self::OPERATING_CYCLE_TABLE . '#' . (int)$cycle['id'];
                $verifiedIntentRefs[] = self::EXECUTION_INTENT_TABLE . '#' . (int)$lineage['intent_id'];
                $verifiedTaskRefs[] = self::EXECUTION_TASK_TABLE . '#' . (int)$lineage['task_id'];
                $verifiedExecutionEvidenceRefs[] = self::EXECUTION_EVIDENCE_TABLE
                    . '#' . (int)$lineage['evidence_id'];
                $verifiedEffectReviewDigests[] = (string)$formalReview['content_digest'];
            }
            if ($verifiedRefs === []) {
                if ($scopeMismatch) {
                    throw new InvalidArgumentException('复制复盘证据引用不属于当前租户和目标酒店');
                }
                if ($outcomeMismatch) {
                    throw new InvalidArgumentException('复制复盘证据结果与所选成功或失败结论不一致');
                }
                if ($dateMismatch) {
                    throw new InvalidArgumentException('复制复盘业务日期与正式效果复盘日期不一致');
                }
                if ($integrityMismatch) {
                    throw new InvalidArgumentException('复制复盘引用的效果记录缺少不可变摘要或人工审批目标绑定');
                }
                if ($lineageMismatch) {
                    throw new InvalidArgumentException('复制复盘引用的效果记录不属于当前复制草稿执行血缘');
                }
                if ($formalReviewMismatch) {
                    throw new InvalidArgumentException('复制复盘引用的正式效果复盘未通过当前审批目标与内容摘要回读');
                }
                if ($cycleMissing) {
                    throw new InvalidArgumentException('复制复盘引用的效果记录尚未进入目标酒店权威经营闭环');
                }
                throw new InvalidArgumentException('复制复盘引用的正式效果复盘不存在或不可用');
            }
            return [
                'status' => 'verified',
                'lineage_status' => 'verified',
                'evidence_type' => 'formal_effect_review',
                'expected_outcome' => $outcome,
                'verified_refs' => array_values(array_unique($verifiedRefs)),
                'verified_operating_cycle_refs' => array_values(array_unique($verifiedCycleRefs)),
                'verified_execution_intent_refs' => array_values(array_unique($verifiedIntentRefs)),
                'verified_execution_task_refs' => array_values(array_unique($verifiedTaskRefs)),
                'verified_execution_evidence_refs' => array_values(array_unique($verifiedExecutionEvidenceRefs)),
                'verified_effect_review_content_digests' => array_values(array_unique($verifiedEffectReviewDigests)),
                'scope' => ['tenant_id' => $tenantId, 'target_hotel_id' => $targetHotelId],
            ];
        }

        if (!$this->tableExists(self::EXECUTION_EVIDENCE_TABLE)
            || !$this->tableExists(self::EXECUTION_TASK_TABLE)
        ) {
            throw new RuntimeException('执行证据表尚未启用，不能保存停止结论');
        }
        $ids = $this->evidenceRefIds($evidenceRefs, self::EXECUTION_EVIDENCE_TABLE);
        if ($ids === []) {
            throw new InvalidArgumentException('停止复盘必须引用 operation_execution_evidence#ID');
        }
        $verifiedRefs = [];
        $verifiedCycleRefs = [];
        $verifiedIntentRefs = [];
        $verifiedTaskRefs = [];
        $scopeMatched = false;
        $cycleMissing = false;
        $lineageMismatch = false;
        foreach ($ids as $id) {
            $evidence = Db::name(self::EXECUTION_EVIDENCE_TABLE)
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->find();
            if (!is_array($evidence)) {
                continue;
            }
            $task = Db::name(self::EXECUTION_TASK_TABLE)
                ->where('id', (int)($evidence['task_id'] ?? 0))
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $targetHotelId)
                ->whereNull('deleted_at')
                ->find();
            if (is_array($task)) {
                $scopeMatched = true;
                try {
                    $lineage = $this->verifyReplicationExecutionLineage(
                        $replication,
                        $tenantId,
                        $targetHotelId,
                        (int)($task['intent_id'] ?? 0),
                        (int)($task['id'] ?? 0),
                        $id,
                        ['executed', 'failed', 'blocked'],
                        null
                    );
                } catch (InvalidArgumentException|RuntimeException) {
                    $lineageMismatch = true;
                    continue;
                }
                $cycle = $this->completedCycleForEvidence(
                    $tenantId,
                    $targetHotelId,
                    self::EXECUTION_EVIDENCE_TABLE,
                    $id,
                    'real_execution_receipt',
                    'execution_receipt'
                );
                if ($cycle === null) {
                    $cycleMissing = true;
                    continue;
                }
                $verifiedRefs[] = self::EXECUTION_EVIDENCE_TABLE . '#' . $id;
                $verifiedCycleRefs[] = self::OPERATING_CYCLE_TABLE . '#' . (int)$cycle['id'];
                $verifiedIntentRefs[] = self::EXECUTION_INTENT_TABLE . '#' . (int)$lineage['intent_id'];
                $verifiedTaskRefs[] = self::EXECUTION_TASK_TABLE . '#' . (int)$lineage['task_id'];
            }
        }
        if ($verifiedRefs === []) {
            if ($lineageMismatch) {
                throw new InvalidArgumentException('停止复盘引用的执行回执不属于当前复制草稿执行血缘');
            }
            if ($scopeMatched && $cycleMissing) {
                throw new InvalidArgumentException('停止复盘的执行回执尚未进入目标酒店权威经营闭环');
            }
            throw new InvalidArgumentException('停止复盘证据引用不属于当前租户和目标酒店');
        }
        return [
            'status' => 'verified',
            'lineage_status' => 'verified',
            'evidence_type' => 'execution_stop_evidence',
            'expected_outcome' => 'stopped',
            'verified_refs' => array_values(array_unique($verifiedRefs)),
            'verified_operating_cycle_refs' => array_values(array_unique($verifiedCycleRefs)),
            'verified_execution_intent_refs' => array_values(array_unique($verifiedIntentRefs)),
            'verified_execution_task_refs' => array_values(array_unique($verifiedTaskRefs)),
            'verified_execution_evidence_refs' => array_values(array_unique($verifiedRefs)),
            'scope' => ['tenant_id' => $tenantId, 'target_hotel_id' => $targetHotelId],
        ];
    }

    /**
     * @param array<string,mixed> $replication
     * @param list<string> $allowedTaskStatuses
     * @return array{intent_id:int,task_id:int,evidence_id:int}
     */
    private function verifyReplicationExecutionLineage(
        array $replication,
        int $tenantId,
        int $targetHotelId,
        int $intentId,
        int $taskId,
        int $evidenceId,
        array $allowedTaskStatuses,
        ?string $expectedResultStatus
    ): array {
        if ($intentId <= 0 || $taskId <= 0 || $evidenceId <= 0
            || !$this->tableExists(self::EXECUTION_INTENT_TABLE)
            || !$this->tableExists(self::EXECUTION_TASK_TABLE)
            || !$this->tableExists(self::EXECUTION_EVIDENCE_TABLE)
        ) {
            throw new InvalidArgumentException('复制草稿执行血缘缺少意图、任务或执行回读');
        }
        $intent = Db::name(self::EXECUTION_INTENT_TABLE)
            ->where('id', $intentId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $targetHotelId)
            ->where('source_module', self::EXECUTION_SOURCE_MODULE)
            ->where('source_record_id', (int)($replication['id'] ?? 0))
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($intent) || strtolower(trim((string)($intent['status'] ?? ''))) !== 'approved') {
            throw new InvalidArgumentException('复制草稿执行血缘缺少当前有效且已人工批准的执行意图');
        }
        $this->assertReplicationExecutionIntentCurrent($intent);

        $task = Db::name(self::EXECUTION_TASK_TABLE)
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $targetHotelId)
            ->where('intent_id', $intentId)
            ->whereNull('deleted_at')
            ->find();
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        if (!is_array($task) || !in_array($taskStatus, $allowedTaskStatuses, true)) {
            throw new InvalidArgumentException('复制草稿执行血缘缺少符合复盘状态的执行任务');
        }
        if ($expectedResultStatus !== null
            && strtolower(trim((string)($task['result_status'] ?? ''))) !== $expectedResultStatus
        ) {
            throw new InvalidArgumentException('复制草稿执行任务结果与效果复盘结论不一致');
        }
        $evidence = Db::name(self::EXECUTION_EVIDENCE_TABLE)
            ->where('id', $evidenceId)
            ->where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($evidence)) {
            throw new InvalidArgumentException('复制草稿执行血缘缺少同一任务的执行或效果回读证据');
        }
        return [
            'intent_id' => $intentId,
            'task_id' => $taskId,
            'evidence_id' => $evidenceId,
        ];
    }

    /** @param list<string> $refs @return list<int> */
    private function evidenceRefIds(array $refs, string $table): array
    {
        $ids = [];
        $pattern = '/^' . preg_quote($table, '/') . '#([1-9][0-9]*)$/D';
        foreach ($refs as $ref) {
            if (preg_match($pattern, trim((string)$ref), $matches) === 1) {
                $ids[] = (int)$matches[1];
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return array<string,mixed> */
    private function readReview(int $id, int $tenantId): array
    {
        $row = Db::name(self::REVIEW_TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('operating replication review not found');
        }
        return $this->normalizeReview($row);
    }

    /** @return array<string,mixed> */
    private function normalizeProfile(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'version_no', 'created_by'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['previous_version_id'] = isset($row['previous_version_id']) ? (int)$row['previous_version_id'] : null;
        $row['is_current'] = (int)($row['is_current'] ?? 0) === 1;
        $row['profile'] = $this->decode($row['profile_json'] ?? null);
        $row['evidence_refs'] = $this->decode($row['evidence_refs_json'] ?? null);
        $row['freshness_status'] = trim((string)($row['evidence_valid_until'] ?? '')) !== ''
            && (string)$row['evidence_valid_until'] >= date('Y-m-d')
            ? 'current'
            : 'expired_or_missing';
        unset($row['profile_json'], $row['evidence_refs_json']);
        return $row;
    }

    /** @param array<string,mixed>|null $profile */
    private function isUsableProfile(?array $profile): bool
    {
        if ($profile === null
            || (string)($profile['quality_status'] ?? '') !== 'verified'
            || trim((string)($profile['evidence_valid_until'] ?? '')) === ''
            || (string)$profile['evidence_valid_until'] < date('Y-m-d')
            || array_values((array)($profile['evidence_refs'] ?? [])) === []
        ) {
            return false;
        }
        $dimensions = self::normalizeProfileDimensions($profile['profile']['dimensions'] ?? []);
        return count(array_filter($dimensions, static fn(array $values): bool => $values !== []))
            === count(self::PROFILE_DIMENSIONS);
    }

    /** @param array<string,mixed>|null $profile @return list<array<string,mixed>> */
    private function profileDataGaps(?array $profile): array
    {
        if ($profile === null) {
            return [['code' => 'hotel_operating_profile_missing', 'message' => '当前酒店尚未保存经营画像。']];
        }
        $gaps = [];
        if ((string)($profile['quality_status'] ?? '') !== 'verified') {
            $gaps[] = ['code' => 'hotel_operating_profile_unverified', 'message' => '当前酒店经营画像尚未核验。'];
        }
        if (trim((string)($profile['evidence_valid_until'] ?? '')) === ''
            || (string)$profile['evidence_valid_until'] < date('Y-m-d')
        ) {
            $gaps[] = ['code' => 'hotel_operating_profile_expired', 'message' => '当前酒店经营画像证据已过期或未声明有效期。'];
        }
        if (array_values((array)($profile['evidence_refs'] ?? [])) === []) {
            $gaps[] = ['code' => 'hotel_operating_profile_evidence_missing', 'message' => '当前酒店经营画像缺少证据引用。'];
        }
        $dimensions = self::normalizeProfileDimensions($profile['profile']['dimensions'] ?? []);
        foreach ($dimensions as $key => $values) {
            if ($values === []) {
                $gaps[] = [
                    'code' => 'hotel_operating_profile_' . $key . '_missing',
                    'dimension' => $key,
                    'message' => '当前酒店经营画像缺少' . self::PROFILE_DIMENSIONS[$key] . '。',
                ];
            }
        }
        return $gaps;
    }

    /** @return array<string,mixed> */
    private function normalizeReview(array $row): array
    {
        foreach ([
            'id', 'tenant_id', 'replication_id', 'review_no', 'source_sop_version_id',
            'source_hotel_id', 'target_hotel_id', 'created_by',
        ] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['review'] = $this->decode($row['review_json'] ?? null);
        unset($row['review_json']);
        return $row;
    }

    /** @return array<string,mixed> */
    private function normalizeOnboardingConfirmations(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $result = [];
        foreach (['room_rate_mapping', 'metric_definition'] as $key) {
            $item = is_array($value[$key] ?? null) ? $value[$key] : [];
            $status = strtolower(trim((string)($item['status'] ?? 'missing')));
            if (!in_array($status, ['verified', 'partial', 'missing'], true)) {
                throw new InvalidArgumentException($key . '状态必须是 verified、partial 或 missing');
            }
            $refs = self::textItems($item['evidence_refs'] ?? [], 50, 300);
            if ($status === 'verified' && $refs === []) {
                throw new InvalidArgumentException($key . '标记 verified 时必须保存证据引用');
            }
            $result[$key] = ['status' => $status, 'evidence_refs' => $refs];
        }
        return $result;
    }

    /** @param array<string,array<string,mixed>> $dimensionEvidence @param list<string> $values @param list<string> $refs @param list<string> $scopes @param list<string> $gaps */
    private function addProfileDraftEvidence(
        array &$dimensionEvidence,
        string $dimension,
        array $values,
        array $refs,
        array $scopes,
        array $gaps
    ): void {
        if (!isset($dimensionEvidence[$dimension])) {
            return;
        }
        $dimensionEvidence[$dimension]['values'] = array_merge(
            (array)$dimensionEvidence[$dimension]['values'],
            $values
        );
        $dimensionEvidence[$dimension]['evidence_refs'] = array_merge(
            (array)$dimensionEvidence[$dimension]['evidence_refs'],
            $refs
        );
        $dimensionEvidence[$dimension]['source_scopes'] = array_merge(
            (array)$dimensionEvidence[$dimension]['source_scopes'],
            $scopes
        );
        $dimensionEvidence[$dimension]['confirmation_gaps'] = array_merge(
            (array)$dimensionEvidence[$dimension]['confirmation_gaps'],
            $gaps
        );
    }

    /** @return array<string,mixed> */
    private function profileDraftHotelMaster(int $tenantId, int $hotelId): array
    {
        $columns = $this->tableColumns('hotels');
        $fields = array_values(array_filter(
            ['id', 'tenant_id', 'city', 'update_time'],
            static fn(string $field): bool => isset($columns[$field])
        ));
        if (!isset($columns['id'], $columns['tenant_id']) || $fields === []) {
            return [];
        }
        $row = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->field(implode(',', $fields))
            ->find();
        return is_array($row) ? $row : [];
    }

    /** @return array{physical_room_count:int,room_type_count:int,room_type_values:list<string>,price_band_value:?string,evidence_refs:list<string>,evidence_dates:list<string>} */
    private function profileDraftRoomFacts(int $tenantId, int $hotelId): array
    {
        $empty = [
            'physical_room_count' => 0,
            'room_type_count' => 0,
            'room_type_values' => [],
            'price_band_value' => null,
            'evidence_refs' => [],
            'evidence_dates' => [],
        ];
        $columns = $this->tableColumns('room_types');
        if (!isset($columns['id'], $columns['hotel_id'])) {
            return $empty;
        }
        $fields = array_values(array_filter(
            ['id', 'tenant_id', 'hotel_id', 'name', 'room_count', 'base_price', 'min_price', 'max_price', 'is_enabled', 'update_time'],
            static fn(string $field): bool => isset($columns[$field])
        ));
        $query = Db::name('room_types')->where('hotel_id', $hotelId);
        if (isset($columns['tenant_id'])) {
            $query->where('tenant_id', $tenantId);
        }
        if (isset($columns['is_enabled'])) {
            $query->where('is_enabled', 1);
        }
        $rows = $query->field(implode(',', $fields))->order('id', 'asc')->limit(100)->select()->toArray();
        if ($rows === []) {
            return $empty;
        }

        $roomCount = 0;
        $roomTypeValues = [];
        $refs = [];
        $prices = [];
        $dates = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $refs[] = 'room_types#' . $id;
            }
            $count = max(0, (int)($row['room_count'] ?? 0));
            $roomCount += $count;
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $roomTypeValues[] = '房型：' . $name . ($count > 0 ? '（' . $count . '间）' : '');
            }
            foreach (['min_price', 'base_price', 'max_price'] as $field) {
                $price = isset($row[$field]) && is_numeric($row[$field]) ? (float)$row[$field] : 0.0;
                if ($price > 0) {
                    $prices[] = $price;
                }
            }
            $updatedAt = trim((string)($row['update_time'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/D', $updatedAt, $matches) === 1) {
                $dates[] = $matches[0];
            }
        }
        $priceBand = null;
        if ($prices !== []) {
            $minimum = min($prices);
            $maximum = max($prices);
            $priceBand = '配置价带：' . $this->profileDraftNumber($minimum)
                . ($maximum > $minimum ? '-' . $this->profileDraftNumber($maximum) : '') . '元';
        }
        return [
            'physical_room_count' => $roomCount,
            'room_type_count' => count($rows),
            'room_type_values' => self::textItems($roomTypeValues, 30, 120),
            'price_band_value' => $priceBand,
            'evidence_refs' => self::textItems($refs, 100, 300),
            'evidence_dates' => self::textItems($dates, 100, 10),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function profileDraftDataSourceBindings(int $tenantId, int $hotelId): array
    {
        $columns = $this->tableColumns('platform_data_sources');
        if (!isset($columns['id'], $columns['tenant_id'], $columns['system_hotel_id'])) {
            return [];
        }
        $fields = array_values(array_filter(
            ['id', 'tenant_id', 'system_hotel_id', 'platform', 'data_type', 'ingestion_method', 'status', 'enabled'],
            static fn(string $field): bool => isset($columns[$field])
        ));
        $rows = Db::name('platform_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->field(implode(',', $fields))
            ->order('id', 'asc')
            ->select()
            ->toArray();
        return array_values(array_filter($rows, static function (array $row): bool {
            if (array_key_exists('enabled', $row) && (int)$row['enabled'] !== 1) {
                return false;
            }
            $status = strtolower(trim((string)($row['status'] ?? '1')));
            return in_array(
                $status,
                ['1', 'active', 'enabled', 'normal', 'ready', 'success', 'partial_success'],
                true
            );
        }));
    }

    /**
     * Evaluate saved/read-back OTA rows with the same truth envelope used by
     * the online-data screens. Raw payloads are inspected only in memory for
     * field-fact evidence and are never returned by this preview.
     *
     * @return array{
     *   verified_count:int,
     *   verified_platforms:list<string>,
     *   verified_business_date_start:?string,
     *   verified_business_date_end:?string,
     *   verified_evidence_refs:list<string>,
     *   candidate_count:int,
     *   evaluated_candidate_count:int,
     *   candidate_evidence_refs:list<string>,
     *   status_counts:array{verified:int,partial:int,unverified:int,collection_failed:int},
     *   evaluation_truncated:bool
     * }
     */
    private function profileDraftTruthFacts(int $tenantId, int $hotelId): array
    {
        $empty = [
            'verified_count' => 0,
            'verified_platforms' => [],
            'verified_business_date_start' => null,
            'verified_business_date_end' => null,
            'verified_evidence_refs' => [],
            'candidate_count' => 0,
            'evaluated_candidate_count' => 0,
            'candidate_evidence_refs' => [],
            'status_counts' => [
                'verified' => 0,
                'partial' => 0,
                'unverified' => 0,
                'collection_failed' => 0,
            ],
            'evaluation_truncated' => false,
        ];
        $columns = $this->tableColumns('online_daily_data');
        foreach (['id', 'tenant_id', 'system_hotel_id', 'readback_verified'] as $required) {
            if (!isset($columns[$required])) {
                return $empty;
            }
        }
        $candidateCount = (int)Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('readback_verified', 1)
            ->count();
        if ($candidateCount <= 0) {
            return $empty;
        }

        // Keep this whitelist aligned with OnlineDataFieldFactService storage
        // targets. No credential/config columns are selected or exposed.
        $fields = array_values(array_filter(
            [
                'id', 'tenant_id', 'system_hotel_id',
                'platform_hotel_id', 'hotel_id', 'ota_hotel_id',
                'system_hotel_name', 'hotel_name', 'data_date',
                'platform', 'source', 'data_type', 'dimension', 'compare_type',
                'ingestion_method', 'source_method', 'source_trace_id',
                'snapshot_time', 'collected_at', 'received_at',
                'readback_verified', 'readback_verified_at',
                'status', 'save_status', 'validation_status', 'validation_flags',
                'amount', 'quantity', 'book_order_num', 'comment_score',
                'qunar_comment_score', 'data_value', 'list_exposure',
                'detail_exposure', 'flow_rate', 'order_filling_num',
                'order_submit_num', 'raw_data', 'create_time', 'update_time',
            ],
            static fn(string $field): bool => isset($columns[$field])
        ));
        $query = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('readback_verified', 1)
            ->field(implode(',', $fields));
        if (isset($columns['data_date'])) {
            $query->order('data_date', 'desc');
        }
        $rows = $query->order('id', 'desc')->limit(5000)->select()->toArray();

        $verifiedCount = 0;
        $verifiedPlatforms = [];
        $verifiedDates = [];
        $verifiedRefs = [];
        $candidateRefs = [];
        $statusCounts = $empty['status_counts'];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0 && count($candidateRefs) < 20) {
                $candidateRefs[] = 'online_daily_data#' . $id;
            }
            $raw = $this->decode($row['raw_data'] ?? null);
            $truth = OnlineDataTrustStatusService::truthEnvelope(
                $row,
                OnlineDataFieldFactService::buildStatus($row, $raw)
            );
            $status = strtolower(trim((string)($truth['status'] ?? 'unverified')));
            if (!array_key_exists($status, $statusCounts)) {
                $status = 'unverified';
            }
            $statusCounts[$status]++;
            if ($status !== 'verified') {
                continue;
            }

            $verifiedCount++;
            $platform = $this->platformLabel((string)($truth['platform'] ?? ''));
            if ($platform !== '') {
                $verifiedPlatforms[$platform] = true;
            }
            $businessDate = trim((string)($truth['data_date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) === 1) {
                $verifiedDates[$businessDate] = true;
            }
            if ($id > 0 && count($verifiedRefs) < 20) {
                $verifiedRefs[] = 'online_daily_data#' . $id;
            }
        }

        $verifiedDateValues = array_keys($verifiedDates);
        sort($verifiedDateValues);
        return [
            'verified_count' => $verifiedCount,
            'verified_platforms' => array_keys($verifiedPlatforms),
            'verified_business_date_start' => $verifiedDateValues[0] ?? null,
            'verified_business_date_end' => $verifiedDateValues === [] ? null : $verifiedDateValues[count($verifiedDateValues) - 1],
            'verified_evidence_refs' => $verifiedRefs,
            'candidate_count' => $candidateCount,
            'evaluated_candidate_count' => count($rows),
            'candidate_evidence_refs' => $candidateRefs,
            'status_counts' => $statusCounts,
            'evaluation_truncated' => count($rows) < $candidateCount,
        ];
    }

    private function platformLabel(string $platform): string
    {
        $platform = strtolower(trim($platform));
        return [
            'ctrip' => '携程',
            '携程' => '携程',
            'meituan' => '美团',
            '美团' => '美团',
            'dingdandao' => '订单来了',
            '订单来了' => '订单来了',
        ][$platform] ?? mb_substr(trim($platform), 0, 40);
    }

    private function profileDraftNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function profileDraftGapMessage(string $gap): string
    {
        return [
            'hotel_type_requires_human_confirmation' => '仅能确认已配置房量，酒店类型仍需人工确认。',
            'business_district_requires_human_confirmation' => '主档城市可用，具体商圈仍需人工确认。',
            'demand_structure_requires_human_confirmation' => '主档不包含需求结构，仍需人工确认。',
            'room_rate_mapping_requires_human_confirmation' => '房型主档不等于平台房型价型映射，映射仍需人工核验。',
            'configured_price_band_requires_human_confirmation' => '配置价格只生成候选价带，不能替代当前成交或在售价带核验。',
            'evidence_validity_requires_human_confirmation' => '完整核验事实没有自动声明画像证据有效期，仍需人工确认。',
            'strict_ota_fact_verification_missing' => '保存回读候选尚未通过完整真值门，不能作为已验证经营事实。',
        ][$gap] ?? $gap;
    }

    /** @return list<array<string,mixed>> */
    private function dataSourceBindings(int $tenantId, int $hotelId): array
    {
        if (!$this->tableExists('platform_data_sources')) {
            return [];
        }
        $rows = Db::name('platform_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->select()
            ->toArray();
        return array_values(array_filter($rows, static function (array $row): bool {
            if (array_key_exists('enabled', $row) && (int)$row['enabled'] !== 1) {
                return false;
            }
            $status = strtolower(trim((string)($row['status'] ?? '1')));
            return in_array(
                $status,
                ['1', 'active', 'enabled', 'normal', 'ready', 'success', 'partial_success'],
                true
            );
        }));
    }

    /** @return array{count:int,refs:list<string>} */
    private function trustedCollection(int $tenantId, int $hotelId): array
    {
        if (!$this->tableExists('online_daily_data')) {
            return ['count' => 0, 'refs' => []];
        }
        $rows = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('readback_verified', 1)
            ->whereIn('validation_status', ['normal', 'verified'])
            ->order('data_date', 'asc')
            ->order('id', 'asc')
            ->limit(20)
            ->select()
            ->toArray();
        return [
            'count' => count($rows),
            'refs' => array_map(static fn(array $row): string => 'online_daily_data#' . (int)$row['id'], $rows),
        ];
    }

    /** @return array<string,mixed>|null */
    private function firstOperatingLoop(int $tenantId, int $hotelId): ?array
    {
        if (!$this->tableExists(self::OPERATING_CYCLE_TABLE)
            || !$this->tableExists(self::OPERATING_CYCLE_EVENT_TABLE)
            || !$this->tableExists(self::OPERATING_CYCLE_EVIDENCE_TABLE)
            || !$this->tableExists(self::EFFECT_REVIEW_TABLE)
        ) {
            return null;
        }
        $rows = Db::name(self::OPERATING_CYCLE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('cycle_status', 'completed')
            ->where('last_completed_stage', self::COMPLETED_LOOP_STAGE)
            ->order('business_date', 'desc')
            ->order('id', 'desc')
            ->limit(20)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $verified = $this->verifiedCompletedOperatingCycle($row, $tenantId, $hotelId);
            if ($verified !== null) {
                return $verified;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function verifiedCompletedOperatingCycle(array $row, int $tenantId, int $hotelId): ?array
    {
        $cycleId = (int)($row['id'] ?? 0);
        $lastEventId = (int)($row['last_event_id'] ?? 0);
        if ($cycleId <= 0
            || (int)($row['tenant_id'] ?? 0) !== $tenantId
            || (int)($row['hotel_id'] ?? 0) !== $hotelId
            || (string)($row['cycle_status'] ?? '') !== 'completed'
            || (string)($row['last_completed_stage'] ?? '') !== self::COMPLETED_LOOP_STAGE
            || (int)($row['last_completed_stage_index'] ?? -1) < 7
            || (string)($row['next_required_stage'] ?? '') !== 'next_cycle_identity_confirmation'
            || (int)($row['state_version'] ?? 0) < 8
            || $lastEventId <= 0
            || !$this->isDigest((string)($row['last_event_digest'] ?? ''))
            || !$this->isDigest((string)($row['projection_digest'] ?? ''))
            || !in_array((string)($row['outcome_status'] ?? ''), ['supported', 'refuted', 'indeterminate'], true)
            || !in_array((string)($row['experience_status'] ?? ''), ['not_reusable', 'candidate', 'promoted', 'rejected'], true)
        ) {
            return null;
        }
        $lastEvent = Db::name(self::OPERATING_CYCLE_EVENT_TABLE)
            ->where('id', $lastEventId)
            ->where('cycle_id', $cycleId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($lastEvent)
            || (string)($lastEvent['stage_key'] ?? '') !== self::COMPLETED_LOOP_STAGE
            || (string)($lastEvent['stage_status'] ?? '') !== 'completed'
            || !hash_equals(
                strtolower(trim((string)($row['last_event_digest'] ?? ''))),
                strtolower(trim((string)($lastEvent['event_digest'] ?? '')))
            )
        ) {
            return null;
        }
        $outcomeEvidence = Db::name(self::OPERATING_CYCLE_EVIDENCE_TABLE)
            ->where('cycle_id', $cycleId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('stage_key', 'comparable_outcome_readback')
            ->where('evidence_role', 'outcome_readback')
            ->where('source_table', self::EFFECT_REVIEW_TABLE)
            ->where('verification_status', 'readback_verified')
            ->where('readback_verified', 1)
            ->order('id', 'desc')
            ->find();
        $knowledgeEvidence = Db::name(self::OPERATING_CYCLE_EVIDENCE_TABLE)
            ->where('cycle_id', $cycleId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('stage_key', self::COMPLETED_LOOP_STAGE)
            ->whereIn('source_table', [
                OperatingMemoryService::TABLE,
                OperatingSopService::VERSION_TABLE,
                'knowledge_units',
                'knowledge_promotion_events',
            ])
            ->where('verification_status', 'readback_verified')
            ->where('readback_verified', 1)
            ->order('id', 'desc')
            ->find();
        if (!is_array($outcomeEvidence) || !is_array($knowledgeEvidence)) {
            return null;
        }
        $effectReviewId = (int)($outcomeEvidence['source_row_id'] ?? 0);
        $effectReview = $effectReviewId > 0
            ? Db::name(self::EFFECT_REVIEW_TABLE)
                ->where('id', $effectReviewId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->find()
            : null;
        if (!is_array($effectReview)
            || (int)($effectReview['causality_claimed'] ?? 1) !== 0
            || !$this->isDigest((string)($effectReview['content_digest'] ?? ''))
        ) {
            return null;
        }
        return [
            'id' => $cycleId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => (string)($row['business_date'] ?? ''),
            'outcome_status' => (string)$row['outcome_status'],
            'experience_status' => (string)$row['experience_status'],
            'verification_status' => 'authoritative_cycle_readback_verified',
            'last_event_ref' => self::OPERATING_CYCLE_EVENT_TABLE . '#' . $lastEventId,
            'outcome_evidence_ref' => self::EFFECT_REVIEW_TABLE . '#' . $effectReviewId,
            'knowledge_evidence_ref' => (string)$knowledgeEvidence['source_table']
                . '#' . (int)($knowledgeEvidence['source_row_id'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function completedCycleForEvidence(
        int $tenantId,
        int $hotelId,
        string $sourceTable,
        int $sourceRowId,
        string $stageKey,
        string $evidenceRole
    ): ?array {
        if ($sourceRowId <= 0
            || !$this->tableExists(self::OPERATING_CYCLE_TABLE)
            || !$this->tableExists(self::OPERATING_CYCLE_EVENT_TABLE)
            || !$this->tableExists(self::OPERATING_CYCLE_EVIDENCE_TABLE)
        ) {
            return null;
        }
        $links = Db::name(self::OPERATING_CYCLE_EVIDENCE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('source_table', $sourceTable)
            ->where('source_row_id', $sourceRowId)
            ->where('stage_key', $stageKey)
            ->where('evidence_role', $evidenceRole)
            ->where('verification_status', 'readback_verified')
            ->where('readback_verified', 1)
            ->order('id', 'desc')
            ->limit(20)
            ->select()
            ->toArray();
        foreach ($links as $link) {
            $cycle = Db::name(self::OPERATING_CYCLE_TABLE)
                ->where('id', (int)($link['cycle_id'] ?? 0))
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->find();
            if (is_array($cycle)) {
                $verified = $this->verifiedCompletedOperatingCycle($cycle, $tenantId, $hotelId);
                if ($verified !== null) {
                    return $verified;
                }
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function verifiedSops(int $tenantId, array $hotelIds, int $targetHotelId): array
    {
        if (!$this->tableExists(OperatingSopService::VERSION_TABLE)) {
            return [];
        }
        $rows = Db::name(OperatingSopService::VERSION_TABLE)
            ->where('tenant_id', $tenantId)
            ->whereIn('hotel_id', $hotelIds)
            ->where('hotel_id', '<>', $targetHotelId)
            ->where('validation_status', 'verified')
            ->where('lifecycle_status', 'active')
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        $names = $this->hotelNameMap($tenantId, $hotelIds);
        return array_map(function (array $row) use ($names, $tenantId): array {
            $scope = $this->decode($row['scope_json'] ?? null);
            $dimensions = self::normalizeProfileDimensions($scope['applicability_profile'] ?? []);
            $gaps = [];
            $sourceOperatingLoop = $this->firstOperatingLoop($tenantId, (int)$row['hotel_id']);
            if ($sourceOperatingLoop === null) {
                $gaps[] = 'source_authoritative_operating_loop_missing';
            }
            foreach ($dimensions as $key => $values) {
                if ($values === []) {
                    $gaps[] = 'applicability_' . $key . '_missing';
                }
            }
            foreach ([
                'action_parameters' => 'action_parameters_missing',
                'success_conditions' => 'success_conditions_missing',
                'failure_samples' => 'failure_samples_missing',
            ] as $field => $gap) {
                if (self::textItems($scope[$field] ?? []) === []) {
                    $gaps[] = $gap;
                }
            }
            if (self::textItems($this->decode($row['stop_conditions_json'] ?? null)) === []) {
                $gaps[] = 'stop_conditions_missing';
            }
            $validUntil = trim((string)($scope['evidence_valid_until'] ?? ''));
            if ($validUntil === '') {
                $gaps[] = 'evidence_valid_until_missing';
            } elseif ($validUntil < date('Y-m-d')) {
                $gaps[] = 'evidence_expired';
            }
            if (self::textItems($this->decode($row['evidence_refs_json'] ?? null), 100, 300) === []) {
                $gaps[] = 'evidence_refs_missing';
            }
            return [
                'id' => (int)$row['id'],
                'hotel_id' => (int)$row['hotel_id'],
                'hotel_name' => $names[(int)$row['hotel_id']] ?? ('酒店 #' . (int)$row['hotel_id']),
                'title' => (string)$row['title'],
                'version_no' => (int)$row['version_no'],
                'profile_dimension_count' => count(array_filter($dimensions, static fn(array $values): bool => $values !== [])),
                'evidence_valid_until' => $scope['evidence_valid_until'] ?? null,
                'replication_eligibility' => $gaps === []
                    ? 'eligible_for_validation_draft'
                    : 'contract_incomplete',
                'replication_gaps' => $gaps,
                'source_operating_loop_ref' => $sourceOperatingLoop === null
                    ? null
                    : self::OPERATING_CYCLE_TABLE . '#' . (int)$sourceOperatingLoop['id'],
            ];
        }, $rows);
    }

    /** @return list<array{id:int,name:string}> */
    private function hotelOptions(int $tenantId, array $hotelIds): array
    {
        $names = $this->hotelNameMap($tenantId, $hotelIds);
        $items = [];
        foreach ($hotelIds as $id) {
            if (isset($names[$id])) {
                $items[] = ['id' => $id, 'name' => $names[$id]];
            }
        }
        return $items;
    }

    /** @return array<int,string> */
    private function hotelNameMap(int $tenantId, array $hotelIds): array
    {
        $rows = Db::name('hotels')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $hotelIds)
            ->where('status', 1)
            ->field('id,name')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = trim((string)($row['name'] ?? '')) ?: ('酒店 #' . (int)$row['id']);
        }
        return $map;
    }

    /** @return array<string,mixed> */
    private function stage(string $key, string $label, string $status, array $evidenceRefs, array $gaps): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'evidence_refs' => array_values($evidenceRefs),
            'data_gaps' => array_values($gaps),
        ];
    }

    /** @return array<string,bool|string> */
    private function boundaries(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status_is_draft' => true,
            'human_target_validation_required' => true,
            'automatic_execution' => false,
            'ota_write' => false,
            'external_message' => false,
            'causality_claimed' => false,
        ];
    }

    private function assertProfileTableReady(): void
    {
        if (!$this->tableExists(self::PROFILE_TABLE)) {
            throw new RuntimeException('酒店经营画像功能尚未启用：请先执行本地数据库迁移');
        }
    }

    private function assertReviewTableReady(): void
    {
        if (!$this->tableExists(self::REVIEW_TABLE) || !$this->tableExists(OperatingSopService::REPLICATION_TABLE)) {
            throw new RuntimeException('复制复盘功能尚未启用：请先执行本地数据库迁移');
        }
    }

    private function assertHotelIdentity(int $tenantId, int $hotelId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0 || !$this->tableExists('hotels')) {
            throw new InvalidArgumentException('酒店经营画像缺少有效的租户或酒店身份');
        }
        $actualTenant = (int)Db::name('hotels')->where('id', $hotelId)->where('status', 1)->value('tenant_id');
        if ($actualTenant <= 0 || $actualTenant !== $tenantId) {
            throw new RuntimeException('酒店经营画像的酒店与租户身份不一致');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->select();
            return true;
        } catch (\Throwable) {
            return false;
        }
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

    /** @return list<int> */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
    }

    private function date(string $value, string $label): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . '格式必须是 YYYY-MM-DD');
        }
        return $value;
    }

    private function optionalDate(string $value, string $label): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $this->date($value, $label);
    }

    private function digest(mixed $value): string
    {
        return (new KnowledgeContentDigestService())->digest($value);
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($value))) === 1;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
