# SUXIOS OTA Revenue Semantic Layer

## Quick Reference

- Area: SUXIOS OTA revenue analytics and decision loop.
- Intended users: Codex/Data Analytics agents answering SUXIOS metric, reporting, diagnosis, operations, pricing, and investment questions.
- Coverage level: Strong for local code/docs/tests; Limited for live source reads.
- Source inventory: `references/source-inventory.md`
- Last synthesized: 2026-06-06 Asia/Shanghai
- Freshness expectations: verify live values from the current database/API before making numeric conclusions; verify OTA platform behavior against current authorized backend evidence before changing capture logic.
- Default date and time zone rules: use `Asia/Shanghai`; OTA facts use `data_date`/source date fields and must keep channel/platform scope.

## Entity Clarification

| Entity | Means | Does Not Mean | Primary IDs | Grain Notes | Sources |
| --- | --- | --- | --- | --- | --- |
| SUXIOS | Hotel SaaS product chain from OTA data to revenue analysis, AI decisions, operations, and investment decisions | A standalone data warehouse or BI platform | Project root `HOTEL/` | Product-level | `HOTEL/AGENTS.md` |
| OTA-channel facts | Ctrip/Meituan/other OTA channel data captured or imported into SUXIOS | Whole-hotel operating truth unless PMS/CRS/direct booking evidence exists | `source`, `platform_key`, `hotel_id`, `system_hotel_id` | Usually daily/platform/hotel/resource | `docs/revenue_metric_standard_fact_table.md`, `OtaStandardEtlService.php` |
| Logical `fact_ota_daily` | Standardized daily OTA fact output from `online_daily_data` | A guaranteed physical table | `date_key`, `hotel_key`, `platform_key`, `data_type`, `dimension` | Daily grain | `OtaStandardEtlService.php` |
| AI decision | Source-backed explanation, diagnosis, or recommendation | Automatic business action or direct OTA write | `ai_model_call_logs`, prompt version, evidence refs | Call/task grain | `docs/ai_governance_p2.md`, `LlmClientTest.php` |
| Operation execution loop | Manual-review path from recommendation to execution evidence and ROI review | Fake automation or success without evidence | `operation_execution_intents`, `operation_execution_tasks`, `operation_execution_evidence` | Intent/task/evidence grain | `p0_decision_execution_closed_loop.md`, `OperationExecutionLoopTest.php` |
| Investment or transfer decision | Pricing/timing/dashboard calculation using submitted operating inputs and optional AI evaluation | Full due diligence without verified financial/legal/source evidence | transfer record/input fields | Scenario/record grain | `TransferDecisionService.php`, `TransferDecisionServiceTest.php` |

## Key Metrics

| Metric | Definition | Numerator | Denominator | Time Grain | Canonical Source | Caveats |
| --- | --- | --- | --- | --- | --- | --- |
| OTA revenue | OTA-channel成交总额,优先 `amount`/`gross_revenue` | Sum revenue fields | Not applicable | Daily and aggregate | `OtaRevenueMetricService::summarizeDataset` | OTA scope only |
| Room revenue | 房费收入;缺结构化房费时沿用 OTA 成交额 | `room_revenue` or revenue fallback | Not applicable | Daily and aggregate | `docs/revenue_metric_standard_fact_table.md` | Label fallback basis where relevant |
| Room nights | OTA间夜量 | Sum `quantity`/`room_nights` | Not applicable | Daily and aggregate | `OtaStandardEtlService::dailyFact` | ADR not calculable when missing or zero |
| ADR | Average Daily Rate from standardized OTA revenue and room nights | `sum(room_revenue)` | `sum(room_nights)` | Daily/period | `OtaRevenueMetricService`, `OtaInsightAnalysisService` | Use `ota_adr`/OTA scope unless whole-hotel data exists |
| OCC | Occupancy from occupied vs available room nights | `sum(occupied_room_nights)` | `sum(available_room_nights)` | Daily/period | `OtaRevenueMetricService` | Return null and data gap when available/occupied room nights are missing |
| RevPAR | Revenue per available room from standardized room revenue | `sum(room_revenue)` | `sum(available_room_nights)` | Daily/period | `OtaRevenueMetricService` | Channel RevPAR unless whole-hotel denominator is proven |
| Net revenue | After-commission revenue | Direct net revenue or `gross_revenue - commission_amount` | Not applicable | Daily/period | `docs/revenue_metric_standard_fact_table.md` | Mark direct vs derived basis |
| Net RevPAR | After-commission revenue per available room | `sum(net_revenue)` | `sum(available_room_nights)` | Daily/period | `OtaRevenueMetricService` | Requires aligned net revenue and available room nights |
| Commission rate | OTA commission share | `sum(commission_amount)` | `sum(gross_revenue)` | Daily/period | `OtaRevenueMetricService` | Use only aligned rows with commission fields |
| Cancellation rate | Cancelled orders over order base | `cancel_order_num` or direct platform cancel rate | `order_count` | Daily/period | `OtaRevenueMetricService` | Keep order and room-night cancellation separate |
| Traffic conversion | Flow/submit conversion from standardized traffic facts | Detail exposure or submit counts | Exposure/fill counts | Daily/platform | `OtaInsightAnalysisService` | Backfill exposure/detail/fill/submit before evaluating missing traffic |
| Competitor price gap | Price difference against competitor | `our_price - competitor_price` | competitor price for gap rate | Daily/platform/room type when available | `OtaRevenueMetricService`, `OtaInsightAnalysisService` | Compare same room type, cancel policy, breakfast/package when acting |
| Advertising ROAS | Attributed OTA ad order amount over spend | `order_amount` | `spend` | Daily/campaign | `OtaRevenueMetricService` | Requires ad spend and attributed order amount |
| Service quality | PSI/service score averages | Score values | Row count | Daily/platform | `OtaRevenueMetricService`, `OtaInsightAnalysisService` | Platform private weights are not reverse-engineered |
| Execution ROI | Incremental revenue/cost/profit from recorded execution evidence | After revenue minus before revenue and cost | Cost when ROI value calculated | Intent/task review | `OperationManagementService::buildExecutionRoi` | Requires execution evidence; no evidence means data gap |
| Transfer valuation | Conservative/reasonable/optimistic valuation | Monthly net profit or decoration investment fallback | Valuation multiple or fallback ratio | Scenario | `TransferDecisionService::calculateAssetPricing` | Amount unit is 万元; full diligence still required |
| Transfer timing score | Score from revenue/order/ADR/OCC trends, rating, holiday window, season, and data quality | Rule score additions/subtractions | 100-point clamp | Scenario | `TransferDecisionService::calculateTransferTiming` | Suspected collection anomaly lowers confidence and must be called out |

## Standard Filters And Dimensions

| Filter Or Dimension | Default Logic | Override When | Applies To | Sources |
| --- | --- | --- | --- | --- |
| `metric_scope` | Default to `ota_channel` for OTA rows | Use whole-hotel only with PMS/CRS/direct-booking/all-room evidence | Metrics/reports/AI answers | `hotel_ota_metric_professional_knowledge.md` |
| `platform` / `source` | Normalize OTA source such as `ctrip`, `meituan` | Preserve original raw value in provenance when mapping is uncertain | All OTA facts | `OtaStandardEtlService.php` |
| `data_type` | Business/order/traffic/advertising/quality/search_keyword map to separate fact families | Keep rejected rows visible when policy disables collection | ETL/metrics | `OtaStandardEtlService.php` |
| `data_date` | Metric attribution date | Use source-provided update date only as freshness metadata | OTA metrics | `OtaStandardEtlService.php` |
| `system_hotel_id` vs OTA `hotel_id` | Prefer system hotel ID when available; otherwise platform hotel key | Do not merge hotels without explicit mapping | Hotel-level cuts | `OtaStandardEtlService.php` |
| Data gaps | Return explicit codes/messages | Never convert missing denominator to zero | Metrics/reports | `OtaRevenueMetricService.php` |
| AI governance | Require prompt version, sources, confidence/eval markers for operational/investment impact | If missing, low-confidence and human confirmation are required | AI calls | `docs/ai_governance_p2.md`, `LlmClientTest.php` |
| Execution status | Draft/pending/approved/executed/evidence/reviewed states | Block when mapping, approval, evidence, or source data is missing | Operations | `OperationExecutionLoopTest.php` |

## Key Tables

| Table | When To Use | Grain | Join Keys | Freshness | Caveats | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| `online_daily_data` | Primary local source for OTA business, traffic, order, advertising, quality, and raw platform data | Row-level imported/captured OTA data | `system_hotel_id`, `hotel_id`, `source`, `data_date`, `data_type` | Check live DB/API before numeric conclusions | Sensitive/private raw data must remain sanitized | `docs/five_modules_business_loop_field_inventory.md`, `OtaStandardEtlService.php` |
| Logical `fact_ota_daily` | Revenue, room nights, ADR/OCC/RevPAR, cancellation, price gap | `date_key + hotel_key + platform_key + data_type + dimension` | `hotel_key`, `platform_key`, date | Built from current rows | Logical output, not necessarily physical table | `docs/revenue_metric_standard_fact_table.md` |
| Logical `fact_ota_traffic` | Exposure/detail/fill/submit conversion | Daily platform/resource | `hotel_key`, `platform_key`, date | Built from current rows | Requires traffic rows | `OtaStandardModuleTest.php` |
| Logical `fact_ota_advertising` | Spend/order amount/clicks/bookings/ROAS | Daily campaign/platform | `hotel_key`, `platform_key`, date, campaign | Built from current rows | Attributed revenue basis can differ by platform | `OtaRevenueMetricService.php` |
| Logical `fact_ota_quality` | PSI/service quality | Daily platform | `hotel_key`, `platform_key`, date | Built from current rows | Platform private formulas are not inferred | `OtaInsightAnalysisService.php` |
| `daily_reports` | Whole-hotel daily operating summary when available | Daily hotel | `hotel_id`, `report_date` | Check current DB | Do not mix with OTA-only rows without labeling | `five_modules_business_loop_field_inventory.md` |
| `competitor_analysis` | Competitor price/score snapshots | Analysis date/hotel | `hotel_id`, `analysis_date` | Check current DB | Fallback to OTA competitor rows only with scope caveat | `five_modules_business_loop_field_inventory.md` |
| `operation_alerts` | Persisted operations alerts | Alert record | `hotel_id`, status, date | Check current DB | Generated alerts may be persisted when table exists | `OperationManagementService.php` |
| `operation_action_tracks` | Operation action tracking and effect validation | Action record | `hotel_id`, date range | Check current DB | Requires before/after windows | `OperationManagementService.php` |
| `operation_execution_intents` | Recommendation-to-execution intent pool | Intent record | `hotel_id`, `source_module`, status | Check current DB | Blocked status must show reason | `p0_decision_execution_closed_loop.md` |
| `operation_execution_tasks` | Approved execution tasks | Task record | `intent_id`, `hotel_id` | Check current DB | Execution requires approved intent | `OperationExecutionLoopTest.php` |
| `operation_execution_evidence` | Execution evidence | Evidence record | `task_id` | Check current DB | No evidence means no successful execution claim | `OperationExecutionLoopTest.php` |
| `ai_model_call_logs` | AI call governance | Model call record | request/module/scenario/prompt/eval fields | Check current DB | Prompt body is not stored as sensitive context | `ai_governance_p2.md` |
| `price_suggestions` | Revenue pricing suggestions | Suggestion record | hotel/room/date | Check current DB | Advisory-only, no direct OTA write | `RevenuePricingRecommendationServiceTest.php` |
| `transfer_records` | Saved transfer pricing/timing/dashboard records | Scenario record | hotel/record_type | Check current DB | Inputs may be manual unless live data is connected | `TransferDecisionService.php` |

## Query Patterns

- Pattern: OTA revenue summary.
  - Use when: answering revenue, ADR, OCC, RevPAR, net revenue, commission, cancellation, channel contribution.
  - Key tables: `online_daily_data` -> logical `fact_ota_daily`.
  - Required filters: hotel/system hotel, platform/source, date window, OTA scope.
  - Common joins: platform/hotel dimensions from ETL output.
  - Example skeleton: build ETL dataset for the date window, then summarize with `OtaRevenueMetricService`; cite `data_gaps` before interpreting nulls.

- Pattern: OTA diagnosis.
  - Use when: explaining low revenue/orders/traffic/conversion/price/service quality.
  - Key tables: `online_daily_data`, `competitor_analysis`, `daily_reports`, logical traffic/quality/ad facts.
  - Required filters: hotel, date, platform, data type.
  - Common joins: compare current day to 7/30-day averages only when data exists.
  - Example skeleton: use `OperationManagementService::fullData/rootCause` or `OtaInsightAnalysisService::analyzeMetrics`; separate missing data from business underperformance.

- Pattern: Recommendation to execution.
  - Use when: tracking whether an AI/rules recommendation became an approved and evidenced action.
  - Key tables: `operation_execution_intents`, `operation_execution_tasks`, `operation_execution_evidence`, `operation_action_tracks`.
  - Required filters: hotel, platform, object type, status.
  - Common joins: intent -> latest task -> evidence -> action track.
  - Example skeleton: query execution flow; show bottleneck, next action, evidence count, and ROI status.

- Pattern: Pricing recommendation.
  - Use when: deciding whether to raise/hold/lower price.
  - Key tables/sources: demand forecast, pickup, elasticity, competitor, holiday, inventory, backtest signals.
  - Required filters: room type/date/platform and min/max price constraints.
  - Common joins: price suggestion -> execution intent only after manual review.
  - Example skeleton: use `RevenuePricingRecommendationService` signal output; always include `advisory_only` and data gaps.

- Pattern: Transfer or investment decision.
  - Use when: calculating asset pricing, transfer timing, or transaction dashboard.
  - Key tables/sources: transfer input, operating metrics, rating, revenue/order/ADR/OCC trend, cost fields.
  - Required filters: scenario record/hotel/time window.
  - Common joins: pricing + timing + extra risk metrics.
  - Example skeleton: use `TransferDecisionService`; call out manual input basis and data anomaly status.

## Gotchas

- Gotcha: OTA data is not automatically whole-hotel data.
  - Impact: ADR/OCC/RevPAR and investment claims can be overstated.
  - How to avoid: label OTA scope and require all-room/all-channel evidence before whole-hotel conclusions.
  - Source: `HOTEL/AGENTS.md`, `hotel_ota_metric_professional_knowledge.md`

- Gotcha: Missing denominators must remain missing.
  - Impact: fake zeroes hide data-quality problems and lead to wrong AI recommendations.
  - How to avoid: use null plus `data_gaps`, `blocked_reason`, or missing status.
  - Source: `revenue_metric_standard_fact_table.md`, `OtaRevenueMetricService.php`

- Gotcha: AI recommendations are not execution.
  - Impact: users may believe a price, inventory, or campaign change happened when only a suggestion exists.
  - How to avoid: require approval, execution evidence, and review status.
  - Source: `p0_decision_execution_closed_loop.md`, `OperationExecutionLoopTest.php`

- Gotcha: Platform private scores are not reverse-engineered.
  - Impact: false precision in PSI/HOS/MCI explanations.
  - How to avoid: store backend values and operational interpretations; verify current platform backend before acting.
  - Source: `hotel_ota_metric_professional_knowledge.md`

- Gotcha: Raw capture artifacts may contain sensitive OTA data.
  - Impact: privacy/security and repo hygiene risk.
  - How to avoid: read summary/status files first; target specific fields with scripts; do not linearly dump raw capture JSON.
  - Source: root/project `AGENTS.md`

## Related Dashboards And Docs

| Source | Use It For | Caveats |
| --- | --- | --- |
| `docs/revenue_metric_standard_fact_table.md` | Metric formulas and fact table boundary | Local documentation, not live metric values |
| `docs/hotel_ota_metric_professional_knowledge.md` | Metric explanations and AI answer boundaries | Platform rules may change |
| `docs/p0_decision_execution_closed_loop.md` | Execution-loop product contract | Verify runtime table state |
| `docs/five_modules_business_loop_field_inventory.md` | Cross-module field status and gaps | Some status may change with later commits |
| `docs/ota_acquisition_decision_playbook.md` | Data collection routing and metadata | Does not authorize live scraping |
| `docs/ai_governance_p2.md` | AI governance behavior | Batch eval runner remains a gap |
| `docs/ctrip_table_build_plan_20260602.md` | Ctrip field/table plan | Verify migrations before writing |

## Open Questions

- Question: Which live source should be treated as the production warehouse or database source for Data Analytics runs?
  - Why it matters: future numeric reports need fresh source reads, not only code/doc semantics.
  - Best owner or source to check next: project owner or local MySQL/XAMPP setup.

- Question: Are there verified dashboards, recurring reports, or Google Drive docs outside this repo that should outrank local docs?
  - Why it matters: source precedence can change metric definitions and caveats.
  - Best owner or source to check next: user-provided Drive/dashboard links or exported reports.

- Question: Which current OTA pages/fields remain blocked after the 2026-06-02 Ctrip field closure work?
  - Why it matters: missing fields directly affect revenue, traffic, quality, and investment conclusions.
  - Best owner or source to check next: Ctrip capture catalog/audit summary and targeted field verifiers.

- Question: Should weekly semantic-layer refresh be enabled?
  - Why it matters: local docs and code will evolve; an explicit refresh prevents stale guidance.
  - Best owner or source to check next: user approval for automation.
