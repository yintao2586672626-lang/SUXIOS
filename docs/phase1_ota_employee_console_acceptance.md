# 宿析OS第一阶段员工视角验收

Updated: 2026-06-12

## 目标

第一阶段不是让 AI 自动管理酒店，而是让一线员工每天打开系统后，能判断 OTA 经营数据是否可信、哪些结论不能下、下一步该补什么证据。

员工视角必须回答六个问题：

1. 今天携程、美团 OTA 数据有没有采到。
2. 哪些字段可信，证据来自哪里。
3. 哪些字段缺失、失败、未授权或未采集。
4. 收入、流量、转化出了什么问题。
5. AI 建议依据了哪些 OTA 数据、指标和缺口。
6. 下一步该执行什么动作，是否需要审批、执行证据和复盘。

## 验收面

| 问题 | 系统承载面 | 当前证据 | 完成口径 |
|---|---|---|---|
| 今天有没有采到 | 数据健康 / 采集可靠性 | `/api/online-data/collection-reliability`, `collectionHealthSummaryCards` | 能看到平台、门店、采集状态、授权状态、最近采集日志 |
| 哪些字段可信 | 字段资产 ledger + `metric_trust` + 数据质量 | `collectionHealthFieldAssetCards`, `/api/ota-standard/revenue-metrics.metric_trust`, 携程 Profile 字段 ledger | 能区分稳定字段、未返回字段、禁止采集字段，并明确字段是否仍需复核 |
| 哪些字段缺失 | 数据质量和缺口 | `data_quality`, `missing_count`, `field_missing`, `data_gaps` | 缺字段、授权失败、未采集必须显式展示 |
| 收入/流量/转化问题 | OTA 标准收益指标 | `/api/ota-standard/revenue-metrics`, `OtaRevenueMetricService` | 能输出收入、间夜、客单、ADR、流量转化和数据缺口 |
| AI 建议依据 | OTA 诊断 | `/api/agent/ota-diagnosis`, `evidence_sources`, `action_items`, `source_policy` | AI 建议必须引用证据和数据缺口 |
| 下一步动作 | 运营执行闭环 | `/api/operation/execution-intents`, `/api/operation/execution-flow` | 建议进入执行意图，阻塞、审批、证据、复盘可追踪 |

`next_required_actions` 必须同时暴露 `action_family`、`question_key`、`related_question_keys`、`entry`、`entry_options`、`success_criteria`、`resolves_missing_codes`、`live_closure_gap_codes`、`blocked_by_action_codes`、`employee_explanation`、`limited_conclusions`、`still_usable_metrics` 和 `explanation_next_action`：`action_family` 说明动作类型，`question_key` 说明动作直接归属的员工六问，`related_question_keys` 说明该动作影响哪些员工六问，`entry` 指向现有核验或执行入口，`entry_options` 只列出现有手动 Cookie/API、浏览器 Profile 和状态核对入口，并用 `label`、`use_when`、`requires`、`boundary` 说明何时选择、前置条件和不改变采集逻辑/字段的边界，`success_criteria` 说明复跑巡检时哪类证据能解除该动作，`resolves_missing_codes` 说明当前动作可解除的员工控制台缺口，`live_closure_gap_codes` 对应第一阶段实时巡检使用的缺口码，`blocked_by_action_codes` 说明当前动作被阻断时要先处理的动作，解释字段说明员工能下哪些结论、不能下哪些结论、仍可看哪些指标和下一步补什么证据。`rows` 每行必须暴露 `next_action_codes`，并且只引用同一输出里的 `next_required_actions.action_code`；未证明的问题行还必须在行本身和 `evidence` 中暴露 `direct_next_action_code`、`primary_next_action_code`、`linked_action_count`，让员工同时看到“这个问题直接对应什么动作”和“按当前阻断关系先处理什么动作”。这些字段都不能作为采集成功或闭环完成证据。
员工动作队列展示 `employee_explanation`、`limited_conclusions`、`still_usable_metrics` 和 `explanation_next_action` 时，必须优先用 `action_code`、`action_family`、`blocked_by_action_codes`、`resolves_missing_codes` 和 `question_key` 映射成员工可读解释、受限结论、仍可使用指标和补证据动作；后端原始解释字段只能保留在结构化数据或标题追溯中，不能因为编码异常、技术码或平台原文让员工看到乱码主文案，也不能用这些可读解释替代真实证据。
员工动作队列的说明、下一步补证、完成判定和元信息使用 `question_key` 或 `local_*_required_action` 推导员工六问时，必须优先映射成稳定六问文案；遇到未知 `question_key` 时，主文案只能显示“当前员工问题”或“未识别员工问题”，原始 key 只能保留在标题追溯或结构化响应中。
员工动作队列主标题、负责人/平台/状态元信息和 `protected_boundary` 必须优先用 `action_code`、`action_family`、`question_key`、`platform`、`status` 映射成员工可读动作名、负责人、平台范围、处理状态和保护边界；后端原始 `action`、`owner`、`reason`、`protected_boundary` 只能保留在结构化数据或标题追溯中，不能作为主展示文案，也不能因此改变携程/美团手动或自动获取逻辑、获取字段、入库表结构或闭环判定。
员工动作队列展示 `entry`、`success_criteria`、`evidence_needed`、`explanation_next_action` 和 `protected_boundary` 时，主文案必须使用 `employee_action`、`employee_evidence_needed`、`employee_success_criteria`、`employee_explanation_next_action` 或 `phase1EmployeeActionEntryText`、`phase1EmployeeActionSuccessCriteriaText`、`phase1EmployeeActionEvidenceNeededText`、`phase1EmployeeActionProtectedBoundaryText` 的员工可读结果；即使映射不到具体接口，也只能显示“现有核验入口/现有执行入口”等通用中文入口或保护边界，原始 API 路径、原始完成条件和原始 `protected_boundary` 只能保留在标题追溯或结构化响应中。
`entry_options[].readiness` 必须说明入口当前是否可直接使用，但它不是采集成功证据，也不能替代目标日入库证明。前端必须把 `readiness.can_run_now` 显示成明确的“可直接执行/需先准备”状态，让员工知道哪些入口只能先补授权或登录上下文。手动 Cookie/API 入口必须标记 `status=requires_user_context`、`can_run_now=false`，说明仍需用户提供授权上下文；浏览器 Profile 入口只能读取本机 `storage/{platform}_profile_*` 目录数量，状态只能是 `profile_missing` 或 `profile_found_login_unverified`，并暴露 `source_policy=read_local_profile_directory_names_only`，不能声明登录态已验证；状态核对入口必须标记 `status=ready`、`can_run_now=true`，且 `evidence=read_existing_collection_reliability_only`，只读现有采集可靠性和 `online_daily_data` 状态。员工端展示入口状态时必须把 `requires_user_context`、`profile_missing`、`profile_found_login_unverified`、`user_supplied_cookie_or_payload_required`、`storage_profile_directory_count`、`read_local_profile_directory_names_only` 和 `read_existing_collection_reliability_only` 映射成可读状态和证据说明，不能只把 readiness 技术码拼到页面上。
员工控制台展示动作 `entry` 时，必须把 `/api/...` 路径映射成员工可读入口名，例如“美团手动 Cookie/API 获取入口”“美团浏览器 Profile 采集入口”“OTA 收益指标与标准事实核对”“AI 诊断证据核对入口”和“运营执行意图入口”；原始入口路径只能保留在结构化数据或标题追溯中，不能作为员工主文案，也不能因此改变携程/美团手动或自动获取逻辑。
员工控制台展示 `entry_options` 时，必须优先用 `mode` 映射稳定入口类型文案：`manual_cookie_api=手动 Cookie/API`、`browser_profile=浏览器 Profile`、`status_check=状态核对`；入口选择说明也必须由前端按 `mode` 生成稳定员工话术，不能把后端 `use_when`、`requires`、`boundary` 原样作为主展示文案；后端 `label`、原始 `entry`、原始 `use_when/requires/boundary` 只能保留在结构化数据或标题追溯中，不能因为 label 编码异常、平台返回异常或技术路径变化让员工看到乱码、API 路径或后端技术描述主文案。
收入/流量/转化动作的手动 Cookie/API 与浏览器 Profile 入口必须暴露 `entry_options[].input_contract` 和 `entry_options[].acceptance_contract`：`input_contract` 至少说明 `target_data_type=traffic`、`required_metric_keys`、`required_storage_fields`、`required_inputs`、`required_field_fact_keys` 和 `sensitive_values_allowed=false`；前端必须把它映射成“需闭环指标”“需入库字段”“需补输入”“需证明采集证据、source path、metric key、入库字段和已入库值”“不展示 Cookie、token 或 Profile 原值”等员工可读说明。`input_contract` 与 `acceptance_contract` 只是采集前置要求和复验要求，不能作为采集成功、目标日入库完成或 P0 闭环完成证据；状态核对入口不能挂采集型 input contract。

员工控制台必须展示完整 `next_required_actions` 队列，不能只截取前几条；每条动作卡片必须展示 `related_question_keys` 对应的影响问题，让员工知道该动作会影响“今天是否采到、字段可信、字段缺失、收益/流量/转化、AI 依据、运营动作”中的哪些问题。前端展示时必须把 `today_ota_collected`、`trusted_fields`、`missing_fields`、`revenue_traffic_conversion`、`ai_evidence`、`next_operation_action` 映射成员工可读问题文案，不能直接展示技术键名。遇到未知 `related_question_keys` 时，动作卡片和闭环摘要主文案只能显示“未识别员工问题”，原始 key 只能保留在标题追溯或结构化响应中。

员工六问卡片展示 `direct_next_action_code`、`primary_next_action_code`、`blocked_action_codes` 和 `next_action_codes` 时，必须映射成员工可读动作名；原始 action code 仍保留在结构化数据和标题追溯中，不能用展示文案替代动作队列契约。遇到未知 action code 时，主文案只能显示“未识别补证动作”，不能直接显示原始机器码。
员工六问卡片展示 `next_action`/`nextAction` 时，必须优先使用 `direct_next_action_code`、`primary_next_action_code` 或 `next_action_codes` 映射成员工可读下一步；原始 `next_action` 只能保留在结构化数据或标题追溯中，不能因为后端原始文案、编码异常或技术动作码让员工看到不可执行的主文案。缺少可识别动作码时，只能显示“按动作队列补齐证据”或对应员工六问的通用补证动作。
AI 依据摘要和运营执行摘要展示 `next_action` 与入口 policy 时，必须复用 `phase1EmployeeQuestionNextActionText` 和 `phase1EmployeeActionEntryText`，优先按 `direct_next_action_code`、`primary_next_action_code`、`next_action_codes`、`entry` 映射成员工可读下一步和入口名称；原始 `next_action` 不能作为摘要主文案，原始 API 路径只能保留在标题追溯，不能用技术路径替代员工可执行动作。
AI 依据摘要和运营执行摘要展示 `blocking_missing_codes` 时，必须复用 `phase1EmployeeGapCodeText` 映射成员工可读阻断缺口；原始阻断码只能保留在标题追溯或结构化响应中，不能作为摘要主文案。
当后端 `rows` 已返回结构化状态、证据和动作队列，但某个问题行缺少 `detail`/`message` 展示说明时，前端可以按同一 `key` 复用本地六问说明文本作为员工卡片解释；该展示补充只能在后端缺少同名展示字段时填补 `question`、`detail`、`nextActionText`、`blockingReasonText` 这类说明字段，不能覆盖后端 `status`、`evidence`、`next_action_codes`、`direct_next_action_code`、`primary_next_action_code`、`entry` 或完成判定，不能把本地说明当作采集成功、字段可信或闭环完成证据。
员工六问行合并后，后端 `status`、`evidence` 和动作码仍是事实来源；`nextActionText`、`blockingReasonText` 这类员工主文案必须优先使用后端动作码、缺口码和 `employee_*` 字段派生出的稳定映射结果，本地六问说明只能在后端缺展示文本时兜底，避免本地状态或原文覆盖实时证据。
员工六问行可以同时保留原始 `detail`/`next_action` 作为结构化追溯，但必须提供或派生 `employee_detail`、`employee_next_action` 作为员工主文案；`metric_trust`、`evidence_sources`、`data_gaps`、`action_items`、`source_date_evidence` 等技术字段名，以及 `CTRIP`、`MEITUAN` 这类平台码，只能出现在原始字段、证据键、title 追溯或契约检查里，不能直接作为员工卡片正文或下一步主文案。
员工六问每行的 `employee_detail` 不能为空，必须直接说明当前问题为什么已证明、为什么缺失、为什么只能作为参考，或为什么不能输出确定结论；不能只依赖前端本地说明兜底，也不能把空说明、技术码或原始字段名作为员工解释。

员工动作队列和首要动作摘要展示 `success_criteria`、`evidence_needed` 时，必须映射成员工可读完成判定和所需证据，说明员工应看到哪类目标日入库、标准事实、收益、流量/转化、AI 证据或执行闭环信号才算解除缺口。原始技术值仍保留在结构化数据、标题追溯或后端响应中，不能作为主文案，不能用员工文案替代原始证据字段，也不能把可读文案当作采集成功或闭环完成证据。
员工动作队列和首要动作摘要还必须展示 `employee_verification_steps` 作为“复核方式”，说明员工执行动作后应刷新哪个闭环、看到什么状态变化才算该动作解除；复核方式只能描述现有数据健康页、员工六问、现有 OTA 诊断或运营执行闭环的核验动作，不能新增采集路径、不能改变采集字段，也不能把刷新后的历史/最近可用数据当成目标日证明。
数据健康页的 `collectionReliability.pending_actions` 在“历史回放 / 待处理”区域展示时，也必须按 `action_code`、`type`、`status` 和 `platform` 映射成可读类型、动作、所需证据和保护边界；原始 `action`、`next_action`、`evidence_needed`、`protected_boundary` 只能保留在标题追溯或结构化响应中，不能作为员工主文案，也不能改变携程/美团手动或自动获取逻辑、获取字段或字段映射。

`collection_source_summary` 必须在 `/api/online-data/collection-reliability`、dashboard 数据源和员工六问控制台同时可见。它只读 `online_daily_data` 与 `source_date_evidence`，每个平台至少暴露 `platform`、`target_date_rows`、`target_date_data_types`、`latest_available`、`latest_available_reference_only`、`storage_table=online_daily_data`、`source_policy=read_existing_online_daily_data_only`、`metric_scope=ota_channel` 和 `collection_logic_changed=false`。`latest_available_reference_only=true` 时只能说明历史或未来日期有参考数据，不能证明目标日已采到。
员工控制台的平台源数据摘要展示 `collection_source_summary.platform`、`target_date_data_types` 和 `latest_available.date_relation` 时，主文案必须映射成“携程/美团”“经营/收益/流量/转化”“早于目标日/晚于目标日/目标日”等员工可读文案；原始 `ctrip`、`meituan`、`business`、`traffic`、`stale_before_target`、`future_dated_for_target` 只能保留在标题追溯或结构化响应中。
员工控制台展示 `collection_source_summary.source_policy`、`storage_table`、`field_trust_policy`、`metric_domain_policy` 或字段可信摘要的 `source_policy` 时，必须映射成可读证据口径；原始机器口径只能保留在标题追溯或结构化响应中，不能作为员工主文案。

`closure_summary` 必须在首要动作上暴露 `top_action_related_question_keys`、`top_action_resolves_missing_codes` 和 `top_action_live_closure_gap_codes`，让员工知道当前优先动作影响哪些六问、复跑巡检时解除哪些缺口、对应哪个 live 缺口码。这些字段只能来自同一输出里的动作队列，不能作为采集成功或闭环完成证据。
员工控制台闭环摘要展示未完成问题、首要动作、首要动作入口和完成判定时，必须优先用 `missing_question_keys`、`top_action_code`、`top_action_entry` 与 `top_action_success_criteria` 映射成员工可读问题名、动作名、入口名和完成判定；`missing_questions`、`top_action`、`top_action_entry` 和原始完成判定只能保留在结构化数据或标题追溯中，不能让后端原始文案、API 路径、编码异常或技术码替代稳定展示。遇到未知 `missing_question_keys` 或未知 `top_action_code` 时，摘要主文案只能显示“未识别员工问题”“现有首要补证动作”等通用可读状态，不能回退展示原始机器值。

`closure_summary.top_action_source_snapshot` 必须暴露首要动作对应平台的当前证据快照：`platform`、`target_date_rows`、`latest_available`、`latest_available_reference_only`、`proof_requirement` 和 `reference_policy`。当 `latest_available_reference_only=true` 时，前端只能展示为参考证据，不能替代目标日入库行证明；员工必须能直接看到证明要求是 `source_date_evidence.platforms` 中该平台 `target_date_rows > 0`。员工端展示“当前证据”时必须把平台、目标日入库行、最近可用日期、日期关系和参考口径映射成人可读文本，不能只显示 `target_date_rows=0`、`latest_available=.../stale_before_target` 或原始 `proof_requirement`。

每个未证明或 warning 的员工六问行必须暴露 `blocking_gap_codes`，来源只能是已有 `blocking_missing_codes`、`metric_domain_gap_codes` 或对应动作的 `resolves_missing_codes`。前端必须把这些码显示成“未证明原因”，不能只显示笼统 warning。员工界面展示时必须把常见 `blocking_missing_codes`、`resolves_missing_codes`、`live_closure_gap_codes` 映射成员工可读缺口文案；未知缺口码主文案只能显示“未识别证据缺口”，原始技术码仍保留在后端结构化数据、标题追溯或 raw 字段中，不能用文案映射替代原始证据，也不能直接把未知机器码作为主文案。

员工六问的证据摘要展示平台覆盖、平台明细、最近可用日期关系和指标域缺失时，必须把 `ctrip`、`meituan`、`latest_available.date_relation`、`revenue/traffic/conversion` 映射成员工可读的平台、日期关系和指标域名称；原始平台码、`stale_before_target`、`future_dated_for_target`、`metric_domain_readiness.missing_domains` 等只能保留在结构化响应或标题追溯中，不能作为主文案。
员工六问的证据摘要展示 `metric_domain_gap_codes`、`data_gap_codes`、`missing_field_codes`、`field_pending_action_codes`、`blocked_action_codes`、`blocking_missing_codes`、`direct_next_action_entry` 和 `direct_next_action_success_criteria` 时，必须映射成员工可读的指标域缺口、数据缺口、字段缺口、字段动作、阻断动作、阻断缺口、入口名称和完成判定；原始缺口码、动作码、API 路径和原始完成条件只能保留在结构化响应或标题追溯中，不能作为主文案。
员工控制台的字段可信摘要、AI 依据摘要和运营执行摘要不得把 `metric_trust`、`data_gaps`、`evidence_sources`、`action_items`、`execution_intents`、`execution_flow`、`source_date_evidence` 等技术字段名作为主文案；必须展示为“指标可信证据”“数据缺口”“证据来源”“动作项”“执行意图”“执行流”“目标日来源证据”等员工可读词，原始字段名只能用于结构化响应、title 追溯或契约检查。

## 字段级证据

字段可信和字段缺失必须暴露字段级证据键：`trusted_fields.evidence.metric_trust_keys` 用于说明哪些指标键已有 `metric_trust` 可复核证据，不能仅用 `metric_trust_key_count` 代替；`trusted_fields.evidence.field_definition_keys` 用于说明字段资产来源；`trusted_fields.evidence.platform_field_trust[].field_trust_status` 在员工六问摘要里必须映射成员工可读字段可信状态，不能直接显示 `target_date_source_missing`、`target_date_metric_inputs_missing` 这类技术码；`missing_fields.evidence.data_gap_codes` 和 `missing_fields.evidence.missing_field_codes` 用于说明显式缺口，不能仅用 `missing_field_count` 代替。`missing_fields.evidence.missing_field_summary` 必须逐项暴露员工可读的 `label`、`source_text`、`business_impact`、`next_action` 和 `policy`，原始 `code` 只用于追溯，不能替代主文案。
字段可信摘要展示 `platform_field_trust[].reason_codes` 时，必须复用 `phase1EmployeeGapCodeText` 映射成员工可读未证明原因；原始 reason code 只保留在标题追溯或结构化响应中，不能作为主文案。
字段可信摘要展示 `source_policy` 时，必须复用 `phase1EmployeeEvidencePolicyText` 映射成员工可读证据口径；原始 `source_policy` 只能保留在标题追溯或结构化响应中，不能作为主文案。
缺失字段摘要展示 `data_gap_codes` 和 `missing_field_codes` 时，必须把 `available_room_nights_missing`、`commission_fields_missing`、`net_revenue_fields_missing`、`lead_time_fields_missing`、`cancellation_fields_missing`、`cancel_room_nights_missing`、`competitor_price_fields_missing` 等缺口码映射成员工可读的业务影响和处理动作；原始缺口码只能保留在标题追溯或结构化响应中，不能作为员工主文案。缺口来源也必须显示成“数据缺口 / 字段缺口”，不能直接把 `data_gaps` 或 `missing_field_codes` 当作主文案。
数据健康页的“字段缺失 / 定义”面板必须把字段名、平台来源、业务模块、入库位置和字段状态映射成员工可读文案；`field`、`source`、`module`、`source_fields`、`storage_table`、`asset_status` 等原始机器口径只能保留在标题追溯或结构化响应中，不能作为员工主文案。`privacy_boundary` 必须显示为“隐私边界”，`not_collected` 必须显示为“不采集/不入库”，禁止采集字段必须明确显示“禁止采集”，且不能改变携程/美团手动或自动获取逻辑。
数据健康页的“失败原因”列表和排行榜必须把 `platform`、`type`、`reason`、`next_action` 映射成员工可读的平台、失败类型、失败原因和下一步动作；原始失败类型、机器码、接口路径或平台返回原文只能保留在标题追溯或结构化响应中，不能作为员工主文案。授权/登录、目标日源数据缺失、字段结构异常、流量/转化缺失、标准事实或收益指标未就绪必须有稳定可读文案，且不能改变携程/美团手动或自动获取逻辑。
数据健康页的“授权记录 / 携程授权记录”必须把 `authorization.list[].platform`、`status`、`message`、`action_hint` 映射成员工可读的平台、授权状态、授权说明和下一步动作；原始平台码、状态码、接口消息或动作提示只能保留在标题追溯或结构化响应中，不能作为员工主文案，也不能改变携程/美团手动或自动获取逻辑。
平台自动获取页的“美团 / 携程 Profile 状态”必须把 `platformProfileStatus.items[].status_code`、`current_status`、`profile_key`、`binding.profile_id`、`binding.store_id`、`binding.poi_id`、`next_action` 和登录任务 `status_text/message` 映射成员工可读的登录状态、绑定状态、下一步动作和登录任务状态；原始 Profile、门店标识、POI、状态码或任务消息只能保留在标题追溯或结构化响应中，不能作为员工主文案，也不能改变携程/美团手动或自动获取逻辑。
数据健康页“采集覆盖统计”里的携程采集目录必须把 `capture_gate_status`、`auth_status`、`failed_check_ids`、`capture_gap_status`、`capture_gap_blockers`、`default_sections`、`wide_sections` 和 `capture_gap_next_actions[].section/endpoint_id` 映射成员工可读的采集状态、授权状态、未通过检查、阻塞原因、采集范围和下一步动作；原始状态码、section key、endpoint id 只能保留在标题追溯或结构化响应中，不能作为员工主文案，也不能改变携程手动或自动获取逻辑。

`revenue_metric_evidence` 必须作为只读摘要挂在 `phase1_employee_questions` 和 dashboard 数据源中，来源口径为 `read_existing_ota_standard_revenue_metrics_only`，只暴露 `metric_trust_keys`、`data_gap_codes`、`metric_trust_key_count`、`data_gap_count`、`status`、`metric_scope=ota_channel` 和脱敏后的 `data_gaps` 摘要。它不能暴露 `raw_data`，也不能把 `metric_trust` 存在解释成携程/美团目标日均已采到。

`ai_evidence` 摘要必须把 `blocking_missing_codes` 和行级 `blocking_gap_codes` 合并映射成员工可读“阻断缺口”；只要这些缺口存在，`data_gaps` 主展示就必须显示“已返回”，不能误写成“缺失”。AI 摘要必须展示员工可读“判断”和“限制”：上游 OTA 证据未补齐时，应说明“AI 建议依据已暴露上游缺口，动作项仍被阻断”，并明确“不能把 blocked 动作项当成可执行经营建议”。原始 `blocked_action_codes`、`blocking_gap_codes`、`evidence_sources`、`data_gaps`、`action_items` 只能保留在结构化响应或标题追溯中，不能作为主展示文案。

`operation_execution_evidence` 必须作为只读摘要挂在 `phase1_employee_questions` 和 dashboard 数据源中，来源口径为 `read_existing_operation_execution_state_only`，只能读取现有 `/api/operation/execution-flow` 或同结构执行流，不创建执行意图、不写执行证据、不改变 OTA 采集逻辑。它必须暴露 `operation_evidence_status`、`execution_intent_count`、`execution_flow_item_count`、`ota_diagnosis_linked_intent_count`、`approved_count`、`executed_count`、`evidence_ready_count`、`reviewed_count`、`roi_ready_count`、`completion_signal_count`、`blocking_missing_codes` 和 `raw_data_exposed=false`。只有执行流可追溯到 `ota_diagnosis` 且出现审批、执行、证据、复盘或 ROI 任一完成信号时，`next_operation_action` 才能被证明；普通手工执行流只能显示 `operation_execution_ai_action_link_missing`，已关联但缺完成信号只能显示 `operation_execution_evidence_incomplete`。
运营执行摘要必须展示员工可读“判断”和“限制”：没有 `execution_intent_count` 和 `execution_flow_item_count` 时，必须说明“还没有可追溯执行意图或执行流”；没有审批、执行、证据、复盘或 ROI 完成信号时，必须说明“不能证明动作已落地”；没有 `ota_diagnosis_linked_intent_count` 或 `ota_diagnosis_linked_flow_item_count` 时，不能把未关联 OTA 诊断的普通执行记录算作闭环。

收入、流量、转化必须暴露指标域级证据键：`revenue_traffic_conversion.evidence.revenue_ready_platforms`、`traffic_ready_platforms`、`conversion_ready_platforms` 说明可复核平台；`revenue_missing_platforms`、`traffic_missing_platforms`、`conversion_missing_platforms` 和 `metric_domain_gap_codes` 说明缺失平台与后续动作缺口，不能只显示一个笼统的 warning。
收入/流量/转化证据摘要展示 `platform`、`target_date_data_types`、`source_rows`、`traffic_rows`、`revenue_status`、`traffic_status`、`conversion_status` 时，必须映射成员工可读的平台、目标日源数据、流量事实、经营/收益、流量/转化和可复核/缺失状态；`traffic_rows=0` 也必须显示为“流量事实 0 行”，不能因为是 0 就隐藏。`revenue_traffic_conversion.evidence.metric_domain_summary` 必须逐平台暴露员工可读的 `platform_label`、`revenue_text`、`traffic_text`、`conversion_text`、`source_text`、`problem`、`next_action` 和 `policy`，前端优先展示该摘要，旧数据缺该字段时才回退到 `metric_domain_readiness` 映射。每个平台卡片还必须展示员工可读“判断”和“处理”：例如收益可复核但流量/转化缺失时，明确说明只能先复核收益、不能判断曝光到下单漏斗，并提示补齐流量/转化事实后复核漏斗诊断；目标日源数据为 0 时，必须说明收益、流量、转化都不能证明。原始 `ctrip`、`meituan`、`business`、`traffic`、`target_date_data_types`、`revenue_status`、`traffic_status`、`conversion_status` 和 `missing_domains` 等机器口径只能保留在标题追溯或结构化响应中，不能作为员工主文案。缺少流量或转化事实时必须明确显示“流量/转化缺失”，不能用收益 ready 掩盖。

`traffic_source_readiness` 必须同步暴露 P0 酒店级流量 gate 元数据：`p0_traffic_gate_status`、`p0_next_action_mode`、`p0_next_action_entry`、`p0_next_step_count`、`next_command_policy=metadata_only_no_sensitive_commands`、`p0_external_evidence_status`、`p0_pre_import_evidence_status`、`p0_pre_import_evidence_policy`、`p0_traffic_field_fact_status`、`p0_required_metric_keys`、`p0_required_storage_fields`、`p0_required_field_fact_keys`、`p0_missing_metric_keys`、`p0_target_traffic_data_types`、`p0_source_chain_reference_only`、`p0_source_chain_scope`、`p0_source_chain_policy` 和 `manual_login_state_verified` 缺口。`p0_next_action_mode=browser_profile` 只表示建议用授权浏览器 Profile 补齐美团目标日流量证据，`p0_pre_import_evidence_status=not_provided` 只表示当前未提供外部预导入证据，`p0_required_metric_keys` 与 `p0_required_storage_fields` 只是闭环要求清单，三者都不能表示采集已成功、目标日入库完成或 P0 闭环完成；即使 `target_date_traffic_rows > 0`，员工端 `p0_traffic_gate_status` 也只能先标记 `requires_p0_verifier`，不能仅凭行数显示 P0 ready，必须由 P0 field-loop verifier 证明字段事实、入库值和 UI 状态均 ready 后才可视为闭环。员工端只能展示建议模式、酒店级步骤数、证据状态和所需指标/入库字段数量，不展示 Cookie、token、Profile 原值或可直接复制的敏感命令。P0 field-loop verifier 还必须暴露 `source_chain_reference_only` 和 `source_chain_scope`，员工端 `traffic_source_readiness` 必须暴露对应的 `p0_source_chain_reference_only` 和 `p0_source_chain_scope`：当目标日 source rows 只有 `business` 等非 `traffic/flow/conversion` 类型时，必须标记为 `reference_only_non_traffic_source_rows`，不能把 source_path/metric/storage 的参考闭环当成 P0 流量闭环。

## 不完成口径

- 只要 `verify:public-entry` 或 `verify:e2e-contracts` 失败，就不能声明前端入口完整。
- 只要没有真实当天携程/美团采集样例，就不能声明“今天数据已可信采到”。
- “今天有没有采到”必须优先读取 `source_date_evidence.platforms`；入库日志、回放行或历史分析行只能作为参考，缺少 `source_date_evidence` 时必须显示 `source_date_evidence_missing`，不能推断携程/美团目标日均已采到。
- 只有字段资产定义或字段列表，不能直接声明字段可信；必须结合目标日样例、`metric_trust` 和数据质量状态。
- 收入、流量、转化必须按目标日 `online_daily_data` 平台数据类型判断；历史分析样本或汇总卡片只能作为参考，不能替代目标日指标域证据。
- 只要 AI 诊断没有带真实 `evidence_sources` 和 `data_gaps` 样例，就不能声明 AI 建议闭环完成。
- 只有 `evidence_sources` 和 `action_items`、但响应缺少 `data_gaps` 字段时，只能显示需复核，不能声明 AI 建议依据已证明。
- 只有 `evidence_sources` 但 `action_items` 缺失，或所有 `action_items` 都是 `blocked_by_*`，只能显示需复核，不能声明 AI 建议可执行。
- 只要执行意图没有真实审批、执行证据和复盘样例，就不能声明运营闭环完成。
- 运营执行记录必须能追溯到 `ota_diagnosis` 的可执行 `action_items`；普通手工执行流、未关联 OTA 诊断证据的执行单，不能证明“下一步动作”已闭环。
- 只有 blocked/pending 的执行意图、空执行流或阶段列表时，只能提示 `operation_execution_evidence_incomplete`，不能把“下一步动作”标成已证明。
- 不允许用空值、默认值、成功文案或本地兜底分析替代缺失、失败、未授权、未采集状态。
- 字段缺口解释必须遵循 `docs/phase1_ota_gap_explanation_matrix.md`，并通过 `verify:phase1-gap-explanations`。

## 验证命令

```powershell
npm.cmd run verify:phase1-employee-console
```

该命令只做结构化只读检查，不启动 OTA 采集，不访问外部平台，不写数据库，不改变携程/美团手动或自动获取逻辑。

## 2026-06-13 口径补充

- 当目标日 OTA 数据、收益指标或流量/转化事实存在已验证上游缺口时，`ai_evidence` 行必须显示 `warning`，并暴露 `diagnosis_status=blocked_by_verified_ota_gaps`、`blocking_missing_codes` 和 `source_policy=read_existing_ota_gap_evidence_only`。
- 前端员工六问卡片必须把上述证据显示为可读的 `AI状态`、`动作状态`、`证据口径`，并把 `diagnosis_status`、`action_item_status`、`source_policy`、`operation_evidence_status` 这类机器状态映射成员工可读状态文案。原始技术值只能保留在结构化数据或悬停/调试信息中，避免员工只看到机器字段而不知道 AI 建议被哪个 OTA 缺口阻断。
- 当 `next_operation_action.evidence.operation_evidence_status=missing` 但已有 `blocking_missing_codes` 和直接动作入口时，员工六问行必须显示 `warning`；这只表示“下一步动作已可定位但被证据缺口阻断”，不能当作运营执行闭环完成。
- 这种状态只说明 AI 建议被已验证 OTA 缺口阻断，不能当作可执行 AI 建议；只有上游证据已闭合但缺少真实 `/api/agent/ota-diagnosis` 响应时，才显示为普通 `missing`。
