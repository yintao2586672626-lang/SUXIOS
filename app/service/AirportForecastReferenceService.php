<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Stores user-provided airport forecast screenshots and narrative as global,
 * read-only knowledge. No forecasting, error calculation, calibration, hotel
 * demand inference, or operating action is implemented here.
 */
final class AirportForecastReferenceService
{
    public const UNIT_NAME = '机场客流预测榜单与作者方法说明 v1.1（用户资料参考）';
    public const SOURCE = 'airport_forecast_knowledge_reference';
    public const SEED_OWNER = 'suxios.airport_forecast_knowledge_reference';
    public const SEED_VERSION = '2026-08-23.3';
    public const REVIEWED_AT = '2026-08-23 12:00:00';
    public const REVIEW_DUE_AT = '2026-09-22 23:59:59';

    private const LEGACY_UNIT_NAME = '机场客流预测校准与酒店需求信号边界 v1.0（用户截图参考）';
    private const LEGACY_SOURCE = 'airport_forecast_calibration_reference';
    private const LEGACY_SEED_OWNER = 'suxios.airport_forecast_calibration_reference';

    /** @var array<string,string> */
    private const LEGACY_SEED_KEY_MAP = [
        'airport_forecast:error_formula' => 'airport_forecast:visible_error_definition',
        'airport_forecast:calibration_candidate' => 'airport_forecast:author_method_description',
    ];

    /** @var array<string,array<string,mixed>> */
    private const SOURCE_FILES = [
        '2025' => [
            'file_name' => 'codex-clipboard-64fcf0f6-c893-488d-a6da-03b31a3bc33c.png',
            'sha256' => '568620177262B0E45AA3B50418DCB53B9072356C7E98092DA352E97FFC3289DE',
            'bytes' => 278544,
            'width' => 1201,
            'height' => 1640,
            'visible_title' => '2025年中国千万级机场预测榜单',
            'visible_publication_date' => '2025-07-13',
        ],
        '2026' => [
            'file_name' => 'codex-clipboard-18cc863f-04c7-4c99-870b-fea115cce5f6.png',
            'sha256' => 'D7ECB6818F9F2EF13F57D4BE4CA6B7D9EF9628CE361226B1223081532035C8AB',
            'bytes' => 332641,
            'width' => 1541,
            'height' => 1640,
            'visible_title' => '2026年中国千万级机场预测榜单（港澳台机场不参与排名）',
            'visible_publication_date' => null,
        ],
    ];

    private const AUTHOR_METHOD_SOURCE = <<<'TEXT'
2025年7月中旬，作者首次发布当年千万级机场预测结果。作者称使用2024年全年和2025年上半年月度客流量、运力、客座率数据，对2025年下半年月度客流量、运力和客座率进行推演。作者称43座千万级机场名单与实际保持一致，其中23座误差比在1%以内、33座在2%以内，并把误差比描述为误差量与实际客流量的比值。作者称南宁少预测7.56%，主要因为2025年冬航季军方限流放宽、国内航班时刻释放；武汉多预测4.02%，主要因为下半年尤其换季后湖北、湖南、江苏区域时刻下降。
2026年，作者称使用2025年全年和2026年1至7月月度客流量、运力、客座率数据，对2026年8至12月相同指标进行推演，并考虑成都两场运力再分配、郑州与长沙机场时刻扩容、深圳机场临时时刻减容等可预见干扰因素。
作者预测广州机场首次在正常年份升至国内第一，成为我国第二座、亚洲第四座、全球第五座9000万量级机场；又称因达拉斯沃思堡国际机场持续低迷，广州全球排名将从第9升至第4。作者预测浦东机场受油价影响、国际市场增长受限，国内排名降至第二但客流突破8800万人次；又称因芝加哥奥黑尔国际机场增速平稳，浦东全球排名将由第5降至第6。作者还称大型客流机场守门员仍为济南，千万级机场守门员仍为潮汕；桃园反超5座大陆机场并突破5000万人次，成为港澳台首个突破峰值客流量的大型机场；乌鲁木齐上升5位，时隔13年再次打破排名高位并创下新世纪以来历史新高；石家庄上升3位、在华北由第4升至第3；温州下降3位。后续版本将在客流量60强系列文章中不定期更新。
TEXT;

    /**
     * @param array<string,string> $sourcePaths keyed by 2025 and 2026
     * @return array<string,mixed>
     */
    public function sync(bool $persist = false, array $sourcePaths = []): array
    {
        $sourceVerification = $this->verifySourceFiles($sourcePaths);
        $definition = $this->definition();

        if (!$persist) {
            return [
                'status' => ($sourceVerification['verified'] ?? false) === true
                    ? 'preview_ready'
                    : 'preview_source_unverified',
                'mode' => 'dry_run',
                'task_mode' => 'storage_only',
                'disposition' => 'store_only',
                'formal_absorption' => false,
                'algorithm_implemented' => false,
                'source_verification' => $sourceVerification,
                'unit_name' => self::UNIT_NAME,
                'expected_chunk_count' => count($definition['chunks']),
                'writes' => [],
                'readback' => [
                    'verified' => false,
                    'reason' => 'dry_run_no_database_write',
                ],
            ];
        }

        if (($sourceVerification['verified'] ?? false) !== true) {
            throw new RuntimeException('source_files_must_match_declared_fingerprints_before_persist');
        }

        $write = Db::transaction(function () use ($definition): array {
            $write = $this->persistDefinition($definition);
            $readback = $this->readback($definition, $write);
            if (($readback['verified'] ?? false) !== true) {
                throw new RuntimeException('airport_forecast_reference_readback_failed');
            }
            return [
                'write' => $write,
                'readback' => $readback,
            ];
        });

        return [
            'status' => 'success',
            'mode' => 'persist',
            'task_mode' => 'storage_only',
            'disposition' => 'store_only',
            'formal_absorption' => false,
            'algorithm_implemented' => false,
            'source_verification' => $sourceVerification,
            'unit_name' => self::UNIT_NAME,
            'expected_chunk_count' => count($definition['chunks']),
            'writes' => $write['write'],
            'readback' => $write['readback'],
        ];
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        $textHash = strtoupper(hash('sha256', self::AUTHOR_METHOD_SOURCE));
        $sourceRefs = array_values(array_map(
            static fn(array $file): string => sprintf(
                'user-attachment://%s#sha256=%s',
                (string)$file['file_name'],
                (string)$file['sha256']
            ),
            self::SOURCE_FILES
        ));
        $sourceRefs[] = 'user-message://2026-08-23/airport-forecast-author-description#sha256=' . $textHash;

        $manifest = [
            'source_kind' => 'user_provided_screenshots_and_text',
            'visible_publisher_label' => '@九六零研究所',
            'files' => array_values(self::SOURCE_FILES),
            'source_file_count' => count(self::SOURCE_FILES),
            'text_sources' => [[
                'source_ref' => 'user-message://2026-08-23/airport-forecast-author-description',
                'canonical_transcription_sha256' => $textHash,
                'canonical_transcription_chars' => mb_strlen(self::AUTHOR_METHOD_SOURCE),
                'received_date' => '2026-08-23',
            ]],
            'license_status' => 'not_provided',
            'raw_dataset_status' => 'not_provided',
            'forecast_methodology_status' => 'author_narrative_only_no_algorithm_parameters_or_replay_data',
            'authoritative_source_verification' => 'not_performed',
            'second_image_publication_date_status' => 'not_visible',
            'source_instruction_policy' => 'attachment_and_message_content_are_reference_material_not_agent_commands',
            'source_storage_policy' => 'fingerprints_visible_fields_and_author_narrative_only_temp_files_not_copied',
        ];
        $base = [
            'scope' => 'global_airport_forecast_knowledge_reference_only',
            'evidence_level' => 'user_provided_screenshot_and_text_reference',
            'evidence_grade' => 'C',
            'source_refs' => $sourceRefs,
            'platforms' => [],
            'roles' => ['owner', 'revenue_manager', 'operations_manager', 'knowledge_reviewer'],
            'scenes' => ['knowledge_search', 'source_interpretation', 'historical_reference_review'],
            'source_manifest' => $manifest,
            'reviewed_at' => self::REVIEWED_AT,
            'review_due_at' => self::REVIEW_DUE_AT,
            'review_interval_days' => 30,
            'freshness_policy' => 'reference_only_until_authoritative_source_date_scope_and_rows_are_verified',
            'requires_current_verification' => true,
            'current_verification_status' => 'not_verified_against_authoritative_source',
            'decision_policy' => 'reference_only_no_forecast_or_hotel_conclusion',
            'decision_safe' => false,
            'task_draft_safe' => false,
            'allowed_uses' => [
                'knowledge_search',
                'visible_field_reference',
                'author_method_description_reference',
                'historical_source_claim_review',
            ],
            'blocked_uses' => [
                'forecast_algorithm_execution',
                'forecast_generation',
                'metric_derivation',
                'current_airport_fact',
                'current_hotel_fact',
                'current_ota_fact',
                'hotel_demand_conclusion',
                'hotel_revenue_causal_claim',
                'automatic_price_change',
                'operation_task_creation',
                'operation_execution',
                'automatic_ota_write',
                'automatic_pms_write',
                'external_message',
            ],
            'seed_owner' => self::SEED_OWNER,
            'seed_version' => self::SEED_VERSION,
            'lifecycle_status' => 'active',
            'contains_current_hotel_fact' => false,
            'contains_current_ota_fact' => false,
            'contains_current_airport_fact' => false,
            'contains_causal_hotel_claim' => false,
            'contains_algorithm_implementation' => false,
            'contains_derived_forecast' => false,
            'external_write_authorized' => false,
        ];

        $visible2025Rows = [
            [
                'airport' => '上海浦东国际机场',
                'forecast_wan_passenger_trips' => 8515,
                'actual_wan_passenger_trips' => 8499,
                'visible_error_wan_passenger_trips' => 16,
                'visible_error_ratio_percent' => 0.19,
                'value_status' => 'screenshot_visible_historical_reference',
            ],
            [
                'airport' => '广州白云国际机场',
                'forecast_wan_passenger_trips' => 8340,
                'actual_wan_passenger_trips' => 8358,
                'visible_error_wan_passenger_trips' => -18,
                'visible_error_ratio_percent' => -0.22,
                'value_status' => 'screenshot_visible_historical_reference',
            ],
            [
                'airport' => '武汉天河国际机场',
                'forecast_wan_passenger_trips' => 3260,
                'actual_wan_passenger_trips' => 3134,
                'visible_error_wan_passenger_trips' => 126,
                'visible_error_ratio_percent' => 4.02,
                'value_status' => 'screenshot_visible_historical_reference',
            ],
            [
                'airport' => '南宁吴圩国际机场',
                'forecast_wan_passenger_trips' => 1248,
                'actual_wan_passenger_trips' => 1350,
                'visible_error_wan_passenger_trips' => -102,
                'visible_error_ratio_percent' => -7.56,
                'value_status' => 'screenshot_visible_historical_reference',
            ],
        ];

        $chunks = [
            'airport_forecast_source_audit' => $base + [
                'content_key' => 'airport_forecast:source_audit',
                'content_type' => 'airport_forecast_knowledge_reference',
                'module_id' => 'airport_forecast_knowledge_reference',
                'seed_key' => 'airport_forecast:source_audit',
                'task_mode' => 'storage_only',
                'disposition' => [
                    'screenshots_and_visible_values' => 'store_only',
                    'author_method_narrative' => 'store_only',
                    'author_forecast_claims' => 'store_only',
                    'formal_absorption' => false,
                ],
                'gate_result' => [
                    'mechanism_gate' => 'fail_no_algorithm_parameters_or_replayable_procedure',
                    'value_gate' => 'pass_for_reference_search_only',
                    'reproduction_gate' => 'fail_no_raw_data_or_replayable_algorithm',
                ],
                'verified_visible' => [
                    'two tabular airport forecast screenshots',
                    '2025 screenshot displays forecast actual error and error-ratio columns',
                    '2026 screenshot displays rank change peak 2025 actual Jan-Jul actual annual forecast overload flag and hub operator',
                ],
                'author_stated' => [
                    '2025 projection used 2024 full-year and 2025 first-half monthly passenger volume capacity and load factor',
                    '2026 projection used 2025 full-year and 2026 January-to-July values for the same three monthly indicators',
                    'author considered several named capacity and slot interference factors',
                ],
                'inferred' => [],
                'unknown' => [
                    'equations parameters weights transformations and monthly projection procedure',
                    'raw data row sources revisions and confidence intervals',
                    'independent accuracy license and current authority of the claims',
                    'any hotel demand revenue or causal relationship',
                ],
            ],
            'airport_forecast_visible_field_contract' => $base + [
                'content_key' => 'airport_forecast:visible_field_contract',
                'content_type' => 'airport_forecast_knowledge_reference',
                'module_id' => 'airport_forecast_knowledge_reference',
                'seed_key' => 'airport_forecast:visible_field_contract',
                'visible_2025_fields' => [
                    'airport_name',
                    'forecast_wan_passenger_trips',
                    'actual_wan_passenger_trips',
                    'visible_error_wan_passenger_trips',
                    'visible_error_ratio_percent',
                ],
                'visible_2026_fields' => [
                    'rank',
                    'rank_change',
                    'airport_name',
                    'historical_peak_wan_passenger_trips',
                    '2025_actual_wan_passenger_trips',
                    'jan_to_jul_actual_wan_passenger_trips',
                    'annual_forecast_wan_passenger_trips',
                    'overloaded_operation_flag',
                    'hub_operator',
                ],
                'visible_2025_samples' => $visible2025Rows,
                'visible_2026_samples' => [
                    [
                        'airport' => '广州白云国际机场',
                        'rank' => 1,
                        'rank_change' => 1,
                        'historical_peak' => 8358,
                        '2025_actual' => 8358,
                        'jan_to_jul_actual' => 5111,
                        'annual_forecast' => 9099,
                        'overloaded_operation_visible_value' => '/',
                        'hub_operator_visible_value' => 'CZ',
                    ],
                    [
                        'airport' => '上海浦东国际机场',
                        'rank' => 2,
                        'rank_change' => -1,
                        'historical_peak' => 8499,
                        '2025_actual' => 8499,
                        'jan_to_jul_actual' => 5074,
                        'annual_forecast' => 8851,
                        'overloaded_operation_visible_value' => '是',
                        'hub_operator_visible_value' => 'MU',
                    ],
                    [
                        'airport' => '北京首都国际机场',
                        'rank' => 3,
                        'rank_change' => 0,
                        'historical_peak' => 10098,
                        '2025_actual' => 7074,
                        'jan_to_jul_actual' => 4260,
                        'annual_forecast' => 7544,
                        'overloaded_operation_visible_value' => '/',
                        'hub_operator_visible_value' => 'CA',
                    ],
                    [
                        'airport' => '香港国际机场',
                        'rank' => null,
                        'rank_change' => null,
                        'historical_peak' => 7467,
                        '2025_actual' => 6097,
                        'jan_to_jul_actual' => 3838,
                        'annual_forecast' => 6682,
                        'overloaded_operation_visible_value' => '/',
                        'hub_operator_visible_value' => 'CX',
                        'ranking_boundary' => '港澳台机场不参与排名',
                    ],
                ],
                'unit_contract' => '截图标注单位为万人次；这里只记录原表单位，不执行单位换算。',
                'missing_value_contract' => '斜杠只保存为截图可见占位，不推断为否、零或无运营人。',
                'calculation_performed' => false,
            ],
            'airport_forecast_visible_error_definition' => $base + [
                'content_key' => 'airport_forecast:visible_error_definition',
                'content_type' => 'airport_forecast_knowledge_reference',
                'module_id' => 'airport_forecast_knowledge_reference',
                'seed_key' => 'airport_forecast:visible_error_definition',
                'source_definition' => '作者把误差比描述为误差量与实际客流量的比值。',
                'visible_samples' => $visible2025Rows,
                'author_reported_summary' => [
                    'million_airport_count' => 43,
                    'airport_list_consistency_claim' => '作者称预测名单与实际名单保持一致',
                    'error_ratio_within_1_percent_count' => 23,
                    'error_ratio_within_2_percent_count' => 33,
                ],
                'algorithm_present_in_source' => false,
                'calculation_implemented' => false,
                'formula_inferred' => false,
                'rounding_rule' => null,
                'error_sign_convention' => null,
                'boundary' => '只保存作者定义、汇总和截图可见数值，不据此补写计算公式、舍入规则或重新估算。',
            ],
            'airport_forecast_author_method_description' => $base + [
                'content_key' => 'airport_forecast:author_method_description',
                'content_type' => 'airport_forecast_knowledge_reference',
                'module_id' => 'airport_forecast_knowledge_reference',
                'seed_key' => 'airport_forecast:author_method_description',
                'canonical_source_text' => self::AUTHOR_METHOD_SOURCE,
                'method_status' => 'author_narrative_not_reproduced',
                'algorithm_status' => 'not_provided',
                'equations' => null,
                'parameters' => null,
                'weights' => null,
                'monthly_procedure' => null,
                '2025_author_description' => [
                    'first_release_time' => '2025年7月中旬',
                    'input_periods' => ['2024年全年', '2025年上半年'],
                    'monthly_inputs' => ['客流量', '运力', '客座率'],
                    'stated_output_period' => '2025年下半年',
                    'stated_output_metrics' => ['月度客流量', '月度运力', '月度客座率'],
                    'action_word_used_by_author' => '推演',
                ],
                '2025_author_explanations' => [
                    [
                        'airport' => '南宁吴圩国际机场',
                        'visible_error_ratio_percent' => -7.56,
                        'author_description' => '少预测7.56%',
                        'author_attributed_reason' => '2025年冬航季军方限流放宽，大量国内航班时刻得以释放。',
                        'causal_status' => 'author_explanation_not_independently_verified',
                    ],
                    [
                        'airport' => '武汉天河国际机场',
                        'visible_error_ratio_percent' => 4.02,
                        'author_description' => '多预测4.02%',
                        'author_attributed_reason' => '下半年尤其换季后湖北、湖南、江苏区域时刻下降，作者称最高跌幅约一成。',
                        'causal_status' => 'author_explanation_not_independently_verified',
                    ],
                ],
                '2026_author_description' => [
                    'input_periods' => ['2025年全年', '2026年1至7月'],
                    'monthly_inputs' => ['客流量', '运力', '客座率'],
                    'stated_output_period' => '2026年8至12月',
                    'stated_output_metrics' => ['月度客流量', '月度运力', '月度客座率'],
                    'action_word_used_by_author' => '推演',
                    'stated_interference_factors' => [
                        '成都两场运力再分配',
                        '郑州机场时刻扩容',
                        '长沙机场时刻扩容',
                        '深圳机场临时时刻减容',
                    ],
                    'stated_goal' => '进一步缩小误差比',
                ],
                'author_forecast_claims' => [
                    [
                        'claim_key' => 'guangzhou_domestic_rank_and_90m_milestone',
                        'subject' => '广州白云国际机场',
                        'author_claim' => '首次在正常年份升至国内第一，成为我国第二座、亚洲第四座、全球第五座9000万量级机场。',
                        'claim_status' => 'author_forecast_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'guangzhou_global_rank_change',
                        'subject' => '广州白云国际机场',
                        'author_claim' => '全球排名由第9升至第4，并被作者称为全球十大机场中的最佳增长机场。',
                        'author_attributed_context' => '达拉斯沃思堡国际机场当年持续低迷。',
                        'claim_status' => 'author_forecast_and_explanation_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'pudong_domestic_rank_and_throughput',
                        'subject' => '上海浦东国际机场',
                        'author_claim' => '国内排名降至第二，但客流量继续增长并突破8800万人次。',
                        'author_attributed_context' => '作者称受油价影响，国际市场增长受限。',
                        'claim_status' => 'author_forecast_and_explanation_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'pudong_global_rank_change',
                        'subject' => '上海浦东国际机场',
                        'author_claim' => '全球排名由第5降至第6。',
                        'author_attributed_context' => '作者称芝加哥奥黑尔国际机场增速平稳，可能保持或略高于去年水平。',
                        'claim_status' => 'author_forecast_and_explanation_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'traffic_tier_gatekeepers',
                        'subject' => '机场客流分级',
                        'author_claim' => '大型客流机场守门员仍为济南机场，千万级机场守门员仍为揭阳潮汕国际机场。',
                        'claim_status' => 'author_forecast_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'taoyuan_rank_and_peak',
                        'subject' => '台湾桃园国际机场',
                        'author_claim' => '反超5座大陆机场，客流突破5000万人次，并成为港澳台首个突破峰值客流量的大型机场。',
                        'claim_status' => 'author_forecast_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'urumqi_rank_change',
                        'subject' => '乌鲁木齐天山国际机场',
                        'author_claim' => '排名上升5位，时隔13年再次打破排名高位，创下新世纪以来历史新高。',
                        'claim_status' => 'author_forecast_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'shijiazhuang_rank_change',
                        'subject' => '石家庄正定国际机场',
                        'author_claim' => '排名上升3位，在华北地区由第4升至第3。',
                        'claim_status' => 'author_forecast_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'wenzhou_rank_change',
                        'subject' => '温州龙湾国际机场',
                        'author_claim' => '排名下降3位。',
                        'claim_status' => 'author_forecast_not_independently_verified',
                    ],
                    [
                        'claim_key' => 'future_update_schedule',
                        'subject' => '后续预测版本',
                        'author_claim' => '将在客流量60强系列文章中不定期更新。',
                        'claim_status' => 'author_publication_plan_not_independently_verified',
                    ],
                ],
                'claim_status' => 'author_forecast_and_explanation_not_independently_verified',
                'implementation_status' => 'not_implemented',
            ],
            'airport_forecast_hotel_boundary' => $base + [
                'content_key' => 'airport_forecast:hotel_boundary',
                'content_type' => 'airport_forecast_knowledge_reference',
                'module_id' => 'airport_forecast_knowledge_reference',
                'seed_key' => 'airport_forecast:hotel_boundary',
                'target_entry' => ['知识中枢', '经营问题只读知识检索'],
                'knowledge_role' => 'external_industry_reference_only',
                'not_a_fact' => [
                    'not_current_airport_fact_without_authoritative_verification',
                    'not_hotel_occupancy_fact',
                    'not_hotel_adr_or_revpar_fact',
                    'not_ota_channel_fact',
                    'not_causal_proof',
                ],
                'do_not_infer' => [
                    'do_not_generate_airport_forecast',
                    'do_not_calculate_missing_values',
                    'do_not_create_hotel_demand_signal',
                    'do_not_attribute_hotel_revenue_change',
                ],
                'failure_states' => [
                    'authoritative source or date missing => reference_only',
                    'algorithm parameters or raw data missing => method_not_reproduced',
                    'airport hotel relationship missing => hotel_application_not_applicable',
                ],
                'human_review_prompt' => '只回答来源写了什么、哪些是作者解释、哪些仍未知；不要补算法、估数或酒店经营结论。',
                'stop_rule' => '当前只完成来源保存与只读检索；不得生成预测、改价、建任务、操作OTA/PMS或外发。',
            ],
        ];

        return [
            'unit' => [
                'hotel_id' => 0,
                'name' => self::UNIT_NAME,
                'source' => self::SOURCE,
                'status' => 'done',
                'lifecycle_status' => 'active',
                'lifecycle_reason' => 'global_reference_only_author_method_not_reproduced',
                'reviewed_at' => self::REVIEWED_AT,
                'review_due_at' => self::REVIEW_DUE_AT,
                'known_knowns' => [
                    '两张用户截图的文件指纹、尺寸、可见标题、字段和部分可见样例已记录。',
                    '用户补充了作者对2025和2026预测所用时间范围、月度指标与干扰因素的文字说明。',
                    '作者称2025年43座千万级机场中23座误差比在1%以内、33座在2%以内。',
                    '作者对广州、浦东、桃园、乌鲁木齐、石家庄、温州以及客流分级守门员给出了预测观点。',
                    '所有内容仅为全局参考，不包含当前酒店、OTA或机场权威事实。',
                ],
                'known_unknowns' => [
                    '来源没有提供逐月公式、参数、权重、转换、舍入规则或可重放算法。',
                    '来源没有提供原始数据、逐行出处、置信区间、许可证或独立核验。',
                    '第二张截图的发布日期和2026年年末实际结果未知。',
                    '机场客流与具体酒店入住率、ADR、RevPAR之间的关系未提供也未验证。',
                ],
                'truth_profile_version' => self::SEED_VERSION,
                'description' => '保存机场客流预测榜单的可见字段和作者方法说明；只回答来源写了什么，不实现算法、不重新估算，也不形成酒店经营结论。',
                'tags' => [
                    '机场客流',
                    '预测榜单',
                    '作者方法说明',
                    '行业参考',
                    'reference_only',
                ],
                'created_by' => 0,
            ],
            'chunks' => $chunks,
            'knowledge_base' => [
                'tenant_id' => 0,
                'hotel_id' => 0,
                'category_id' => 7,
                'title' => self::UNIT_NAME,
                'content' => "# 机场客流预测榜单与作者方法说明\n\n"
                    . "## 来源写了什么\n"
                    . "作者称，2025年预测使用2024年全年和2025年上半年月度客流量、运力、客座率，对下半年相同指标进行推演；2026年预测使用2025年全年和2026年1至7月数据，对8至12月进行推演，并考虑成都两场运力再分配、郑州和长沙时刻扩容、深圳临时时刻减容等因素。\n\n"
                    . "作者称2025年43座千万级机场中23座误差比在1%以内、33座在2%以内，并把误差比描述为误差量与实际客流量的比值。南宁和武汉原因解释均为作者归因，未独立核验。\n\n"
                    . "作者还预测广州国内排名第一且全球排名由第9升至第4，浦东国内排名第二且全球排名由第5降至第6；并给出济南、潮汕、桃园、乌鲁木齐、石家庄、温州等机场的分级或排名观点。这些全部是作者预测声明，未独立核验。\n\n"
                    . "## 使用边界\n"
                    . "资料没有提供逐月算法、公式、参数、权重、原始数据和可重放过程。宿析OS只保存来源指纹、可见字段、作者说明和未知项，不自行计算或估算，不把预测榜单写成当前机场、酒店或OTA事实，也不据此形成入住率、ADR、RevPAR、调价或任务结论。",
                'keywords' => '机场客流,预测榜单,月度客流量,运力,客座率,作者方法,误差比,航班时刻,行业参考,不估算',
                'tags' => ['机场客流', '预测榜单', '作者方法说明', 'reference_only'],
                'sort_order' => 0,
                'is_enabled' => 1,
                'view_count' => 0,
                'like_count' => 0,
            ],
        ];
    }

    /**
     * @param array<string,string> $sourcePaths
     * @return array<string,mixed>
     */
    private function verifySourceFiles(array $sourcePaths): array
    {
        $items = [];
        $errors = [];
        foreach (self::SOURCE_FILES as $key => $expected) {
            $path = trim((string)($sourcePaths[$key] ?? ''));
            if ($path === '') {
                $errors[] = 'source_path_missing:' . $key;
                continue;
            }
            if (!is_file($path)) {
                $errors[] = 'source_file_missing:' . $key;
                continue;
            }

            $image = @getimagesize($path);
            $actual = [
                'file_name' => basename($path),
                'sha256' => strtoupper((string)hash_file('sha256', $path)),
                'bytes' => (int)filesize($path),
                'width' => is_array($image) ? (int)($image[0] ?? 0) : 0,
                'height' => is_array($image) ? (int)($image[1] ?? 0) : 0,
            ];
            $matches = $actual['sha256'] === (string)$expected['sha256']
                && $actual['bytes'] === (int)$expected['bytes']
                && $actual['width'] === (int)$expected['width']
                && $actual['height'] === (int)$expected['height'];
            if (!$matches) {
                $errors[] = 'source_fingerprint_mismatch:' . $key;
            }
            $items[$key] = $actual + ['matches_expected' => $matches];
        }

        return [
            'verified' => $errors === [] && count($items) === count(self::SOURCE_FILES),
            'expected_count' => count(self::SOURCE_FILES),
            'verified_count' => count(array_filter(
                $items,
                static fn(array $item): bool => ($item['matches_expected'] ?? false) === true
            )),
            'items' => $items,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function persistDefinition(array $definition): array
    {
        $now = date('Y-m-d H:i:s');
        $unitRows = Db::name('knowledge_units')
            ->whereIn('name', [self::UNIT_NAME, self::LEGACY_UNIT_NAME])
            ->whereIn('source', [self::SOURCE, self::LEGACY_SOURCE])
            ->order('unit_id', 'asc')
            ->lock(true)
            ->select()
            ->toArray();
        if (count($unitRows) > 1) {
            throw new RuntimeException('duplicate_airport_forecast_reference_units');
        }

        $unitData = $definition['unit'];
        $unitData['known_knowns'] = $this->encodeJson($unitData['known_knowns']);
        $unitData['known_unknowns'] = $this->encodeJson($unitData['known_unknowns']);
        $unitData['tags'] = $this->encodeJson($unitData['tags']);
        $unitData['updated_at'] = $now;

        $unitId = (int)($unitRows[0]['unit_id'] ?? 0);
        $unitAction = 'updated';
        if ($unitId <= 0) {
            $unitData['created_at'] = $now;
            $unitId = (int)Db::name('knowledge_units')->insertGetId($unitData);
            $unitAction = 'inserted';
        } else {
            Db::name('knowledge_units')->where('unit_id', $unitId)->update($unitData);
        }

        $existingRows = Db::name('knowledge_chunks')
            ->where('unit_id', $unitId)
            ->lock(true)
            ->select()
            ->toArray();
        $existingByKey = [];
        foreach ($existingRows as $row) {
            $content = $this->decodeJson($row['content'] ?? null);
            $owner = (string)($content['seed_owner'] ?? '');
            if (!in_array($owner, [self::SEED_OWNER, self::LEGACY_SEED_OWNER], true)) {
                continue;
            }
            $seedKey = trim((string)($content['seed_key'] ?? ''));
            $seedKey = self::LEGACY_SEED_KEY_MAP[$seedKey] ?? $seedKey;
            if ($seedKey === '') {
                continue;
            }
            if (isset($existingByKey[$seedKey])) {
                throw new RuntimeException('duplicate_airport_forecast_reference_chunk:' . $seedKey);
            }
            $existingByKey[$seedKey] = $row;
        }

        $chunkActions = [];
        foreach ($definition['chunks'] as $type => $content) {
            $seedKey = (string)$content['seed_key'];
            $encoded = $this->encodeJson($content);
            $chunkData = [
                'unit_id' => $unitId,
                'lifecycle_status' => 'active',
                'content_digest' => hash('sha256', $encoded),
                'type' => $type,
                'content' => $encoded,
                'created_by' => 0,
            ];
            $existing = $existingByKey[$seedKey] ?? null;
            if (!is_array($existing)) {
                $chunkData['created_at'] = $now;
                $chunkId = (int)Db::name('knowledge_chunks')->insertGetId($chunkData);
                $chunkActions[$type] = ['action' => 'inserted', 'chunk_id' => $chunkId];
                continue;
            }
            $chunkId = (int)$existing['chunk_id'];
            Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->update($chunkData);
            $chunkActions[$type] = ['action' => 'updated', 'chunk_id' => $chunkId];
        }

        $mirrorRows = Db::name('knowledge_base')
            ->where('hotel_id', 0)
            ->whereIn('title', [self::UNIT_NAME, self::LEGACY_UNIT_NAME])
            ->order('id', 'asc')
            ->lock(true)
            ->select()
            ->toArray();
        if (count($mirrorRows) > 1) {
            throw new RuntimeException('duplicate_airport_forecast_reference_mirrors');
        }
        $mirrorData = $definition['knowledge_base'];
        $mirrorData['tags'] = $this->encodeJson($mirrorData['tags']);
        $mirrorData['update_time'] = $now;
        $mirrorId = (int)($mirrorRows[0]['id'] ?? 0);
        $mirrorAction = 'updated';
        if ($mirrorId <= 0) {
            $mirrorData['create_time'] = $now;
            $mirrorId = (int)Db::name('knowledge_base')->insertGetId($mirrorData);
            $mirrorAction = 'inserted';
        } else {
            unset($mirrorData['view_count'], $mirrorData['like_count']);
            Db::name('knowledge_base')->where('id', $mirrorId)->update($mirrorData);
        }

        return [
            'unit_id' => $unitId,
            'unit_action' => $unitAction,
            'chunk_actions' => $chunkActions,
            'knowledge_base_id' => $mirrorId,
            'knowledge_base_action' => $mirrorAction,
        ];
    }

    /**
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $write
     * @return array<string,mixed>
     */
    private function readback(array $definition, array $write): array
    {
        $errors = [];
        $unitId = (int)($write['unit_id'] ?? 0);
        $unit = $unitId > 0
            ? Db::name('knowledge_units')->where('unit_id', $unitId)->find()
            : null;
        $expectedUnit = is_array($definition['unit'] ?? null) ? $definition['unit'] : [];
        if (!is_array($unit)
            || (string)($unit['name'] ?? '') !== self::UNIT_NAME
            || (string)($unit['source'] ?? '') !== self::SOURCE
            || (int)($unit['hotel_id'] ?? -1) !== 0
            || (int)($unit['created_by'] ?? -1) !== 0
            || (string)($unit['status'] ?? '') !== 'done'
            || (string)($unit['lifecycle_status'] ?? '') !== 'active'
            || (string)($unit['lifecycle_reason'] ?? '') !== (string)($expectedUnit['lifecycle_reason'] ?? '')
            || (string)($unit['reviewed_at'] ?? '') !== (string)($expectedUnit['reviewed_at'] ?? '')
            || (string)($unit['review_due_at'] ?? '') !== (string)($expectedUnit['review_due_at'] ?? '')
            || (string)($unit['description'] ?? '') !== (string)($expectedUnit['description'] ?? '')
            || $this->decodeJson($unit['known_knowns'] ?? null) !== ($expectedUnit['known_knowns'] ?? [])
            || $this->decodeJson($unit['known_unknowns'] ?? null) !== ($expectedUnit['known_unknowns'] ?? [])
            || $this->decodeJson($unit['tags'] ?? null) !== ($expectedUnit['tags'] ?? [])
            || (string)($unit['truth_profile_version'] ?? '') !== self::SEED_VERSION
        ) {
            $errors[] = 'unit_contract_mismatch';
        }

        $rows = $unitId > 0
            ? Db::name('knowledge_chunks')->where('unit_id', $unitId)->select()->toArray()
            : [];
        $actual = [];
        foreach ($rows as $row) {
            $content = $this->decodeJson($row['content'] ?? null);
            if ((string)($content['seed_owner'] ?? '') !== self::SEED_OWNER) {
                continue;
            }
            $seedKey = (string)($content['seed_key'] ?? '');
            if ($seedKey === '' || isset($actual[$seedKey])) {
                $errors[] = 'chunk_key_duplicate_or_missing';
                continue;
            }
            $actual[$seedKey] = $row + ['decoded_content' => $content];
        }

        $gate = new KnowledgeDecisionGateService();
        foreach ($definition['chunks'] as $type => $expectedContent) {
            $seedKey = (string)$expectedContent['seed_key'];
            $row = $actual[$seedKey] ?? null;
            if (!is_array($row)) {
                $errors[] = 'chunk_missing:' . $type;
                continue;
            }
            $encoded = $this->encodeJson($expectedContent);
            if ((string)($row['type'] ?? '') !== $type
                || (string)($row['content_digest'] ?? '') !== hash('sha256', $encoded)
                || $this->decodeJson($row['content'] ?? null) !== $expectedContent
            ) {
                $errors[] = 'chunk_content_mismatch:' . $type;
            }
            $assessment = $gate->assess(
                is_array($unit) ? $unit : [],
                $expectedContent,
                self::REVIEWED_AT
            );
            if (($assessment['status'] ?? '') !== KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY
                || ($assessment['retrieval_safe'] ?? false) !== true
                || ($assessment['decision_safe'] ?? true) !== false
                || ($assessment['task_draft_safe'] ?? true) !== false
            ) {
                $errors[] = 'knowledge_gate_mismatch:' . $type;
            }
        }
        if (count($actual) !== count($definition['chunks'])) {
            $errors[] = 'chunk_count_mismatch:' . count($actual);
        }

        $mirror = Db::name('knowledge_base')
            ->where('hotel_id', 0)
            ->where('title', self::UNIT_NAME)
            ->where('is_enabled', 1)
            ->select()
            ->toArray();
        $expectedMirror = is_array($definition['knowledge_base'] ?? null)
            ? $definition['knowledge_base']
            : [];
        if (count($mirror) !== 1
            || (int)($mirror[0]['tenant_id'] ?? -1) !== (int)($expectedMirror['tenant_id'] ?? -2)
            || (int)($mirror[0]['hotel_id'] ?? -1) !== (int)($expectedMirror['hotel_id'] ?? -2)
            || (int)($mirror[0]['category_id'] ?? -1) !== (int)($expectedMirror['category_id'] ?? -2)
            || (string)($mirror[0]['title'] ?? '') !== (string)($expectedMirror['title'] ?? '')
            || (string)($mirror[0]['content'] ?? '') !== (string)($expectedMirror['content'] ?? '')
            || (string)($mirror[0]['keywords'] ?? '') !== (string)($expectedMirror['keywords'] ?? '')
            || $this->decodeJson($mirror[0]['tags'] ?? null) !== ($expectedMirror['tags'] ?? [])
            || (int)($mirror[0]['sort_order'] ?? -1) !== (int)($expectedMirror['sort_order'] ?? -2)
            || (int)($mirror[0]['is_enabled'] ?? -1) !== (int)($expectedMirror['is_enabled'] ?? -2)
        ) {
            $errors[] = 'knowledge_base_mirror_mismatch';
        }

        return [
            'verified' => $errors === [],
            'unit_id' => $unitId,
            'unit_count' => is_array($unit) ? 1 : 0,
            'chunk_count' => count($actual),
            'knowledge_base_count' => count($mirror),
            'task_mode' => 'storage_only',
            'disposition' => 'store_only',
            'formal_absorption' => false,
            'algorithm_implemented' => false,
            'decision_safe' => false,
            'task_draft_safe' => false,
            'external_write_authorized' => false,
            'errors' => $errors,
        ];
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
