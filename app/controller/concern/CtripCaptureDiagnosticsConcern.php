<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\SystemConfig;
use app\service\CtripCaptureDiagnosisService;

trait CtripCaptureDiagnosticsConcern
{
    private function buildCtripCaptureGateDecision(array $payload): array
    {
        $gate = $payload['capture_gate'] ?? null;
        if (!is_array($gate)) {
            return [
                'accepted' => false,
                'status' => 'missing',
                'failed_check_ids' => ['capture_gate_missing'],
                'gate' => null,
            ];
        }

        $status = strtolower(trim((string)($gate['status'] ?? '')));
        $failedCheckIds = array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            is_array($gate['failed_check_ids'] ?? null) ? $gate['failed_check_ids'] : []
        )));
        $accepted = $status === 'pass' && $failedCheckIds === [];

        return [
            'accepted' => $accepted,
            'status' => $status !== '' ? $status : 'unknown',
            'failed_check_ids' => $failedCheckIds,
            'gate' => $gate,
        ];
    }

    private function getCtripCaptureBlockingFailedCheckIds(array $failedCheckIds): array
    {
        $softCheckIds = ['field_coverage', 'endpoint_coverage'];
        return array_values(array_filter(
            $failedCheckIds,
            static fn($checkId): bool => !in_array((string)$checkId, $softCheckIds, true)
        ));
    }

    private function canContinueCtripCaptureWithSoftGateWarning(array $payload, array $captureGateDecision): bool
    {
        $failedCheckIds = is_array($captureGateDecision['failed_check_ids'] ?? null) ? $captureGateDecision['failed_check_ids'] : [];
        if ($failedCheckIds === [] || $this->getCtripCaptureBlockingFailedCheckIds($failedCheckIds) !== []) {
            return false;
        }

        if (!(bool)($payload['auth_status']['ok'] ?? false)) {
            return false;
        }

        $capturedCounts = $this->buildCtripCaptureCounts($payload);
        return (int)$capturedCounts['standard_rows'] > 0
            && (
                (int)$capturedCounts['business'] > 0
                || (int)$capturedCounts['traffic'] > 0
                || (int)$capturedCounts['responses'] > 0
            );
    }

    private function buildCtripCaptureGateWarning(array $captureGateDecision): array
    {
        $failedCheckIds = is_array($captureGateDecision['failed_check_ids'] ?? null) ? $captureGateDecision['failed_check_ids'] : [];
        return [
            'level' => 'warning',
            'message' => 'Ctrip browser Profile captured usable rows, but capture gate coverage has gaps. Saved captured rows and kept diagnostics for missing coverage.',
            'status' => (string)($captureGateDecision['status'] ?? 'unknown'),
            'failed_check_ids' => $failedCheckIds,
            'blocking_failed_check_ids' => $this->getCtripCaptureBlockingFailedCheckIds($failedCheckIds),
        ];
    }

    private function countMeituanPayloadSection(array $payload, string $section): int
    {
        return isset($payload[$section]) && is_array($payload[$section]) ? count($payload[$section]) : 0;
    }

    private function countCtripPayloadSection(array $payload, string $section): int
    {
        return isset($payload[$section]) && is_array($payload[$section]) ? count($payload[$section]) : 0;
    }

    private function buildCtripCaptureCounts(array $payload): array
    {
        return CtripCaptureDiagnosisService::buildCaptureCounts(
            $payload,
            count($this->extractCtripCapturedSection($payload, 'business')),
            count($this->extractCtripCapturedSection($payload, 'traffic'))
        );
    }

    private function buildCtripCaptureFactRowCountPayload(array $capturedCounts, int $savedCount, int $parsedRowCount): array
    {
        return CtripCaptureDiagnosisService::buildFactRowCountPayload($capturedCounts, $savedCount, $parsedRowCount);
    }

    private function buildCtripCaptureDiagnosisSummary(array $payload): array
    {
        return CtripCaptureDiagnosisService::buildDiagnosisSummary($payload);
    }

    private function addCtripCaptureMetricKey(array &$capturedMetrics, string $metricKey): void
    {
        CtripCaptureDiagnosisService::addMetricKey($capturedMetrics, $metricKey);
    }

    private function ctripCaptureMetricKeyFromDimension(string $dimension): string
    {
        return CtripCaptureDiagnosisService::metricKeyFromDimension($dimension);
    }

    private function ctripCaptureDiagnosisGroups(): array
    {
        return CtripCaptureDiagnosisService::diagnosisGroups();
    }

    private function ctripCaptureDiagnosisMetricLabels(): array
    {
        return CtripCaptureDiagnosisService::metricLabels();
    }

    private function readCtripCaptureCatalogHealth(): array
    {
        $root = dirname(__DIR__, 3);
        $catalog = $this->readOptionalLocalJsonFile($root . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'ctrip_capture_catalog.json');
        $auditPath = $root . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'ctrip_capture_audit_latest.json';
        $audit = $this->readOptionalLocalJsonFile($auditPath);
        if ($audit !== []) {
            $audit['_source_path'] = 'reports/ctrip_capture_audit_latest.json';
            $audit['_source_mtime'] = is_file($auditPath) ? (int)(filemtime($auditPath) ?: 0) : 0;
        }
        $diagnosisSnapshot = $this->readLatestCtripDiagnosisSnapshotForCatalogHealth($root);

        return $this->buildCtripCaptureCatalogHealth($catalog, $audit, $diagnosisSnapshot);
    }

    private function readCtripLatestCaptureDashboard(): array
    {
        $modules = [];
        $responseCount = 0;
        $standardRowCount = 0;
        $catalogFactCount = 0;
        $capturedFieldCount = 0;
        $missingFieldCount = 0;
        $missingEndpointCount = 0;
        $latestCapturedAt = '';
        $sourceFiles = [];

        foreach ($this->ctripLatestCaptureModuleDefinitions() as $definition) {
            $module = $this->buildCtripLatestCaptureModule($definition);
            $modules[] = $module;
            $responseCount += (int)($module['response_count'] ?? 0);
            $standardRowCount += (int)($module['standard_row_count'] ?? 0);
            $catalogFactCount += (int)($module['catalog_fact_count'] ?? 0);
            $capturedFieldCount += (int)($module['captured_field_count'] ?? 0);
            $missingFieldCount += (int)($module['missing_field_count'] ?? 0);
            $missingEndpointCount += (int)($module['missing_endpoint_count'] ?? 0);
            $capturedAt = (string)($module['captured_at'] ?? '');
            if ($capturedAt !== '' && strcmp($capturedAt, $latestCapturedAt) > 0) {
                $latestCapturedAt = $capturedAt;
            }
            if (!empty($module['file_found']) && !empty($module['file_path'])) {
                $sourceFiles[$module['file']] = $module['file_path'];
            }
        }

        $dashboard = [
            'available' => $modules !== [],
            'scope' => 'ctrip_ebooking_ota_channel',
            'scope_label' => '携程 eBooking OTA渠道口径',
            'source' => 'reports/ctrip_capture_target_*.json',
            'data_date' => $latestCapturedAt !== '' ? substr($latestCapturedAt, 0, 10) : '',
            'captured_at' => $latestCapturedAt,
            'module_count' => count($modules),
            'response_count' => $responseCount,
            'standard_row_count' => $standardRowCount,
            'catalog_fact_count' => $catalogFactCount,
            'captured_field_count' => $capturedFieldCount,
            'missing_field_count' => $missingFieldCount,
            'missing_endpoint_count' => $missingEndpointCount,
            'modules' => $modules,
            'source_files' => array_values(array_map(
                static fn(string $file, string $path): array => ['file' => $file, 'path' => $path],
                array_keys($sourceFiles),
                array_values($sourceFiles)
            )),
            'excluded_sections' => [
                'order_detail' => '用户已明确不需要订单明细',
                'review_list' => '用户已明确不需要点评列表',
            ],
        ];

        $dashboard['coverage_rate'] = $this->calculateCtripLatestCaptureCoverageRate(
            $capturedFieldCount,
            $missingFieldCount
        );
        $dashboard['freshness'] = $this->buildCtripLatestCaptureFreshness($latestCapturedAt);
        $dashboard['effectiveness'] = $this->buildCtripLatestCaptureEffectiveness(
            $responseCount,
            $standardRowCount,
            $catalogFactCount
        );

        $this->persistCtripLatestCaptureDashboardSummary($dashboard);

        return $dashboard;
    }

    /**
     * 获取线上数据 - 携程ebooking接口
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function ctripLatestCaptureModuleDefinitions(): array
    {
        return [
            ['section' => 'business_weekly_overview', 'label' => '经营报告-概要周报', 'file' => 'ctrip_capture_target_07h_business_weekly_quick.json'],
            ['section' => 'sales_report', 'label' => '销售数据', 'file' => 'ctrip_capture_target_01_business_sales.json'],
            ['section' => 'traffic_report', 'label' => '流量数据', 'file' => 'ctrip_capture_target_02_room_traffic.json'],
            ['section' => 'user_profile', 'label' => '用户分析', 'file' => 'ctrip_capture_target_03_user_im_psi.json'],
            ['section' => 'im_board', 'label' => 'IM看板', 'file' => 'ctrip_capture_target_07e_im_board_quick.json'],
            ['section' => 'quality_psi', 'label' => 'PSI服务质量分', 'file' => 'ctrip_capture_target_07d_quality_psi_quick.json'],
            ['section' => 'competitor_overview', 'label' => '竞争圈动态', 'file' => 'ctrip_capture_target_07b_competitor_overview_quick.json'],
            ['section' => 'loss_analysis', 'label' => '流失分析', 'file' => 'ctrip_capture_target_04b_loss_analysis.json'],
            ['section' => 'competitor_rank', 'label' => '竞争圈榜单', 'file' => 'ctrip_capture_target_04c_competitor_rank.json'],
            ['section' => 'ads_pyramid', 'label' => '金字塔数据报告', 'file' => 'ctrip_capture_target_05b_ads_fixed.json'],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function buildCtripLatestCaptureModule(array $definition): array
    {
        $section = (string)($definition['section'] ?? '');
        $file = (string)($definition['file'] ?? '');
        $path = $this->resolveCtripCaptureReportPath($file);
        $payload = $path !== '' ? $this->readOptionalLocalJsonFile($path) : [];
        $responses = $this->filterCtripCaptureItemsBySection($payload['responses'] ?? [], [$section], 'section');
        $rows = $this->filterCtripCaptureItemsBySection($payload['standard_rows'] ?? [], [$section], 'capture_section');
        $facts = $this->filterCtripCaptureItemsBySection($payload['catalog_facts'] ?? [], [$section], 'section');
        $metrics = $this->summarizeCtripCaptureMetrics($facts);
        $endpoints = $this->summarizeCtripCaptureEndpoints($responses);
        $sampleRows = $this->summarizeCtripCaptureRows($rows, 10);
        $missingFields = $this->extractCtripCaptureMissingFields($payload, [$section]);
        $missingEndpoints = $this->extractCtripCaptureMissingEndpoints($payload, [$section]);
        $gate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
        $gateStatus = strtolower(trim((string)($gate['status'] ?? 'missing')));
        $status = 'missing_file';
        if ($payload !== [] && $gateStatus === 'fail') {
            $status = 'failed';
        } elseif ($payload !== [] && count($rows) > 0) {
            $status = 'captured';
        } elseif ($payload !== [] && (count($responses) > 0 || count($facts) > 0)) {
            $status = 'needs_mapping';
        } elseif ($payload !== []) {
            $status = 'empty';
        }

        return [
            'section' => $section,
            'label' => (string)($definition['label'] ?? $this->ctripCaptureSectionLabel($section)),
            'status' => $status,
            'gate_status' => $gateStatus,
            'file' => $file,
            'file_path' => $path,
            'file_found' => $payload !== [],
            'captured_at' => (string)($payload['captured_at'] ?? ''),
            'response_count' => count($responses),
            'standard_row_count' => count($rows),
            'catalog_fact_count' => count($facts),
            'captured_field_count' => count($metrics),
            'missing_field_count' => count($missingFields),
            'missing_endpoint_count' => count($missingEndpoints),
            'captured_fields' => array_values(array_map(
                static fn(array $metric): string => (string)($metric['label'] ?? $metric['key'] ?? ''),
                $metrics
            )),
            'missing_fields' => $missingFields,
            'captured_endpoints' => array_values(array_map(
                static fn(array $endpoint): string => (string)($endpoint['id'] ?? ''),
                $endpoints
            )),
            'missing_endpoints' => $missingEndpoints,
            'metrics' => $metrics,
            'endpoints' => $endpoints,
            'sample_rows' => $sampleRows,
            'snapshot_values' => $this->buildCtripCaptureSnapshotValues($section, $metrics, $sampleRows, 12),
        ];
    }

    private function calculateCtripLatestCaptureCoverageRate(int $capturedFieldCount, int $missingFieldCount): ?float
    {
        $total = $capturedFieldCount + $missingFieldCount;
        if ($total <= 0) {
            return null;
        }

        return round(($capturedFieldCount / $total) * 100, 1);
    }

    private function buildCtripLatestCaptureFreshness(string $capturedAt): array
    {
        if ($capturedAt === '') {
            return [
                'status' => 'missing',
                'label' => '无采集时间',
                'age_hours' => null,
            ];
        }

        $timestamp = strtotime($capturedAt);
        if ($timestamp === false) {
            return [
                'status' => 'unknown',
                'label' => '时间不可解析',
                'age_hours' => null,
            ];
        }

        $ageHours = max(0, round((time() - $timestamp) / 3600, 1));
        $status = 'fresh';
        $label = '24小时内';
        if ($ageHours > 72) {
            $status = 'stale';
            $label = '超过72小时';
        } elseif ($ageHours > 24) {
            $status = 'aging';
            $label = '超过24小时';
        }

        return [
            'status' => $status,
            'label' => $label,
            'age_hours' => $ageHours,
        ];
    }

    private function buildCtripLatestCaptureEffectiveness(int $responseCount, int $standardRowCount, int $catalogFactCount): array
    {
        $status = 'missing';
        $label = '未形成快照';
        if ($standardRowCount > 0 && $catalogFactCount > 0) {
            $status = 'effective';
            $label = '已形成可分析快照';
        } elseif ($responseCount > 0) {
            $status = 'needs_mapping';
            $label = '已响应，待标准化';
        }

        return [
            'status' => $status,
            'label' => $label,
            'response_count' => $responseCount,
            'standard_row_count' => $standardRowCount,
            'catalog_fact_count' => $catalogFactCount,
        ];
    }

    private function persistCtripLatestCaptureDashboardSummary(array $dashboard): void
    {
        $summary = [
            'scope' => $dashboard['scope'] ?? 'ctrip_ebooking_ota_channel',
            'data_date' => $dashboard['data_date'] ?? '',
            'captured_at' => $dashboard['captured_at'] ?? '',
            'coverage_rate' => $dashboard['coverage_rate'] ?? null,
            'freshness' => $dashboard['freshness'] ?? [],
            'effectiveness' => $dashboard['effectiveness'] ?? [],
            'module_count' => $dashboard['module_count'] ?? 0,
            'response_count' => $dashboard['response_count'] ?? 0,
            'standard_row_count' => $dashboard['standard_row_count'] ?? 0,
            'catalog_fact_count' => $dashboard['catalog_fact_count'] ?? 0,
            'captured_field_count' => $dashboard['captured_field_count'] ?? 0,
            'missing_field_count' => $dashboard['missing_field_count'] ?? 0,
            'missing_endpoint_count' => $dashboard['missing_endpoint_count'] ?? 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        try {
            SystemConfig::setValue('ctrip_capture_snapshot_health', $json, '携程采集覆盖率与实效快照');
        } catch (\Throwable) {
            // Dashboard rendering must not be blocked by config persistence.
        }
    }

    /**
     * @param array<int, array<string, mixed>> $metrics
     * @param array<int, array<string, mixed>> $sampleRows
     * @return array<int, array<string, mixed>>
     */
    private function buildCtripCaptureSnapshotValues(string $section, array $metrics, array $sampleRows, int $limit): array
    {
        $preferred = $this->ctripCaptureSnapshotPreferredMetricKeys($section);
        $metricByKey = [];
        foreach ($metrics as $metric) {
            $key = (string)($metric['key'] ?? '');
            if ($key !== '') {
                $metricByKey[$key] = $metric;
            }
        }

        $ordered = [];
        foreach ($preferred as $key) {
            if (isset($metricByKey[$key])) {
                $ordered[] = $metricByKey[$key];
                unset($metricByKey[$key]);
            }
        }
        foreach ($metricByKey as $metric) {
            if ($this->isCtripCaptureSupportMetric((string)($metric['key'] ?? ''))) {
                continue;
            }
            $ordered[] = $metric;
        }

        $values = [];
        $seenLabels = [];
        foreach ($ordered as $metric) {
            $examples = is_array($metric['examples'] ?? null) ? array_values(array_filter(
                $metric['examples'],
                static fn($value): bool => $value !== null && $value !== ''
            )) : [];
            if ($examples === []) {
                continue;
            }
            $label = $this->normalizeCtripSnapshotMetricLabel(
                (string)($metric['key'] ?? ''),
                (string)($metric['label'] ?? $metric['key'] ?? '')
            );
            if ($label === '' || isset($seenLabels[$label])) {
                continue;
            }
            $value = $examples[0];
            $values[] = [
                'label' => $label,
                'value' => $value,
                'unit' => (string)($metric['unit'] ?? ''),
                'count' => (int)($metric['count'] ?? 0),
                'scope' => (string)($metric['metric_scope'] ?? 'ota_channel'),
            ];
            $seenLabels[$label] = true;
            if (count($values) >= $limit) {
                return $values;
            }
        }

        foreach ($sampleRows as $row) {
            $rowMetrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            foreach ($rowMetrics as $metric) {
                $key = (string)($metric['key'] ?? '');
                if ($key === '' || $this->isCtripCaptureSupportMetric($key)) {
                    continue;
                }
                $value = $metric['value'] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $label = $this->normalizeCtripSnapshotMetricLabel($key, $key);
                if ($label === '' || isset($seenLabels[$label])) {
                    continue;
                }
                $values[] = [
                    'label' => $label,
                    'value' => $value,
                    'unit' => '',
                    'count' => 1,
                    'scope' => 'ota_channel',
                ];
                $seenLabels[$label] = true;
                if (count($values) >= $limit) {
                    return $values;
                }
            }
        }

        return $values;
    }

    private function normalizeCtripSnapshotMetricLabel(string $key, string $label): string
    {
        $labelMap = [
            'amount' => '销售额排名',
            'quantity' => '间夜排名',
            'bookOrderNum' => '订单排名',
            'bookingGMVrank' => '成交金额排名',
            'bookingOrdersrank' => '订单数排名',
            'stayInRNrank' => '入住间夜排名',
            'rentalRaterank' => '出租率排名',
            'commentScore' => '点评分排名',
            'flow_rank' => '流量排名',
            'tensity' => '紧张度',
            'room_nights' => '间夜量',
            'occupancy_rate' => '出租率',
            'order_count' => '预订订单数',
            'order_amount' => '预订销售额',
            'avg_price' => '平均卖价',
            'visitor_count' => '访客量',
            'detail_visitor' => '详情页访客量',
            'list_exposure' => '列表页曝光量',
            'conversion_rate' => '成交/下单转化率',
            'flow_rate' => '流量转化率',
            'hotel_name' => '酒店名称',
            'competitor_hotel_name' => '竞品酒店名称',
            'room_type_name' => '房型名称',
            'keyword' => '搜索关键词',
        ];
        if (isset($labelMap[$key])) {
            return $labelMap[$key];
        }

        $label = trim($label);
        if ($label === '') {
            return '';
        }
        if ($label === $key && preg_match('/^[A-Za-z0-9_]+$/', $label) === 1) {
            return '';
        }

        return $label;
    }

    /**
     * @return array<int, string>
     */
    private function ctripCaptureSnapshotPreferredMetricKeys(string $section): array
    {
        return match ($section) {
            'business_overview', 'sales_report' => [
                'order_amount', 'room_nights', 'order_count', 'avg_price', 'occupancy_rate',
                'visitor_count', 'list_exposure', 'detail_visitor', 'conversion_rate', 'rank',
                'diagnosis_score', 'diagnosis_level', 'advice_text',
            ],
            'room_type' => ['room_type_name', 'order_amount', 'room_nights', 'order_count', 'avg_price', 'occupancy_rate'],
            'traffic_report' => [
                'list_exposure', 'detail_visitor', 'visitor_count', 'order_submit_user',
                'order_count', 'flow_rate', 'conversion_rate', 'keyword',
            ],
            'user_profile' => [
                'avg_user_age', 'user_age', 'user_sex', 'user_type', 'user_source', 'user_source_scope',
                'source_region', 'source_city', 'travel_time', 'booking_hour', 'hotel_star_preference',
                'price_band', 'consumption_power', 'price_sensitivity', 'booking_method', 'order_hotel_count',
                'avg_booking_days', 'booking_days',
                'avg_stay_days', 'stay_days',
                'order_preference', 'preference_frequency', 'distribution_share',
            ],
            'im_board' => [
                'session_count', 'five_min_reply_rate', 'manual_reply_rate', 'robot_resolution_rate',
                'manual_session_count', 'robot_session_count', 'im_order_conversion_rate', 'im_rank',
            ],
            'quality_psi' => [
                'psi_score', 'base_score', 'reward_score', 'deduct_score', 'task_name', 'course_title',
                'psi_basic_item_type', 'psi_basic_item_code', 'psi_basic_item_name', 'psi_basic_item_weight', 'psi_basic_item_score',
                'psi_basic_item_rank', 'psi_basic_item_score_gap', 'psi_basic_item_score_gap_unit',
                'psi_basic_item_start_date', 'psi_basic_item_end_date', 'psi_basic_item_tips',
            ],
            'competitor_overview' => [
                'order_amount', 'room_nights', 'order_count', 'avg_price', 'occupancy_rate',
                'visitor_count', 'conversion_rate', 'rank', 'comment_score_summary', 'psi_score',
            ],
            'loss_analysis' => ['loss_order_amount', 'competitor_hotel_name', 'hotel_name'],
            'competitor_rank' => [
                'amount', 'quantity', 'bookOrderNum', 'bookingGMVrank', 'bookingOrdersrank',
                'stayInRNrank', 'rentalRaterank', 'commentScore', 'flow_rank',
            ],
            'ads_pyramid' => [
                'ad_cost', 'ad_impressions', 'ad_clicks', 'ad_orders', 'ad_order_amount',
                'ad_room_nights', 'roas', 'ctr', 'cvr', 'campaign_id',
            ],
            default => [],
        };
    }

    private function isCtripCaptureSupportMetric(string $key): bool
    {
        return in_array($key, [
            'hotel_id',
            'date',
            'config_name',
            'config_value',
            'course_url',
            'task_action',
        ], true);
    }

    private function ctripCaptureSectionLabel(string $section): string
    {
        return match ($section) {
            'business_overview' => '经营报告-概要日报/周报',
            'sales_report' => '销售数据',
            'room_type' => '销售数据-房型',
            'traffic_report' => '流量数据',
            'competitor_overview' => '竞争圈动态',
            'ads_pyramid' => '金字塔广告/诊断',
            'quality_psi' => 'PSI服务质量分',
            'im_board' => 'IM看板',
            'loss_analysis' => '流失分析',
            'user_profile' => '用户分析',
            'competitor_rank' => '竞争圈榜单',
            default => $section,
        };
    }

    private function resolveCtripCaptureReportPath(string $file): string
    {
        if ($file === '') {
            return '';
        }
        $root = dirname(__DIR__, 3);
        foreach ([
            $root . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . $file,
            dirname($root) . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . $file,
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * @param mixed $items
     * @param array<int, string> $sections
     * @return array<int, array<string, mixed>>
     */
    private function filterCtripCaptureItemsBySection(mixed $items, array $sections, string $sectionKey): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static function ($item) use ($sections, $sectionKey): bool {
            return is_array($item) && in_array((string)($item[$sectionKey] ?? ''), $sections, true);
        }));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $sections
     * @return array<int, string>
     */
    private function extractCtripCaptureMissingFields(array $payload, array $sections): array
    {
        $gap = is_array($payload['capture_gap_report'] ?? null) ? $payload['capture_gap_report'] : [];
        $missingBySection = is_array($gap['missing_fields_by_section'] ?? null) ? $gap['missing_fields_by_section'] : [];
        $fields = [];
        foreach ($sections as $section) {
            $sectionGap = is_array($missingBySection[$section] ?? null) ? $missingBySection[$section] : [];
            foreach ($this->normalizeStringList($sectionGap['missing_field_ids'] ?? []) as $fieldId) {
                $fields[$fieldId] = $fieldId;
            }
        }

        return array_values($fields);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $sections
     * @return array<int, array<string, mixed>>
     */
    private function extractCtripCaptureMissingEndpoints(array $payload, array $sections): array
    {
        $gap = is_array($payload['capture_gap_report'] ?? null) ? $payload['capture_gap_report'] : [];
        $endpoints = is_array($gap['missing_formal_endpoints'] ?? null) ? $gap['missing_formal_endpoints'] : [];
        $filtered = [];
        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            $section = (string)($endpoint['section'] ?? '');
            if (!in_array($section, $sections, true)) {
                continue;
            }
            $filtered[] = [
                'section' => $section,
                'id' => (string)($endpoint['id'] ?? ''),
                'label' => (string)($endpoint['label'] ?? ''),
                'status' => (string)($endpoint['status'] ?? ''),
            ];
        }

        return $filtered;
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, array<string, mixed>>
     */
    private function summarizeCtripCaptureMetrics(array $facts): array
    {
        $metrics = [];
        foreach ($facts as $fact) {
            $key = trim((string)($fact['metric_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!isset($metrics[$key])) {
                $metrics[$key] = [
                    'key' => $key,
                    'label' => (string)($fact['metric_label'] ?? $key),
                    'unit' => (string)($fact['unit'] ?? ''),
                    'metric_scope' => (string)($fact['metric_scope'] ?? 'ota_channel'),
                    'count' => 0,
                    'examples' => [],
                    'source_paths' => [],
                ];
            }
            $metrics[$key]['count']++;
            $value = $this->compactCtripCaptureValue($fact['value'] ?? null);
            if ($value !== null && $value !== '' && count($metrics[$key]['examples']) < 3) {
                $metrics[$key]['examples'][] = $value;
            }
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            if ($sourcePath !== '' && !in_array($sourcePath, $metrics[$key]['source_paths'], true) && count($metrics[$key]['source_paths']) < 3) {
                $metrics[$key]['source_paths'][] = $sourcePath;
            }
        }

        return array_values($metrics);
    }

    /**
     * @param array<int, array<string, mixed>> $responses
     * @return array<int, array<string, mixed>>
     */
    private function summarizeCtripCaptureEndpoints(array $responses): array
    {
        $endpoints = [];
        foreach ($responses as $response) {
            $id = trim((string)($response['endpoint_id'] ?? ''));
            if ($id === '') {
                $id = 'page_or_supporting_response';
            }
            if (!isset($endpoints[$id])) {
                $endpoints[$id] = [
                    'id' => $id,
                    'label' => (string)($response['endpoint_label'] ?? ''),
                    'section' => (string)($response['section'] ?? ''),
                    'count' => 0,
                    'standard_row_count' => 0,
                    'catalog_fact_count' => 0,
                    'url' => (string)($response['url'] ?? ''),
                ];
            }
            $endpoints[$id]['count']++;
            $endpoints[$id]['standard_row_count'] += (int)($response['standard_row_count'] ?? 0);
            $endpoints[$id]['catalog_fact_count'] += (int)($response['catalog_fact_count'] ?? 0);
        }

        return array_values($endpoints);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function summarizeCtripCaptureRows(array $rows, int $limit): array
    {
        $summaryRows = [];
        foreach (array_slice($rows, 0, max(0, $limit)) as $row) {
            $rawData = is_array($row['raw_data'] ?? null) ? $row['raw_data'] : [];
            $metrics = is_array($rawData['metrics'] ?? null) ? $rawData['metrics'] : [];
            $summaryRows[] = [
                'hotel_id' => (string)($row['hotel_id'] ?? ''),
                'hotel_name' => (string)($row['hotel_name'] ?? ''),
                'data_date' => (string)($row['data_date'] ?? ''),
                'data_type' => (string)($row['data_type'] ?? ''),
                'section' => (string)($row['capture_section'] ?? ''),
                'endpoint_id' => (string)($row['endpoint_id'] ?? ''),
                'dimension' => (string)($row['dimension'] ?? ''),
                'metrics' => $this->compactCtripCaptureMetricMap($metrics),
            ];
        }

        return $summaryRows;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<int, array<string, mixed>>
     */
    private function compactCtripCaptureMetricMap(array $metrics): array
    {
        $items = [];
        foreach ($metrics as $key => $value) {
            $items[] = [
                'key' => (string)$key,
                'value' => $this->compactCtripCaptureValue($value),
            ];
            if (count($items) >= 10) {
                break;
            }
        }

        return $items;
    }

    private function compactCtripCaptureValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($text)) {
            return '';
        }

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '...' : $text;
    }

    private function readOptionalLocalJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string)file_get_contents($path), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function readLatestCtripDiagnosisSnapshotForCatalogHealth(string $root): array
    {
        $candidates = [];
        foreach ([
            $root . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'ctrip_diagnosis_snapshot.json',
            $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'ctrip_diagnosis_snapshot.json',
            dirname($root) . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'ctrip_diagnosis_snapshot.json',
        ] as $path) {
            $snapshot = $this->readOptionalLocalJsonFile($path);
            if (!$this->isEffectiveCtripDiagnosisSnapshot($snapshot)) {
                continue;
            }
            $snapshot['_source_path'] = $this->relativeCtripEvidencePath($path, $root);
            $snapshot['_source_mtime'] = is_file($path) ? (int)(filemtime($path) ?: 0) : 0;
            $candidates[] = $snapshot;
        }

        $runtimeSnapshot = $this->buildLatestCtripDiagnosisSnapshot();
        if ($this->isEffectiveCtripDiagnosisSnapshot($runtimeSnapshot)) {
            $snapshotPath = (string)($runtimeSnapshot['snapshot_path'] ?? '');
            $runtimeSnapshot['_source_path'] = $snapshotPath !== ''
                ? $this->relativeCtripEvidencePath($snapshotPath, $root)
                : 'runtime/ctrip_capture';
            $runtimeSnapshot['_source_mtime'] = $snapshotPath !== '' && is_file($snapshotPath)
                ? (int)(filemtime($snapshotPath) ?: 0)
                : 0;
            $candidates[] = $runtimeSnapshot;
        }

        if ($candidates === []) {
            return [];
        }

        usort(
            $candidates,
            fn(array $a, array $b): int => $this->ctripDiagnosisSnapshotTimestamp($b) <=> $this->ctripDiagnosisSnapshotTimestamp($a)
        );

        return $candidates[0];
    }

    private function relativeCtripEvidencePath(string $path, string $root): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedParent = rtrim(str_replace('\\', '/', dirname($root)), '/');
        if (str_starts_with(strtolower($normalizedPath), strtolower($normalizedRoot . '/'))) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }
        if (str_starts_with(strtolower($normalizedPath), strtolower($normalizedParent . '/'))) {
            return '../' . substr($normalizedPath, strlen($normalizedParent) + 1);
        }

        return $path;
    }

    private function ctripDiagnosisSnapshotTimestamp(array $snapshot): int
    {
        foreach (['generated_at', 'captured_at', 'snapshot_time', 'updated_at'] as $key) {
            $value = trim((string)($snapshot[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return (int)($snapshot['_source_mtime'] ?? 0);
    }

    private function ctripDiagnosisSnapshotStatus(array $snapshot): string
    {
        $summary = is_array($snapshot['diagnosis_summary'] ?? null) ? $snapshot['diagnosis_summary'] : [];
        $status = strtolower(trim((string)($snapshot['status'] ?? $summary['status'] ?? '')));
        return $status !== '' ? $status : 'unknown';
    }

    private function isEffectiveCtripDiagnosisSnapshot(array $snapshot): bool
    {
        if ($snapshot === []) {
            return false;
        }

        $status = $this->ctripDiagnosisSnapshotStatus($snapshot);
        $summary = is_array($snapshot['diagnosis_summary'] ?? null) ? $snapshot['diagnosis_summary'] : [];
        $counts = is_array($snapshot['counts'] ?? null) ? $snapshot['counts'] : [];
        $availableGroups = $this->normalizeStringList($snapshot['available_groups'] ?? $summary['available_groups'] ?? []);
        $standardRows = (int)($counts['standard_rows'] ?? 0);
        $hasEvidence = $availableGroups !== []
            || (int)($counts['responses'] ?? 0) > 0
            || (int)($counts['catalog_facts'] ?? 0) > 0;

        return $standardRows > 0
            && $hasEvidence
            && (
                in_array($status, ['ready', 'success', 'effective', 'ok'], true)
                || $availableGroups !== []
            );
    }

    private function compactCtripDiagnosisSnapshotForHealth(array $snapshot): array
    {
        $summary = is_array($snapshot['diagnosis_summary'] ?? null) ? $snapshot['diagnosis_summary'] : [];
        $counts = is_array($snapshot['counts'] ?? null) ? $snapshot['counts'] : [];

        return [
            'available' => true,
            'source' => (string)($snapshot['source'] ?? 'diagnosis_snapshot'),
            'status' => $this->ctripDiagnosisSnapshotStatus($snapshot),
            'generated_at' => (string)($snapshot['generated_at'] ?? ''),
            'source_path' => (string)($snapshot['_source_path'] ?? $snapshot['snapshot_path'] ?? ''),
            'snapshot_path' => (string)($snapshot['snapshot_path'] ?? $snapshot['_source_path'] ?? ''),
            'counts' => [
                'responses' => (int)($counts['responses'] ?? 0),
                'catalog_facts' => (int)($counts['catalog_facts'] ?? 0),
                'standard_rows' => (int)($counts['standard_rows'] ?? 0),
            ],
            'available_groups' => $this->normalizeStringList($snapshot['available_groups'] ?? $summary['available_groups'] ?? []),
            'missing_groups' => $this->normalizeStringList($snapshot['missing_groups'] ?? $summary['missing_groups'] ?? []),
            'inputs' => array_slice(is_array($snapshot['inputs'] ?? null) ? $snapshot['inputs'] : [], 0, 8),
        ];
    }

    private function buildCtripCaptureCatalogHealth(array $catalog, array $audit, array $diagnosisSnapshot = []): array
    {
        $available = $catalog !== [];
        $gate = is_array($audit['capture_gate'] ?? null) ? $audit['capture_gate'] : [];
        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];
        $auth = is_array($audit['auth_status'] ?? null) ? $audit['auth_status'] : [];
        $fieldCoverage = is_array($audit['field_coverage'] ?? null) ? $audit['field_coverage'] : [];
        $fieldCoverageSummary = is_array($fieldCoverage['summary'] ?? null) ? $fieldCoverage['summary'] : $fieldCoverage;
        $gapReport = is_array($audit['capture_gap_report'] ?? null) ? $audit['capture_gap_report'] : [];

        $captureGateStatus = strtolower(trim((string)($gate['status'] ?? '')));
        if ($captureGateStatus === '') {
            $captureGateStatus = $available ? 'missing' : 'missing';
        }

        $failedCheckIds = $this->normalizeStringList($gate['failed_check_ids'] ?? []);
        $authStatus = trim((string)($auth['status'] ?? 'unknown'));
        $responseCount = (int)($summary['response_count'] ?? 0);
        $standardRowCount = (int)($summary['standard_row_count'] ?? 0);
        $coverageRate = $fieldCoverageSummary['coverage_rate'] ?? null;
        $gapStatus = strtolower(trim((string)($gapReport['status'] ?? '')));
        if ($gapStatus === '') {
            $gapStatus = $gapReport !== [] ? 'unknown' : 'missing';
        }
        $missingFormalEndpoints = is_array($gapReport['missing_formal_endpoints'] ?? null) ? $gapReport['missing_formal_endpoints'] : [];
        $missingFormalEndpointCount = is_numeric($gapReport['missing_formal_endpoint_count'] ?? null)
            ? max(0, (int)$gapReport['missing_formal_endpoint_count'])
            : count($missingFormalEndpoints);
        $missingFieldsBySection = is_array($gapReport['missing_fields_by_section'] ?? null) ? $gapReport['missing_fields_by_section'] : [];
        $missingFieldCount = 0;
        foreach ($missingFieldsBySection as $section) {
            if (!is_array($section)) {
                continue;
            }
            $missingFieldCount += max(0, (int)($section['missing_field_count'] ?? 0));
        }
        $p3CandidateSections = is_array($gapReport['p3_candidate_sections'] ?? null) ? $gapReport['p3_candidate_sections'] : [];
        $p3EvidenceSections = is_array($gapReport['p3_evidence_sections'] ?? null) ? $gapReport['p3_evidence_sections'] : [];
        $gapNextActions = $this->normalizeCtripCaptureGapActions($gapReport['next_actions'] ?? []);
        $gapBlockers = $this->normalizeStringList($gapReport['blockers'] ?? []);
        $auditEvidence = [
            'source_path' => (string)($audit['_source_path'] ?? 'reports/ctrip_capture_audit_latest.json'),
            'auth_status' => $authStatus !== '' ? $authStatus : 'unknown',
            'capture_gate_status' => $captureGateStatus,
            'failed_check_ids' => $failedCheckIds,
            'capture_gap_status' => $gapStatus,
            'capture_gap_blockers' => $gapBlockers,
            'summary' => [
                'response_count' => $responseCount,
                'standard_row_count' => $standardRowCount,
            ],
        ];
        $snapshotReady = $this->isEffectiveCtripDiagnosisSnapshot($diagnosisSnapshot);
        $snapshotEvidence = $snapshotReady ? $this->compactCtripDiagnosisSnapshotForHealth($diagnosisSnapshot) : [];
        if ($snapshotReady) {
            $snapshotCounts = is_array($snapshotEvidence['counts'] ?? null) ? $snapshotEvidence['counts'] : [];
            $responseCount = max($responseCount, (int)($snapshotCounts['responses'] ?? 0));
            $standardRowCount = max($standardRowCount, (int)($snapshotCounts['standard_rows'] ?? 0));
            $captureGateStatus = 'pass';
            $failedCheckIds = [];
            $authStatus = 'snapshot_ready';
            $gapStatus = 'snapshot_ready';
            $gapBlockers = [];
            $gapNextActions = [];
        }
        $isLiveReady = $available
            && ($snapshotReady || in_array($captureGateStatus, ['pass', 'ok', 'success'], true))
            && !in_array($authStatus, ['login_required', 'unknown', ''], true)
            && $responseCount > 0
            && $standardRowCount > 0;

        $message = '携程采集目录未生成，请先生成目录后再进入抓取健康判断。';
        if ($available && $snapshotReady) {
            $message = 'Ctrip diagnosis snapshot is ready; stale capture audit is retained as audit_evidence.';
        } elseif ($available && $isLiveReady) {
            $message = '携程采集目录和真实采集审计均已通过，可进入标准行入库。';
        } elseif ($available && $captureGateStatus === 'fail') {
            $message = '携程真实采集未通过：' . ($failedCheckIds !== [] ? implode('、', $failedCheckIds) : 'capture_gate');
        } elseif ($available) {
            $message = '携程采集目录已生成，真实采集审计缺失或未通过。';
        }

        return [
            'available' => $available,
            'platform' => (string)($catalog['platform'] ?? 'ctrip'),
            'section_count' => (int)($catalog['section_count'] ?? 0),
            'endpoint_count' => (int)($catalog['endpoint_count'] ?? 0),
            'field_count' => (int)($catalog['field_count'] ?? 0),
            'default_sections' => $this->normalizeStringList($catalog['default_sections'] ?? []),
            'core_sections' => $this->extractCtripCapturePresetSections($catalog, 'core'),
            'wide_sections' => $this->extractCtripCapturePresetSections($catalog, 'wide'),
            'interaction_plan_section_count' => (int)($catalog['interaction_plan_section_count'] ?? 0),
            'interaction_plan_step_count' => (int)($catalog['interaction_plan_step_count'] ?? 0),
            'capture_gate_status' => $captureGateStatus,
            'failed_check_ids' => $failedCheckIds,
            'auth_status' => $authStatus !== '' ? $authStatus : 'unknown',
            'response_count' => $responseCount,
            'standard_row_count' => $standardRowCount,
            'coverage_rate' => is_numeric($coverageRate) ? (float)$coverageRate : null,
            'is_live_capture_ready' => $isLiveReady,
            'capture_gap_status' => $gapStatus,
            'capture_gap_blockers' => $gapBlockers,
            'capture_gap_missing_formal_endpoint_count' => $missingFormalEndpointCount,
            'capture_gap_missing_field_section_count' => count($missingFieldsBySection),
            'capture_gap_missing_field_count' => $missingFieldCount,
            'capture_gap_p3_candidate_section_count' => count($p3CandidateSections),
            'capture_gap_p3_evidence_section_count' => count($p3EvidenceSections),
            'capture_gap_next_actions' => $gapNextActions,
            'diagnosis_snapshot_ready' => $snapshotReady,
            'diagnosis_snapshot' => $snapshotEvidence,
            'audit_evidence' => $auditEvidence,
            'capture_gate_status_source' => $snapshotReady ? 'diagnosis_snapshot' : 'capture_audit',
            'message' => $message,
        ];
    }

    private function extractCtripCapturePresetSections(array $catalog, string $preset): array
    {
        $presets = is_array($catalog['presets'] ?? null) ? $catalog['presets'] : [];
        $value = $presets[$preset] ?? [];
        if (is_array($value) && is_array($value['sections'] ?? null)) {
            $value = $value['sections'];
        }

        return $this->normalizeStringList($value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCtripCaptureGapActions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $actions = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $action = trim($item);
                if ($action !== '') {
                    $actions[] = [
                        'action' => $action,
                        'reason' => '',
                        'section' => '',
                        'endpoint_id' => '',
                        'candidate_section' => '',
                        'required_evidence' => [],
                    ];
                }
            } elseif (is_array($item)) {
                $action = trim((string)($item['action'] ?? ''));
                $reason = trim((string)($item['reason'] ?? ''));
                $section = trim((string)($item['section'] ?? ''));
                $endpointId = trim((string)($item['endpoint_id'] ?? ''));
                $candidateSection = trim((string)($item['candidate_section'] ?? ''));
                if ($action === '' && $reason === '' && $section === '' && $endpointId === '' && $candidateSection === '') {
                    continue;
                }
                $actions[] = [
                    'action' => $action,
                    'reason' => $reason,
                    'section' => $section,
                    'endpoint_id' => $endpointId,
                    'candidate_section' => $candidateSection,
                    'required_evidence' => $this->normalizeStringList($item['required_evidence'] ?? []),
                ];
            }

            if (count($actions) >= 12) {
                break;
            }
        }

        return $actions;
    }

}
