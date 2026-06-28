# 携程云梯排名预测采集方式

状态：已记录为用户提供证据；已用本地授权 Profile 在 2026-06-28 live 探针命中 `getHotelSimulateRank`；已补入携程采集 catalog 的 fact-only 候选模块；未接入生产自动采集或自动入库。

## 业务边界

- 平台：携程 eBooking，OTA 渠道内的云梯推广模块。
- 页面入口：`https://ebooking.ctrip.com/toolcenter/ladder/home`
- 接口路径：`/toolcenter/api/ladder/getHotelSimulateRank`
- 示例请求：`GET https://ebooking.ctrip.com/toolcenter/api/ladder/getHotelSimulateRank?hostType=HE&v=<cache_bust>`
- 用途：记录云梯方案对未来日期排名和流量提升的预测信息，用于携程 OTA 渠道推广决策、收益诊断参考和 AI 建议草稿。
- 禁止扩大口径：不得把该接口结果解释成真实成交、实际排名结果、全酒店经营数据、ADR、RevPAR 或投资判断依据。

## 推荐采集方式

```text
授权携程 eBooking 浏览器 Profile
-> 打开云梯页面
-> 页面自行触发 getHotelSimulateRank
-> 监听 XHR / Fetch JSON 响应
-> 脱敏保存 endpoint evidence
-> 人工审核字段映射
-> 后续才可接入 raw_data / field facts / UI 状态 / verifier
```

操作步骤：

1. 使用已授权门店账号打开云梯页面，确认当前门店是目标酒店。
2. DevTools Network 过滤 `ladder` 或 `getHotelSimulateRank`。
3. 刷新页面或点击页面中的流量预测/云梯预测入口，等待接口返回。
4. 只复制脱敏后的 Request URL、method、Preview / Response、page context 和采集时间；不要保存 Cookie、Authorization、Token、完整 headers、账号信息或含敏感信息截图。
5. 若接口未命中、登录过期、返回空列表或字段缺失，必须记录缺失状态，不写默认值。

## 响应字段契约

| JSON path | 建议字段 | 类型 | 单位 | 说明 | 缺失状态 |
| --- | --- | --- | --- | --- | --- |
| `code` | `ctrip_ladder_response_code` | integer | - | 接口状态码；样例为 `0` | `api_error` |
| `data.participating` | `ctrip_ladder_participating` | boolean | - | 是否正在参与云梯推广 | `field_missing` |
| `data.rangeParticipating` | `ctrip_ladder_range_participating` | boolean | - | 是否处于范围参与状态；具体平台口径待页面文案复核 | `field_missing` |
| `data.hotelSimulateRankBoList[].effectDate` | `ctrip_ladder_effect_date` | date | 日 | 预测生效日期 | `field_missing` |
| `data.hotelSimulateRankBoList[].currentRank` | `ctrip_ladder_current_rank` | integer | 名 | 接口给出的当前排名字段；是否已含当前云梯效果需复核 | `field_missing` |
| `data.hotelSimulateRankBoList[].predicateRank` | `ctrip_ladder_predicted_rank` | integer | 名 | 接口字段名为 `predicateRank`，按页面语义暂记为预测排名 | `field_missing` |
| `data.hotelSimulateRankBoList[].originRank` | `ctrip_ladder_origin_rank` | integer | 名 | 原始/基准排名，通常用于对比预测提升 | `field_missing` |
| `data.hotelSimulateRankBoList[].effectValue` | `ctrip_ladder_strategy_ratio` | number | % | 样例对应页面策略比例 `15%` | `field_missing` |
| `data.hotelSimulateRankBoList[].increaseRatioValue` | `ctrip_ladder_estimated_traffic_lift` | number | % | 样例与页面流量提升预测折线相关；精确标签待复核 | `field_missing` |
| `data.hotelSimulateRankBoList[].participatePromoteValue` | `ctrip_ladder_participate_promote_lift` | number | % | 样例与页面推广提升折线相关；精确标签待复核 | `field_missing` |

## 派生指标候选

> 下列派生指标只作为后续映射候选。接入前必须用页面文案和多组响应复核。

| 指标 | 公式候选 | 说明 |
| --- | --- | --- |
| 预测排名提升位次 | `originRank - predicateRank` | 假设名次数字越小越好；正值表示相对原始排名提升。 |
| 当前到预测排名差异 | `currentRank - predicateRank` | 正值表示预测排名优于当前排名；负值表示预测排名弱于当前排名。 |
| 策略比例 | `effectValue / 100` | 样例中所有日期为 `15`，页面显示为 `15%`。 |

## 用户提供样例摘要

- 证据来源：用户在 2026-06-28 提供的 eBooking 云梯页面截图和 DevTools Preview JSON。
- 本地探针：2026-06-28 使用授权浏览器 Profile 打开云梯页面，命中 `getHotelSimulateRank` 响应 `1` 次，`hotelSimulateRankBoList` 返回 `14` 条，观测到行字段 `currentRank/effectDate/effectValue/increaseRatioValue/originRank/participatePromoteValue/predicateRank`，缺失状态为 `ok`。
- 页面显示：云梯，推广日期 `2026/06/28起持续投放`，策略比例 `15%`，推广平台 `携程+去哪儿`，提交时间 `2026/06/28 16:15`。
- 响应状态：`code=0`，`participating=true`，`rangeParticipating=true`。
- 样例列表：`hotelSimulateRankBoList` 共 `14` 条，`effectDate` 覆盖 `2026-06-28` 至 `2026-07-11`。
- 样例值范围：`originRank` 为 `59-193`，`predicateRank` 为 `32-124`，`currentRank` 为 `30-101`，`effectValue=15`，`increaseRatioValue=21-46`，`participatePromoteValue=30-60`。

## 接入前门禁

| 检查项 | 通过条件 |
| --- | --- |
| 授权范围 | 只来自客户自己账号可见的携程 eBooking 云梯页面 |
| 证据完整度 | 有脱敏 Request URL、Preview / Response、页面上下文、采集时间 |
| 敏感信息 | 不包含 Cookie、Token、Authorization、账号、手机号、完整 Profile 路径 |
| 结构校验 | `code=0` 且 `data.hotelSimulateRankBoList` 为数组 |
| 口径校验 | 页面文案确认 `predicateRank`、`increaseRatioValue`、`participatePromoteValue` 的真实含义 |
| 状态透明 | `api_not_hit`、`login_expired`、`empty_list`、`field_missing`、`schema_mismatch` 显式暴露 |
| 下游边界 | 只能作为 OTA 推广预测参考；不能自动写价格、创建运营执行记录或升级为投资判断 |
