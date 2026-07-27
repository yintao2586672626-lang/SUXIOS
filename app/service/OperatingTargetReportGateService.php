<?php
declare(strict_types=1);

namespace app\service;

/**
 * Pure preview and delivery gate for explicitly-scoped operating-target reports.
 *
 * This adapter renders an existing operating-target preview through the
 * established WeCom daily-report renderer. It deliberately has no production
 * delivery method. An authorized test push is possible only through an
 * explicitly injected isolated test dispatcher.
 */
final class OperatingTargetReportGateService
{
    public const TEST_PUSH_PURPOSE = 'operating_target_report_test_push';
    public const TEST_ONLY_REQUEST_STATUS = 'test_push_request_accepted_not_sent';

    /** @var callable|null */
    private $isolatedTestDispatcher;

    public function __construct(
        private readonly ?WechatRobotDeliveryService $renderer = null,
        ?callable $isolatedTestDispatcher = null,
        private readonly ?ManualNotificationTestTargetService $testTargets = null
    ) {
        $this->isolatedTestDispatcher = $isolatedTestDispatcher;
    }

    /**
     * @param array<string, mixed> $operatingTargetPreview
     * @return array<string, mixed>
     */
    public function pagePreview(array $operatingTargetPreview, string $hotelName): array
    {
        $previewAvailable = $this->previewAvailable($operatingTargetPreview);
        $formalGate = $this->formalSendGate($operatingTargetPreview);
        $previewFingerprint = $this->previewFingerprint($operatingTargetPreview);

        return [
            'status' => $previewAvailable ? 'preview_ready' : 'preview_unavailable',
            'delivery_status' => 'preview_only',
            'delivery_note' => '当前仅生成页面预览，未调用企业微信 Webhook 或任何外部发送通道。',
            'preview_fingerprint' => $previewFingerprint,
            'formal_send_gate' => $formalGate,
            'test_push_gate' => [
                'allowed' => false,
                'status' => 'authorization_required',
                'purpose' => self::TEST_PUSH_PURPOSE,
                'required_preview_fingerprint' => $previewFingerprint,
                'test_target' => $this->testTargetForHotel(
                    (int)($operatingTargetPreview['hotel_id'] ?? 0)
                ),
                'required_confirmations' => [
                    'first' => '已核对本页来源、日期、质量与正式发送门禁。',
                    'second' => '确认仅发起测试请求；本轮不读取 Webhook、不向企业微信外发。',
                ],
                'note' => '必须在查看页面预览后，由有权人员按该预览指纹完成两次明确确认，才可发起单次测试请求。',
            ],
            'fact_trace' => $this->factTrace($operatingTargetPreview),
            'payload' => $this->renderPayload(
                $operatingTargetPreview,
                $hotelName,
                'page_preview',
                $formalGate
            ),
        ];
    }

    /**
     * Build an externally deliverable candidate without performing delivery.
     * Scheduled and immediate senders must use this method so the payload and
     * the formal data gate always share one source of truth.
     *
     * @param array<string, mixed> $operatingTargetPreview
     * @return array<string, mixed>
     */
    public function deliveryCandidate(
        array $operatingTargetPreview,
        string $hotelName,
        string $deliveryMode
    ): array {
        if (!in_array($deliveryMode, ['immediate_test', 'scheduled_test'], true)) {
            throw new \InvalidArgumentException('operating_target_delivery_mode_invalid');
        }

        $formalGate = $this->formalSendGate($operatingTargetPreview);
        $fingerprint = $this->previewFingerprint($operatingTargetPreview);
        if (!$this->previewAvailable($operatingTargetPreview) || ($formalGate['allowed'] ?? false) !== true) {
            return [
                'status' => 'blocked',
                'reason_code' => 'operating_target_report_not_ready',
                'business_date' => (string)($operatingTargetPreview['target_date'] ?? ''),
                'preview_fingerprint' => $fingerprint,
                'formal_send_gate' => $formalGate,
                'payload' => null,
            ];
        }

        return [
            'status' => 'ready',
            'reason_code' => 'operating_target_report_ready',
            'business_date' => (string)$operatingTargetPreview['target_date'],
            'preview_fingerprint' => $fingerprint,
            'formal_send_gate' => $formalGate,
            'payload' => $this->renderPayload(
                $operatingTargetPreview,
                $hotelName,
                $deliveryMode,
                $formalGate
            ),
        ];
    }

    /**
     * Dispatch one explicitly authorized test payload through an injected,
     * isolated test transport. No Webhook URL is accepted by this boundary.
     *
     * @param array<string, mixed> $operatingTargetPreview
     * @param array<string, mixed> $authorization
     * @return array<string, mixed>
     */
    public function authorizedTestPush(
        array $operatingTargetPreview,
        string $hotelName,
        array $authorization
    ): array {
        $formalGate = $this->formalSendGate($operatingTargetPreview);
        $authorizationBlockers = $this->testAuthorizationBlockers(
            $operatingTargetPreview,
            $authorization
        );
        if ($this->isolatedTestDispatcher === null) {
            $authorizationBlockers[] = [
                'code' => 'isolated_test_dispatcher_missing',
                'message' => '未注入隔离测试发送器，测试推送已阻断。',
            ];
        }

        if ($authorizationBlockers !== []) {
            return [
                'delivery_status' => 'test_push_blocked',
                'delivery_mode' => 'authorized_test',
                'test_dispatcher_invoked' => false,
                'formal_send_gate' => $formalGate,
                'authorization_blockers' => $authorizationBlockers,
            ];
        }

        $payload = $this->renderPayload(
            $operatingTargetPreview,
            $hotelName,
            'authorized_test',
            $formalGate
        );
        $context = [
            'purpose' => self::TEST_PUSH_PURPOSE,
            'actor_id' => (int)$authorization['actor_id'],
            'approval_reference' => trim((string)$authorization['approval_reference']),
            'test_destination' => trim((string)$authorization['test_destination']),
            'test_only' => ($authorization['test_only'] ?? false) === true,
            'target_robot_id' => (int)($authorization['target_robot_id'] ?? 0),
            'target_robot_name' => trim((string)($authorization['target_robot_name'] ?? '')),
            'first_confirmation' => ($authorization['first_confirmation'] ?? false) === true,
            'second_confirmation' => ($authorization['second_confirmation'] ?? false) === true,
            'hotel_id' => (int)$operatingTargetPreview['hotel_id'],
            'target_date' => (string)$operatingTargetPreview['target_date'],
            'preview_fingerprint' => $this->previewFingerprint($operatingTargetPreview),
            'formal_send_allowed' => (bool)$formalGate['allowed'],
        ];

        try {
            $result = call_user_func($this->isolatedTestDispatcher, $payload, $context);
        } catch (\Throwable $exception) {
            return [
                'delivery_status' => 'test_push_failed',
                'delivery_mode' => 'authorized_test',
                'test_dispatcher_invoked' => true,
                'formal_send_gate' => $formalGate,
                'authorization' => $context,
                'failure' => [
                    'code' => 'isolated_test_dispatcher_failed',
                    'message' => '隔离测试发送器执行失败：' . $this->safeText($exception->getMessage(), 160),
                ],
            ];
        }

        $result = is_array($result) ? $result : [];
        $deliveryStatus = trim((string)($result['delivery_status'] ?? ''));
        if (
            $deliveryStatus === self::TEST_ONLY_REQUEST_STATUS
            && ($result['request_accepted'] ?? false) === true
            && ($result['delivery_attempted'] ?? false) === false
        ) {
            return [
                'delivery_status' => self::TEST_ONLY_REQUEST_STATUS,
                'delivery_mode' => 'authorized_test',
                'test_only' => true,
                'delivery_attempted' => false,
                'test_dispatcher_invoked' => true,
                'formal_send_gate' => $formalGate,
                'authorization' => $context,
                'test_receipt' => [
                    'request_accepted' => true,
                    'delivery_attempted' => false,
                    'receipt_id' => $this->safeText((string)($result['receipt_id'] ?? ''), 100),
                    'message' => $this->safeText(
                        (string)($result['message'] ?? '测试请求已受理；本轮未向企业微信外发。'),
                        160
                    ),
                ],
            ];
        }
        $success = ($result['success'] ?? false) === true;

        return [
            'delivery_status' => $success ? 'test_push_sent' : 'test_push_failed',
            'delivery_mode' => 'authorized_test',
            'test_dispatcher_invoked' => true,
            'formal_send_gate' => $formalGate,
            'authorization' => $context,
            'test_receipt' => [
                'success' => $success,
                'receipt_id' => $this->safeText((string)($result['receipt_id'] ?? ''), 100),
                'message' => $this->safeText(
                    (string)($result['message'] ?? ($success ? '隔离测试发送完成。' : '隔离测试发送失败。')),
                    160
                ),
            ],
        ];
    }

    /**
     * Pure decision only. This method never delivers a report.
     *
     * @param array<string, mixed> $operatingTargetPreview
     * @return array<string, mixed>
     */
    public function formalSendGate(array $operatingTargetPreview): array
    {
        $blockers = [];
        $status = strtolower(trim((string)($operatingTargetPreview['status'] ?? 'missing')));
        $facts = is_array($operatingTargetPreview['facts'] ?? null)
            ? $operatingTargetPreview['facts']
            : [];
        $gaps = array_values(array_filter(
            (array)($operatingTargetPreview['gaps'] ?? []),
            'is_array'
        ));

        if ($status !== 'ready') {
            $blockers[] = [
                'code' => 'operating_target_not_ready',
                'message' => '经营目标报告尚未达到 ready 状态。',
            ];
        }
        $factScope = strtolower(trim((string)($facts['fact_scope'] ?? '')));
        $scopeLabel = $factScope === 'accommodation_room_fee'
            ? '住宿房费'
            : '全酒店经营';
        if ($facts === []) {
            $blockers[] = [
                'code' => 'operating_facts_missing',
                'message' => '缺少可核验的经营事实。',
            ];
        } elseif (!in_array($factScope, ['whole_hotel', 'accommodation_room_fee'], true)) {
            $blockers[] = [
                'code' => 'operating_fact_scope_unsupported',
                'message' => '经营事实口径不受支持；不能用 OTA 渠道事实替代酒店经营事实。',
            ];
        }

        $qualityStatus = strtolower(trim((string)($facts['quality_status'] ?? 'unverified')));
        if (!in_array($qualityStatus, ['verified', 'manual_confirmed'], true)) {
            $blockers[] = [
                'code' => 'operating_fact_quality_unverified',
                'message' => $scopeLabel . '事实尚未核验或人工确认。',
            ];
        }
        $sourceType = strtolower(trim((string)($facts['source_type'] ?? '')));
        if (!in_array($sourceType, ['manual', 'daily_report', 'pms', 'import'], true)) {
            $blockers[] = [
                'code' => 'fact_source_type_missing',
                'message' => '经营事实来源类型缺失或无效。',
            ];
        }
        if (trim((string)($facts['source_reference'] ?? '')) === '') {
            $blockers[] = [
                'code' => 'fact_source_reference_missing',
                'message' => '缺少可回查的经营事实来源依据。',
            ];
        }
        if (trim((string)($facts['fact_captured_at'] ?? '')) === '') {
            $blockers[] = [
                'code' => 'fact_captured_at_missing',
                'message' => '缺少经营事实采集或核对时间。',
            ];
        }

        $requiredFacts = [
            'target_revenue' => '当日营收目标',
            'actual_revenue' => $scopeLabel . '实际额',
            'sold_room_nights' => '已售间夜',
            'sellable_room_nights' => '可售房夜',
        ];
        $numericFacts = [];
        foreach ($requiredFacts as $key => $label) {
            if (!array_key_exists($key, $facts) || $facts[$key] === null || $facts[$key] === '') {
                $blockers[] = [
                    'code' => $key . '_missing',
                    'message' => $label . '缺失，禁止正式发送。',
                ];
                continue;
            }
            $value = $this->finiteNumber($facts[$key]);
            if ($value === null) {
                $blockers[] = [
                    'code' => $key . '_invalid',
                    'message' => $label . '不是有效数值，禁止正式发送。',
                ];
                continue;
            }
            $numericFacts[$key] = $value;
        }
        if (array_key_exists('target_revenue', $numericFacts) && $numericFacts['target_revenue'] <= 0) {
            $blockers[] = [
                'code' => 'target_revenue_not_positive',
                'message' => '当日营收目标必须大于 0。',
            ];
        }
        if (array_key_exists('actual_revenue', $numericFacts) && $numericFacts['actual_revenue'] < 0) {
            $blockers[] = [
                'code' => 'actual_revenue_negative',
                'message' => '全酒店实际营收不能小于 0。',
            ];
        }
        if (
            array_key_exists('sold_room_nights', $numericFacts)
            && ($numericFacts['sold_room_nights'] < 0 || floor($numericFacts['sold_room_nights']) !== $numericFacts['sold_room_nights'])
        ) {
            $blockers[] = [
                'code' => 'sold_room_nights_invalid',
                'message' => '已售间夜必须是大于或等于 0 的整数。',
            ];
        }
        if (
            array_key_exists('sellable_room_nights', $numericFacts)
            && ($numericFacts['sellable_room_nights'] <= 0 || floor($numericFacts['sellable_room_nights']) !== $numericFacts['sellable_room_nights'])
        ) {
            $blockers[] = [
                'code' => 'sellable_room_nights_invalid',
                'message' => '可售房夜必须是大于 0 的整数。',
            ];
        }
        if (
            array_key_exists('sold_room_nights', $numericFacts)
            && array_key_exists('sellable_room_nights', $numericFacts)
            && $numericFacts['sold_room_nights'] > $numericFacts['sellable_room_nights']
        ) {
            $blockers[] = [
                'code' => 'room_nights_inconsistent',
                'message' => '已售间夜大于可售房夜，事实口径冲突。',
            ];
        }

        if ($gaps !== []) {
            $blockers[] = [
                'code' => 'unresolved_data_gaps',
                'message' => '仍有 ' . count($gaps) . ' 项数据缺口未解决。',
                'gap_codes' => array_values(array_filter(array_map(
                    static fn(array $gap): string => trim((string)($gap['code'] ?? '')),
                    $gaps
                ))),
            ];
        }

        $integrated = is_array($operatingTargetPreview['integrated_sources'] ?? null)
            ? $operatingTargetPreview['integrated_sources']
            : null;
        if (is_array($integrated) && ($integrated['applies'] ?? false) === true) {
            if (($integrated['delivery_allowed'] ?? false) !== true) {
                $integratedBlockers = array_values(array_filter(
                    (array)($integrated['blockers'] ?? []),
                    'is_array'
                ));
                if ($integratedBlockers === []) {
                    $integratedBlockers[] = [
                        'code' => 'single_hotel_integrated_sources_not_ready',
                        'message' => '单店PMS、携程或美团来源证据未通过。',
                    ];
                }
                array_push($blockers, ...$integratedBlockers);
            }
            if ((int)($integrated['hotel_id'] ?? 0) !== (int)($operatingTargetPreview['hotel_id'] ?? 0)
                || (string)($integrated['business_date'] ?? '') !== (string)($operatingTargetPreview['target_date'] ?? '')
            ) {
                $blockers[] = [
                    'code' => 'single_hotel_integrated_scope_mismatch',
                    'message' => '单店综合来源与经营目标的酒店或日期不一致。',
                ];
            }
        }

        $blockers = $this->uniqueBlockers($blockers);
        $allowed = $blockers === [];

        return [
            'allowed' => $allowed,
            'status' => $allowed ? 'formal_send_allowed' : 'formal_send_blocked',
            'blockers' => $blockers,
            'note' => $allowed
                ? '门禁条件满足；实际正式发送仍需单独的外部授权与生产投递流程。'
                : '当前仅允许页面预览；禁止进入正式企业微信发送流程。',
        ];
    }

    /**
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $formalGate
     * @return array{msgtype: string, markdown: array{content: string}}
     */
    private function renderPayload(
        array $preview,
        string $hotelName,
        string $mode,
        array $formalGate
    ): array {
        $report = $this->mapToDailyReport($preview);
        $payload = ($this->renderer ?? new WechatRobotDeliveryService())
            ->buildDailyReportPayload($report, $hotelName);
        $content = (string)($payload['markdown']['content'] ?? '');
        $lines = explode("\n", $content);
        if ($lines === []) {
            $lines = ['# 宿析OS 每日经营目标报告'];
        } else {
            $lines[0] = '# 宿析OS 每日经营目标报告';
        }

        $modeLabel = match ($mode) {
            'authorized_test' => '授权测试推送，禁止作为正式经营结论',
            'immediate_test' => '企业微信测试群立即真实投递',
            'scheduled_test' => '企业微信测试群定时真实投递',
            default => '页面预览，未触发任何外部发送',
        };
        $formalLabel = ($formalGate['allowed'] ?? false) === true
            ? '允许（仍需另行取得正式发送授权）'
            : '阻断';
        array_splice($lines, 1, 0, [
            '> 当前模式：' . $modeLabel,
            '> 正式发送门禁：' . $formalLabel,
        ]);
        $factTrace = $this->factTrace($preview);
        array_splice($lines, 3, 0, [
            '> 经营事实日期：' . $this->safeText((string)$factTrace['target_date'], 24),
            '> 事实来源：' . $this->safeText((string)$factTrace['source_label'], 80),
            '> 来源依据：' . $this->safeText((string)$factTrace['source_reference'], 180),
            '> 数据质量：' . $this->safeText((string)$factTrace['quality_label'], 80),
            '> 采集/核对时间：' . $this->safeText((string)$factTrace['fact_captured_at'], 40),
        ]);
        $integrated = is_array($preview['integrated_sources'] ?? null)
            ? $preview['integrated_sources']
            : null;
        if (is_array($integrated) && ($integrated['applies'] ?? false) === true) {
            $lines[0] = '# 宿析OS｜敦煌漠蓝新单店经营日报';
            array_push($lines, '', ...$this->integratedSourceLines($integrated));
        }
        $payload['markdown']['content'] = mb_strcut(
            implode("\n", $lines),
            0,
            3800,
            'UTF-8'
        );

        return $payload;
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function mapToDailyReport(array $preview): array
    {
        $status = strtolower(trim((string)($preview['status'] ?? 'missing')));
        $facts = is_array($preview['facts'] ?? null) ? $preview['facts'] : [];
        $metrics = is_array($preview['metrics'] ?? null) ? $preview['metrics'] : [];
        $gaps = array_values(array_filter((array)($preview['gaps'] ?? []), 'is_array'));
        $reminders = array_values(array_filter((array)($preview['reminders'] ?? []), 'is_array'));

        $metricDefinitions = [
            ['source' => $facts, 'key' => 'target_revenue', 'label' => '当日营收目标', 'unit' => '元'],
            [
                'source' => $facts,
                'key' => 'actual_revenue',
                'label' => (string)($facts['fact_scope'] ?? '') === 'accommodation_room_fee'
                    ? '住宿房费实际额'
                    : '全酒店实际营收',
                'unit' => '元',
            ],
            ['source' => $facts, 'key' => 'sold_room_nights', 'label' => '已售间夜', 'unit' => '间夜'],
            ['source' => $facts, 'key' => 'sellable_room_nights', 'label' => '可售房夜', 'unit' => '间夜'],
            ['source' => $metrics, 'key' => 'completion_rate_percent', 'label' => '营收目标完成率', 'unit' => '%'],
            ['source' => $metrics, 'key' => 'remaining_revenue', 'label' => '剩余营收目标', 'unit' => '元'],
            ['source' => $metrics, 'key' => 'selling_progress_percent', 'label' => '销售进度', 'unit' => '%'],
            ['source' => $metrics, 'key' => 'remaining_sellable_room_nights', 'label' => '剩余可售房夜', 'unit' => '间夜'],
            ['source' => $metrics, 'key' => 'required_average_rate', 'label' => '剩余所需均价', 'unit' => '元'],
        ];
        $reportMetrics = [];
        foreach ($metricDefinitions as $definition) {
            $source = $definition['source'];
            $key = $definition['key'];
            if (!array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
                continue;
            }
            $reportMetrics[] = [
                'key' => $key,
                'label' => $definition['label'],
                'value' => $source[$key],
                'unit' => $definition['unit'],
            ];
        }

        $factScope = strtolower(trim((string)($facts['fact_scope'] ?? '')));
        $scopeLabel = $factScope === 'accommodation_room_fee' ? '住宿房费' : '全酒店经营';
        $summary = match ($status) {
            'ready' => '经营目标报告已按已核验或人工确认的' . $scopeLabel . '事实生成。',
            'blocked' => '经营目标数据存在冲突，当前只可预览，禁止正式发送。',
            'partial' => '经营目标报告仍有数据缺口，当前只可预览，禁止正式发送。',
            default => '经营目标报告缺少可回读事实，当前无法进入发送流程。',
        };
        $statusLabel = match ($status) {
            'ready' => $scopeLabel . '事实已就绪',
            'blocked' => '数据冲突，正式发送已阻断',
            'partial' => '数据部分可用，正式发送已阻断',
            default => '经营事实缺失，正式发送已阻断',
        };
        $scopeNote = $factScope === 'accommodation_room_fee'
            ? '仅使用同酒店、同日期的订单来了住宿房费事实；目标与实际均为住宿房费口径，不含其他收入。'
            : '仅使用同酒店、同日期的全酒店经营事实；OTA 渠道事实不能替代全酒店经营事实。';

        return [
            'report_date' => (string)($preview['target_date'] ?? ''),
            'summary' => $summary,
            'result_readiness' => [
                'status' => $status,
                'status_label' => $statusLabel,
                'scope_note' => $scopeNote,
            ],
            'report_scope' => [
                'scope' => $factScope !== '' ? $factScope : 'unknown',
                'scope_note' => $scopeNote,
            ],
            'data_gaps' => $gaps,
            'yesterday_result' => ['metrics' => $reportMetrics],
            'recommended_actions' => array_map(
                static fn(array $reminder): array => [
                    'action' => (string)($reminder['message'] ?? '请人工核对经营事实。'),
                    'blocked_reason' => $status === 'ready' ? '' : '正式发送门禁未通过',
                ],
                $reminders
            ),
        ];
    }

    /**
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $authorization
     * @return array<int, array{code: string, message: string}>
     */
    private function testAuthorizationBlockers(array $preview, array $authorization): array
    {
        $blockers = [];
        if (!$this->previewAvailable($preview)) {
            $blockers[] = [
                'code' => 'page_preview_unavailable',
                'message' => '没有可供核对的经营目标页面预览。',
            ];
        }
        if (($authorization['approved'] ?? false) !== true) {
            $blockers[] = [
                'code' => 'test_push_not_approved',
                'message' => '尚未明确授权测试推送。',
            ];
        }
        if (($authorization['test_only'] ?? false) !== true) {
            $blockers[] = [
                'code' => 'test_only_required',
                'message' => '必须明确标记为 test_only，禁止借测试入口进入正式发送。',
            ];
        }
        $target = $this->testTargetResolver()->resolve(
            (int)($preview['hotel_id'] ?? 0),
            (int)($authorization['target_robot_id'] ?? 0),
            trim((string)($authorization['target_robot_name'] ?? ''))
        );
        if ($target === null) {
            $blockers[] = [
                'code' => 'test_robot_forbidden',
                'message' => '经营目标测试请求仅允许当前酒店已验证并明确标记为测试群的机器人。',
            ];
        }
        if (($authorization['first_confirmation'] ?? false) !== true) {
            $blockers[] = [
                'code' => 'test_push_first_confirmation_required',
                'message' => '请先确认已核对来源、日期、质量和正式发送门禁。',
            ];
        }
        if (($authorization['second_confirmation'] ?? false) !== true) {
            $blockers[] = [
                'code' => 'test_push_second_confirmation_required',
                'message' => '请再次确认本轮仅发起测试请求，不会外发企业微信。',
            ];
        }
        if ((string)($authorization['purpose'] ?? '') !== self::TEST_PUSH_PURPOSE) {
            $blockers[] = [
                'code' => 'test_push_purpose_invalid',
                'message' => '授权用途与经营目标报告测试推送不匹配。',
            ];
        }
        if ((int)($authorization['actor_id'] ?? 0) <= 0) {
            $blockers[] = [
                'code' => 'test_push_actor_missing',
                'message' => '缺少授权操作人。',
            ];
        }
        if (trim((string)($authorization['approval_reference'] ?? '')) === '') {
            $blockers[] = [
                'code' => 'test_push_approval_reference_missing',
                'message' => '缺少可追溯的授权编号。',
            ];
        }
        if (trim((string)($authorization['test_destination'] ?? '')) === '') {
            $blockers[] = [
                'code' => 'test_destination_missing',
                'message' => '缺少明确的测试接收目标。',
            ];
        }
        $expectedFingerprint = $this->previewFingerprint($preview);
        $authorizedFingerprint = trim((string)($authorization['preview_fingerprint'] ?? ''));
        if (
            $authorizedFingerprint === ''
            || !hash_equals($expectedFingerprint, $authorizedFingerprint)
        ) {
            $blockers[] = [
                'code' => 'preview_fingerprint_mismatch',
                'message' => '授权未绑定当前页面预览，或预览内容在授权后已变化。',
            ];
        }

        return $blockers;
    }

    /** @param array<string, mixed> $preview */
    private function previewAvailable(array $preview): bool
    {
        return (int)($preview['hotel_id'] ?? 0) > 0
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($preview['target_date'] ?? '')) === 1
            && is_array($preview['facts'] ?? null)
            && strtolower(trim((string)($preview['status'] ?? 'missing'))) !== 'missing';
    }

    /** @return array<string, mixed> */
    private function testTargetForHotel(int $hotelId): array
    {
        $target = $this->testTargetResolver()->resolve($hotelId);
        return [
            'test_only' => true,
            'hotel_id' => $hotelId,
            'robot_id' => (int)($target['robot_id'] ?? 0),
            'robot_name' => (string)($target['robot_name'] ?? ''),
            'binding_status' => (string)($target['binding_status'] ?? 'test_binding_missing'),
            'delivery_attempted' => false,
            'formal_group_delivery_allowed' => false,
        ];
    }

    private function testTargetResolver(): ManualNotificationTestTargetService
    {
        return $this->testTargets ?? new ManualNotificationTestTargetService();
    }

    /** @return array<string, string> */
    private function factTrace(array $preview): array
    {
        $facts = is_array($preview['facts'] ?? null) ? $preview['facts'] : [];
        $sourceType = strtolower(trim((string)($facts['source_type'] ?? '')));
        $qualityStatus = strtolower(trim((string)($facts['quality_status'] ?? '')));
        $sourceLabels = [
            'manual' => '人工录入',
            'daily_report' => '经营日报',
            'pms' => 'PMS',
            'import' => '导入文件',
        ];
        $qualityLabels = [
            'verified' => '已验证',
            'manual_confirmed' => '已人工确认',
            'unverified' => '未验证',
            'missing' => '缺失',
            'collection_failed' => '采集失败',
            'identity_mismatch' => '身份不匹配',
        ];

        return [
            'target_date' => (string)($preview['target_date'] ?? '未返回'),
            'source_type' => $sourceType !== '' ? $sourceType : '未说明',
            'source_label' => $sourceLabels[$sourceType] ?? '未说明来源',
            'source_reference' => trim((string)($facts['source_reference'] ?? '')) ?: '未提供来源依据',
            'quality_status' => $qualityStatus !== '' ? $qualityStatus : 'unverified',
            'quality_label' => $qualityLabels[$qualityStatus] ?? '未说明质量状态',
            'quality_reason' => trim((string)($facts['quality_reason'] ?? '')) ?: '未提供质量说明',
            'fact_captured_at' => trim((string)($facts['fact_captured_at'] ?? '')) ?: '未记录',
        ];
    }

    /** @param array<string, mixed> $preview */
    private function previewFingerprint(array $preview): string
    {
        $canonical = [
            'hotel_id' => (int)($preview['hotel_id'] ?? 0),
            'target_date' => (string)($preview['target_date'] ?? ''),
            'status' => (string)($preview['status'] ?? 'missing'),
            'facts' => is_array($preview['facts'] ?? null) ? $preview['facts'] : null,
            'metrics' => is_array($preview['metrics'] ?? null) ? $preview['metrics'] : null,
            'gaps' => is_array($preview['gaps'] ?? null) ? array_values($preview['gaps']) : [],
            'reminders' => is_array($preview['reminders'] ?? null)
                ? array_values($preview['reminders'])
                : [],
            'integrated_sources' => is_array($preview['integrated_sources'] ?? null)
                ? $preview['integrated_sources']
                : null,
        ];
        $encoded = json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        return hash('sha256', $encoded === false ? '' : $encoded);
    }

    /** @param array<string,mixed> $integrated @return array<int,string> */
    private function integratedSourceLines(array $integrated): array
    {
        $sources = is_array($integrated['sources'] ?? null) ? $integrated['sources'] : [];
        $pms = is_array($sources['pms'] ?? null) ? $sources['pms'] : [];
        $ctrip = is_array($sources['ctrip'] ?? null) ? $sources['ctrip'] : [];
        $meituan = is_array($sources['meituan'] ?? null) ? $sources['meituan'] : [];
        $pmsFacts = is_array($pms['facts'] ?? null) ? $pms['facts'] : [];
        $ctripFacts = is_array($ctrip['facts'] ?? null) ? $ctrip['facts'] : [];
        $meituanFacts = is_array($meituan['facts'] ?? null) ? $meituan['facts'] : [];

        return [
            '**PMS住宿经营事实（订单来了）**',
            '- 房费：' . $this->metricText($pmsFacts['room_fee_revenue'] ?? null, '元')
                . '；ADR：' . $this->metricText($pmsFacts['adr'] ?? null, '元')
                . '；入住率：' . $this->metricText($pmsFacts['occupancy_rate_percent'] ?? null, '%'),
            '- RevPAR：' . $this->metricText($pmsFacts['revpar'] ?? null, '元')
                . '；累计售出间夜：' . $this->metricText($pmsFacts['sold_room_nights'] ?? null, '间夜')
                . '；平均每日间夜：'
                . $this->metricText($pmsFacts['average_daily_room_nights'] ?? null, '间夜'),
            '- 推算可售房夜：'
                . $this->metricText($pmsFacts['sellable_room_nights'] ?? null, '间夜'),
            '- 状态：' . $this->sourceStatusLabel($pms)
                . '；明细对账：' . $this->safeText((string)($pms['reconciliation_status'] ?? '未验证'), 24),
            '',
            '**携程渠道事实（不与PMS相加）**',
            '- 渠道收入：' . $this->metricText($ctripFacts['channel_revenue'] ?? null, '元')
                . '；订单：' . $this->metricText($ctripFacts['orders'] ?? null, '单')
                . '；间夜：' . $this->metricText($ctripFacts['room_nights'] ?? null, '间夜'),
            '- 状态：' . $this->sourceStatusLabel($ctrip)
                . '；采集时间：' . $this->safeText((string)($ctrip['collected_at'] ?? '未记录'), 32),
            '',
            '**美团渠道事实（流量转化口径）**',
            '- 曝光：' . $this->metricText($meituanFacts['list_exposure'] ?? null, '次')
                . '；意向访客：' . $this->metricText($meituanFacts['detail_exposure'] ?? null, '人')
                . '；转化率：' . $this->metricText($meituanFacts['flow_rate_percent'] ?? null, '%'),
            '- 支付订单：' . $this->metricText($meituanFacts['paid_orders'] ?? null, '单')
                . '；房费：缺失；间夜：缺失',
            '- 目标日订单页汇总：'
                . $this->metricText($meituanFacts['target_date_order_count'] ?? null, '单')
                . '（仅订单数字段已验证，与流量转化口径不相加）',
            '- 状态：' . $this->sourceStatusLabel($meituan)
                . '；缺失字段未用0补齐',
            '',
            '> 口径：PMS为住宿房费经营事实；携程、美团仅为各自OTA渠道事实，三者不相加。',
        ];
    }

    /** @param array<string,mixed> $source */
    private function sourceStatusLabel(array $source): string
    {
        return match (strtolower(trim((string)($source['status'] ?? 'missing')))) {
            'ready' => '已验证并回读',
            'partial' => '部分可用（身份/日期/回读已验证）',
            'blocked' => '已阻断',
            default => '缺失或未验证',
        };
    }

    private function metricText(mixed $value, string $unit): string
    {
        if (is_bool($value) || $value === null || $value === '' || !is_numeric($value)) {
            return '缺失';
        }
        $number = (float)$value;
        $text = abs($number - round($number)) < 0.000001
            ? (string)(int)round($number)
            : number_format($number, 2, '.', '');

        return $text . $unit;
    }

    /**
     * @param array<int, array<string, mixed>> $blockers
     * @return array<int, array<string, mixed>>
     */
    private function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $code = (string)($blocker['code'] ?? '');
            if ($code !== '') {
                $unique[$code] = $blocker;
            }
        }

        return array_values($unique);
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, $maxLength);
    }

    private function finiteNumber(mixed $value): ?float
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;

        return is_finite($number) ? $number : null;
    }
}
