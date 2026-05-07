# Codex 启动提示词

> 直接复制下方全部内容粘贴给 Codex，即可快速接手项目。

---

```
我正在开发一个名为「宿析OS」（SuXi OS）的酒店管理 SaaS 系统，请接手继续开发。

## 项目基本信息

- **项目路径**：`d:\桌面\国际\JD-main\HOTEL\`
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
cd "d:\桌面\国际\JD-main\HOTEL\"
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

携程/美团 ebooking Cookie + Bookmarklet 抓取体系：
- **流程**：管理员登录携程/美团 → 运行书签脚本捕获 Cookie → 回传到系统 → 前端调用抓取接口获取数据
- **书签脚本生成**：`GET /api/online-data/bookmarklet`（携程）
- **携程抓取**：`POST /api/online-data/fetch-ctrip`，Body: `{ cookies, node_id, start_date, end_date }`
- **美团抓取**：`POST /api/online-data/fetch-meituan`，Body: `{ cookies, partner_id, poi_id, date_range }`
- **⚠️ 美团日期格式是 YYYYMMDD，携程是 YYYY-MM-DD**
- **Cookie 有效期通常只有 1-7 天**，过期需要重新获取
- 定时任务：`GET /api/online-data/cron-trigger`（通过 X-Cron-Token 验证）

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
