# 携程点评规则与治理知识沉淀

## 资料边界

| 项目 | 说明 |
| --- | --- |
| 来源 | 用户提供的携程/Trip.com 点评规则、常见问题、点评展示、差评反馈、酒店回复截图 |
| 截图规则时间 | 截图中显示的规则更新时间为 2026-04-03 |
| 联网复核 | 已补充检索携程公开点评规则页、携程酒店商家经营规则、eBooking 公开说明；执行时仍以当前 eBooking 后台和平台规则页为准 |
| 验证状态 | `user_image_20260705_partial_official_checked` |
| 沉淀目的 | 服务宿析OS的 OTA 点评治理、评分解释、差评处理、AI 建议边界和订单/点评辅助匹配原因解释 |
| 非目标 | 不新增采集功能；不新增业务表；不实现自动查差评人；不输出匿名用户反查、手机号补全、账号还原或绕过平台规则的方法 |

本文只沉淀平台规则和系统口径，不保存订单号、手机号、Cookie、Token、完整账号、原始评论敏感明细或可识别住客身份的信息。

## 一、核心结论

| 结论 | 宿析OS使用方式 |
| --- | --- |
| 携程点评存在“可点评窗口、展示窗口、计分窗口、折叠规则、回复窗口”多套口径 | 宿析OS知识库完整沉淀这些平台规则；插件实现口径单独收敛，见“8.4 插件实现口径” |
| 离店后 90 天是携程点评、追评和商家回复的重要窗口 | 知识库记录为可点评/追评/回复窗口；插件按“点评发布时间 - 订单离店日”判断该订单是否可能产生这条点评 |
| 已经出现在点评页的历史点评不因超过 90 天而变成匹配失败 | 90 天不能证明某个客人以前没写过点评；只能降低“这条新增点评由该订单产生”的候选权重 |
| 近 3 年有效点评影响展示/计分解释 | 知识库记录为平台展示/计分口径；插件匹配页默认不展示 3 年口径，也不把超过 3 年当成订单匹配失败 |
| 同一用户同一酒店半年内多条点评会被系统选取/折叠 | 知识库保留平台规则；插件展示时按“同一点评用户组 + 候选订单数”辅助判断 |
| 第三方点评可能被展示但不参与携程自有点评分 | 知识库保留第三方展示/评分规则；当前携程 IM 匹配插件不处理非携程客人 |
| 携程不向商家提供“谁写了差评”的查询能力 | 系统只能做“授权证据下的本地辅助匹配”，不能命名为“差评人查询” |
| 退款成功不消灭客人点评权 | 差评处理应回到事实、证据、回复、申诉和整改，不应假设退款后可删除点评 |
| 酒店回复也受审核、字数、内容和时间限制 | AI 生成回复必须做合规检查，不输出联系方式、隐私、辱骂、威胁、误导或明显与事实不符内容 |

## 二、点评生命周期规则

### 2.1 可点评与追评

| 规则项 | 口径 |
| --- | --- |
| 点评开始时间 | Ctrip 离店日 14:00 后；Trip 离店日 00:00 后 |
| 点评截止时间 | 离店后 90 天内 |
| 追评开始时间 | 点评结束后，每条点评可追评一次 |
| 追评截止时间 | 离店后 90 天内 |
| 提交后修改/删除 | 截图规则显示：点评一旦提交，用户无法自行修改和删除 |
| 不可点评常见原因 | 离店前、超过 90 天、订单未成交、现付订单酒店未审核入住、职业差评账号等 |

宿析OS标签建议：

| 标签 | 含义 |
| --- | --- |
| `eligible_to_review` | 仍在可点评窗口内 |
| `expired_90d` | 已超过离店后 90 天，不再作为可新增点评或回复窗口 |
| `follow_up_possible` | 仍可追评 |
| `follow_up_expired` | 追评窗口关闭 |
| `order_not_eligible` | 订单状态不满足点评条件 |
| `platform_restricted_reviewer` | 平台账号规则限制点评 |

### 2.2 审核与展示

| 规则项 | 口径 |
| --- | --- |
| 审核要求 | 点评需通过审核后才发表；内容包括评分、文字、图片、视频 |
| 审核时效 | 截图显示整体审核通常为 1-2 个工作日 |
| 文字先过、图片/视频后过 | 文字已通过但图片/视频审核中时，可能先展示文字 |
| 审核不通过 | 涉及违法违规、虚假体验、隐私泄露、抄袭、广告营销、低参考价值、不当语言等时不展示或处理 |
| 折叠/置底 | 低参考价值或平台认为不适合直接展示的点评可能被折叠或置底 |

宿析OS标签建议：

| 标签 | 含义 |
| --- | --- |
| `audit_pending` | 审核中 |
| `text_visible_media_pending` | 文字已展示，图片/视频待审核 |
| `audit_rejected` | 审核未通过 |
| `visible` | 正常展示 |
| `folded` | 被折叠 |
| `bottomed` | 被置底 |
| `display_unknown` | 展示状态未知 |

## 三、计分与展示规则

### 3.1 点评分展示

| 规则项 | 口径 |
| --- | --- |
| Ctrip 点评分 | 5 分制 |
| Trip.com 点评分 | 截图显示自 2025-04 起切换到 10 分制；执行前需以当前 Trip.com 官方页为准 |
| 展示小数 | 截图显示酒店点评分展示取 1 位小数，按向下取 1 位处理，例如 4.79 展示为 4.7 |
| 近 3 年有效点评 | 近 3 年有效点评参与展示/计分解释；过期点评不展示且不计分 |
| 点评分延迟 | 点评审核通过当天可展示，但点评分可能按日滚动更新，存在延迟 |

字段建议：

| 字段 | 说明 |
| --- | --- |
| `raw_score` | 平台原始评分 |
| `score_scale` | `5_point` / `10_point` / `unknown` |
| `display_score` | 平台页面展示分 |
| `score_display_rule` | 例如 `floor_to_1_decimal` |
| `score_update_status` | `same_day_pending` / `updated` / `unknown` |

### 3.2 计分、仅展示与第三方补充

| 场景 | 系统处理 |
| --- | --- |
| 携程自有点评 | 可作为携程自有点评分解释样本 |
| 自有点评不足 5 条 | 截图显示可能融合可信第三方评分；需标注为 `third_party_score_reference`，执行前复核后台 |
| 自有点评少于 40 条 | 携程网页可能引用第三方点评补充展示；第三方点评仅展示内容，不参加携程点评分计算 |
| 自有点评达到 40 条 | 截图显示携程站不再引用其他平台点评展示 |
| 第三方点评 | 不应强行匹配携程订单；匹配失败原因应显示为 `third_party_display_only` |

字段建议：

| 字段 | 说明 |
| --- | --- |
| `review_origin` | `ctrip_owned` / `third_party` / `unknown` |
| `source_platform` | `ctrip` / `trip` / `qunar` / `tongcheng` / `other` |
| `score_count_mode` | `counted` / `display_only` / `score_reference` / `not_counted` / `unknown` |
| `third_party_mode` | `not_third_party` / `display_only` / `score_reference` |

## 四、同一用户多条点评选取规则

截图规则显示，同一用户在半年内入住同一家酒店并存在多条点评时，平台会结合新鲜度、真实性、观点差异性，选取更全面的点评展示。计分仍保持一人一票制。

| 场景 | 新规则保留结果 |
| --- | --- |
| 同时存在 `>=3分` 和 `<3分` 点评 | 保留两条点评：选取 `>=3分` 中质量最高的一条，以及 `<3分` 中质量最高的一条 |
| 全部 `<3分` | 仅保留质量最高的一条 |
| 全部 `>=3分` | 仅保留质量最高的一条 |

质量最高的判断依据：

1. 系统考虑新鲜度，入住时间越近优先级越高。
2. 若入住时间相同，则点评时间越新优先级越高。
3. 同时结合真实性和观点差异性。

宿析OS处理规则：

| 系统场景 | 处理方式 |
| --- | --- |
| 一个用户写多条点评 | 按 `reviewer_group_key` 聚合，不重复当成多个独立点评用户 |
| 多条点评都能匹配同一客人 | 展示为“同一用户多评”，并标注平台可能只保留 1-2 条 |
| 同一用户多评影响评分解释 | 不直接按条数加权；使用 `one_user_one_vote` 标记 |
| 历史差评重新被展示 | 标注为 `historical_negative_selected`，用于差评复盘和服务整改 |

字段建议：

| 字段 | 说明 |
| --- | --- |
| `reviewer_group_key` | 脱敏后的同一点评用户聚合键 |
| `multi_review_window` | `half_year` / `unknown` |
| `multi_review_selection` | `positive_and_negative_selected` / `single_best_selected` / `unknown` |
| `selection_reason` | `freshness` / `authenticity` / `viewpoint_difference` / `unknown` |
| `one_user_one_vote` | 布尔值 |

## 五、订单、IM 与点评辅助匹配边界

### 5.1 不能做的事

截图规则明确：携程不能查到谁写了差评，此信息属于客人隐私，平台不做任何点评查询。

宿析OS禁止把以下能力产品化：

- “查询差评人”
- “匿名点评人反查”
- “手机号补全”
- “通过头像/昵称/MD5 还原真实账号”
- “绕过平台隐私规则定位住客”
- “自动生成枚举字典或破解流程”

### 5.2 可以做的事

在用户合法授权、平台后台可见且数据最小化的前提下，宿析OS可以做：

| 能力 | 边界 |
| --- | --- |
| 本地辅助匹配 | 仅基于已授权 IM、订单、点评页面可见数据，输出置信度和原因 |
| 订单线索关联 | 有 `orderNo/orderId` 时可作为强证据；无订单号时不能强行确认 |
| IM 客人线索 | `guest_uid/partnerId/group_id` 只作为本地证据，不等同平台官方身份确认 |
| 未匹配原因解释 | 明确显示无 IM UID、第三方点评、超过 90 天、同名多订单、昵称规则无法还原等 |
| 人工补证 | 引导补充合法证据，如订单截图、后台工单、整改照片、站内沟通状态 |

匹配结果分类：

| 分类 | 典型原因 |
| --- | --- |
| `matched` | `matched_order_id` / `matched_guest_uid` / `matched_partner_id` / `unique_order_confirmed` |
| `suspected` | `nickname_rule_hit` / `avatar_hint_hit` / `room_time_partial_match` / `same_name_multiple_orders` |
| `unmatched` | `no_im_guest_uid` / `nickname_rule_unrecoverable` / `no_order_link` / `not_in_collected_range` |
| `platform_rule_not_matchable` | `third_party_display_only` / `expired_90d` / `expired_3y_or_not_displayed` / `folded_by_platform` / `privacy_not_queryable` |

## 六、差评反馈与证据闭环

### 6.1 退款与差评

截图规则显示：客人对入住体验不满意而投诉，退款/赔偿不会与客人的点评权利冲突，不能消除差评。

系统处理：

| 场景 | 标签 | 建议动作 |
| --- | --- | --- |
| 已退款但仍出现差评 | `refund_does_not_remove_review_right` | 记录退款原因、服务事实、补救动作和回复口径 |
| 客诉属实 | `service_issue_confirmed` | 生成整改任务、复查时间和回复草稿 |
| 客诉不属实但证据不足 | `evidence_insufficient` | 不承诺申诉成功，补充证据 |
| 疑似恶意差评 | `suspected_malicious_review` | 仅走平台合规申诉，保留证据，不反向骚扰客人 |

### 6.2 恶意差评处理

截图规则建议的合规处理路径：

1. 注意潜在“差评师”，但不要反向骚扰、引诱或威胁客人。
2. 对证据进行搜集保留，包括事件经过、视频、聊天记录、后台订单、酒店 ID、截图等。
3. 向平台进行点评反馈；确有直接证据时按平台流程处理。
4. 对没有确切证据的差评，可以做有理有据的公开回复。

证据字段建议：

| 字段 | 说明 |
| --- | --- |
| `evidence_status` | `none` / `partial` / `complete` / `platform_ticket_submitted` |
| `evidence_types` | `order_record` / `chat_record` / `room_inspection` / `photo_video` / `staff_log` / `refund_record` |
| `appeal_status` | `not_started` / `submitted` / `supplementing` / `closed_success` / `closed_failed` / `unknown` |
| `appeal_limit_status` | `can_supplement` / `closed_no_more_feedback` / `unknown` |

## 七、酒店回复规则

| 规则项 | 口径 |
| --- | --- |
| 字数 | 每条回复最多 4000 个字 |
| 语言 | 用词文明，不辱骂、不侮辱、不威胁 |
| 联系方式 | 避免留下电话、手机、邮箱、网址、微博、微信等联系方式 |
| 隐私 | 不侵犯客人隐私，不辱骂或阳晒客人 |
| 真实性 | 不出现明显误导性、与事实不符或严重夸张内容 |
| 审核展示 | 审核通过的回复将在 24 小时内更新到平台网站 |
| 回复窗口 | 仅允许在用户点评后 90 天内回复，超时无法回复 |
| 违规后果 | 回复不符合规则可能被处理；严重时可能禁止回复且无法恢复 |

AI 回复检查标签：

| 标签 | 说明 |
| --- | --- |
| `reply_length_ok` | 字数未超过限制 |
| `reply_contains_contact` | 含联系方式，需删除 |
| `reply_privacy_risk` | 含客人隐私或可识别信息 |
| `reply_abusive_language` | 含辱骂、威胁或不当语言 |
| `reply_fact_unverified` | 含未核实事实 |
| `reply_window_expired` | 超过 90 天回复窗口 |
| `reply_waiting_audit` | 回复待平台审核 |

## 八、宿析OS落地方式

### 8.1 知识库字段

不建议立刻新增业务表。优先沉淀到知识块，后续如进入结构化能力，再映射到 `raw_data` 或独立 review evidence 结构。

| 字段 | 建议值/说明 |
| --- | --- |
| `knowledge_domain` | `ota_review_governance` |
| `platform` | `ctrip` / `trip` |
| `rule_source` | `user_image_20260705` / `official_public_page` |
| `rule_verified_status` | `user_provided_unverified` / `partial_official_checked` / `backend_verified` |
| `rule_type` | `lifecycle` / `display` / `score` / `multi_review` / `matching_boundary` / `appeal` / `reply` |
| `business_scope` | `ota_channel_only` |
| `privacy_scope` | `minimum_necessary_desensitized` |
| `ai_guardrail` | `no_identity_reverse_lookup` |

### 8.2 宿析OS治理结果页展示建议

宿析OS后台治理页可以完整呈现平台规则口径。点评匹配或治理结果页建议按以下顺序展示：

1. `未匹配`：先列原因，便于补采和人工判断。
2. `疑似`：显示命中线索、置信度和需要补充的证据。
3. `已匹配`：显示命中字段和证据来源，但隐藏敏感信息。
4. `平台规则不可匹配/不可计分`：展示第三方、折叠、过期、隐私不可查询等原因。
5. `需处理`：差评、待回复、需申诉、需整改、需复核。

每条记录至少显示：

| 字段 | 示例 |
| --- | --- |
| `match_status` | `unmatched` / `suspected` / `matched` / `platform_rule_not_matchable` |
| `match_reason` | `no_im_guest_uid` / `third_party_display_only` / `matched_guest_uid` |
| `score_count_mode` | `counted` / `display_only` / `not_counted` |
| `display_mode` | `visible` / `folded` / `bottomed` / `unknown` |
| `action_hint` | `reply` / `appeal` / `service_recovery` / `no_action` |

### 8.3 AI 输出边界

AI 可以输出：

- 点评是否在 90 天点评/回复窗口内。
- 点评是否可能属于第三方展示、不计分、折叠或过期。
- 同一用户多评时的聚合和计分解释。
- 差评的证据清单、回复草稿、整改任务和复核计划。
- 匹配失败的原因分类和下一步补证建议。

AI 不可以输出：

- 匿名点评人的真实身份。
- 手机号、微信、账号、Cookie、Token 或完整订单号。
- 通过头像、昵称、MD5、IM 成员枚举还原身份的步骤。
- 承诺删除、修改、隐藏或恢复平台评分。
- 诱导、胁迫、利益交换、刷评或规避平台规则的 SOP。

### 8.4 插件实现口径

当前携程点评辅助插件只服务“点评页可见点评 -> 本地订单/IM线索辅助匹配”，不承担完整平台治理、评分解释、折叠判断或第三方点评分析。

| 插件问题 | 实现口径 |
| --- | --- |
| 90 天口径 | 以“点评发布时间 - 订单离店日”判断，不以当前日期简单排除。超过 90 天只能说明这笔订单理论上不应产生这条新增点评，不能证明该客人过去没写过点评 |
| IM 采集优先级 | 优先采离店日起 90 天内仍可能新增点评/追评的订单会话；90 天外优先使用缓存和已有证据，不做强否定 |
| 已出现的历史点评 | 点评已经在点评页出现时，超过 90 天不代表匹配失败；仍可用已有缓存、订单、IM、昵称、头像、房型和时间线索匹配 |
| 3 年展示/计分窗口 | 这是平台评分和展示口径，插件匹配页默认不展示，也不作为匹配失败原因 |
| 同一用户多条点评 | 聚合为“同一用户多评”，显示候选订单数和命中线索；不能按点评条数直接当成多个独立有效订单 |
| 第三方点评 | 携程 IM 无法触达非携程客人，插件不主动处理第三方点评；如页面明确标识第三方，仅作为无法走携程订单链路的背景信息 |
| 折叠、不计分、低参考价值 | 由平台统计和展示规则自行处理；插件暂不做建议，只保留知识库规则 |
| 没有订单号 | 不先做复杂点评类型判断；直接进入疑似范围计算或未匹配原因展示 |

插件匹配结果建议只保留三类主状态：

| 主状态 | 展示方式 |
| --- | --- |
| `unmatched` | 未匹配优先展示，列出缺少的关键线索，例如无 IM UID、无订单号、昵称规则无法还原、超出采集范围 |
| `suspected` | 给出疑似范围，显示候选人数/候选订单数、命中字段和置信度，不强行确认 |
| `matched` | 只在订单号、guest_uid、partnerId、人工唯一订单等强证据满足时展示已匹配 |

疑似范围建议：

| 置信层级 | 典型线索 |
| --- | --- |
| 高 | 唯一候选订单；或 `guest_uid/partnerId/group_id` 命中且候选订单唯一 |
| 中 | 昵称规则命中、头像线索命中、房型/入住日期接近，但存在多个候选订单 |
| 低 | 只有房型、点评月份、模糊昵称等弱线索，只展示“疑似范围”，不展示为命中 |

插件界面文案建议：

- `超出该订单离店后的点评窗口：降低此订单对当前点评的候选权重`
- `已存在点评仍可匹配历史线索，90天不能证明客人过去未点评`
- `同一用户多评：候选订单 X 单，平台可能只采纳部分点评`
- `疑似匹配：命中昵称/头像/房型/时间线索，仍需人工确认`
- `未匹配：无 IM UID 或无可用订单链路`

### 8.5 插件辅助判断条件

插件运行在单酒店后台时，酒店一致是默认上下文，只做异常校验，不参与匹配加分，正常结果页不展示“酒店一致”。

#### 8.5.1 IM 订单接口证据

接口：`getImOrderListV2`

| 字段/组合 | 判断作用 | 权重 |
| --- | --- | --- |
| `hasSessionOrder=true` + `total=1` | 当前 IM 会话只关联 1 个订单，是最强候选订单证据 | 强 |
| `total>1` | 当前 IM 会话关联多笔订单，只能进入候选范围 | 中 |
| `total=0` 或 `hasSessionOrder=false` | 当前会话没有订单链路，不能靠订单接口锁定 | 排除订单侧命中 |
| `orderId/cusOrderId/formId` | 精确订单链路，用于串订单详情和去重；展示和日志必须脱敏 | 强 |
| `clientName` | 与 IM guest 昵称、人工唯一订单做一致性判断；展示时脱敏 | 强 |
| `arrivalDateTime/departureDateTime` | 用于判断点评发布时间是否落在离店后的可点评窗口内 | 强 |
| `roomName/roomEnName` | 与 IM 消息正文、点评页房型做归一化对比 | 中强 |
| `orderStatus`、页面订单状态 | 只作为订单有效性辅助判断；不要只凭数字含义直接下结论 | 中 |
| `allinanceName/sourceType` | 确认该订单来自携程链路 | 中 |

订单接口命中建议：

| 场景 | 输出 |
| --- | --- |
| `total=1` 且订单客人与唯一 guest 一致 | `matched: im_session_unique_order_and_guest` |
| `total=1` 但缺少 guest 成员 | `suspected_high: unique_im_order_without_guest_member` |
| `total>1` | `suspected: multiple_session_orders`，展示候选订单数 |
| `total=0` | `unmatched: no_im_order_link` |

#### 8.5.2 IM 消息与成员接口证据

接口：`queryMessageListByGroupId` 或同类消息列表接口。

| 字段/组合 | 判断作用 | 权重 |
| --- | --- | --- |
| `groupId` | 会话主键，用于串订单接口、消息接口、缓存游标 | 强 |
| `hasHistory=true` | 会话有消息历史，可提取房型、订单号、时间、服务场景等弱线索 | 中 |
| `members` 中唯一 `roleType=guest` | 当前会话客人成员唯一，可与订单客人合并判断 | 强 |
| `guest.uid` | 本地会话身份键；仅做哈希/脱敏存储，不对外暴露 | 强 |
| `guest.nickName` | 与订单客人姓名、点评昵称规则做辅助比对 | 中强 |
| `guest.pic` | 可与点评头像做辅助比对；只存链接哈希或脱敏摘要 | 中 |
| `messageBody.messageText` | 提取房型、订单号、到店时间、服务诉求等关键词 | 中 |
| `firstMessageTimestamp/lastMsgId/lastTimestamp` | 增量采集游标，判断会话是否需要重采 | 性能关键 |
| `hasHistory=false`、`members={}`、`messages=[]` | 接口成功但无消息/成员证据；不能否定订单接口命中 | 排除消息侧命中 |

消息接口命中建议：

| 场景 | 输出 |
| --- | --- |
| 唯一 guest + 唯一订单 + 姓名一致 + 房型一致 | `matched: unique_guest_unique_order_name_room` |
| 唯一 guest + 唯一订单，但房型缺失 | `matched_or_high_suspected: unique_guest_unique_order` |
| 多 guest 或多订单 | `suspected: multiple_guest_or_order_candidates` |
| 消息为空但订单唯一 | `suspected_high: unique_order_message_empty`，不降为未匹配 |
| 消息为空且无订单 | `unmatched: no_message_member_or_order_evidence` |

#### 8.5.3 弱证据和不参与匹配的字段

| 字段/接口 | 使用方式 |
| --- | --- |
| `enterLists` 电话入口 | 只说明订单联系入口可用，不证明点评归属；不参与匹配加分 |
| `CLOGGING_TRACE_ID/RootMessageId` | 仅用于调试，不展示、不长期保存 |
| 手机号、虚拟号、邮箱 | 不进入匹配结果，不写日志，不作为反查依据 |
| `hotelName/hotelId` | 单酒店后台里只做异常校验，正常不加分、不展示 |

#### 8.5.4 90 天候选窗口判断

90 天判断必须围绕“这条点评”和“这笔订单”做，不围绕当前时间做。

| 判断 | 结果 |
| --- | --- |
| `reviewPublishedAt >= departureDate` 且 `reviewPublishedAt - departureDate <= 90天` | 该订单可以作为当前点评候选 |
| `reviewPublishedAt - departureDate > 90天` | 降低该订单对当前点评的候选权重，原因写“超出该订单点评窗口” |
| 当前日期距离离店超过 90 天 | 只影响是否继续硬采 IM，不证明历史上没点评 |
| 已存在点评但没有历史 IM 缓存 | 写“未采到历史 IM 证据”，不要写“不是该客人” |

#### 8.5.5 性能优先级

| 优化点 | 做法 |
| --- | --- |
| 先订单后消息 | 先跑 `getImOrderListV2`，`total=1` 时只补轻量成员/房型证据，避免深扫消息 |
| 真增量 | 用 `groupId + lastMsgId/lastTimestamp` 做游标，未变化会话直接跳过 |
| 索引预计算 | 采集后建立 `groupId`、`guestUid`、`orderId`、`clientName`、`avatarHash`、`roomToken+date` 索引 |
| UI 虚拟列表 | 结果和日志不全量渲染 DOM，优先展示未匹配、疑似、已匹配 |
| 自适应限速 | 正常小并发，连续超时/页面卡顿时降速，恢复后再提速 |

## 九、与现有文档关系

| 文档 | 关系 |
| --- | --- |
| `docs/ota_review_platform_rules_knowledge.md` | 多平台点评规则总览；本文是携程/Trip 点评规则专项补充 |
| `docs/ota_all_channel_review_method_20260701.md` | 点评运营方法沉淀；本文补充携程官方规则、展示/计分和回复边界 |
| `docs/ctrip_review_orderer_extension_learning_20260629.md` | 携程点评/IM 辅助匹配插件学习；本文补充平台规则和隐私边界 |
| `docs/ota_external_script_learning_20260629.md` | 外部采集脚本学习边界；本文继续保持只沉淀规则，不默认启用采集 |

## 十、后续接入建议

1. 先作为 Agent 检索材料和人工知识库使用。
2. 后续进入系统知识库时，按 `platform + rule_type + rule_verified_status` 拆成知识块。
3. 点评页或插件只使用这些规则解释“为什么未匹配/不计分/被折叠”，不把规则转成身份反查能力。
4. 如需产品化，优先做“点评规则解释卡”“差评证据清单”“回复合规检查”“同人多评聚合解释”。
5. 所有执行动作以当前 eBooking 后台、平台工单和官方规则页为准。
