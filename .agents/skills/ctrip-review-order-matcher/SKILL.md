---
name: ctrip-review-order-matcher
description: 用于宿析OS把携程点评与授权携程订单池做候选匹配、评分拆解和置信度巡检；PMS订单详情是可选增强证据，不是运行前提。
---

# 携程点评订单候选匹配

## 目标

读取当前用户授权范围内的携程点评和携程订单证据，输出点评到订单的候选、评分拆解、缺失证据和人工复核清单。默认不承诺唯一命中；只有订单号或渠道订单号完全一致时标记 `confirmed`。

## 宿析OS适配边界

- 线上业务入口使用 `app/service/CtripReviewOrderCandidateScoringService.php`；本技能的 `scripts/score_candidates.py` 用于离线重放和口径核对。
- PMS可选。没有PMS时使用登录后抓取并按系统酒店隔离的携程订单池；有PMS时可补内部订单号、金额、状态和详情复核证据。
- 不根据携程脱敏昵称反查客人身份，不尝试还原姓名、UID、手机号或头像。订单匹配与身份解析严格分开。
- 携程订单证据只用于携程OTA渠道分析，不扩大为全酒店经营事实。
- 门店映射不明确时不得给 `high_confidence`；房型映射不明确时保留候选并降级。

## 核心函数

- `build_matches(...)`：批量入口，同时检测同一订单命中多条点评。
- `score_candidate(...)`：单条点评与单张订单的评分核心。
- `room_score(...)`：显式映射、标准化同名、主体名和关键词评分。
- `date_score(...)`：离店后点评窗口评分；离店无时分秒时按当天14:00。
- `status_score(...)`：完成状态加分；取消、关闭、NoShow保留但不加分。
- `amount_score(...)`：金额大于0加分；0元或缺失保留并降级。
- `content_score(...)`：内容线索只做微调，不能单独定案。
- `has_strong_id(...)`：点评订单号与订单渠道订单号一致时强匹配。
- `final_status(...)`：输出 `confirmed / high_confidence / candidate / ambiguous / not_found`。

## 查询窗口

按顺序使用第一个能得到候选的窗口：

1. 点评明确入住/离店日期；
2. 点评发表日前0至14天离店；
3. 点评发表日前15至30天离店；
4. 点评入住月份整月。

只有以上窗口全部尝试后仍无候选才可输出 `not_found`。渠道不是携程的订单不进入候选池。房型冲突、取消、关闭、NoShow、0元、详情未复核的订单不静默丢弃。

## 评分与状态

完整分值和边界见 [matching-rules.md](references/matching-rules.md)。

- `confirmed`：强订单标识完全一致。
- `high_confidence`：总分至少75、候选唯一、房型明确、时间合理、有效入住状态、金额大于0、详情已复核、门店映射已复核，且没有跨点评重复命中。
- `candidate`：存在候选但缺少一项或多项高置信证据。
- `ambiguous`：前两名分差小于10、同一订单命中多点评、房型冲突或时间硬冲突。
- `not_found`：全部窗口均无候选，或授权携程订单池为空。

## 执行流程

1. 核对系统酒店和携程门店映射；不明确时只输出待确认映射，不给高置信。
2. 抓取或读取授权携程点评，保留点评ID、发表时间、入住信息、房型、正文和可见订单标识。
3. 读取同系统酒店下的携程订单池；PMS数据若存在则作为增强字段合并。
4. 用 `build_matches(...)` 批量评分，不能逐条独立运行后忽略重复订单冲突。
5. 保存并回显评分、依据、缺失证据、窗口、候选和复核标记。
6. 至少重放一个正常样例、一个低置信样例和一个 `not_found` 样例。

## 离线使用

```bash
python scripts/score_candidates.py --input review_order_input.json --output scored_matches.json
```

输入字段：`reviews`、`orders`、可选 `room_mapping`、可选 `store_mapping_verified`。脚本只输出订单证据，不输出或推断客人身份。

## 输出要求

必须包含门店/订单来源范围、使用窗口、候选排名、状态、总分、`score_breakdown`、依据、缺失证据、详情复核状态和重点复核清单。无法抓取、无权限、缺订单池、门店映射不明或窗口无候选时，要明确返回真实失败或缺失原因。
