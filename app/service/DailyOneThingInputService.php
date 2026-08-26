<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Adapts only three approved sources into DailyOneThingService candidates:
 * strict dual-OTA facts, saved operating questions, and explicit data gaps.
 */
final class DailyOneThingInputService
{
    public const CONTRACT_VERSION = 'daily_one_thing_input.v1';

    /** @var Closure(int,string):array<string,mixed> */
    private Closure $fieldClosureReader;

    /** @var Closure(int,array<int,int>,int):array<string,mixed> */
    private Closure $questionReader;

    /** @var Closure(array<string,mixed>):(?array<string,mixed>) */
    private Closure $questionActionReader;

    /** @var Closure():DateTimeImmutable */
    private Closure $clock;

    public function __construct(
        ?callable $fieldClosureReader = null,
        ?callable $questionReader = null,
        ?callable $questionActionReader = null,
        ?callable $clock = null
    ) {
        $this->fieldClosureReader = $fieldClosureReader !== null
            ? Closure::fromCallable($fieldClosureReader)
            : static fn(int $hotelId, string $businessDate): array =>
                (new DualOtaFieldClosureService())->build($hotelId, $businessDate);
        $this->questionReader = $questionReader !== null
            ? Closure::fromCallable($questionReader)
            : static fn(int $tenantId, array $hotelIds, int $hotelId): array =>
                (new OperatingQuestionService())->list($tenantId, $hotelIds, $hotelId);
        $this->questionActionReader = $questionActionReader !== null
            ? Closure::fromCallable($questionActionReader)
            : static fn(array $question): ?array =>
                (new OperatingQuestionExecutionBridgeService())
                    ->eligibleActionForDailyOneThing($question, 0);
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    }

    /** @return array<string,mixed> */
    public function build(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        int $ownerId
    ): array {
        $businessDate = $this->date($businessDate);
        if ($tenantId <= 0 || $hotelId <= 0 || $ownerId <= 0) {
            throw new InvalidArgumentException('每日事项输入缺少租户、酒店或负责人身份');
        }

        $now = ($this->clock)()->setTimezone(new DateTimeZone('Asia/Shanghai'));
        [$dueAt, $reviewAt] = $this->schedule($businessDate, $now);
        $candidates = [];
        $sourceErrors = [];
        $strictFactStatus = 'readback_ready';
        try {
            $closure = ($this->fieldClosureReader)($hotelId, $businessDate);
            $this->assertClosureScope($closure, $tenantId, $hotelId, $businessDate);
            $candidates = array_merge(
                $candidates,
                $this->closureCandidates($closure, $tenantId, $hotelId, $businessDate, $ownerId, $dueAt, $reviewAt)
            );
        } catch (\Throwable $error) {
            $closure = [
                'contract_version' => 'dual_ota_field_closure.v1',
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'status' => 'unavailable',
                'platforms' => [],
                'closure_digest' => hash('sha256', implode('|', [
                    'dual_ota_field_closure_unavailable',
                    (string)$tenantId,
                    (string)$hotelId,
                    $businessDate,
                ])),
            ];
            $strictFactStatus = 'source_unavailable';
            $sourceErrors[] = ['code' => 'strict_fact_layer_unavailable'];
        }

        try {
            $questions = ($this->questionReader)($tenantId, [$hotelId], $hotelId);
            foreach ((array)($questions['list'] ?? []) as $question) {
                if (!is_array($question)
                    || (int)($question['tenant_id'] ?? 0) !== $tenantId
                    || (int)($question['hotel_id'] ?? 0) !== $hotelId
                    || (string)($question['date_start'] ?? '') > $businessDate
                    || (string)($question['date_end'] ?? '') < $businessDate
                ) {
                    continue;
                }
                try {
                    $action = ($this->questionActionReader)($question);
                } catch (\Throwable) {
                    continue;
                }
                if (is_array($action)) {
                    $candidate = $this->questionCandidate(
                        $question,
                        $action,
                        $tenantId,
                        $hotelId,
                        $businessDate,
                        $ownerId,
                        $dueAt,
                        $reviewAt
                    );
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
            }
        } catch (\Throwable) {
            $sourceErrors[] = ['code' => 'saved_question_readback_unavailable'];
        }

        $stable = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'strict_fact_status' => $strictFactStatus,
            'candidate_digests' => array_values(array_map(
                static fn(array $candidate): string => DailyOneThingService::materialIdentityDigest($candidate),
                $candidates
            )),
            'source_errors' => $sourceErrors,
        ];
        return $stable + [
            'candidates' => $candidates,
            'source_snapshot' => [
                'dual_ota_field_closure' => $closure,
                'strict_fact_status' => $strictFactStatus,
                'saved_question_count' => count(array_filter(
                    $candidates,
                    static fn(array $candidate): bool => ($candidate['source_type'] ?? '') === 'saved_question'
                )),
            ],
            'source_digest' => hash('sha256', $this->canonicalJson($stable)),
            'boundary' => [
                'allowed_sources' => ['strict_fact_signal', 'saved_question', 'explicit_data_gap'],
                'client_candidates_accepted' => false,
                'automatic_execution' => false,
                'automatic_ota_write' => false,
                'automatic_pms_write' => false,
                'automatic_wecom_message' => false,
                'strict_fact_source_ready' => $strictFactStatus === 'readback_ready',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function closureCandidates(
        array $closure,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        int $ownerId,
        DateTimeImmutable $dueAt,
        DateTimeImmutable $reviewAt
    ): array {
        $platforms = is_array($closure['platforms'] ?? null) ? $closure['platforms'] : [];
        $ctrip = is_array($platforms['ctrip'] ?? null) ? $platforms['ctrip'] : [];
        $ctripFields = $this->fieldMap($ctrip);
        $ctripRefs = $this->refs($ctrip['current_receipt_all_record_refs'] ?? []);
        $ctripCore = ['revenue', 'order_count', 'room_nights', 'exposure', 'visits', 'conversion'];
        $ctripReady = array_values(array_filter(
            $ctripCore,
            fn(string $key): bool => $this->fieldReady($ctripFields[$key] ?? [])
        ));
        $ctripMissing = array_values(array_diff($ctripCore, $ctripReady));
        $candidates = [];
        if ($ctripRefs === []) {
            $candidates[] = $this->gapCandidate(
                'gap:ctrip:target_date_source_rows',
                '携程 ' . $businessDate . ' 目标日期可信源数据尚未回读',
                '补齐携程目标日期可信事实，再决定是否需要任何经营动作。',
                'ctrip_target_date_source_rows_missing',
                [],
                0,
                $closure,
                $tenantId,
                $hotelId,
                $businessDate,
                $ownerId,
                $dueAt,
                $reviewAt
            );
        } elseif ($ctripMissing !== []) {
            $labels = array_map(fn(string $key): string => $this->fieldLabel($ctripFields[$key] ?? [], $key), $ctripMissing);
            $candidates[] = $this->gapCandidate(
                'gap:ctrip:core_facts',
                '携程 ' . $businessDate . ' 核心可信事实未齐：' . implode('、', $labels),
                '补齐并严格回读缺失的携程核心字段；在收入、间夜、曝光和访问口径未齐前不贸然调价。',
                'ctrip_core_facts_missing',
                $ctripRefs,
                count($ctripReady),
                $closure,
                $tenantId,
                $hotelId,
                $businessDate,
                $ownerId,
                $dueAt,
                $reviewAt,
                $ctripMissing
            );
        }

        $meituan = is_array($platforms['meituan'] ?? null) ? $platforms['meituan'] : [];
        $meituanFields = $this->fieldMap($meituan);
        $trafficKeys = ['exposure', 'visits', 'conversion'];
        $trafficReady = count(array_filter(
            $trafficKeys,
            fn(string $key): bool => $this->fieldReady($meituanFields[$key] ?? [])
        )) === count($trafficKeys);
        $revenueReady = $this->fieldReady($meituanFields['revenue'] ?? []);
        $roomNightsReady = $this->fieldReady($meituanFields['room_nights'] ?? []);
        if ($trafficReady && (!$revenueReady || !$roomNightsReady)) {
            $conversion = (float)($meituanFields['conversion']['value'] ?? 0.0);
            $meituanRefs = $this->refs($meituan['current_receipt_record_refs'] ?? []);
            $snapshotDigest = hash('sha256', $this->canonicalJson([
                'contract' => 'daily_one_thing_meituan_traffic_only.v1',
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'platform' => 'meituan',
                'business_date' => $businessDate,
                'metric' => 'conversion',
                'value' => $conversion,
                'fact_refs' => $meituanRefs,
                'missing' => ['revenue', 'room_nights'],
            ]));
            $candidates[] = $this->baseCandidate(
                'gap:meituan:traffic_only_scope',
                'explicit_data_gap',
                '当前只有美团流量漏斗可信，收入或间夜尚不足',
                [[
                    'statement' => '美团曝光、访问和曝光到访问转化已在同酒店同日期严格回读；收入/间夜仍缺失或不可消费。',
                    'evidence_ref' => 'dual_ota_field_closure:' . $hotelId . ':' . $businessDate . ':meituan',
                    'quality_status' => 'strict_readback',
                ]],
                [
                    'type' => 'human_reviewed_traffic_check',
                    'object' => 'meituan_traffic_funnel',
                    'title' => '只核对美团流量漏斗，不扩大为收益判断',
                    'description' => '核对美团曝光到访问路径；收入和间夜未严格回读前，不生成 ADR、利润或全酒店结论。',
                    'steps' => [
                        '打开美团同日期流量页，只读核对曝光、访问和转化。',
                        '把真实执行说明和回执绑定到原任务。',
                        '等待同范围后续事实再复盘，不把前后变化写成因果。',
                    ],
                ],
                [
                    'key' => 'conversion',
                    'label' => '美团曝光到访问转化率',
                    'unit' => 'percent',
                    'baseline_value' => $conversion,
                    'aggregation' => 'latest',
                ],
                'meituan',
                'ota_channel',
                '仅适用于美团、当前酒店、当前营业日的流量漏斗；不代表携程、全 OTA 或全酒店收益。',
                [
                    'level' => 'low',
                    'summary' => '主要风险是把流量事实误写成收入或因果判断。',
                    'controls' => ['只读核对，不调价、不改房态、不改活动。', '缺失收入和间夜保持未知。'],
                    'stop_conditions' => ['发现平台、酒店或日期不一致时立即停止。', '来源回读不再严格时保持阻断。'],
                ],
                ['impact' => 72, 'urgency' => 74, 'evidence_strength' => 94, 'execution_cost' => 24],
                0,
                'dual_ota_field_closure:' . $hotelId . ':' . $businessDate . ':meituan',
                $snapshotDigest,
                $meituanRefs,
                ['meituan_revenue_or_room_nights_missing'],
                $tenantId,
                $hotelId,
                $businessDate,
                $ownerId,
                $dueAt,
                $reviewAt
            );
        }
        return $candidates;
    }

    /** @return array<string,mixed> */
    private function gapCandidate(
        string $candidateKey,
        string $problem,
        string $description,
        string $gapCode,
        array $factRefs,
        int $baselineCount,
        array $closure,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        int $ownerId,
        DateTimeImmutable $dueAt,
        DateTimeImmutable $reviewAt,
        array $missingFields = []
    ): array {
        $digest = hash('sha256', $this->canonicalJson([
            'contract' => 'daily_one_thing_explicit_gap.v1',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'business_date' => $businessDate,
            'candidate_key' => $candidateKey,
            'gap_code' => $gapCode,
            'fact_refs' => array_values($factRefs),
            'baseline_count' => $baselineCount,
            'missing_fields' => array_values($missingFields),
        ]));
        $statement = $missingFields === []
            ? '严格事实层确认当前目标日期尚无可消费的携程核心事实。'
            : '严格事实层已回读部分携程记录，但缺少：' . implode('、', $missingFields) . '。';
        return $this->baseCandidate(
            $candidateKey,
            'explicit_data_gap',
            $problem,
            [[
                'statement' => $statement,
                'evidence_ref' => 'dual_ota_field_closure:' . $hotelId . ':' . $businessDate . ':ctrip',
                'quality_status' => 'gap_readback_verified',
            ]],
            [
                'type' => 'collect_trusted_ota_facts',
                'object' => 'ctrip_target_date_strict_facts',
                'title' => '补齐携程目标日期可信事实',
                'description' => $description,
                'steps' => [
                    '使用当前授权的携程采集入口读取目标日期，不修改价格、房态或活动。',
                    '按同租户、同酒店、同平台、同日期保存，并精确回读来源记录和字段事实。',
                    '回到原任务记录人工执行证据；后续自然事实未到时保持等待复盘。',
                ],
            ],
            [
                'key' => 'ctrip_strict_core_fact_count',
                'label' => '携程严格核心事实数',
                'unit' => 'verified_fields',
                'baseline_value' => $baselineCount,
                'aggregation' => 'latest',
            ],
            'ctrip',
            'ota_channel_data_quality',
            '只适用于当前酒店、携程、目标营业日的数据完整性；不形成调价、收入或全酒店结论。',
            [
                'level' => 'low',
                'summary' => '主要风险是误用旧日期、其他门店或非最终记录制造“已补齐”。',
                'controls' => ['必须保存并精确回读。', '缺失保持缺失，不能补 0 或沿用旧值。'],
                'stop_conditions' => ['登录、酒店绑定或日期不一致时立即停止。', '需要密码、验证码或二次验证时交由用户完成。'],
            ],
            ['impact' => 100, 'urgency' => 100, 'evidence_strength' => 100, 'execution_cost' => 18],
            0,
            'dual_ota_field_closure:' . $hotelId . ':' . $businessDate . ':ctrip',
            $digest,
            $factRefs,
            [$gapCode],
            $tenantId,
            $hotelId,
            $businessDate,
            $ownerId,
            $dueAt,
            $reviewAt
        );
    }

    /** @return ?array<string,mixed> */
    private function questionCandidate(
        array $question,
        array $action,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        int $ownerId,
        DateTimeImmutable $dueAt,
        DateTimeImmutable $reviewAt
    ): ?array {
        $questionId = (int)($question['id'] ?? 0);
        $digest = strtolower(trim((string)($question['content_digest'] ?? '')));
        $metricKey = strtolower(trim((string)($action['expected_metric'] ?? '')));
        $baseline = $this->questionMetricBaseline($question, $action, $metricKey);
        if ($questionId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || $baseline === null
        ) {
            return null;
        }
        $platform = strtolower(trim((string)($question['platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            return null;
        }
        $risk = is_array($action['risk'] ?? null) ? $action['risk'] : [];
        $priority = strtolower(trim((string)($action['priority'] ?? 'medium')));
        $impact = ['high' => 92, 'medium' => 78, 'low' => 62][$priority] ?? 78;
        $steps = array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            (array)($action['execution_steps'] ?? [])
        )));
        if ($steps === []) {
            $steps = ['按保存的问题范围只读核对事实。', '记录真实人工执行说明和回执。', '等待同范围后续事实后再复盘。'];
        }
        $evidenceRefs = $this->refs($action['evidence_refs'] ?? []);
        $evidenceRefs[] = 'hotel_operating_questions#' . $questionId;
        $evidenceRefs = array_values(array_unique($evidenceRefs));
        return $this->baseCandidate(
            'question:' . $questionId . ':action:0',
            'saved_question',
            trim((string)($question['question'] ?? $question['question_text'] ?? '已保存经营问题')),
            [[
                'statement' => trim((string)($question['answer_summary'] ?? $question['answer']['summary'] ?? '已保存问题引用严格事实形成一项可人工确认的动作。')),
                'evidence_ref' => 'hotel_operating_questions#' . $questionId,
                'quality_status' => 'readback_verified_saved_question',
            ]],
            [
                'type' => 'human_reviewed_operating_check',
                'object' => trim((string)($action['action_object'] ?? 'saved_operating_question')),
                'title' => trim((string)($action['title'] ?? '核对已保存经营问题')),
                'description' => trim((string)($action['action'] ?? '按已保存问题范围完成一次低风险人工核对。')),
                'steps' => $steps,
            ],
            [
                'key' => $metricKey,
                'label' => trim((string)($action['expected_metric_label'] ?? $metricKey)),
                'unit' => (string)$baseline['unit'],
                'baseline_value' => (float)$baseline['value'],
                'aggregation' => (string)$baseline['aggregation'],
            ],
            $platform,
            'ota_channel',
            '只适用于已保存问题中的同酒店、同平台和同日期窗口，不扩大为全酒店结论。',
            [
                'level' => in_array((string)($risk['level'] ?? ''), ['low', 'medium', 'high'], true)
                    ? (string)$risk['level'] : 'medium',
                'summary' => trim((string)($risk['summary'] ?? $action['risk_summary'] ?? '执行前需核对事实，执行后只做观察型复盘。')),
                'controls' => array_values((array)($risk['controls'] ?? $action['risk_controls'] ?? ['人工二次确认', '只做可回滚或只读动作'])),
                'stop_conditions' => array_values((array)($action['stop_conditions'] ?? ['事实或范围漂移时停止'])),
            ],
            [
                'impact' => $impact,
                'urgency' => $priority === 'high' ? 90 : 76,
                'evidence_strength' => 96,
                'execution_cost' => min(80, 18 + count($steps) * 6),
            ],
            $questionId,
            'hotel_operating_questions#' . $questionId,
            $digest,
            $evidenceRefs,
            [],
            $tenantId,
            $hotelId,
            $businessDate,
            $ownerId,
            $dueAt,
            $reviewAt
        );
    }

    /** @return ?array{value:float,unit:string,aggregation:string} */
    private function questionMetricBaseline(array $question, array $action, string $metricKey): ?array
    {
        if ($metricKey === '') {
            return null;
        }
        $refs = $this->refs($action['evidence_refs'] ?? []);
        $values = [];
        $units = [];
        foreach ((array)($question['answer']['fact_samples'] ?? []) as $fact) {
            if (!is_array($fact) || !in_array((string)($fact['ref'] ?? ''), $refs, true)) {
                continue;
            }
            $metricValues = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
            $metricUnits = is_array($fact['metric_units'] ?? null) ? $fact['metric_units'] : [];
            $unit = trim((string)($metricUnits[$metricKey] ?? ''));
            if (!is_numeric($metricValues[$metricKey] ?? null) || $unit === '') {
                continue;
            }
            $values[] = (float)$metricValues[$metricKey];
            $units[$unit] = true;
        }
        if ($values === [] || count($units) !== 1 || count($values) !== count($refs)) {
            return null;
        }
        $unit = (string)array_key_first($units);
        $aggregation = preg_match('/(?:rate|ratio|percent|pct|score)/i', $unit) === 1 ? 'average' : 'sum';
        return [
            'value' => round($aggregation === 'average' ? array_sum($values) / count($values) : array_sum($values), 6),
            'unit' => $unit,
            'aggregation' => $aggregation,
        ];
    }

    /** @return array<string,mixed> */
    private function baseCandidate(
        string $candidateKey,
        string $sourceType,
        string $problem,
        array $factBasis,
        array $action,
        array $metric,
        string $platform,
        string $metricScope,
        string $scopeNote,
        array $risk,
        array $ranking,
        int $sourceRecordId,
        string $sourceRecordRef,
        string $snapshotDigest,
        array $factRefs,
        array $gapCodes,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        int $ownerId,
        DateTimeImmutable $dueAt,
        DateTimeImmutable $reviewAt
    ): array {
        $ranking['reasons'] = [
            'impact' => '业务影响范围与是否阻断后续收益判断',
            'urgency' => '当前营业日是否仍缺关键事实或存在已保存行动',
            'evidence_strength' => '严格回读缺口或已保存问题的证据强度',
            'execution_cost' => '人工只读核对、保存与回读的预计成本',
        ];
        return [
            'candidate_key' => $candidateKey,
            'source_type' => $sourceType,
            'problem' => $problem,
            'fact_basis' => $factBasis,
            'recommended_action' => $action,
            'expected_observation_metric' => $metric,
            'scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'business_date' => $businessDate,
                'metric_scope' => $metricScope,
                'scope_note' => $scopeNote,
            ],
            'risk' => $risk,
            'responsibility' => [
                'owner_id' => $ownerId,
                'owner_label' => '当前确认人',
                'due_at' => $dueAt->format('Y-m-d H:i:s'),
                'review_at' => $reviewAt->format('Y-m-d H:i:s'),
            ],
            'ranking' => $ranking,
            'source' => [
                'record_id' => $sourceRecordId,
                'record_ref' => $sourceRecordRef,
                'snapshot_digest' => $snapshotDigest,
                'fact_refs' => $factRefs,
                'gap_codes' => $gapCodes,
            ],
            'external_write_boundary' => [
                'automatic_ctrip_write' => false,
                'automatic_meituan_write' => false,
                'automatic_pms_write' => false,
                'automatic_wecom_message' => false,
                'automatic_execution' => false,
                'human_confirmation_required' => true,
                'causality_claimed' => false,
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function fieldMap(array $platform): array
    {
        $map = [];
        foreach ((array)($platform['fields'] ?? []) as $field) {
            if (is_array($field) && trim((string)($field['key'] ?? '')) !== '') {
                $map[(string)$field['key']] = $field;
            }
        }
        return $map;
    }

    private function fieldReady(array $field): bool
    {
        return in_array((string)($field['status'] ?? ''), ['strict_readback', 'verified_calculation'], true)
            && ($field['identity_binding_verified'] ?? false) === true
            && ($field['strict_final_gate'] ?? false) === true;
    }

    private function fieldLabel(array $field, string $fallback): string
    {
        return trim((string)($field['label'] ?? '')) ?: $fallback;
    }

    /** @return list<string> */
    private function refs(mixed $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            is_array($values) ? $values : []
        ))));
    }

    private function assertClosureScope(array $closure, int $tenantId, int $hotelId, string $businessDate): void
    {
        $digest = strtolower(trim((string)($closure['closure_digest'] ?? '')));
        if ((string)($closure['contract_version'] ?? '') !== 'dual_ota_field_closure.v1'
            || (int)($closure['tenant_id'] ?? 0) !== $tenantId
            || (int)($closure['hotel_id'] ?? 0) !== $hotelId
            || (string)($closure['business_date'] ?? '') !== $businessDate
            || (string)($closure['metric_scope'] ?? '') !== 'ota_channel_only'
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
        ) {
            throw new InvalidArgumentException('每日事项严格事实范围或摘要无效');
        }
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    private function schedule(string $businessDate, DateTimeImmutable $now): array
    {
        unset($businessDate);
        $timezone = new DateTimeZone('Asia/Shanghai');
        $dueAt = new DateTimeImmutable($now->format('Y-m-d') . ' 23:00:00', $timezone);
        if ($dueAt <= $now) {
            $dueAt = $dueAt->modify('+1 day');
        }
        $reviewAt = new DateTimeImmutable($dueAt->modify('+1 day')->format('Y-m-d') . ' 10:00:00', $timezone);
        return [$dueAt, $reviewAt];
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('每日事项业务日期无效');
        }
        return $value;
    }

    private function canonicalJson(mixed $value): string
    {
        return (string)json_encode(
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
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
