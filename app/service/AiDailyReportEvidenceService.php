<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

/** Pure consumer of the canonical OTA field closure; never queries or repairs facts. */
final class AiDailyReportEvidenceService
{
    public const VERSION = 'ai_evidence_reasoning.v1';
    private const METRICS = [
        'revenue' => ['收入', ['CNY']], 'order_count' => ['订单', ['orders']],
        'room_nights' => ['间夜', ['room_nights']], 'adr' => ['ADR', ['CNY']],
        'exposure' => ['曝光', ['people', 'impressions']], 'visits' => ['详情访客', ['people']],
        // This is exposure-to-visit conversion, never occupancy or booking conversion.
        'conversion' => ['曝光到访问转化率', ['percent']], 'cancellation' => ['取消率', ['percent']],
        'sellable' => ['在售房量', ['rooms']], 'bookable' => ['可订房量', ['rooms']],
    ];

    public function scope(int $tenantId, int $hotelId, string $date, array $platforms = ['ctrip', 'meituan']): array
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $platforms = array_values(array_unique($platforms));
        sort($platforms);
        if ($tenantId <= 0 || $hotelId <= 0 || !$parsed || $parsed->format('Y-m-d') !== $date
            || $platforms === [] || array_diff($platforms, ['ctrip', 'meituan']) !== []) {
            throw new InvalidArgumentException('diagnosis_request_scope_invalid', 422);
        }
        return ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'business_date' => $date,
            'platforms' => $platforms, 'metric_scope' => 'ota_channel'];
    }

    public function factPack(array $closure, array $scope): array
    {
        $this->assertScope($closure, $scope);
        if (!in_array($closure['contract_version'] ?? '', ['ai_daily_report_broadcast_strict_facts.v2', 'dual_ota_field_closure.v1'], true)) {
            throw new RuntimeException('diagnosis_fact_contract_unsupported', 422);
        }
        $facts = $gaps = $referencePlatforms = [];
        foreach ($scope['platforms'] as $platform) {
            $source = $closure['platforms'][$platform] ?? [];
            $this->assertOptionalIdentity($source, $scope, $platform);
            $allowedRefs = (array)($source['accepted_record_refs'] ?? $source['current_receipt_all_record_refs'] ?? []);
            foreach (self::METRICS as $key => [$label, $units]) {
                $field = $source['fields'][$key] ?? null;
                if (!is_array($field)) {
                    foreach ((array)($source['fields'] ?? []) as $candidate) {
                        if (is_array($candidate) && ($candidate['key'] ?? $candidate['metric_key'] ?? '') === $key) {
                            $field = $candidate;
                            break;
                        }
                    }
                }
                $field = is_array($field) ? $field : [];
                $this->assertOptionalIdentity($field, $scope, $platform);
                $status = (string)($field['status'] ?? $source['status'] ?? 'missing');
                $value = $field['value'] ?? null;
                $rawRefs = (array)($field['source_record_refs'] ?? []);
                $refs = array_values(array_unique(array_filter($rawRefs, 'is_string')));
                sort($refs);
                $unit = (string)($field['unit'] ?? '');
                $ready = ($field['revenue_analysis_consumable'] ?? false) === true;
                $semanticKey = (string)($field['semantic_metric_key'] ?? $field['semantic_key'] ?? $field['metric_key'] ?? $key);
                if ($ready) $this->assertFieldMetricIdentity($field, $key, $semanticKey, $unit, $platform);
                if ($ready && ($refs === [] || count(array_filter($rawRefs, 'is_string')) !== count($rawRefs)
                    || count(array_filter($refs, static fn(string $ref): bool => preg_match('/^online_daily_data#[1-9][0-9]*$/', $ref) === 1)) !== count($refs)
                    || array_diff($refs, $allowedRefs) !== [])) {
                    throw new RuntimeException('diagnosis_fact_reference_invalid:' . $platform . '.' . $key, 422);
                }
                foreach ($ready ? $refs : [] as $ref) {
                    if (isset($referencePlatforms[$ref]) && $referencePlatforms[$ref] !== $platform) {
                        throw new RuntimeException('diagnosis_reference_platform_mismatch', 422);
                    }
                    $referencePlatforms[$ref] = $platform;
                }
                $validNumber = (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0;
                $validUnit = in_array($unit, $units, true);
                if ($unit === 'percent' && $validNumber && $value > 100) $validNumber = false;
                if (in_array($unit, ['orders', 'room_nights', 'rooms', 'people', 'impressions'], true)
                    && $validNumber && floor((float)$value) !== (float)$value) $validNumber = false;
                if ($ready && !in_array($status, ['strict_readback', 'verified_calculation', 'verified', 'available'], true)) $ready = false;
                if (!$ready || !$validNumber || !$validUnit) {
                    $gaps[] = ['platform' => $platform, 'metric_key' => $key,
                        'status' => !$validUnit && $ready ? 'unit_unverified' : (!$validNumber && $ready ? 'invalid_value' : $status),
                        'value' => null, 'next_action' => '补齐同酒店、同平台、同业务日的' . $label . '及单位和精确回读证据。'];
                    continue;
                }
                $facts[] = ['fact_id' => $platform . '.' . $key, 'metric_key' => $key,
                    'semantic_key' => $semanticKey, 'label' => $label,
                    'value' => $value, 'unit' => $unit, 'platform' => $platform,
                    'tenant_id' => $scope['tenant_id'], 'hotel_id' => $scope['hotel_id'],
                    'business_date' => $scope['business_date'], 'metric_scope' => 'ota_channel',
                    'source_refs' => $refs, 'quality_status' => 'verified',
                    'source_method' => (string)($field['source_method'] ?? $field['ingestion_method'] ?? 'canonical_field_closure'),
                    'collected_at' => $field['collected_at'] ?? null];
            }
        }
        $pack = ['contract_version' => self::VERSION, 'scope' => $scope,
            'dataset_kind' => ($closure['dataset_kind'] ?? '') === 'synthetic' ? 'synthetic' : 'source_readback', 'facts' => $facts,
            'gaps' => $gaps, 'status' => $facts === [] ? 'blocked' : ($gaps === [] ? 'ready' : 'partial')];
        $pack['fingerprint'] = self::digest($pack);
        return $pack;
    }

    public function unavailablePack(array $scope, string $reason): array
    {
        $pack = ['contract_version' => self::VERSION, 'scope' => $scope, 'facts' => [],
            'gaps' => [['status' => $reason, 'next_action' => '恢复可信事实读取后重新生成。']], 'status' => 'blocked'];
        $pack['fingerprint'] = self::digest($pack);
        return $pack;
    }

    public function diagnose(array $pack, ?array $previous = null): array
    {
        $this->assertPack($pack);
        $scope = $pack['scope'];
        $map = array_column($pack['facts'], null, 'fact_id');
        $previousMap = [];
        if ($previous !== null) {
            $this->assertPack($previous);
            $priorScope = $previous['scope'];
            $comparable = $priorScope['tenant_id'] === $scope['tenant_id'] && $priorScope['hotel_id'] === $scope['hotel_id']
                && $priorScope['platforms'] === $scope['platforms'] && $priorScope['business_date'] ===
                    (new \DateTimeImmutable($scope['business_date']))->modify('-1 day')->format('Y-m-d');
            if ($comparable) $previousMap = array_column($previous['facts'], null, 'fact_id');
        }
        $observations = $hypotheses = $recommendations = [];
        foreach ($scope['platforms'] as $platform) {
            $directions = [];
            foreach (self::METRICS as $key => $_) {
                $id = $platform . '.' . $key;
                $current = $map[$id] ?? null;
                $prior = $previousMap[$id] ?? null;
                if (!$current || !$prior || $current['unit'] !== $prior['unit'] || $current['semantic_key'] !== $prior['semantic_key']) continue;
                $direction = $current['value'] <=> $prior['value'];
                $directions[$key] = $direction;
                $observations[] = ['metric_key' => $key, 'platform' => $platform, 'direction' => ['下降', '持平', '上升'][$direction + 1],
                    'current' => $current, 'comparison' => $prior, 'comparison_kind' => 'previous_day_observation',
                    'causality' => 'not_established'];
            }
            $down = ($directions['exposure'] ?? null) === -1;
            $conflicts = array_values(array_filter($pack['gaps'], static fn(array $gap): bool =>
                ($gap['platform'] ?? '') === $platform && str_contains((string)$gap['status'], 'conflict')));
            $available = array_values(array_filter($pack['facts'], static fn(array $fact): bool => $fact['platform'] === $platform));
            $problem = $conflicts !== [] ? '同范围事实相互矛盾，暂停相关经营归因。' : ($down
                ? '曝光较已保存的前一业务日下降；经营影响与原因仍待验证。'
                : ($directions === [] ? '缺少可比历史，当前事实不能证明趋势异常。' : '已比较同范围前一业务日；变化本身不能证明原因。'));
            $candidates = [
                'visibility' => ['渠道展示机会可能变化', $down ? [$platform . '.exposure'] : [], [],
                    ['同范围渠道排名、展示位置与投放变化', '平台总需求变化'], '核对排名、展示位置和投放记录，区分本店可见度与市场需求。'],
                'demand' => ['渠道需求可能变化', $down ? [$platform . '.exposure'] : [], [],
                    ['同地区同日期的需求证据', '可比房型和可订条件'], '收集同地区同日期需求与可比样本；知识案例不能代替现场需求。'],
                'funnel' => ['曝光到访问环节可能变化', ($directions['conversion'] ?? null) === -1 ? [$platform . '.conversion'] : [],
                    isset($directions['conversion']) && $directions['conversion'] >= 0 ? [$platform . '.conversion'] : [],
                    ['订单转化与取消的同口径证据', '入住及可售房量证据'], '核对访问、下单与入住各环节；曝光到访问率不等于订单转化或入住率。'],
                'price' => ['价格因素尚不能判断', [], [],
                    ['同房型同售卖条件可比价格', '订单转化、入住和价格变动时间线'], '先取得可比价格及下游转化证据，再决定是否提出价格实验。'],
            ];
            if (isset($directions['order_count']) && $directions['order_count'] >= 0) {
                $candidates['funnel'][2][] = $platform . '.order_count';
            }
            foreach ($candidates as $code => [$title, $support, $counter, $unknown, $next]) {
                $strength = $conflicts !== [] ? 'blocked' : ($support !== [] ? 'plausible' : 'insufficient');
                $hypotheses[] = ['hypothesis_id' => $platform . '.' . $code, 'platform' => $platform,
                    'title' => $title, 'evidence_strength' => $strength, 'causality' => 'not_established',
                    'supporting_fact_ids' => $support, 'counter_fact_ids' => $counter, 'unknowns' => $unknown,
                    'next_evidence' => $next, 'testable_observation' => '在复盘窗口回读相同指标和来源；缺数据则记为无法判断，不将改善认定为因果。',
                    'actionability' => $code === 'price' ? 'requires_more_evidence' : 'evidence_collection',
                    'rank_score' => ($support !== [] ? 20 : 0) + ($code === 'price' ? 0 : 10) - count($counter) * 5];
            }
            $actionScope = ['tenant_id' => $scope['tenant_id'], 'hotel_id' => $scope['hotel_id'], 'platform' => $platform,
                'date_start' => $scope['business_date'], 'date_end' => $scope['business_date'], 'object_ref' => 'ota_channel:' . $platform];
            $comparisons = array_values(array_filter($observations, static fn(array $item): bool => $item['platform'] === $platform));
            $evidenceSnapshot = ['scope' => $actionScope, 'facts' => $available, 'comparisons' => $comparisons,
                'source_refs' => array_values(array_unique(array_merge([], ...array_column($available, 'source_refs')))),
                'fact_pack_fingerprint' => $pack['fingerprint']];
            $evidenceSnapshot['fingerprint'] = self::digest($evidenceSnapshot);
            $suggestionId = substr(self::digest([$evidenceSnapshot['fingerprint'], $platform]), 0, 24);
            $recommendations[] = ['recommendation_id' => $suggestionId, 'suggestion_ref' => $suggestionId,
                'issue_ref' => 'diagnosis:' . $platform . ':evidence_review', 'source_digest' => $evidenceSnapshot['fingerprint'],
                'scope' => $actionScope, 'workflow_type' => 'conversion_optimization',
                'problem' => $problem, 'platform' => $platform, 'status' => 'proposed',
                'action_type' => $conflicts !== [] ? 'resolve_evidence_conflict' : 'collect_and_review_evidence',
                'evidence_snapshot' => $evidenceSnapshot,
                'completion_criteria' => ['补证记录保留租户、酒店、平台、业务日、指标单位与来源引用。',
                    '保存并精确回读；逐项记录支持、反证或仍未知，不以任务完成替代效果。'],
                'review_window' => ['baseline_start' => $scope['business_date'], 'baseline_end' => $scope['business_date'],
                    'followup_start' => (new \DateTimeImmutable($scope['business_date']))->modify('+1 day')->format('Y-m-d'),
                    'followup_end' => (new \DateTimeImmutable($scope['business_date']))->modify('+7 days')->format('Y-m-d'),
                    'start_date' => (new \DateTimeImmutable($scope['business_date']))->modify('+1 day')->format('Y-m-d'),
                    'end_date' => (new \DateTimeImmutable($scope['business_date']))->modify('+7 days')->format('Y-m-d'),
                    'timezone' => 'Asia/Shanghai', 'basis' => 'suggested_observation_window'],
                'verification_metric_keys' => ['exposure', 'visits', 'conversion', 'order_count'],
                'requires_human_confirmation' => true, 'auto_execute' => false,
                'handoff_status' => $available === [] ? 'blocked_by_missing_facts' : 'ready_for_task_proposal'];
        }
        usort($hypotheses, static fn(array $a, array $b): int => ($b['rank_score'] <=> $a['rank_score']) ?: strcmp($a['hypothesis_id'], $b['hypothesis_id']));
        return ['status' => $pack['status'], 'observations' => $observations, 'hypotheses' => $hypotheses,
            'recommendations' => $recommendations, 'comparison_status' => $observations === [] ? 'missing_or_incomparable' : 'saved_previous_day',
            'boundary' => '仅限所选 OTA 渠道；相关变化不能证明价格过高、经营差或全酒店因果。'];
    }

    /** Accept only exact metric claims and known hypothesis IDs, never model-created numbers/prose. */
    public function validateModel(array $output, array $pack, array $diagnosis): array
    {
        if (!is_array($output['scope'] ?? null) || self::digest($output['scope']) !== self::digest($pack['scope'])
            || ($output['facts_fingerprint'] ?? '') !== $pack['fingerprint']) {
            throw new RuntimeException('diagnosis_model_scope_or_fingerprint_mismatch', 422);
        }
        $facts = array_column($pack['facts'], null, 'fact_id');
        foreach ((array)($output['claims'] ?? []) as $claim) {
            $fact = is_array($claim) ? ($facts[$claim['fact_id'] ?? ''] ?? null) : null;
            if (!$fact || !is_numeric($claim['value'] ?? null) || (float)$claim['value'] !== (float)$fact['value']
                || ($claim['metric_key'] ?? '') !== $fact['metric_key'] || ($claim['unit'] ?? '') !== $fact['unit']
                || ($claim['source_refs'] ?? []) !== $fact['source_refs']) {
                throw new RuntimeException('diagnosis_model_claim_mismatch', 422);
            }
        }
        $ids = $output['hypothesis_ids'] ?? [];
        if (!is_array($ids) || $ids === [] || count(array_filter($ids, 'is_string')) !== count($ids) || count($ids) !== count(array_unique($ids))
            || array_diff($ids, array_column($diagnosis['hypotheses'], 'hypothesis_id')) !== []) {
            throw new RuntimeException('diagnosis_model_hypothesis_reference_invalid', 422);
        }
        return ['status' => 'available', 'selected_hypothesis_ids' => array_values($ids),
            'note' => '模型仅选择已绑定证据的候选解释；事实、排序和结论边界由确定性校验保持。'];
    }

    public function seal(array $pack, array $diagnosis, array $model, array $knowledge = []): array
    {
        $this->assertPack($pack);
        $snapshot = ['contract_version' => self::VERSION, 'scope' => $pack['scope'], 'fact_pack' => $pack,
            'diagnosis' => $diagnosis, 'model' => $model, 'text_line_endings' => 'CRLF',
            'knowledge_references' => $this->knowledgeReferences($knowledge, $pack['scope']),
            'knowledge_gaps' => $this->knowledgeGaps($knowledge)];
        $snapshot['final_text'] = $this->render($snapshot);
        $snapshot['final_text_sha256'] = hash('sha256', $snapshot['final_text']);
        $snapshot['snapshot_fingerprint'] = self::digest($snapshot);
        return $snapshot;
    }

    public function verify(array $snapshot, array $scope): void
    {
        if (($snapshot['contract_version'] ?? '') !== self::VERSION || ($snapshot['scope'] ?? null) !== $scope) {
            throw new RuntimeException('diagnosis_snapshot_scope_mismatch', 422);
        }
        $this->assertPack($snapshot['fact_pack'] ?? []);
        if ($snapshot['fact_pack']['scope'] !== $scope) throw new RuntimeException('diagnosis_fact_scope_mismatch', 422);
        $fingerprint = $snapshot['snapshot_fingerprint'] ?? '';
        $unsigned = $snapshot;
        unset($unsigned['snapshot_fingerprint']);
        if (!is_string($fingerprint) || !hash_equals(self::digest($unsigned), $fingerprint)
            || ($snapshot['final_text_sha256'] ?? '') !== hash('sha256', (string)($snapshot['final_text'] ?? ''))
            || ($snapshot['final_text'] ?? '') !== $this->render($snapshot)) {
            throw new RuntimeException('diagnosis_snapshot_integrity_failed', 422);
        }
    }

    private function render(array $snapshot): string
    {
        $pack = $snapshot['fact_pack'];
        $diagnosis = $snapshot['diagnosis'];
        $stateLabels = ['ready' => '事实完整', 'partial' => '部分事实', 'blocked' => '事实阻塞',
            'not_requested' => '未启用模型', 'ok' => '模型结果已校验', 'timeout' => '模型超时',
            'not_configured' => '模型未配置', 'failed' => '模型失败', 'invalid_output' => '模型输出未通过校验',
            'blocked_by_data_quality' => '缺少可信事实，模型已阻塞'];
        $strengthLabels = ['plausible' => '待验证解释', 'insufficient' => '证据不足', 'blocked' => '冲突阻塞'];
        $factMap = array_column($pack['facts'], null, 'fact_id');
        $cite = static function (string $id) use ($factMap): string {
            $fact = $factMap[$id] ?? null;
            return $fact ? $fact['label'] . ' ' . $fact['value'] . ' ' . $fact['unit'] . ' [' . implode(',', $fact['source_refs']) . ']' : $id;
        };
        $lines = ['OTA 证据诊断' . (($pack['dataset_kind'] ?? '') === 'synthetic' ? '（SYNTHETIC 本地样本）' : ''), '酒店：' . $snapshot['scope']['hotel_id'] . '；业务日：' . $snapshot['scope']['business_date'],
            '事实状态：' . ($stateLabels[$pack['status']] ?? $pack['status']) . '；模型状态：' . ($stateLabels[$snapshot['model']['status'] ?? 'not_requested'] ?? $snapshot['model']['status']),
            $diagnosis['comparison_status'] === 'saved_previous_day' ? '比较依据：同范围已保存的前一业务日，仅作变化观察。' : '缺少可比历史，不能认定趋势异常。'];
        $factLines = ['已验证事实明细：'];
        foreach ($pack['facts'] as $fact) {
            $factLines[] = $fact['platform'] . ' ' . $fact['label'] . '：' . $fact['value'] . ' ' . $fact['unit']
                . ' [' . implode(', ', $fact['source_refs']) . ']';
        }
        if ($pack['facts'] === []) $lines[] = '缺少可信事实，无法形成经营结论。';
        foreach ($pack['gaps'] as $gap) $factLines[] = '未知：' . ($gap['platform'] ?? '') . ' ' . ($gap['metric_key'] ?? '') . ' [' . $gap['status'] . ']';
        foreach ($diagnosis['observations'] as $item) $lines[] = $item['platform'] . ' ' . $item['current']['label']
            . '较已保存前一日' . $item['direction'] . '（' . $item['comparison']['value'] . ' → ' . $item['current']['value'] . ' ' . $item['current']['unit'] . '）。';
        foreach ($diagnosis['hypotheses'] as $item) {
            $lines[] = '候选解释：' . $item['platform'] . ' ' . $item['title'] . ' [' . ($strengthLabels[$item['evidence_strength']] ?? $item['evidence_strength']) . ']';
            $lines[] = '支持：' . (implode('、', array_map($cite, $item['supporting_fact_ids'])) ?: '暂无') . '；反证：' . (implode('、', array_map($cite, $item['counter_fact_ids'])) ?: '暂无，不能视为已排除其他解释');
            $lines[] = '未知：' . implode('；', $item['unknowns']);
            $lines[] = '补证：' . $item['next_evidence'];
            $lines[] = '观察：' . $item['testable_observation'];
        }
        foreach ($diagnosis['recommendations'] as $item) {
            $lines[] = '建议任务：' . $item['platform'] . ' ' . $item['problem'];
            $lines[] = '完成条件：' . implode('；', $item['completion_criteria']);
            $lines[] = '建议复盘窗口：' . $item['review_window']['start_date'] . ' 至 ' . $item['review_window']['end_date'] . ' Asia/Shanghai；待人工确认。';
        }
        $lines = array_merge($lines, $factLines);
        foreach ($snapshot['knowledge_references'] as $reference) $lines[] = '知识参考：' . $reference['ref'] . '，版本 ' . $reference['version'] . '，reference_only，不是酒店事实。';
        foreach ($snapshot['knowledge_gaps'] ?? [] as $gap) $lines[] = '知识补证：' . $gap . '；先核验来源、版本和适用条件。';
        $lines[] = $diagnosis['boundary'];
        // Native Windows clipboard canonicalizes text to CRLF. Persist that same
        // representation so DOM, clipboard, hash and JSON export compare byte-for-byte.
        return implode("\r\n", $lines);
    }

    private function knowledgeReferences(array $knowledge, array $scope): array
    {
        if ($knowledge !== [] && (int)($knowledge['hotel_id'] ?? 0) !== $scope['hotel_id']) return [];
        $references = [];
        foreach ((array)($knowledge['entries'] ?? []) as $entry) {
            if (!is_array($entry) || !in_array((int)($entry['unit_hotel_id'] ?? -1), [0, $scope['hotel_id']], true)) continue;
            if (!empty($entry['platforms']) && array_intersect($entry['platforms'], $scope['platforms']) === []) continue;
            $id = (int)($entry['chunk_id'] ?? 0);
            $applicability = is_array($entry['applicability'] ?? null) ? $entry['applicability'] : [];
            $version = trim((string)($applicability['version'] ?? $entry['truth_profile_version'] ?? ''));
            if (($applicability['reference_safe'] ?? true) !== true) continue;
            if ($id > 0 && $version !== '') $references[] = ['ref' => 'knowledge_chunks#' . $id,
                'version' => $version, 'usage' => 'reference_only', 'hotel_fact' => false, 'fact_safe' => false,
                'content_digest' => (string)($applicability['content_digest'] ?? ''),
                'applicability' => $applicability, 'external_write_authorized' => false];
        }
        return $references;
    }

    private function knowledgeGaps(array $knowledge): array
    {
        $gaps = [];
        foreach (array_merge((array)($knowledge['applicability_exclusions'] ?? []), (array)($knowledge['conflicts'] ?? [])) as $item) {
            if (is_array($item)) $gaps = array_merge($gaps, (array)($item['reason_codes'] ?? [$item['code'] ?? 'knowledge_applicability_unverified']));
        }
        foreach ((array)($knowledge['entries'] ?? []) as $entry) {
            $applicability = is_array($entry) ? ($entry['applicability'] ?? []) : [];
            if (($applicability['reference_safe'] ?? true) !== true) $gaps = array_merge($gaps, (array)($applicability['reason_codes'] ?? ['knowledge_not_applicable']));
        }
        return array_values(array_unique(array_filter($gaps, static fn($value): bool => is_string($value) && preg_match('/^[a-z_][a-z0-9_:.\-]{0,100}$/', $value) === 1)));
    }

    private function assertPack(array $pack): void
    {
        $fingerprint = $pack['fingerprint'] ?? '';
        unset($pack['fingerprint']);
        if (($pack['contract_version'] ?? '') !== self::VERSION || !is_string($fingerprint)
            || !hash_equals(self::digest($pack), $fingerprint)) throw new RuntimeException('diagnosis_fact_pack_integrity_failed', 422);
    }

    private function assertScope(array $returned, array $scope): void
    {
        foreach (['tenant_id', 'hotel_id', 'business_date'] as $key) {
            if (($returned[$key] ?? null) !== $scope[$key]) throw new RuntimeException('diagnosis_fact_scope_mismatch:' . $key, 422);
        }
    }

    private function assertOptionalIdentity(array $item, array $scope, string $platform): void
    {
        foreach (['tenant_id', 'hotel_id', 'business_date'] as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== $scope[$key]) throw new RuntimeException('diagnosis_field_scope_mismatch:' . $key, 422);
        }
        if (isset($item['system_hotel_id']) && (int)$item['system_hotel_id'] !== $scope['hotel_id']) throw new RuntimeException('diagnosis_field_scope_mismatch:hotel_id', 422);
        if (isset($item['data_date']) && $item['data_date'] !== $scope['business_date']) throw new RuntimeException('diagnosis_field_scope_mismatch:business_date', 422);
        if (isset($item['platform']) && $item['platform'] !== $platform) throw new RuntimeException('diagnosis_platform_mismatch', 422);
    }

    private function assertFieldMetricIdentity(array $field, string $key, string $semanticKey, string $unit, string $platform): void
    {
        if (($field['metric_key'] ?? '') !== $key || (isset($field['key']) && $field['key'] !== $key)) {
            throw new RuntimeException('diagnosis_field_metric_identity_mismatch', 422);
        }
        // These are the existing ota_field_semantics.v1 identities, not new metric definitions.
        $semanticUnits = match ($key) {
            'exposure' => ['exposure' => ['people', 'impressions'], $platform . '_exposure_users' => ['people'], 'ota_exposure_volume' => ['impressions']],
            'visits' => ['visits' => ['people'], $platform . '_detail_visitors' => ['people']],
            'conversion' => ['conversion' => ['percent'], 'exposure_to_visit_rate' => ['percent']],
            default => [$key => self::METRICS[$key][1]],
        };
        if (!isset($semanticUnits[$semanticKey])
            || (isset($field['semantic_key']) && $field['semantic_key'] !== $semanticKey)
            || (isset($field['semantic_unit']) && $field['semantic_unit'] !== $unit)
            || ($semanticKey !== $key && !in_array($unit, $semanticUnits[$semanticKey], true))) {
            throw new RuntimeException('diagnosis_field_metric_identity_mismatch', 422);
        }
    }

    public static function digest(array $value): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as &$item) $item = $canonical($item);
            return $value;
        };
        // Integral floats and integers are equivalent across database JSON round trips.
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Mutable execution IDs and human reviews do not change the frozen report projection. */
    public static function projectionDigest(array $report): string
    {
        $projection = ['summary' => (string)($report['summary'] ?? '')];
        foreach (['yesterday_result', 'abnormal_metrics', 'competitor_changes', 'data_gaps', 'source_refs'] as $key) {
            $projection[$key] = $report[$key] ?? json_decode((string)($report[$key . '_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        }
        return self::digest($projection);
    }

    public static function reportMetric(array $fact): array
    {
        return array_merge($fact, ['key' => $fact['fact_id'], 'label' => $fact['platform'] . ' ' . $fact['label'],
            'data_status' => 'available', 'result_layer' => 'source_fact', 'metric_scopes' => ['ota_channel'],
            'source_ref' => implode(',', $fact['source_refs'])]);
    }

    public static function reportSources(array $facts): array
    {
        $refs = [];
        foreach ($facts as $fact) foreach ($fact['source_refs'] as $ref) {
            $refs[$ref] = ['key' => $ref, 'source' => $fact['platform'], 'platform' => $fact['platform'],
                'tenant_id' => $fact['tenant_id'], 'hotel_id' => $fact['hotel_id'], 'system_hotel_id' => $fact['hotel_id'],
                'data_date' => $fact['business_date'], 'scope' => 'ota_channel', 'validation_status' => 'verified',
                'readback_verified' => true, 'metric_keys' => array_values(array_unique(array_merge(
                    $refs[$ref]['metric_keys'] ?? [], [$fact['metric_key'], $fact['fact_id']])) )];
        }
        return array_values($refs);
    }

    public static function reportObservation(array $item): array
    {
        return ['type' => $item['platform'] . '.' . $item['metric_key'], 'label' => $item['current']['label'] . $item['direction'],
            'value' => $item['current']['value'], 'unit' => $item['current']['unit'], 'fact_id' => $item['current']['fact_id'],
            'source_ref' => implode(',', $item['current']['source_refs']), 'causal_claim_allowed' => false,
            'reference_basis' => ['status' => 'available', 'type' => 'saved_previous_day',
                'measured_value' => $item['current']['value'], 'reference_value' => $item['comparison']['value'],
                'business_date' => $item['comparison']['business_date'], 'unit' => $item['comparison']['unit'],
                'source_refs' => $item['comparison']['source_refs']]];
    }

    /** A matching checksum alone is not evidence of correct metric binding. */
    public function assertProjection(array $report, array $snapshot): void
    {
        $decode = static fn(string $key): array => (array)($report[$key]
            ?? json_decode((string)($report[$key . '_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR));
        $pack = $snapshot['fact_pack'];
        $facts = array_column($pack['facts'], null, 'fact_id');
        $result = $decode('yesterday_result');
        $metrics = $result['metrics'] ?? [];
        $ids = array_column($metrics, 'key');
        if (count($metrics) !== count($facts) || count($ids) !== count(array_unique($ids))
            || ($result['source_scope'] ?? '') !== 'ota_channel' || ($result['report_date'] ?? '') !== $pack['scope']['business_date']) {
            throw new RuntimeException('diagnosis_projection_scope_or_coverage_mismatch', 422);
        }
        foreach ($metrics as $metric) {
            $fact = $facts[$metric['key'] ?? ''] ?? null;
            foreach (['metric_key', 'platform', 'tenant_id', 'hotel_id', 'business_date', 'unit', 'source_refs', 'metric_scope'] as $field) {
                if (!$fact || ($metric[$field] ?? null) !== $fact[$field]) throw new RuntimeException('diagnosis_projection_metric_binding_mismatch', 422);
            }
            if (!is_numeric($metric['value'] ?? null) || (float)$metric['value'] !== (float)$fact['value']) {
                throw new RuntimeException('diagnosis_projection_value_mismatch', 422);
            }
            if (self::digest($metric) !== self::digest(self::reportMetric($fact))) {
                throw new RuntimeException('diagnosis_projection_metric_binding_mismatch', 422);
            }
        }
        if (self::digest($decode('source_refs')) !== self::digest(self::reportSources($pack['facts']))) {
            throw new RuntimeException('diagnosis_projection_source_binding_mismatch', 422);
        }
        $observations = $snapshot['diagnosis']['observations'];
        $abnormalities = $decode('abnormal_metrics');
        if (count($abnormalities) !== count($observations)) throw new RuntimeException('diagnosis_projection_comparison_mismatch', 422);
        foreach ($abnormalities as $index => $item) {
            $observation = $observations[$index];
            if (self::digest($item) !== self::digest(self::reportObservation($observation))) {
                throw new RuntimeException('diagnosis_projection_comparison_mismatch', 422);
            }
        }
        $summary = $pack['facts'] === [] ? '缺少可信事实，经营归因已阻塞。' : '已保存渠道事实与待验证解释；变化不证明因果。';
        if (($report['summary'] ?? '') !== $summary || $decode('competitor_changes') !== []) {
            throw new RuntimeException('diagnosis_projection_unbound_claim', 422);
        }
    }
}
