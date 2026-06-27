# 文件用途与合并清单

更新时间：2026-05-17

## 范围

本清单覆盖项目自有文件。以下内容不逐文件展开：

- `vendor/`：Composer 第三方依赖，可由 `composer install` 恢复。
- `.git/`：Git 元数据。
- `runtime/`：ThinkPHP 运行缓存，保留 `.gitignore` 即可。
- `.env`、`.mcp.json`、数据库备份 SQL：本地/敏感/大文件，按用途说明，不展开内容。

## 已合并或清理

| 类型 | 处理 | 原因 |
|---|---|---|
| Vite 旧构建产物 | 已删除 `public/assets/` | 当前 `public/index.html` 未引用，保留会造成多版本前端入口混淆 |
| 前端过渡文件 | 已删除 `public/app-main.*`、`public/app.js`、`public/app-styles.css` | 当前页面未引用，`PACKAGE_MANIFEST.md` 已标记为过渡/废弃 |
| 未引用样式 | 已删除 `public/components.css`、`public/enhanced-components.css`、`public/tailwind-custom.css` | 当前入口未加载，实际样式来源为 `tailwind.min.css`、`style.css`、`ai-custom.css` |
| 备份样式 | 已删除 `public/tailwind.min.css.bak` | 与当前 `tailwind.min.css` 重复 |
| 无效配置 | 已删除 `public/nginx.htaccess` | 空文件且当前本地服务使用 ThinkPHP/PHP 内置或 Apache 入口 |
| 临时工作树/旧副本 | 已删除外层 Codex worktree 与内层 `HOTEL/` 旧副本 | 与当前主项目重复，避免误改旧代码 |
| 运行与测试产物 | 已清理 `runtime/` 缓存、`tests/report/`、`output/` | 不属于源码，可重新生成 |

## 不建议合并

| 文件/目录 | 判断 |
|---|---|
| `app/controller/*` 与 `app/service/*` | Controller 负责 HTTP 输入输出，Service 负责业务计算；合并会降低可维护性 |
| `app/model/*` | 每个模型对应独立业务表或实体；不建议合并成通用模型 |
| `config/*.php` | ThinkPHP 约定配置拆分；保持独立 |
| `database/migrations/*.sql` | 按历史变更顺序保存，不能合并成单文件，否则丢失迁移语义 |
| `.agents/skills/*` 与 `plugins/suxi-os-toolkit/skills/*` | 内容相同，但用途不同：前者为项目本地 Skill，后者为本地插件分发源；暂不合并 |
| `scripts/verify_*.mjs` | 覆盖不同模块验证场景；可由 `run_all.sh` 聚合执行，但不建议删除单项脚本 |

## 根目录文件

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `.env` | 本地运行配置，含数据库/密钥等敏感信息 | 保留，不提交 |
| `.example.env` | 环境变量模板 | 保留 |
| `.gitignore` | 忽略运行产物、依赖、备份和敏感文件 | 保留 |
| `.mcp.json` | 本地 MCP/助手配置 | 保留，本地使用 |
| `AGENTS.md` | Codex 项目规则和操作约束 | 保留 |
| `README.md` | 项目基础说明 | 保留，建议后续修复乱码 |
| `QUICK_START.md` | 快速启动说明 | 保留 |
| `PROJECT_HANDOFF.md` | 项目交接说明 | 保留 |
| `CODEX_HANDOFF.md` | Codex 交接摘要 | 保留 |
| `CODEX_START_PROMPT.md` | Codex 启动提示 | 保留 |
| `DEV_LOG.md` | 开发日志 | 保留 |
| `PACKAGE_MANIFEST.md` | 打包与发布清单 | 保留 |
| `MISSING_TABLES.md` | 缺失表说明 | 保留 |
| `TYPESCRIPT_MIGRATION.md` | TypeScript 迁移说明 | 保留，标记为历史计划 |
| `LICENSE.txt` | 许可证 | 保留 |
| `composer.json` | PHP 依赖声明和 autoload | 保留 |
| `composer.lock` | PHP 依赖锁定版本 | 保留 |
| `package.json` | TypeScript/验证脚本依赖声明 | 保留 |
| `package-lock.json` | npm 依赖锁定版本 | 保留 |
| `tsconfig.json` | TypeScript 检查配置 | 保留 |
| `tsconfig.build.json` | TypeScript build 配置 | 保留 |
| `think` | ThinkPHP 命令行入口 | 保留 |
| `start-hotel.bat` | Windows 一键启动脚本 | 保留 |
| `run_all.sh` | 聚合验证脚本 | 保留 |
| `hotelx_dump.sql` | 数据库完整备份 | 保留，本地敏感大文件 |
| `hotelx_backup_before_missing_tables.sql` | 补表前数据库备份 | 保留到确认无回滚需求后再归档 |
| `项目问题解决方案库.md` | 历史问题和解决方案 | 保留 |

## 应用核心

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `app/.htaccess` | app 目录访问保护 | 保留 |
| `app/AppService.php` | ThinkPHP 应用服务注册 | 保留 |
| `app/BaseController.php` | ThinkPHP 基础控制器 | 保留 |
| `app/ExceptionHandle.php` | 全局异常处理 | 保留 |
| `app/Request.php` | 请求对象扩展 | 保留 |
| `app/common.php` | 全局公共函数入口 | 保留 |
| `app/event.php` | 事件配置 | 保留 |
| `app/middleware.php` | 全局中间件配置 | 保留 |
| `app/provider.php` | 容器服务提供者配置 | 保留 |
| `app/service.php` | ThinkPHP 服务配置 | 保留 |

## 控制器

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `app/controller/Base.php` | API 响应、分页、权限等控制器公共能力 | 保留 |
| `app/controller/Auth.php` | 登录、退出、用户信息、改密 | 保留 |
| `app/controller/Index.php` | 首页/基础入口控制 | 保留 |
| `app/controller/User.php` | 用户管理 API | 保留 |
| `app/controller/RoleController.php` | 角色与权限 API | 保留 |
| `app/controller/Hotel.php` | 酒店基础资料 API | 保留 |
| `app/controller/HotelFieldTemplate.php` | 酒店字段模板 API | 保留 |
| `app/controller/OnlineData.php` | OTA 数据抓取、Cookie、历史和分析 API | 保留，核心 |
| `app/controller/DailyReport.php` | 日报导入、导出、配置和详情 API | 保留 |
| `app/controller/MonthlyTask.php` | 月度任务 API | 保留 |
| `app/controller/ReportConfig.php` | 报表配置 API | 保留 |
| `app/controller/SystemConfigController.php` | 系统配置 API | 保留 |
| `app/controller/Ai.php` | AI 策略/模拟/可行性 API | 保留 |
| `app/controller/AiConfig.php` | AI 模型配置 API | 保留 |
| `app/controller/Agent.php` | AI Agent、知识库、工单、收益、资产 API | 保留，核心 |
| `app/controller/HolidayRevenue.php` | 节假日收益倒计时 API | 保留 |
| `app/controller/MacroSignal.php` | 宏观经营信号 API | 保留 |
| `app/controller/Lifecycle.php` | 生命周期数据 API | 保留 |
| `app/controller/StrategySimulation.php` | 智略策略推演 API | 保留 |
| `app/controller/Simulation.php` | 智算量化模拟 API | 保留 |
| `app/controller/OperationManagement.php` | 运营管理 API | 保留 |
| `app/controller/Opening.php` | 开业筹建管理 API | 保留 |
| `app/controller/Expansion.php` | 扩张管理 API | 保留 |
| `app/controller/TransferDecision.php` | 转让决策 API | 保留 |
| `app/controller/CompetitorApi.php` | 竞对价格外部任务/报告接口 | 保留 |
| `app/controller/OperationLogController.php` | 操作日志查询 API | 保留 |

## 管理后台控制器与视图

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `app/controller/admin/Compass.php` | 门店罗盘后台/API | 保留 |
| `app/controller/admin/CompetitorDeviceController.php` | 竞对设备管理 | 保留 |
| `app/controller/admin/CompetitorHotelController.php` | 竞对酒店管理 | 保留 |
| `app/controller/admin/CompetitorPriceLogController.php` | 竞对价格日志 | 保留 |
| `app/controller/admin/CompetitorWechatRobotController.php` | 企业微信机器人后台/API | 保留 |
| `app/view/admin/compass/index.html` | 门店罗盘传统视图 | 保留 |
| `app/view/admin/competitor_wechat_robot/add.html` | 企业微信机器人新增页 | 保留 |
| `app/view/admin/competitor_wechat_robot/edit.html` | 企业微信机器人编辑页 | 保留 |
| `app/view/admin/competitor_wechat_robot/index.html` | 企业微信机器人列表页 | 保留 |
| `view/README.md` | ThinkPHP view 目录说明 | 保留 |

## 中间件与命令

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `app/middleware/Auth.php` | Token 认证中间件 | 保留，核心安全 |
| `app/middleware/Cors.php` | CORS 中间件 | 保留 |
| `app/command/AutoFetchOnlineData.php` | 自动抓取线上数据命令 | 保留 |
| `app/command/InitDatabase.php` | 数据库初始化命令 | 保留 |
| `app/command/MigrateLoginLogs.php` | 登录日志迁移命令 | 保留 |
| `app/command/MigrateOnlineData.php` | 线上数据迁移命令 | 保留 |

## 服务层

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `app/service/LlmClient.php` | LLM 调用抽象/统一客户端 | 保留 |
| `app/service/ExternalSignalService.php` | 外部信号聚合服务 | 保留 |
| `app/service/MacroSignalService.php` | 宏观信号计算服务 | 保留 |
| `app/service/FeasibilityReportService.php` | 可行性报告生成服务 | 保留 |
| `app/service/QuantSimulationService.php` | 量化模拟服务 | 保留 |
| `app/service/OperationManagementService.php` | 运营管理业务服务 | 保留 |
| `app/service/OpeningService.php` | 开业筹建业务服务 | 保留 |
| `app/service/ExpansionService.php` | 扩张评估业务服务 | 保留 |
| `app/service/TransferDecisionService.php` | 转让决策业务服务 | 保留 |

## 模型

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `app/model/User.php` | 用户模型 | 保留 |
| `app/model/UserHotelPermission.php` | 用户酒店权限模型 | 保留 |
| `app/model/Role.php` | 角色模型 | 保留 |
| `app/model/LoginLog.php` | 登录日志模型 | 保留 |
| `app/model/OperationLog.php` | 操作日志模型 | 保留 |
| `app/model/SystemConfig.php` | 系统配置模型 | 保留 |
| `app/model/Hotel.php` | 酒店模型 | 保留 |
| `app/model/RoomType.php` | 房型模型 | 保留 |
| `app/model/HotelFieldTemplate.php` | 酒店字段模板模型 | 保留 |
| `app/model/HotelFieldTemplateItem.php` | 酒店字段模板项模型 | 保留 |
| `app/model/FieldMapping.php` | 字段映射模型 | 保留 |
| `app/model/DailyReport.php` | 日报模型 | 保留 |
| `app/model/MonthlyTask.php` | 月度任务模型 | 保留 |
| `app/model/ReportConfig.php` | 报表配置模型 | 保留 |
| `app/model/AiModelConfig.php` | AI 模型配置模型 | 保留 |
| `app/model/AgentConfig.php` | Agent 配置模型 | 保留 |
| `app/model/AgentConversation.php` | Agent 会话模型 | 保留 |
| `app/model/AgentLog.php` | Agent 日志模型 | 保留 |
| `app/model/AgentTask.php` | Agent 任务模型 | 保留 |
| `app/model/AgentWorkOrder.php` | Agent 工单模型 | 保留 |
| `app/model/KnowledgeBase.php` | 知识库模型 | 保留 |
| `app/model/KnowledgeCategory.php` | 知识分类模型 | 保留 |
| `app/model/PriceSuggestion.php` | 定价建议模型 | 保留 |
| `app/model/DemandForecast.php` | 需求预测模型 | 保留 |
| `app/model/CompetitorAnalysis.php` | 竞对分析模型 | 保留 |
| `app/model/CompetitorDevice.php` | 竞对设备模型 | 保留 |
| `app/model/CompetitorHotel.php` | 竞对酒店模型 | 保留 |
| `app/model/CompetitorPriceLog.php` | 竞对价格日志模型 | 保留 |
| `app/model/Device.php` | 设备模型 | 保留 |
| `app/model/DeviceCategory.php` | 设备分类模型 | 保留 |
| `app/model/DeviceMaintenance.php` | 设备维护模型 | 保留 |
| `app/model/MaintenancePlan.php` | 维护计划模型 | 保留 |
| `app/model/EnergyBenchmark.php` | 能耗基准模型 | 保留 |
| `app/model/EnergyConsumption.php` | 能耗数据模型 | 保留 |
| `app/model/EnergySavingSuggestion.php` | 节能建议模型 | 保留 |
| `app/model/FeasibilityReport.php` | 可行性报告模型 | 保留 |
| `app/model/StrategyDataSnapshot.php` | 策略数据快照模型 | 保留 |
| `app/model/StrategySimulationRecord.php` | 策略推演记录模型 | 保留 |

## 路由与配置

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `route/app.php` | API 路由和根页面入口 | 保留，核心 |
| `config/app.php` | 应用基础配置 | 保留 |
| `config/cache.php` | 缓存配置 | 保留 |
| `config/console.php` | 控制台命令配置 | 保留 |
| `config/cookie.php` | Cookie 配置 | 保留 |
| `config/database.php` | 数据库连接配置 | 保留 |
| `config/filesystem.php` | 文件系统配置 | 保留 |
| `config/lang.php` | 多语言配置 | 保留 |
| `config/llm.php` | LLM 配置 | 保留 |
| `config/log.php` | 日志配置 | 保留 |
| `config/middleware.php` | 中间件配置 | 保留 |
| `config/route.php` | 路由配置 | 保留 |
| `config/session.php` | Session 配置 | 保留 |
| `config/trace.php` | Trace 配置 | 保留 |
| `config/view.php` | 视图配置 | 保留 |

## 数据库文件

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `database/README_INIT.md` | 数据库初始化说明 | 保留 |
| `database/init_full.sql` | 初始化 SQL | 保留 |
| `database/hotel_admin_mysql.sql` | 主业务数据库结构/数据 SQL | 保留 |
| `database/login_logs.sql` | 登录日志表 SQL | 保留 |
| `database/complaint_tables.sql` | 投诉相关表 SQL | 保留 |
| `database/update_system_config.sql` | 系统配置更新 SQL | 保留 |
| `database/migrations/20250402_create_agent_tables.sql` | 创建 Agent 基础表 | 保留 |
| `database/migrations/20250402_enhance_agent_tables.sql` | 增强 Agent 表结构 | 保留 |
| `database/migrations/20260509_create_strategy_simulation_tables.sql` | 策略推演表 | 保留 |
| `database/migrations/20260511_add_ota_traffic_fields.sql` | OTA 流量字段 | 保留 |
| `database/migrations/20260511_create_ai_model_configs.sql` | AI 模型配置表 | 保留 |
| `database/migrations/20260511_create_missing_business_tables.sql` | 缺失业务表补齐 | 保留 |
| `database/migrations/20260516_create_opening_management_tables.sql` | 开业筹建表 | 保留 |
| `database/migrations/20260516_create_operation_management_tables.sql` | 运营管理表 | 保留 |
| `database/migrations/20260517_add_international_ota_report_fields.sql` | 国际 OTA 报表字段 | 保留 |
| `database/migrations/20260517_create_expansion_records.sql` | 扩张记录表 | 保留 |
| `database/migrations/20260517_create_quant_simulation_records.sql` | 量化模拟记录表 | 保留 |
| `database/migrations/20260517_create_transfer_records.sql` | 转让记录表 | 保留 |
| `database/migrations/20260519_seed_ctrip_browser_capture_knowledge.sql` | 携程浏览器采集方法知识种子 | 保留 |

## 前端与公开资源

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `public/.htaccess` | Apache 重写配置 | 保留 |
| `public/index.php` | ThinkPHP Web 入口 | 保留 |
| `public/index.html` | 当前 Vue CDN 单文件 SPA | 保留，核心 |
| `public/router.php` | PHP 内置服务路由 | 保留 |
| `public/robots.txt` | 搜索引擎规则 | 保留 |
| `public/favicon.ico` | 浏览器图标 | 保留 |
| `public/qrcode.png` | 二维码静态资源 | 保留 |
| `public/images/logo.svg` | Logo 资源 | 保留 |
| `public/static/.gitignore` | static 目录占位 | 保留 |
| `public/vue.global.prod.js` | Vue 3 CDN 本地副本 | 保留 |
| `public/tailwind.min.css` | Tailwind 本地 CSS | 保留 |
| `public/font-awesome.min.css` | FontAwesome CSS | 保留 |
| `public/webfonts/fa-solid-900.woff2` | FontAwesome 字体 | 保留 |
| `public/webfonts/fa-regular-400.woff2` | FontAwesome 字体 | 保留 |
| `public/webfonts/fa-brands-400.woff2` | FontAwesome 字体 | 保留 |
| `public/style.css` | 当前全局业务样式 | 保留 |
| `public/ai-custom.css` | AI 模块补充样式 | 保留 |

## 脚本与测试

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `scripts/auto_fetch_online_data.php` | 定时抓取 OTA 数据脚本 | 保留 |
| `scripts/cron_fetch.sh` | Linux cron 触发脚本 | 保留 |
| `scripts/export_daily_report.py` | 日报导出脚本 | 保留 |
| `scripts/build_hotelx_full_dump.ps1` | 构建完整数据库 dump | 保留 |
| `scripts/mcp/README.md` | MCP 脚本说明 | 保留 |
| `scripts/mcp/deepseek_worker_mcp.py` | DeepSeek worker MCP 脚本 | 保留 |
| `scripts/verify_ai_model_config_i18n.mjs` | AI 模型配置国际化验证 | 保留 |
| `scripts/verify_expansion_p2.mjs` | 扩张 P2 验证 | 保留 |
| `scripts/verify_feasibility_loop.mjs` | 可行性闭环验证 | 保留 |
| `scripts/verify_five_modules_p1.mjs` | 五模块 P1 验证 | 保留 |
| `scripts/verify_home_trends.mjs` | 首页趋势验证 | 保留 |
| `scripts/verify_market_evaluation_random_sample.mjs` | 市场评估随机样本验证 | 保留 |
| `scripts/verify_missing_modules.php` | 缺失模块验证 | 保留 |
| `scripts/verify_non_security_review.mjs` | 非安全项复核 | 保留 |
| `scripts/verify_ota_config_hotel_match.mjs` | OTA 配置酒店匹配验证 | 保留 |
| `scripts/verify_ota_config_visibility.mjs` | OTA 配置可见性验证 | 保留 |
| `scripts/verify_simulation_p2.mjs` | 模拟 P2 验证 | 保留 |
| `scripts/verify_strategy_p2.mjs` | 策略 P2 验证 | 保留 |
| `scripts/verify_transfer_p2.mjs` | 转让 P2 验证 | 保留 |
| `tests/automation/suxi_full_automation_test.mjs` | 全链路自动化测试 | 保留 |

## 文档

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `docs/ai_model_config.md` | AI 模型配置说明 | 保留 |
| `docs/ctrip_browser_capture_method.md` | 携程 OTA 浏览器自动采集方法详版说明 | 保留 |
| `docs/external_signal_api.md` | 外部信号 API 说明 | 保留 |
| `docs/five_modules_business_loop_field_inventory.md` | 五模块业务闭环字段清单 | 保留 |
| `docs/lifecycle_binding_example.md` | 生命周期绑定示例 | 保留 |
| `docs/revenue_agent_api.md` | 收益 Agent API 说明 | 保留 |
| `docs/file_inventory.md` | 当前文件用途与合并决策清单 | 保留 |

## 本地 Skill 与插件

| 文件 | 用途 | 处理建议 |
|---|---|---|
| `.agents/plugins/marketplace.json` | 本地插件市场配置 | 保留 |
| `.agents/skills/ecc-codex-adapter/SKILL.md` | ECC/Codex 适配 Skill | 保留 |
| `.agents/skills/scrapling/SKILL.md` | 授权网页解析与 selector 证据 Skill | 保留 |
| `.agents/skills/suxi-ai-report/SKILL.md` | AI 报告 Skill | 保留 |
| `.agents/skills/suxi-ai-report/agents/openai.yaml` | AI 报告 Skill agent 配置 | 保留 |
| `.agents/skills/suxi-ctrip-field-table-closure/SKILL.md` | 携程字段到表闭环 Skill | 保留 |
| `.agents/skills/suxi-dashboard-ui/SKILL.md` | 数据看板 UI Skill | 保留 |
| `.agents/skills/suxi-dashboard-ui/agents/openai.yaml` | 数据看板 UI Skill agent 配置 | 保留 |
| `.agents/skills/suxi-investment-calculation/SKILL.md` | 投资测算 Skill | 保留 |
| `.agents/skills/suxi-investment-calculation/agents/openai.yaml` | 投资测算 Skill agent 配置 | 保留 |
| `.agents/skills/suxi-ota-ops/SKILL.md` | OTA 运营 Skill | 保留 |
| `.agents/skills/suxi-ota-ops/agents/openai.yaml` | OTA 运营 Skill agent 配置 | 保留 |
| `.agents/skills/suxi-ota-ops/references/ctrip-browser-capture.md` | 携程浏览器采集方法参考 | 保留 |
| `.agents/skills/suxi-ota-ops/references/meituan-browser-capture.md` | 美团浏览器采集方法参考 | 保留 |
| `.agents/skills/suxi-ota-revenue-semantic-layer/SKILL.md` | OTA收益语义层 Skill | 保留 |
| `.agents/skills/suxi-plugin-priority-router/SKILL.md` | 插件优先路由 Skill | 保留 |
| `.agents/skills/suxi-skill-installer/SKILL.md` | Skill 安装规则 | 保留 |
| `.agents/skills/suxi-skill-installer/agents/openai.yaml` | Skill 安装 agent 配置 | 保留 |
| `.agents/skills/suxi-test-guard/SKILL.md` | 测试守卫 Skill | 保留 |
| `.agents/skills/suxi-test-guard/agents/openai.yaml` | 测试守卫 agent 配置 | 保留 |
| `plugins/suxi-os-toolkit/.codex-plugin/plugin.json` | 本地插件定义 | 保留 |
| `plugins/suxi-os-toolkit/skills/*` | 插件分发版 Skill 内容，包含上述 11 个项目本地 Skill | 保留，与 `.agents/skills` 同源并同步分发 |
| `plugins/suxi-os-toolkit/skills/suxi-ota-ops/references/ctrip-browser-capture.md` | 插件分发版携程浏览器采集参考 | 保留 |
| `plugins/suxi-os-toolkit/skills/suxi-ota-ops/references/meituan-browser-capture.md` | 插件分发版美团浏览器采集参考 | 保留 |
