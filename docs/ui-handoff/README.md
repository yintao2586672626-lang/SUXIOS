# UI Handoff Checklist

更新时间：2026-05-30

## 范围

本清单用于上市前 UI 交付核对，覆盖当前仓库内可验证页面与资产，不替代 Figma/Canva 源文件。

## 当前代码侧页面

| 模块 | 代码入口 | 核对状态 |
|---|---|---|
| 登录与主应用 | `public/index.html` | 已纳入 `verify:public-entry` 与前端边界守卫 |
| OTA 数据 | `public/index.html`、`app/controller/OnlineData.php` | 需继续标注 OTA 渠道口径 |
| 收益分析 | `public/index.html`、`app/controller/DailyReport.php` | 已有报表导入、公式与导出回归验证 |
| AI 决策 | `public/index.html`、`app/controller/Agent.php` | 生产模型配置依赖 `LlmClient` 与数据库加密配置 |
| 运营管理 | `public/index.html`、`app/controller/OperationManagement.php` | 已纳入业务链路测试 |
| 投资决策 | `public/index.html`、`app/controller/Expansion.php`、`app/controller/TransferDecision.php` | 已纳入服务层与业务链路测试 |

## 待补外部交付物

| 交付物 | 状态 | 要求 |
|---|---|---|
| Figma 或 Canva 源链接 | 待补 | 按 `docs/design_handoff_manifest.example.json` 创建 `docs/design_handoff_manifest.json`，补充可访问链接、版本、负责人 |
| 品牌规范 | 待补 | 补充颜色、字体、组件状态、图标规范 |
| 设计验收记录 | 待补 | 覆盖登录、首页、OTA 数据、收益分析、AI 决策、运营管理、投资决策 |

## 发布前要求

- 不把 OTA-only 指标描述成全酒店经营口径。
- 设计验收前不声明已有 Figma/Canva 源文件。
- UI 变更后复跑 `npm.cmd run verify:p0-guards` 和相关 E2E 合同检查。
