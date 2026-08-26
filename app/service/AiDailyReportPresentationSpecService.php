<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Builds a presentation-neutral, evidence-governed Deck Spec from an already
 * persisted AI daily report. It never renders, publishes, or executes actions.
 */
final class AiDailyReportPresentationSpecService
{
    private const TABLE = 'ai_report_presentation_specs';

    public const SCHEMA_VERSION = 'suxios.ai_daily_report.presentation_spec.v1';
    public const ADAPTER_VERSION = '2026-08-24.5';

    private const SOURCE_REPOSITORY = 'https://github.com/moyusheng0916-eng/JHIRA-YUSHENG-PPT';
    private const SOURCE_COMMIT = '4dc9898c86ef3c4589c903e69ad12f6e398dcf28';
    private const SOURCE_TREE_SHA256 = '8bfc490509e9fb46a44a81dc0f753355ce3b6c5c9b4e9737e929136431334fdd';
    private const SOURCE_SKILL_TREE_SHA256 = 'cee95b70b70ccd899a058f31fb918a4e9a45b6da50c4ef318368cd07e10f2497';

    private const AUDIENCES = ['owner', 'expert', 'training'];
    private const EVIDENCE_CLASSES = [
        'VERIFIED_FACT',
        'DERIVED_METRIC',
        'PROFESSIONAL_JUDGMENT',
        'ACTION_RECOMMENDATION',
        'HUMAN_DECISION',
        'UNKNOWN',
        'MOCK',
    ];

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public function build(array $report, string $audience = 'owner'): array
    {
        $audience = strtolower(trim($audience));
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new InvalidArgumentException('presentation audience must be owner, expert or training');
        }

        $reportId = (int)($report['id'] ?? 0);
        $tenantId = (int)($report['tenant_id'] ?? 0);
        $hotelId = (int)($report['hotel_id'] ?? 0);
        $reportDate = trim((string)($report['report_date'] ?? ''));
        if ($reportId <= 0 || $tenantId <= 0 || $hotelId <= 0 || !$this->isDate($reportDate)) {
            throw new InvalidArgumentException('saved AI daily report identity is incomplete');
        }

        $resultContract = is_array($report['result_contract'] ?? null)
            ? $report['result_contract']
            : [];
        $resultLayers = is_array($report['result_layers'] ?? null)
            ? $report['result_layers']
            : [];
        $sourceRefs = $this->arrayRows($report['source_refs'] ?? []);
        $verifiedSourceRefs = array_values(array_filter(
            $sourceRefs,
            fn(array $ref): bool => $this->sourceRefIsVerified($ref, $hotelId, $reportDate)
        ));
        $verifiedMetricCoverage = $this->verifiedMetricCoverage($verifiedSourceRefs);
        $verifiedSourceRefIds = array_values(array_unique(array_filter(array_map(
            fn(array $ref): string => $this->sourceRefIdentity($ref, $audience === 'training'),
            $verifiedSourceRefs
        ))));

        $evidence = [];
        foreach ($this->arrayRows($resultLayers['source_facts'] ?? []) as $item) {
            $matchedSourceRefs = $this->matchingVerifiedSourceRefIds(
                $item,
                $verifiedSourceRefs,
                false,
                $audience === 'training'
            );
            $evidence[] = $this->sourceFactEvidence(
                $item,
                count($evidence) + 1,
                $this->itemHasVerifiedMetricCoverage($item, $verifiedMetricCoverage, false)
                    && $matchedSourceRefs !== [],
                $matchedSourceRefs
            );
        }
        $derivedIndex = 0;
        foreach ($this->arrayRows($resultLayers['derived_metrics'] ?? []) as $item) {
            $derivedIndex++;
            $matchedSourceRefs = $this->matchingVerifiedSourceRefIds(
                $item,
                $verifiedSourceRefs,
                true,
                $audience === 'training'
            );
            $evidence[] = $this->derivedMetricEvidence(
                $item,
                $derivedIndex,
                $this->itemHasVerifiedMetricCoverage($item, $verifiedMetricCoverage, true)
                    && $matchedSourceRefs !== []
                    && trim((string)($resultContract['metric_version'] ?? '')) !== '',
                $matchedSourceRefs,
                (string)($resultContract['metric_version'] ?? '')
            );
        }
        $signalIndex = 0;
        foreach ($this->arrayRows($resultLayers['anomaly_signals'] ?? $report['abnormal_metrics'] ?? []) as $item) {
            $signalIndex++;
            $evidence[] = $this->signalEvidence($item, $signalIndex, $verifiedSourceRefIds);
        }

        $aiAssistance = is_array($resultLayers['ai_assistance'] ?? null)
            ? $resultLayers['ai_assistance']
            : (is_array($report['ai_interpretation'] ?? null) ? $report['ai_interpretation'] : []);
        $aiEvidence = $this->aiAssistanceEvidence($aiAssistance, $verifiedSourceRefIds);
        if ($aiEvidence !== null) {
            $evidence[] = $aiEvidence;
        }

        $actionIndex = 0;
        foreach ($this->arrayRows($report['recommended_actions'] ?? []) as $item) {
            $actionIndex++;
            $evidence[] = $this->actionEvidence($item, $actionIndex, $verifiedSourceRefIds);
        }

        if ($audience !== 'training') {
            $humanIndex = 0;
            foreach ($this->arrayRows($report['human_judgments'] ?? $resultLayers['human_judgments'] ?? []) as $item) {
                $humanEvidence = $this->humanDecisionEvidence($item, $humanIndex + 1);
                if ($humanEvidence !== null) {
                    $humanIndex++;
                    $evidence[] = $humanEvidence;
                }
            }
        }

        $gapIndex = 0;
        foreach ($this->arrayRows($report['data_gaps'] ?? []) as $item) {
            $gapIndex++;
            $evidence[] = $this->gapEvidence($item, $gapIndex, $audience === 'training');
        }

        if ($evidence === []) {
            $evidence[] = [
                'id' => 'U-01',
                'class' => 'UNKNOWN',
                'label' => '报告证据',
                'statement' => '当前已保存报告没有可用于演示的结构化证据。',
                'value' => null,
                'unit' => '',
                'source_refs' => [],
                'status' => 'missing',
            ];
        }

        $hasVerifiedFact = $this->containsEvidenceClass($evidence, 'VERIFIED_FACT');
        $hasDerivedMetric = $this->containsEvidenceClass($evidence, 'DERIVED_METRIC');
        $hasGaps = $this->containsEvidenceClass($evidence, 'UNKNOWN');
        $dataStatus = !$hasVerifiedFact && !$hasDerivedMetric
            ? 'unverified'
            : ($hasGaps ? 'partial' : 'ready_for_review');

        $summary = $this->evidenceSummary($evidence);
        $title = '宿析OS AI经营日报证据演示';
        if ($audience !== 'training') {
            $title .= ' · ' . $reportDate;
        }

        $spec = [
            'schema_version' => self::SCHEMA_VERSION,
            'adapter_version' => self::ADAPTER_VERSION,
            'source_report' => [
                'tenant_id' => $audience === 'training' ? null : $tenantId,
                'report_id' => $audience === 'training' ? null : $reportId,
                'hotel_id' => $audience === 'training' ? null : $hotelId,
                'business_date' => $audience === 'training' ? null : $reportDate,
                'result_version' => trim((string)($resultContract['result_version'] ?? '')),
                'metric_version' => trim((string)($resultContract['metric_version'] ?? '')),
                'reference_version' => trim((string)($resultContract['reference_version'] ?? '')),
                'readback_basis' => 'ai_daily_reports exact report read through tenant and hotel scoped service',
                'anonymization_status' => $audience === 'training'
                    ? 'identity_fields_removed_content_review_required'
                    : 'not_requested',
            ],
            'deck' => [
                'title' => $title,
                'decision_question' => '这份已保存日报能支持哪些判断，哪些仍需补证或人工决定？',
                'audience' => $audience,
                'language' => 'zh-CN',
                'ratio' => '16:9',
                'primary_reading_mode' => $audience === 'expert' ? 'READING_FIRST' : 'MIXED',
                'format_targets' => ['HTML', 'PPTX'],
                'source_boundary' => (string)(
                    $resultContract['boundary']
                    ?? 'OTA渠道事实、派生指标、辅助判断和人工决策分层展示，不扩大为全酒店财务结论。'
                ),
                'summary' => $summary,
                'data_status' => $dataStatus,
            ],
            'visual_system' => [
                'brand' => 'SUXIOS',
                'direction_name' => 'SUXIOS_RESTRAINED_EVIDENCE',
                'formality' => 'PROFESSIONAL',
                'density' => $audience === 'expert' ? 'MEDIUM' : 'LOW',
                'palette_id' => 'SUXIOS_NATIVE',
                'palette_version' => 'current-project-tokens',
                'palette_status' => 'PROJECT_NATIVE',
                'footer_brand' => 'SUXIOS',
                'footer_pagination' => 'DYNAMIC_CURRENT_TOTAL',
                'external_brand_adopted' => false,
            ],
            'evidence_ledger' => array_values($evidence),
            'slides' => $this->buildSlides($evidence, $title, $summary, $audience),
            'render_contract' => [
                'single_spec_required' => true,
                'recalculation_during_render' => false,
                'cross_format_semantic_parity_required' => true,
                'html' => [
                    'status' => 'not_rendered',
                    'runtime' => 'self_contained_offline',
                    'external_requests_allowed' => false,
                ],
                'pptx' => [
                    'status' => 'not_rendered',
                    'runtime' => 'suxios_native_ooxml_renderer',
                    'editable_text_and_shapes_required' => true,
                ],
                'external_write_authorized' => false,
                'human_review_required' => true,
            ],
            'qa' => [
                'spec_validation_status' => 'pending',
                'source_readback_status' => $verifiedSourceRefs === [] ? 'unverified' : 'verified',
                'evidence_status' => $dataStatus,
                'html_status' => 'not_rendered',
                'pptx_status' => 'not_rendered',
                'cross_format_parity_status' => 'pending_render',
                'human_review_status' => 'pending',
            ],
            'method_provenance' => [
                'source_repository' => self::SOURCE_REPOSITORY,
                'source_commit' => self::SOURCE_COMMIT,
                'source_tree_sha256' => self::SOURCE_TREE_SHA256,
                'source_skill_tree_sha256' => self::SOURCE_SKILL_TREE_SHA256,
                'source_license_status' => 'not_provided_no_code_copied',
                'integration_mode' => 'source_inspired_suxios_native_rebuild',
                'source_package_installed' => false,
            ],
            'authorization' => [
                'analysis_only' => true,
                'external_write_authorized' => false,
                'ota_write_authorized' => false,
                'pms_write_authorized' => false,
                'publish_authorized' => false,
            ],
        ];

        if ($audience === 'training') {
            $spec = $this->sanitizeTrainingSpec($spec, $reportDate);
        }

        $validation = $this->validate($spec);
        $spec['qa']['spec_validation_status'] = $validation['status'];
        $spec['qa']['spec_validation_errors'] = $validation['errors'];
        if ($validation['status'] !== 'pass') {
            throw new RuntimeException('AI daily report presentation spec validation failed');
        }
        $spec['spec_fingerprint'] = hash('sha256', $this->canonicalJson($spec));

        return $spec;
    }

    /**
     * Persist an immutable presentation specification and immediately read the
     * exact stored JSON back. Repeating the same request is idempotent.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public function saveAndReadback(array $report, string $audience, int $userId): array
    {
        $reportId = (int)($report['id'] ?? 0);
        $hotelId = (int)($report['hotel_id'] ?? 0);
        $tenantId = $this->resolveTenantId($report, $hotelId);
        $report['tenant_id'] = $tenantId;
        $spec = $this->build($report, $audience);
        $fingerprint = (string)$spec['spec_fingerprint'];
        $specJson = $this->canonicalJson($spec);
        $audience = (string)($spec['deck']['audience'] ?? '');
        $created = false;
        $id = 0;

        try {
            $id = (int)Db::name(self::TABLE)->insertGetId([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'report_id' => $reportId,
                'audience' => $audience,
                'schema_version' => self::SCHEMA_VERSION,
                'adapter_version' => self::ADAPTER_VERSION,
                'source_result_version' => trim((string)($spec['source_report']['result_version'] ?? '')),
                'spec_fingerprint' => $fingerprint,
                'spec_json' => $specJson,
                'data_status' => trim((string)($spec['deck']['data_status'] ?? 'unverified')),
                'render_status' => 'not_rendered',
                'created_by' => max(0, $userId),
            ]);
            if ($id <= 0) {
                throw new RuntimeException('AI daily report presentation spec save failed');
            }
            $created = true;
        } catch (Throwable $error) {
            if (!$this->isDuplicateKeyConflict($error)) {
                throw $error;
            }
        }

        $query = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('report_id', $reportId)
            ->where('audience', $audience)
            ->where('adapter_version', self::ADAPTER_VERSION)
            ->where('spec_fingerprint', $fingerprint);
        if ($id > 0) {
            $query->where('id', $id);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('AI daily report presentation spec readback failed');
        }

        return $this->normalizeStoredRow($row, $specJson, $created, [
            'tenant_id' => $tenantId,
            'hotel_ids' => [$hotelId],
            'report_id' => $reportId,
            'audience' => $audience,
        ]);
    }

    /**
     * Read the latest persisted specification through the already-authorized
     * hotel scope. This method never generates a replacement implicitly.
     *
     * @param array<int,int> $hotelIds
     * @return array<string,mixed>|null
     */
    public function readLatest(
        int $reportId,
        array $hotelIds,
        int $tenantId,
        string $audience = 'owner'
    ): ?array
    {
        $audience = strtolower(trim($audience));
        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('presentation tenant scope is required');
        }
        if ($reportId <= 0 || $hotelIds === [] || !in_array($audience, self::AUDIENCES, true)) {
            return null;
        }

        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('report_id', $reportId)
            ->whereIn('hotel_id', $hotelIds)
            ->where('audience', $audience)
            ->where('adapter_version', self::ADAPTER_VERSION)
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return null;
        }

        return $this->normalizeStoredRow($row, null, false, [
            'tenant_id' => $tenantId,
            'hotel_ids' => $hotelIds,
            'report_id' => $reportId,
            'audience' => $audience,
        ]);
    }

    /**
     * @param array<string,mixed> $spec
     * @return array{status:string,errors:array<int,string>}
     */
    public function validate(array $spec): array
    {
        $errors = [];
        if (($spec['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version_mismatch';
        }
        if (($spec['visual_system']['brand'] ?? '') !== 'SUXIOS'
            || ($spec['visual_system']['footer_brand'] ?? '') !== 'SUXIOS'
            || ($spec['visual_system']['external_brand_adopted'] ?? true) !== false
        ) {
            $errors[] = 'brand_boundary_mismatch';
        }
        if (($spec['authorization']['external_write_authorized'] ?? true) !== false
            || ($spec['render_contract']['external_write_authorized'] ?? true) !== false
        ) {
            $errors[] = 'external_write_boundary_mismatch';
        }
        $audience = (string)($spec['deck']['audience'] ?? '');
        $sourceReport = is_array($spec['source_report'] ?? null) ? $spec['source_report'] : [];
        $isCurrentAdapter = (string)($spec['adapter_version'] ?? '') === self::ADAPTER_VERSION;
        if (!in_array($audience, self::AUDIENCES, true)) {
            $errors[] = 'audience_invalid';
        } elseif ($audience === 'training') {
            $trainingIdentityFields = $isCurrentAdapter
                ? ['tenant_id', 'hotel_id', 'report_id', 'business_date']
                : ['hotel_id', 'report_id', 'business_date'];
            foreach ($trainingIdentityFields as $identityField) {
                if (!array_key_exists($identityField, $sourceReport) || $sourceReport[$identityField] !== null) {
                    $errors[] = 'training_identity_not_removed:' . $identityField;
                }
            }
        } elseif (($isCurrentAdapter && (int)($sourceReport['tenant_id'] ?? 0) <= 0)
            || (int)($sourceReport['hotel_id'] ?? 0) <= 0
            || (int)($sourceReport['report_id'] ?? 0) <= 0
            || !$this->isDate((string)($sourceReport['business_date'] ?? ''))
        ) {
            $errors[] = 'source_report_identity_incomplete';
        }

        $evidenceIds = [];
        foreach ($this->arrayRows($spec['evidence_ledger'] ?? []) as $item) {
            $id = trim((string)($item['id'] ?? ''));
            $class = trim((string)($item['class'] ?? ''));
            if ($id === '' || isset($evidenceIds[$id])) {
                $errors[] = 'evidence_id_missing_or_duplicate';
                continue;
            }
            $evidenceIds[$id] = true;
            if (!in_array($class, self::EVIDENCE_CLASSES, true)) {
                $errors[] = 'evidence_class_invalid:' . $id;
            }
            if (in_array($class, ['VERIFIED_FACT', 'DERIVED_METRIC'], true)
                && ($item['metric_scope'] ?? '') !== 'ota_channel'
            ) {
                $errors[] = 'verified_evidence_scope_mismatch:' . $id;
            }
            if (in_array($class, ['VERIFIED_FACT', 'DERIVED_METRIC'], true)
                && array_values(array_filter((array)($item['source_refs'] ?? []), 'is_string')) === []
            ) {
                $errors[] = 'verified_evidence_source_missing:' . $id;
            }
            if ($class === 'UNKNOWN' && ($item['value'] ?? null) !== null) {
                $errors[] = 'unknown_value_must_be_null:' . $id;
            }
            if (($item['status'] ?? '') === 'hypothesis_review_required'
                && ($class !== 'UNKNOWN'
                    || ($item['raw_text_republished'] ?? true) !== false
                    || ($item['causality_claimed'] ?? true) !== false
                    || preg_match('/^[a-f0-9]{64}$/', (string)($item['raw_review_sha256'] ?? '')) !== 1)
            ) {
                $errors[] = 'hypothesis_boundary_mismatch:' . $id;
            }
            if ($class === 'ACTION_RECOMMENDATION'
                && (($item['execution_authorized'] ?? true) !== false
                    || ($item['external_write_authorized'] ?? true) !== false)
            ) {
                $errors[] = 'action_authorization_mismatch:' . $id;
            }
        }
        if ($evidenceIds === []) {
            $errors[] = 'evidence_ledger_empty';
        }

        $slideIds = [];
        foreach ($this->arrayRows($spec['slides'] ?? []) as $slide) {
            $slideId = trim((string)($slide['id'] ?? ''));
            if ($slideId === '' || isset($slideIds[$slideId])) {
                $errors[] = 'slide_id_missing_or_duplicate';
            } else {
                $slideIds[$slideId] = true;
            }
            if (trim((string)($slide['title'] ?? '')) === '') {
                $errors[] = 'slide_title_missing:' . $slideId;
            }
            if (count((array)($slide['evidence_ids'] ?? [])) > 5) {
                $errors[] = 'slide_evidence_density_exceeded:' . $slideId;
            }
            foreach ((array)($slide['evidence_ids'] ?? []) as $evidenceId) {
                if (!isset($evidenceIds[(string)$evidenceId])) {
                    $errors[] = 'slide_evidence_ref_missing:' . (string)$evidenceId;
                }
            }
        }

        return [
            'status' => $errors === [] ? 'pass' : 'fail',
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param array<int,array<string,mixed>> $evidence */
    private function buildSlides(array $evidence, string $title, string $summary, string $audience): array
    {
        $idsByClass = [];
        foreach ($evidence as $item) {
            $class = (string)($item['class'] ?? 'UNKNOWN');
            $idsByClass[$class][] = (string)($item['id'] ?? '');
        }
        $facts = array_values(array_filter(array_merge(
            $idsByClass['VERIFIED_FACT'] ?? [],
            $idsByClass['DERIVED_METRIC'] ?? []
        )));
        $judgments = array_values(array_filter($idsByClass['PROFESSIONAL_JUDGMENT'] ?? []));
        $decisions = array_values(array_filter(array_merge(
            $idsByClass['ACTION_RECOMMENDATION'] ?? [],
            $idsByClass['HUMAN_DECISION'] ?? []
        )));
        $unknowns = array_values(array_filter($idsByClass['UNKNOWN'] ?? []));

        $slides = [[
            'id' => 'S01',
            'role' => 'TITLE',
            'title' => $title,
            'message' => '从已保存事实出发，分开呈现派生指标、辅助判断、待确认动作与未知项。',
            'evidence_ids' => array_slice($facts !== [] ? $facts : $unknowns, 0, 2),
            'source_note' => '来源：已保存并按租户/酒店范围精确读取的 AI 经营日报。',
        ]];

        $slideNumber = 2;
        $this->appendEvidenceSlides(
            $slides,
            $slideNumber,
            'EXECUTIVE_SUMMARY',
            '先确认哪些数据已经站得住',
            $summary !== '' ? $summary : '先看可核验证据，再看派生指标；缺失项不补值。',
            $facts,
            '事实与派生指标分层展示；范围仅限已保存 OTA 渠道证据，不扩大为全酒店结论。'
        );
        $this->appendEvidenceSlides(
            $slides,
            $slideNumber,
            'DIAGNOSIS',
            '辅助判断必须留在证据之后',
            $judgments === []
                ? '当前没有达到展示门槛的辅助判断。'
                : '这些解释用于缩小核查范围，不把相关性写成因果。',
            $judgments,
            '专业判断与 AI 辅助均不替代人工判断。'
        );
        $this->appendEvidenceSlides(
            $slides,
            $slideNumber,
            'DECISION',
            '下一步只形成待确认动作',
            '动作仅供人工确认；未授权发布、OTA/PMS 写入或外部发送。',
            $decisions,
            '审批动作必须由用户主动触发。'
        );
        if ($unknowns !== []) {
            $this->appendEvidenceSlides(
                $slides,
                $slideNumber,
                'GAP',
                '尚缺的证据决定结论边界',
                '缺失项保持未知；补齐同酒店、同平台、同日期和同口径证据后再判断。',
                $unknowns,
                '未知项不以 0、空数组或模型补写替代。'
            );
        }

        if ($audience === 'expert') {
            $this->appendEvidenceSlides(
                $slides,
                $slideNumber,
                'METHOD',
                '一份规范驱动两种格式',
                'HTML 与 PPTX 消费同一份 PresentationSpec；渲染阶段不得重算或补写指标。',
                array_merge($facts, $unknowns),
                '跨格式比较语义、数值、单位、来源、日期和状态，不要求像素一致。'
            );
        }

        return $slides;
    }

    /**
     * @param array<int,array<string,mixed>> $slides
     * @param array<int,string> $evidenceIds
     */
    private function appendEvidenceSlides(
        array &$slides,
        int &$slideNumber,
        string $role,
        string $title,
        string $message,
        array $evidenceIds,
        string $sourceNote
    ): void {
        $chunks = $evidenceIds === [] ? [[]] : array_chunk(array_values($evidenceIds), 5);
        $total = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $slides[] = [
                'id' => sprintf('S%02d', $slideNumber++),
                'role' => $role,
                'title' => $total > 1 ? $title . sprintf('（%d/%d）', $index + 1, $total) : $title,
                'message' => $message,
                'evidence_ids' => $chunk,
                'source_note' => $sourceNote,
            ];
        }
    }

    /** @param array<string,mixed> $item @param array<int,string> $sourceRefs */
    private function sourceFactEvidence(array $item, int $index, bool $hasVerifiedSource, array $sourceRefs): array
    {
        $value = $this->displayValue($item);
        $verified = $value !== null && $hasVerifiedSource && $this->itemTruthIsVerified($item);
        $label = $this->itemLabel($item, '来源事实');
        return [
            'id' => sprintf('F-%02d', $index),
            'class' => $verified ? 'VERIFIED_FACT' : 'UNKNOWN',
            'label' => $label,
            'statement' => $verified
                ? $this->metricStatement($label, $value, (string)($item['unit'] ?? ''))
                : $label . '：来源或回读状态不足，未作为事实展示。',
            'value' => $verified ? $value : null,
            'unit' => $verified ? trim((string)($item['unit'] ?? '')) : '',
            'metric_scope' => $this->itemMetricScope($item),
            'source_refs' => $sourceRefs,
            'status' => $verified ? 'verified' : 'unverified',
        ];
    }

    /** @param array<string,mixed> $item @param array<int,string> $sourceRefs */
    private function derivedMetricEvidence(
        array $item,
        int $index,
        bool $derivationSupported,
        array $sourceRefs,
        string $metricVersion
    ): array {
        $value = $this->displayValue($item);
        $ready = $value !== null && $derivationSupported && $this->itemUsesVerifiedOtaScope($item);
        $label = $this->itemLabel($item, '派生指标');
        return [
            'id' => sprintf('D-%02d', $index),
            'class' => $ready ? 'DERIVED_METRIC' : 'UNKNOWN',
            'label' => $label,
            'statement' => $ready
                ? $this->metricStatement($label, $value, (string)($item['unit'] ?? ''))
                : $label . '：计算版本或来源回读不完整，未展示数值。',
            'value' => $ready ? $value : null,
            'unit' => $ready ? trim((string)($item['unit'] ?? '')) : '',
            'metric_scope' => $this->itemMetricScope($item),
            'metric_version' => $ready ? trim($metricVersion) : '',
            'source_refs' => $sourceRefs,
            'status' => $ready ? 'derived_from_verified_sources' : 'unverified',
            'causality_claimed' => false,
        ];
    }

    /** @param array<string,mixed> $item @param array<int,string> $sourceRefs */
    private function signalEvidence(array $item, int $index, array $sourceRefs): array
    {
        $basis = is_array($item['reference_basis'] ?? null) ? $item['reference_basis'] : [];
        $available = strtolower(trim((string)($basis['status'] ?? ''))) === 'available';
        $label = $this->controlledSignalLabel($item, $index);
        $rawReviewSha = $this->rawReviewFingerprint(
            $item,
            ['type', 'code', 'key', 'label', 'name', 'evidence', 'message', 'description']
        );
        $reviewable = $available && $rawReviewSha !== null;
        return [
            'id' => sprintf('J-%02d', $index),
            'class' => 'UNKNOWN',
            'label' => $label,
            'statement' => $reviewable
                ? $label . '：观察到相关线索；原始自由文本未进入结论层，尚不能归因，需按同酒店、同平台、同日期和同口径复核。'
                : $label . '：缺少同口径参考依据，暂不判断异常。',
            'value' => null,
            'unit' => '',
            'source_refs' => $sourceRefs,
            'status' => $reviewable ? 'hypothesis_review_required' : 'reference_missing',
            'raw_review_sha256' => $rawReviewSha,
            'raw_text_republished' => false,
            'causality_claimed' => false,
        ];
    }

    /** @param array<string,mixed> $item @param array<int,string> $sourceRefs */
    private function aiAssistanceEvidence(array $item, array $sourceRefs): ?array
    {
        $status = strtolower(trim((string)($item['status'] ?? '')));
        $texts = [];
        foreach (['summary', 'explanation', 'possible_explanations', 'conflicting_evidence', 'missing_information'] as $field) {
            $value = $item[$field] ?? null;
            if (is_array($value)) {
                foreach ($value as $row) {
                    $text = trim((string)$row);
                    if ($text !== '') {
                        $texts[] = $text;
                    }
                }
            } else {
                $text = trim((string)$value);
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }
        $texts = array_values(array_unique($texts));
        if ($texts === [] && $status === '') {
            return null;
        }
        $combinedText = implode('；', array_slice($texts, 0, 5));
        $blocked = in_array($status, ['blocked', 'blocked_by_data_conflict', 'unavailable', 'error'], true);
        $reviewable = $texts !== [] && !$blocked;
        return [
            'id' => 'J-AI-01',
            'class' => 'UNKNOWN',
            'label' => 'AI辅助解读',
            'statement' => $reviewable
                ? '已记录AI辅助解读线索；原始自由文本未进入结论层，仅作为待核验假设，不能据此归因或评价分平台表现。'
                : 'AI辅助解读不可用或被证据门禁阻断。',
            'value' => null,
            'unit' => '',
            'source_refs' => $sourceRefs,
            'status' => $reviewable ? 'hypothesis_review_required' : ($status !== '' ? $status : 'unavailable'),
            'raw_review_sha256' => $combinedText !== '' ? hash('sha256', $combinedText) : null,
            'raw_text_republished' => false,
            'causality_claimed' => false,
        ];
    }

    /** @param array<string,mixed> $item @param array<int,string> $sourceRefs */
    private function actionEvidence(array $item, int $index, array $sourceRefs): array
    {
        $label = trim((string)($item['title'] ?? $item['label'] ?? '待人工确认动作'));
        $statement = trim((string)($item['action'] ?? $item['suggestion'] ?? $item['description'] ?? ''));
        return [
            'id' => sprintf('R-%02d', $index),
            'class' => 'ACTION_RECOMMENDATION',
            'label' => mb_substr($label !== '' ? $label : '待人工确认动作', 0, 120),
            'statement' => mb_substr($statement !== '' ? $statement : '动作内容未提供，需人工补充。', 0, 500),
            'value' => null,
            'unit' => '',
            'source_refs' => $sourceRefs,
            'status' => trim((string)($item['status'] ?? 'pending_approval')) ?: 'pending_approval',
            'execution_authorized' => false,
            'external_write_authorized' => false,
        ];
    }

    /** @param array<string,mixed> $item */
    private function humanDecisionEvidence(array $item, int $index): ?array
    {
        $decision = trim((string)($item['decision'] ?? ''));
        $decisionId = trim((string)($item['id'] ?? ''));
        $recordedAt = trim((string)($item['recorded_at'] ?? ''));
        $recordedBy = (int)($item['user_id'] ?? 0);
        if (!in_array($decision, ['accepted', 'rejected', 'corrected', 'needs_more_evidence'], true)
            || $decisionId === ''
            || $recordedBy <= 0
            || !$this->isDateTime($recordedAt)
        ) {
            return null;
        }
        $target = trim((string)($item['target_type'] ?? '人工判断'));
        $note = trim((string)($item['note'] ?? $item['comment'] ?? ''));
        return [
            'id' => sprintf('H-%02d', $index),
            'class' => 'HUMAN_DECISION',
            'label' => mb_substr($target !== '' ? $target : '人工判断', 0, 120),
            'statement' => mb_substr(trim($decision . ($note !== '' ? '：' . $note : '')), 0, 500),
            'value' => null,
            'unit' => '',
            'source_refs' => [],
            'status' => 'recorded',
            'execution_authorized' => false,
            'external_write_authorized' => false,
        ];
    }

    /** @param array<int,array<string,mixed>> $evidence */
    private function evidenceSummary(array $evidence): string
    {
        $counts = array_fill_keys(self::EVIDENCE_CLASSES, 0);
        $hypothesisCount = 0;
        foreach ($evidence as $item) {
            $class = (string)($item['class'] ?? 'UNKNOWN');
            $counts[$class] = ($counts[$class] ?? 0) + 1;
            if ($class === 'UNKNOWN' && ($item['status'] ?? '') === 'hypothesis_review_required') {
                $hypothesisCount++;
            }
        }
        return sprintf(
            '已验证事实%d项｜派生指标%d项｜待核验假设%d项｜待确认动作%d项｜未知%d项。',
            $counts['VERIFIED_FACT'],
            $counts['DERIVED_METRIC'],
            $hypothesisCount,
            $counts['ACTION_RECOMMENDATION'] + $counts['HUMAN_DECISION'],
            max(0, $counts['UNKNOWN'] - $hypothesisCount)
        );
    }

    /** @param array<string,mixed> $item */
    private function gapEvidence(array $item, int $index, bool $anonymize): array
    {
        $code = trim((string)($item['code'] ?? ''));
        $label = $this->humanizeGapLabel($code, $index);
        $message = trim((string)($item['message'] ?? '存在尚未补齐的数据或证据。'));
        return [
            'id' => sprintf('U-%02d', $index),
            'class' => 'UNKNOWN',
            'label' => mb_substr($label !== '' ? $label : '数据缺口', 0, 120),
            'statement' => mb_substr($message, 0, 500),
            'value' => null,
            'unit' => '',
            'gap_code' => $code,
            'source_refs' => $anonymize
                ? []
                : array_values(array_filter([
                    trim((string)($item['source_ref'] ?? '')),
                ])),
            'status' => 'missing_or_unverified',
        ];
    }

    private function humanizeGapLabel(string $code, int $index): string
    {
        $known = [
            'competitor_same_scope_missing' => '同口径竞品样本待补齐',
            'competitors_data_pending' => '竞品数据待补齐',
            'service_quality_data_pending' => '服务质量数据待补齐',
            'collection_abnormal_flag' => '采集异常状态待核验',
            'ctrip_source_trace_unverified' => '携程来源追踪未核验',
            'meituan_binding_missing' => '美团本店 POI 绑定缺失',
            'meituan_self_rank_missing' => '美团本店排名缺失',
        ];
        if (isset($known[$code])) {
            return $known[$code];
        }
        if ($code !== '' && preg_match('/\p{Han}/u', $code) === 1) {
            return mb_substr($code, 0, 60);
        }
        return '数据缺口 ' . $index;
    }

    /** @param array<string,mixed> $item */
    private function itemTruthIsVerified(array $item): bool
    {
        if (($item['readback_verified'] ?? false) === true) {
            return true;
        }
        $truth = is_array($item['truth'] ?? null) ? $item['truth'] : [];
        if (($truth['readback_verified'] ?? false) === true) {
            return true;
        }
        if (!$this->itemUsesVerifiedOtaScope($item)) {
            return false;
        }
        $status = strtolower(trim((string)(
            $truth['status']
            ?? $truth['truth_status']
            ?? $item['data_status']
            ?? $item['status']
            ?? ''
        )));
        return in_array($status, [
            'available',
            'verified',
            'ready',
            'trusted',
            'source_verified',
            'readback_verified',
            'ok',
        ], true);
    }

    /** @param array<string,mixed> $item */
    private function itemUsesVerifiedOtaScope(array $item): bool
    {
        return $this->itemMetricScope($item) === 'ota_channel';
    }

    /** @param array<string,mixed> $item */
    private function itemMetricScope(array $item): string
    {
        $scope = strtolower(trim((string)($item['metric_scope'] ?? '')));
        if ($scope !== '') {
            return $scope;
        }
        $scopes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            is_array($item['metric_scopes'] ?? null) ? $item['metric_scopes'] : []
        ))));
        return count($scopes) === 1 ? $scopes[0] : ($scopes === [] ? 'unknown' : 'mixed_source_scope');
    }

    /**
     * @param array<int,array<string,mixed>> $verifiedRefs
     * @return array<string,true>
     */
    private function verifiedMetricCoverage(array $verifiedRefs): array
    {
        $coverage = [];
        foreach ($verifiedRefs as $ref) {
            foreach (['metric_keys', 'field_fact_metric_keys'] as $field) {
                foreach ((array)($ref[$field] ?? []) as $metricKey) {
                    $metricKey = strtolower(trim((string)$metricKey));
                    if ($metricKey !== '') {
                        $coverage[$metricKey] = true;
                    }
                }
            }
        }
        return $coverage;
    }

    /**
     * Return only the verified source identities that actually cover the
     * evidence row. An unrelated verified source must never be attached merely
     * because it belongs to the same report.
     *
     * @param array<string,mixed> $item
     * @param array<int,array<string,mixed>> $verifiedRefs
     * @return array<int,string>
     */
    private function matchingVerifiedSourceRefIds(
        array $item,
        array $verifiedRefs,
        bool $derived,
        bool $anonymize
    ): array {
        if ($verifiedRefs === [] || !$this->itemUsesVerifiedOtaScope($item)) {
            return [];
        }
        $key = strtolower(trim((string)($item['key'] ?? $item['metric_key'] ?? '')));
        if ($key === '') {
            return [];
        }

        $direct = $this->metricAliases($key);
        $matches = $this->sourceRefsCoveringAliases($verifiedRefs, $direct);
        if ($matches === [] && $derived) {
            $requirements = $this->derivedMetricRequirements($key);
            if ($requirements === []) {
                return [];
            }
            foreach ($requirements as $aliases) {
                $groupMatches = $this->sourceRefsCoveringAliases($verifiedRefs, $aliases);
                if ($groupMatches === []) {
                    return [];
                }
                foreach ($groupMatches as $index => $ref) {
                    $matches[$index] = $ref;
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn(array $ref): string => $this->sourceRefIdentity($ref, $anonymize),
            array_values($matches)
        ))));
    }

    /**
     * @param array<int,array<string,mixed>> $refs
     * @param array<int,string> $aliases
     * @return array<int,array<string,mixed>>
     */
    private function sourceRefsCoveringAliases(array $refs, array $aliases): array
    {
        $matches = [];
        foreach ($refs as $index => $ref) {
            $keys = [];
            foreach (['metric_keys', 'field_fact_metric_keys'] as $field) {
                foreach ((array)($ref[$field] ?? []) as $metricKey) {
                    $metricKey = strtolower(trim((string)$metricKey));
                    if ($metricKey !== '') {
                        $keys[$metricKey] = true;
                    }
                }
            }
            foreach ($aliases as $alias) {
                if (isset($keys[$alias])) {
                    $matches[$index] = $ref;
                    break;
                }
            }
        }
        return $matches;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,true> $coverage
     */
    private function itemHasVerifiedMetricCoverage(array $item, array $coverage, bool $derived): bool
    {
        if ($coverage === [] || !$this->itemUsesVerifiedOtaScope($item)) {
            return false;
        }
        $key = strtolower(trim((string)($item['key'] ?? $item['metric_key'] ?? '')));
        if ($key === '') {
            return false;
        }

        $hasAny = static function (array $aliases) use ($coverage): bool {
            foreach ($aliases as $alias) {
                if (isset($coverage[$alias])) {
                    return true;
                }
            }
            return false;
        };
        $directAliases = $this->metricAliases($key);
        if ($hasAny($directAliases)) {
            return true;
        }
        if (!$derived) {
            return false;
        }

        $requirements = $this->derivedMetricRequirements($key);
        if ($requirements === []) {
            return false;
        }
        foreach ($requirements as $aliases) {
            if (!$hasAny($aliases)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int,string> */
    private function metricAliases(string $key): array
    {
        return match ($key) {
            'revenue' => ['revenue', 'amount', 'order_amount'],
            'orders' => ['orders', 'book_order_num', 'order_count'],
            'room_nights' => ['room_nights', 'quantity'],
            'exposure' => ['exposure', 'list_exposure'],
            'visitors', 'views' => ['visitors', 'views', 'detail_exposure'],
            'order_filling' => ['order_filling', 'order_filling_num'],
            'order_submit' => ['order_submit', 'order_submit_num'],
            default => [$key],
        };
    }

    /** @return array<int,array<int,string>> */
    private function derivedMetricRequirements(string $key): array
    {
        return match ($key) {
            'adr' => [
                ['revenue', 'amount', 'order_amount'],
                ['room_nights', 'quantity'],
            ],
            'flow_rate' => [
                ['exposure', 'list_exposure'],
                ['visitors', 'views', 'detail_exposure'],
            ],
            'fill_submit_rate' => [
                ['order_filling', 'order_filling_num'],
                ['order_submit', 'order_submit_num'],
            ],
            'conversion_rate' => [
                ['orders', 'book_order_num', 'order_count'],
                ['visitors', 'views', 'detail_exposure'],
            ],
            default => [],
        };
    }

    /** @param array<string,mixed> $ref */
    private function sourceRefIsVerified(array $ref, int $hotelId, string $reportDate): bool
    {
        $persistence = is_array($ref['persistence'] ?? null) ? $ref['persistence'] : [];
        $verified = ($ref['readback_verified'] ?? false) === true
            || ($persistence['readback_verified'] ?? false) === true;
        if (!$verified) {
            return false;
        }

        $platform = strtolower(trim((string)($ref['platform'] ?? '')));
        $dataSourceId = (int)($ref['data_source_id'] ?? 0);
        $refHotelId = (int)($ref['system_hotel_id'] ?? $ref['hotel_id'] ?? 0);
        if (!in_array($platform, ['ctrip', 'meituan'], true)
            || $dataSourceId <= 0
            || $refHotelId !== $hotelId
        ) {
            return false;
        }
        $refDate = trim((string)($ref['data_date'] ?? $ref['date'] ?? ''));
        if ($refDate !== $reportDate) {
            return false;
        }
        $quality = strtolower(trim((string)($ref['quality_status'] ?? $ref['validation_status'] ?? '')));
        return in_array($quality, [
            'ok',
            'normal',
            'partial',
            'verified',
            'trusted',
            'available',
            'ready',
            'readback_verified',
        ], true);
    }

    /** @param array<string,mixed> $ref */
    private function sourceRefIdentity(array $ref, bool $anonymize): string
    {
        $identity = trim((string)(
            $ref['ref']
            ?? $ref['key']
            ?? $ref['source_ref']
            ?? $ref['source']
            ?? ''
        ));
        if ($identity === '') {
            $platform = strtolower(trim((string)($ref['platform'] ?? 'source')));
            $rowId = trim((string)($ref['row_id'] ?? $ref['id'] ?? ''));
            $identity = $platform . ($rowId !== '' ? '#' . $rowId : '');
        }
        return $anonymize
            ? 'source#' . substr(hash('sha256', $identity), 0, 12)
            : mb_substr($identity, 0, 240);
    }

    private function sanitizeTrainingSpec(mixed $value, string $reportDate): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeTrainingSpec($item, $reportDate);
            }
            return $sanitized;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $parts = array_map('intval', explode('-', $reportDate));
        $variants = [$reportDate, str_replace('-', '/', $reportDate)];
        if (count($parts) === 3) {
            $variants[] = sprintf('%04d年%02d月%02d日', $parts[0], $parts[1], $parts[2]);
            $variants[] = sprintf('%04d年%d月%d日', $parts[0], $parts[1], $parts[2]);
        }
        return str_replace(array_values(array_unique($variants)), '[业务日期已移除]', $value);
    }

    private function isDuplicateKeyConflict(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate entry')
                || str_contains($message, 'error 1062')
                || str_contains($message, 'errno: 1062')
                || str_contains($message, 'uk_ai_report_presentation_spec_identity')
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $item */
    private function itemLabel(array $item, string $fallback): string
    {
        $label = trim((string)($item['label'] ?? $item['name'] ?? $item['key'] ?? ''));
        return mb_substr($label !== '' ? $label : $fallback, 0, 120);
    }

    /** @param array<string,mixed> $item */
    private function controlledSignalLabel(array $item, int $index): string
    {
        $labels = [
            'traffic_down' => 'OTA曝光变化信号',
            'order_conversion_low' => '订单/访客变化信号',
            'abnormal_flag' => '待补证异常信号',
        ];
        foreach (['type', 'code'] as $field) {
            $code = strtolower(trim((string)($item[$field] ?? '')));
            if (isset($labels[$code])) {
                return $labels[$code];
            }
        }
        return '异常信号 ' . max(1, $index);
    }

    /** @param array<string,mixed> $item @param array<int,string> $fields */
    private function rawReviewFingerprint(array $item, array $fields): ?string
    {
        $material = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $item)) {
                continue;
            }
            $value = $item[$field];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            } elseif ($value === null || (!is_scalar($value) && !is_array($value))) {
                continue;
            }
            $material[$field] = $value;
        }
        return $material === [] ? null : hash('sha256', $this->canonicalJson($material));
    }

    /** @param array<string,mixed> $item */
    private function displayValue(array $item): int|float|string|null
    {
        if (!array_key_exists('value', $item) || $item['value'] === null) {
            return null;
        }
        $value = $item['value'];
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : mb_substr($value, 0, 160);
        }
        return null;
    }

    private function metricStatement(string $label, int|float|string $value, string $unit): string
    {
        return $label . '：' . (string)$value . trim($unit);
    }

    /** @param array<int,array<string,mixed>> $evidence */
    private function containsEvidenceClass(array $evidence, string $class): bool
    {
        foreach ($evidence as $item) {
            if (($item['class'] ?? '') === $class) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $report */
    public function resolveTenantScope(array $report): int
    {
        return $this->resolveTenantId($report, (int)($report['hotel_id'] ?? 0));
    }

    /** @param array<string,mixed> $report */
    private function resolveTenantId(array $report, int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('saved AI daily report hotel scope is invalid');
        }
        $tenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('saved AI daily report tenant scope is unavailable');
        }
        $reportTenantId = (int)($report['tenant_id'] ?? 0);
        if ($reportTenantId > 0 && $reportTenantId !== $tenantId) {
            throw new RuntimeException('saved AI daily report tenant scope mismatch');
        }
        return $tenantId;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeStoredRow(
        array $row,
        ?string $expectedJson,
        bool $created,
        array $expectedIdentity
    ): array
    {
        $raw = $row['spec_json'] ?? null;
        if (is_array($raw)) {
            $spec = $raw;
        } else {
            $spec = json_decode((string)$raw, true);
        }
        if (!is_array($spec)) {
            throw new RuntimeException('AI daily report presentation spec stored JSON is invalid');
        }

        $validation = $this->validate($spec);
        $storedFingerprint = trim((string)($row['spec_fingerprint'] ?? ''));
        $embeddedFingerprint = trim((string)($spec['spec_fingerprint'] ?? ''));
        $specWithoutFingerprint = $spec;
        unset($specWithoutFingerprint['spec_fingerprint']);
        $calculatedFingerprint = hash('sha256', $this->canonicalJson($specWithoutFingerprint));
        $actualJson = $this->canonicalJson($spec);
        $expectedTenantId = (int)($expectedIdentity['tenant_id'] ?? 0);
        $expectedHotelIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($expectedIdentity['hotel_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        $expectedReportId = (int)($expectedIdentity['report_id'] ?? 0);
        $expectedAudience = trim((string)($expectedIdentity['audience'] ?? ''));
        if ($expectedTenantId <= 0
            || $expectedHotelIds === []
            || $expectedReportId <= 0
            || !in_array($expectedAudience, self::AUDIENCES, true)
        ) {
            throw new RuntimeException('AI daily report presentation spec expected identity is incomplete');
        }
        $rowIdentityVerified = (int)($row['tenant_id'] ?? 0) === $expectedTenantId
            && in_array((int)($row['hotel_id'] ?? 0), $expectedHotelIds, true)
            && (int)($row['report_id'] ?? 0) === $expectedReportId
            && (string)($row['audience'] ?? '') === $expectedAudience;
        $sourceIdentityVerified = $expectedAudience === 'training'
            ? (($spec['source_report']['tenant_id'] ?? null) === null
                && ($spec['source_report']['hotel_id'] ?? null) === null
                && ($spec['source_report']['report_id'] ?? null) === null)
            : ((int)($spec['source_report']['tenant_id'] ?? 0) === $expectedTenantId
                && in_array((int)($spec['source_report']['hotel_id'] ?? 0), $expectedHotelIds, true)
                && (int)($spec['source_report']['report_id'] ?? 0) === $expectedReportId);
        $readbackVerified = $validation['status'] === 'pass'
            && $storedFingerprint !== ''
            && hash_equals($storedFingerprint, $embeddedFingerprint)
            && hash_equals($storedFingerprint, $calculatedFingerprint)
            && $rowIdentityVerified
            && $sourceIdentityVerified
            && ($expectedJson === null || hash_equals(hash('sha256', $expectedJson), hash('sha256', $actualJson)));
        if (!$readbackVerified) {
            throw new RuntimeException('AI daily report presentation spec exact readback verification failed');
        }

        return [
            'record_id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'report_id' => (int)($row['report_id'] ?? 0),
            'audience' => (string)($row['audience'] ?? ''),
            'storage_status' => $created ? 'saved' : 'already_saved',
            'readback_verified' => true,
            'spec_fingerprint' => $storedFingerprint,
            'data_status' => (string)($row['data_status'] ?? 'unverified'),
            'render_status' => (string)($row['render_status'] ?? 'not_rendered'),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'spec' => $spec,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function arrayRows(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function isDateTime(string $value): bool
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $dateTime instanceof \DateTimeImmutable
            && $dateTime->format('Y-m-d H:i:s') === $value;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
