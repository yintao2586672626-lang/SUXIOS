# 携程 OTA 建表方案 - 2026-06-02

## 用户规则

- 携程商旅先不要：`biztravel_bpi`、`biztravel_business_report`、`biztravel_competitor` 标记为暂缓。
- 其他携程字段都进入建表范围，包括本轮未采到的字段。
- 未采到字段先进入缺口表，后续用户协助提供网站/页面后补采集证据。
- 合并同类字段：`hotel_id`、`hotel_name`、`data_date` 等作为公共维度，不重复建“酒店ID1/酒店ID2”。
- 口径边界：所有表都是携程 OTA 渠道经营诊断口径，不代表全酒店经营数据。

## 统计

| 项目 | 数量 |
| --- | --- |
| 原筛选字段行 | 395 |
| 用户选择=要 | 326 |
| 用户选择=暂缓(商旅) | 69 |
| 指标事实表行 | 111 |
| 公共维度字段行 | 39 |
| 辅助表/实体表行 | 15 |
| 待网站协助/缺口表行 | 161 |
| 合并后 canonical 字段数 | 129 |

## 物理表

| 表名 | 用途 | 是否包含商旅 | 合并规则 |
| --- | --- | --- | --- |
| ota_ctrip_capture_runs | 记录每次 Profile 采集、门禁、响应数、facts 数 | 否 | 一次采集一个 run，不重复存 hotel_id 字段定义 |
| ota_ctrip_metric_catalog | 保存字段目录、你的选择、建表决定、是否需要网站协助 | 商旅标暂缓 | 同 metric_key/category 合并 source_keys 和端点 |
| ota_ctrip_metric_facts | 保存经营/流量/竞争/PSI/广告等所有标量指标 | 否 | 公共维度只存一次；指标用 metric_key + section + endpoint 区分 |
| ota_ctrip_entity_snapshots | 保存房型、竞对酒店、广告活动、用户分段、热点事件等对象 | 否 | entity_type + entity_key 合并同类对象 |
| ota_ctrip_capture_gaps | 保存当前抓不到的字段/端点，等你提供网站后补证据 | 否 | 按 section + endpoint + metric_key 记录缺口 |

## 文件

- 迁移 SQL：`database/migrations/20260602_create_ctrip_ota_metric_tables.sql`
- 已按用户规则标记的筛选表：`docs/ctrip_field_selection_matrix_tiancheng_20260602_selected.csv`
- 合并同类字段映射：`docs/ctrip_merged_field_build_map_20260602.csv`

## 后续落库规则

- 已采到且门禁通过：写入 `ota_ctrip_metric_facts` 或 `ota_ctrip_entity_snapshots`。
- 已采到但门禁未过：可记录 facts，但 `capture_status` 必须保留为需复核，不能作为稳定经营指标直接展示。
- 未采到：写入 `ota_ctrip_capture_gaps`，不填 0，不伪造成空指标。
- 商旅：暂不建业务事实，后续需要时另开 `ota_ctrip_biztravel_*`。
