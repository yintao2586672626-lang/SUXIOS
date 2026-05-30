# Codex 启动提示词

> 直接复制下方全部内容粘贴给 Codex，即可快速接手项目。

---

```
我正在开发一个名为「宿析OS」（SuXi OS）的酒店管理 SaaS 系统，请接手继续开发。

## 项目基本信息

- **项目路径**：`D:\桌面\SUXIOS\宿析OS初始版\HOTEL\`
- **Git 远程**：`https://github.com/yintao2586672626-lang/0412.git`
- **注意**：`hotel-frontend/`（Vite 重构版）源码从未提交 Git，仅本地存在。

## 技术栈

- 后端：ThinkPHP 8.0 + ThinkORM
- 前端（当前）：Vue 3（CDN 单文件，17,000 行，`public/index.html`）
- 前端（重构版）：`hotel-frontend/` Vue 3 + Vite + TypeScript + Tailwind
- 数据库：MySQL（hotelx），root/空密码，XAMPP 环境
- PHP >= 8.0，Composer 管理依赖

## 数据库连接

- Host：127.0.0.1
- 端口：3306
- 数据库名：hotelx
- 用户名：root
- 密码：（空，通过 `.env` 文件配置）

## 启动方式

```bash
cd "D:\桌面\SUXIOS\宿析OS初始版\HOTEL\"
composer install
# 启动 XAMPP (Apache + MySQL)
# 访问 http://hotelx.local/ 或 http://localhost/HOTEL/public/
```

## 认证机制

- 登录接口：`POST /api/auth/login`，Body: `{ username, password }`
- 成功返回：`{ code: 200, data: { token, expires_in: 86400, user: {...} } }`
- 后续请求 Header：`Authorization: Bearer <token>`
- Token 存在 ThinkPHP Cache（文件缓存，24h 过期）
- 无需认证的接口：login、health、receive-cookies（书签回传）、cron-trigger（定时任务）

## 核心业务模块

### 1. 线上数据抓取（最重要）

携程/美团 OTA 数据采集体系：
- **推荐顺序**：已确认接口走后端直连 Cookie/API；接口不确定或参数复杂时用 CDP 临时监听页面；必须真实打开页面才有数据时再使用 Profile + CDP 兜底。
- **携程经营概况**：`POST /api/online-data/fetch-ctrip`，默认直连接口包含 `getDayReportCompeteHotelReport`
- **携程流量**：`POST /api/online-data/fetch-ctrip-traffic` 或 `POST /api/online-data/ctrip/traffic`
- **携程浏览器兜底**：`POST /api/online-data/capture-ctrip-browser`，脚本 `scripts/ctrip_browser_capture.mjs`
- **美团流量/订单/广告**：`fetch-meituan-traffic`、`fetch-meituan-orders`、`fetch-meituan-ads`
- **美团浏览器兜底**：`POST /api/online-data/capture-meituan-browser`，脚本 `scripts/meituan_browser_capture.mjs`
- **数据源同步**：`/api/online-data/data-sources`，支持 `manual`、`import_*`、`api` 等类型
- **详细流程**：查看 `docs/ota_acquisition_decision_playbook.md`
- **口径边界**：携程/美团输入默认是 OTA 渠道口径；未接入 PMS/CRS、全量可售房和全量收入前，不写成全酒店入住率、ADR 或 RevPAR。
- **Cookie/Token/Profile 均为敏感信息**，不得写入普通文档、日志或 Git。
- **Agent 工具边界**：本地系统页面用 Browser/Playwright；真实 OTA 页面接口定位用 Chrome/CDP；Windows 桌面应用或文件选择器用 Computer Use；OpenAI API/Agents 相关问题再使用 OpenAI Developers。

### 2. 日报表

- 字段由 `report_configs` 表定义
- 支持 Excel 导入/导出
- `field_mappings` 表建立原始数据字段 → 系统字段映射
- 详情含同比/环比/完成率等计算

### 3. AI Agent 系统

三个 Agent，核心在 `app/controller/Agent.php`：
- 智能员工 Agent（type=1）：知识库问答、工单、对话
- 收益管理 Agent（type=2）：竞对分析、定价建议、需求预测
- 资产运维 Agent（type=3）：设备管理、能耗监控、维护计划

### 4. 竞对监控

- 竞对价格监控 API：`POST /api/competitor/task`
- 企业微信机器人通知
- 竞对门店管理：`api/admin/competitor-hotels/`

### 5. 门店罗盘

- 自定义仪表盘布局
- 布局保存到 `compass_layouts` 表

## API 路由结构（route/app.php）

几乎所有接口都通过 Auth 中间件，除了：
- `POST /api/auth/login`
- `GET /api/health`
- `POST /api/online-data/receive-cookies`（书签回传，URL token 验证）
- `GET /api/online-data/cron-trigger`（X-Cron-Token 验证）
- `POST /api/test-ctrip-fetch`（调试用）

完整路由分组：`api/auth/`、`api/hotels/`、`api/users/`、`api/roles/`、`api/daily-reports/`、`api/monthly-tasks/`、`api/report-configs/`、`api/online-data/`（最核心）、`api/ai/`、`api/competitor/`、`api/admin/competitor-hotels/`、`compass/`、`api/operation-logs/`

## 关键权限项

`can_view_report`、`can_fill_daily_report`、`can_fill_monthly_task`、`can_edit_report`、`can_delete_report`

## 已知的坑

1. **Cookie 失效**：携程/美团 Cookie 通常 1-7 天过期，API 返回 403/401 时需要重新获取
2. **美团日期格式**：必须是 YYYYMMDD，不是 YYYY-MM-DD
3. **.env 密码覆盖**：config/database.php 默认密码 z123123，但 .env 中 DB_PASS=空，实际用空密码
4. **OPcache 问题**：修改 PHP 代码后可能不生效，需要清除 OPcache 或关闭 OPcache
5. **Vite 打包覆盖**：`vite build` 会覆盖 `public/index.html`，前端源码在 `hotel-frontend/` 不在 `public/`

## 我的需求是：

[在此描述你的开发需求]

## 参考文档

- 项目详细交接：查看 `PROJECT_HANDOFF.md`
- 开发日志：查看 `DEV_LOG.md`
- Vite 重构计划：查看 `c:\Users\Administrator\AppData\Roaming\CodeBuddy CN\User\globalStorage\tencent-cloud.coding-copilot\plans\3837f287a4ec4b889ac0e4fbbdb0f5c5\plan.md`
```
