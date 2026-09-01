# 美团酒店流量自检截图学习记录

## 结果

- 观察日期：2026-08-31
- 任务模式：storage_only
- 处置状态：absorption_candidate
- 学习成熟度：observed
- 三门：机制门 indeterminate；价值门 pass；复现门 fail
- 知识用途：reference_only，可检索，不可直接计算、决策或生成任务
- 指令边界：截图里的“聚金、获客币、扫码冲单、推广通”等文字是来源建议，不是宿析OS操作指令、效果证明或外部写入授权。

## 来源指纹

| 平台 | 保存文件 | SHA-256 | 尺寸 |
| --- | --- | --- | --- |
| 美团 | `docs/knowledge/meituan-traffic-self-check/sources/meituan-hotel-traffic-self-check-visible-reference.png` | `A1EB608EA9BB8DF34624C61629E40A602F0C3B6531B3875879128178CE8A2F67` | 1038 × 1182 |

原图由用户提供。截图没有显示官方来源 URL、发布主体、发布日期或生效日期，因此观察日期不能解释成平台规则生效日期，也不能证明页面结构和规则截至当前仍完整有效。

## 已核对的可见结构

截图把自检组织为两步：先识别自身与商圈流量位置，再用“有没有 / 我的数据近七天 / 同行标杆近七天 / 差距 / 运营提升”逐项检查流量构成。可见分类包括基础流量下的基础曝光、加权曝光，以及广告流量下的奖励曝光、付费曝光；经营指导卡另显示流量排名、基础曝光、奖励曝光、广告曝光。

截图还显示顶流、高流、中流、低流、曝光加权中、曝光中、商圈顶流、曝光榜单等标签，但没有给出任何计算公式、阈值、单位、同行范围、失败状态或真实门店输入输出，所以这些标签只作为来源术语保存。

## 最有价值的候选机制

```text
先校验门店、平台、七天窗口和同行范围
→ 识别来源可证明的自身/商圈位置
→ 拆分基础曝光、加权曝光、奖励曝光、付费曝光
→ 逐项展示有没有、自身值、标杆值、可比性和差距
→ 只为有证据的差距给出渠道运营候选动作
→ 外部套餐、投放或OTA动作保持待人工审批
```

关键失败合同是：自身数据缺失或未验证时，不补成 0，不计算差距，不标记低流，不直接建议购买聚金、获客币或推广通；应显示 `not_ready / unavailable` 并先补齐同门店、同平台、同七天窗口的事实。

## 指标映射边界

1. “曝光量”不能仅凭文字映射为曝光人数、整体曝光量或广告曝光量。
2. “基础曝光”不能静默等同于宿析已有的 `organic_exposure`。
3. “广告曝光”和“付费曝光”最多是 `ad_exposure` 的候选别名；缺少来源模块、定义、单位、日期和门店绑定时不得入事实链。
4. “同行标杆近七天”最多是 `peer_avg_value` 的候选结构；必须证明同指标、同窗口和同行范围可比。
5. 截图不包含当前酒店数据，不进入 `fact_ingestion`，也不支持全酒店经营结论。

## 知识入口

- 全局知识单元：美团酒店流量自检（截图参考）
- 知识片段：
  - `meituan_traffic_self_check_visible_reference`
  - `meituan_traffic_self_check_mechanism_candidate`
  - `meituan_traffic_self_check_metric_boundary`
- 镜像入口：Knowledge Center 全局知识库
- 结构化来源：`docs/knowledge/meituan-traffic-self-check/source-manifest.json`
- 结构化参考：`docs/knowledge/meituan-traffic-self-check/reference-pack.json`

## 晋级条件

只有取得可定位的当前美团官方页面或结构化响应、明确字段定义，并获得同门店同平台同七天窗口的正常输入输出样例与至少一个缺失/不可比/失败样例后，才重新评估正式吸纳。补证前保持 `absorption_candidate + reference_only`，不进入流量档位、同行差距、经营任务或外部投放执行链。
