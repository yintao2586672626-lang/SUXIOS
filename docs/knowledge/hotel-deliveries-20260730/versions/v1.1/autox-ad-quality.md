# 广告质量分型、逐字段校验与真实零值证明

来源：用户提供 hotel_auto_x 交付包，指纹见 source-manifest.json；代码出处为 `pipeline/ad_quality.py:L8-147`。原样例 `tests/test_ctrip_ad_api_quality.py:L72-105`；详细合同、AST重放脚本和14个结果在 `output/absorption/20261006-hotel-deliveries/`。这不是当前门店广告事实。

输入：门店/平台/目标日期、是否开推广、必填广告指标、API不可用原因及零值证明。动作应先判来源失败/未采集，再逐字段完整性、数值有效性及来源范围，最后区分活动开关与真零。输出按字段携带质量原因、来源、观测日期和回读状态，不能只凭汇总成功状态放行。

原实现的可学状态包括 unknown、collect_failed、promo_active_partial_metrics、promo_active_with_metrics、promo_active_zero_metrics、promo_active_zero_verified、api_target_missing、no_promo_with_metrics。它把“无推广但有指标”保留为冲突，并能区分目标日期缺失与未验证零。

本轮只隔离执行3个常量和3个纯函数AST；无原包导入、网络、环境、数据库或外发。14/14合成样例复现实际返回，且输入不修改。复现与质量验收是两回事：

- 正常：完整正指标→active_with_metrics；少必填→partial；目标日期缺失→api_target_missing。
- 零值：全零无证明→zero_metrics；来源标记有证明→zero_verified，函数没有验证标记真实性。
- 已复现缺陷：Inf→with_metrics；NaN没有invalid状态；非法字符串可被忽略。非法字符串+verified标记、缺3个必填+verified标记、负费用与正点击抵消+verified标记，均可能进入verified zero。
- 核心原因：L64-71把不同量纲指标求和，缺失按0、非法字符串略过、非有限数未拒绝；L38-61的verified-zero分支未强制逐字段必填和证据核验。和为0不等于每个指标真为0。

五轮自纠补充7组来源反例：正指标或verified-zero分支能优先覆盖`_report_missing/api_target_missing`冲突；布尔数值True被当1，负数非零被标成zero_metrics，全部有限输入的总和也可能溢出。控制样例同时证明`_error`确实优先、字符串`"false"`不会被`is True`误认成零值证明；不凭印象虚构truthiness缺陷。出处L41-60,L64-71，精确输入输出见five-pass/round-3-source.json。

宿析适配合同：先绑定租户/酒店/平台门店/业务日期，再检查每个字段finite、必填、有效域与来源完整性；真实零须来自服务端可核对源证据与独立保存回读，不能接受客户端或外部包的verified布尔标记。缺字段、迟到、失败、冲突保持null与原因，不补零；可以分别展示可信的已知子项，但不放行缺口上的ROAS、整体预算或全酒店结论。

现有 `HotelCollectionQualityJudgmentService.php:L90-197,L210-294` 已包含执行/来源/质量/精确回读身份；因此当前最有价值是保留状态设计和这些反例，后续按具体来源缺口扩展现有合同。未确认独特且当前必需的功能缺口，本轮不复制新的广告引擎。

处置：参考储存 `store_only`；整个能力单元 `absorption_candidate / understood`，具名纯函数子机制有局部 `reproduced` 证据。完整真实零值来源证明、持久化和下游放行仍未复现。未达到 integrated，不成为当前事实、默认阈值、经营任务或外部动作。
