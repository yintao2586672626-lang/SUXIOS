# 中国行政区划维表

## 范围

- 只引入省/市/区县三级，表名：`dim_china_divisions`。
- 数据来源：`modood/Administrative-divisions-of-China` 的 `dist/provinces.json`、`dist/cities.json`、`dist/areas.json`。
- 来源版本：`2023-statistical-division-code`。
- 来源截止日期：`2023-06-30`，上游项目已标注不再更新。

## 业务定位

这张表用于宿析 OS 的地址、门店、竞品、投资项目和区域筛选标准化。它不是 OTA 经营事实源，不提供客流、竞品价格、订单、间夜、转化、ADR、出租率或 RevPAR。

在 `OTA 数据 -> 收益分析 -> AI 决策 -> 运营管理 -> 投资决策` 链路中，它只作为区域维度：

| 使用位置 | 可用方式 | 禁止口径 |
|---|---|---|
| 门店/投资项目基础信息 | `city`、`district`、`address` 标准化为 code | 不把行政区划当作商圈热度 |
| 竞品酒店样本 | 辅助同城/同区县归类和去重 | 不代表平台竞争圈或全市场样本 |
| 投资评估筛选 | 按省、市、区县聚合候选项目 | 不进入 ADR、出租率、RevPAR、收益预测公式 |
| OTA 数据归因 | 辅助城市/区县文本归一 | 不改写 OTA 原始来源字段 |

## 字段

| 字段 | 说明 |
|---|---|
| `code` | 行政区划代码，省 2 位、市 4 位、区县 6 位 |
| `name` | 行政区划名称 |
| `level` | `province`、`city`、`district` |
| `parent_code` | 父级 code，省级为空 |
| `source` | 固定为 `modood/Administrative-divisions-of-China` |
| `source_version` | 固定为 `2023-statistical-division-code` |
| `source_cutoff_date` | 固定为 `2023-06-30` |
| `is_active` | 当前版本是否有效 |

## 匹配状态

后续把酒店、竞品或投资项目地址映射到此表时，匹配状态必须显式保存，不能用默认值掩盖问题：

| 状态 | 含义 |
|---|---|
| `exact` | 输入文本与唯一行政区划匹配 |
| `missing` | 未匹配到 code |
| `ambiguous` | 多个候选，需人工确认 |
| `stale_source` | 来源版本可能过旧，需复核官方数据 |

对外报告可合并展示为 `missing / ambiguous / stale_source` 风险提示。

## 验证

```powershell
node scripts/verify_china_division_contract.mjs
```

该验证会检查：

- `database/init_full.sql` 已接入 migration。
- `dim_china_divisions` 只允许 `province/city/district`。
- seed 行数为 31 省、342 市、2978 区县。
- 市级必须挂省级父 code，区县必须挂市级父 code。
- 文档明确说明该表不是经营事实源。
