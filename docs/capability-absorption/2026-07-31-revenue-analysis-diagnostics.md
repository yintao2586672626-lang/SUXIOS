# 收益分析数据校验与指标诊断吸纳卡

## 来源

- 插件：OpenAI Data Analytics
- 版本：`0.2.8-13ceeea1f599`
- 许可证：Proprietary
- 来源主页：<https://openai.com/>
- 吸纳 Skill：`analyze-data-quality`、`validate-data`、`metric-diagnostics`
- 本次未执行插件 MCP、Widget、脚本或外部数据连接器；未新增依赖、凭证或网络权限。

## 宿析OS能力契约

入口为现有 Revenue Agent 收益分析接口与 P1 收益分析闭环卡片。诊断只读取
`RevenueFactLayerService` 已保存、已回读的事实，不重新采集、不修复数据、不产生默认值。

输出契约 `revenue_analysis_diagnostics.v1` 包含：

- 酒店、租户、业务日与来源粒度；
- 三源保存/精确回读、日期语义、指标可计算性、null 与口径隔离、最低保护价检查；
- `ready_to_share`、`share_with_caveats`、`needs_revision` 三档评估；
- 收益分析、人工调价审核、全酒店外推三个独立使用边界；
- 按影响排序的问题、证据摘要和下一步动作。

## 验证证据

- 单元测试：成功、缺失 OTA、最低保护价缺失三条路径；零值 `0.0` 保留，缺失指标保持 `null`。
- 前端契约：P1 卡片优先使用统一诊断摘要、问题和下一步，同时保留旧响应兼容。
- 2026-07-31 本地控制器冒烟：`system_hotel_id=7`，`metric_scope=three_source_layered`，
  当前真实结果为 `needs_revision`、精确回读来源 `0/3`、问题 `3` 项；这是本地开发状态，不代表生产酒店事实。
- 本地 HTTP：页面入口、主前端包、Revenue AI 辅助文件均返回 200，运行时版本一致。

## 后续升级触发条件

只有当三源事实已稳定精确回读、需要长期趋势归因时，才继续吸纳 KPI 设计和定期报告；
Excel 导入必须先固定字段映射、酒店/日期/来源和保存回读契约，不能把表格解析成功当作事实可用。
