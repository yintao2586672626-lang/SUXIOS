# 宿析OS 生产候选交付与验收清单（2026-08-21）

## 1. 候选结论

| 项目 | 结果 |
| --- | --- |
| 候选标识 | `SUXIOS-HOTEL-PC-20260821` |
| 本地集成候选 | **通过**：构建、数据库、PHP、Node、浏览器核心链路均通过 |
| 生产成功 | **未声明**：未部署，未使用真实生产账号、真实门店和真实业务日验收 |
| 隔离工作树 | `D:\桌面\SUXIOS\宿析OS初始版\.release-worktrees\production-candidate-20260821` |
| 分支 | `candidate/production-20260821` |
| 基线 / 当前 HEAD | `origin/main@b42fc9e7e4ad971c27be8505bd3276a98472e3cc` |
| 提交 / 推送 / 部署 | 均未执行 |
| 主脏工作区 | 保持 `agent/fix-daily-review-gates@8200d04dadb99d34d5bedb7bee1a5cfa55c10ae6`，未清理、未覆盖 |
| 候选包 | `release-candidates/SUXIOS-HOTEL-production-candidate-20260821-b42fc9e7.tar.gz` |
| 校验文件 | 同目录同名 `.sha256` 文件；以交付目录中的实际文件为准 |

本候选可以交给部署流程做受控暂存。现有 `scripts/deploy_tencent_cloud.ps1` 明确拒绝脏工作树，并只从精确 Git 提交生成归档；因此正式执行该脚本前，仍需由有权限的发布负责人审核并提交候选内容。本文和候选包不构成提交、推送或部署授权。

## 2. 本次整理后的功能边界

- 数据闭环：保持酒店、租户、平台、业务日期、来源和事实质量边界；新增门店三源向导按门店精确 ID 保存和回读，不把创建、授权或身份保存描述成采集成功，也不自动发送企业微信。
- 经营问答：统一经营问题保存、严格二次回读、历史恢复、事实/知识/模型/执行证据分层；行动卡只进入人工审批，不自动执行 OTA 写入。
- 运营管理：行动从草稿、审批、执行、证据到效果复盘采用追加式生命周期账本；证据不足时保持 `observing`、`unknown` 或阻塞状态，不伪造归因和 ROI。
- 前端交付：首页使用独立三源事实层；酒店三源向导、AI 日报展示与其他重组件延迟加载；携程下载继续复用页面可见模型；登录、酒店切换、业务日期、数据页、问答历史和行动卡保留兼容入口。
- 构建来源：所有可执行前端产物均由 `public/app-main.js`、模板片段、CSS 源文件或构建脚本重新生成；最终构建全部报告 `changed=false`，证明产物与当前源文件一致，不依赖手工修改压缩产物。

## 3. 数据库变更与兼容性

### 正式迁移

1. `database/migrations/20260821_create_operation_action_management_loop.sql`
   - 新增 `operation_action_lifecycle_events`。
   - 新增 `operation_action_reviews`。
   - 两表均为追加式账本，并由触发器拒绝 UPDATE/DELETE。
   - 不回填、不改写既有 `operation_execution_*` 历史数据；旧服务可继续忽略新表。
2. `database/migrations/20260816_seed_hotel_self_service_sop_reference.sql`
   - 幂等写入 `knowledge_units`、`knowledge_chunks`、`knowledge_base` 的自助经营 SOP 参考知识。
3. `database/migrations/20260820_seed_geo_content_operations_reference.sql`
   - 幂等写入 GEO 内容运营参考知识；保持参考用途，不升级为酒店经营事实。

### 当前验证

- 候选主库：`175/175` 迁移已登记，版本 `20260821_create_operation_action_management_loop`。
- 新建专用本地 E2E 库 `hotelx_candidate_20260821_e2e`：从完整基线初始化后同样为 `175/175`；每轮测试种子、日志和权限数据均精确清理为 0 残留。
- 旧的 `hotelx_e2e` 测试库存在历史迁移校验和漂移，未删除、未强制升级；候选验证改用新专用库，避免破坏旧数据。
- 回滚应用版本时建议保留上述新增表和参考知识；删除追加账本或知识行不是自动回滚动作，只有在确认无新数据且已有备份后才能另行执行受审迁移。

## 4. 构建与性能证据

| 检查 | 结果 |
| --- | --- |
| 模板同步 | 61 个片段，`snapshot_changed=false`，`manifest_changed=false` |
| 模板 SHA-256 | `80b60ca418d9d9837e55df08ebe8127e90486c575fbdbc5cb96455daa6600bd4` |
| `app-main.min.js` | 1,337,864 B，SHA-256 短指纹 `ee7e83485f`，`artifact_changed=false` |
| 公共登录壳 gzip | 17,127 B / 180,000 B |
| 登录后启动 gzip | **618,557 B / 620,000 B**，目标余量 1,443 B |
| 完整登录后资产 gzip | 1,113,849 B；其中延迟资产 495,292 B |
| 阻塞脚本 | 0 |
| 公共入口与 CSP | 入口、生成物、缓存指纹、CSP 和性能预算全部通过 |
| 源码热点预算 | 通过；`public/app-main.js` 为 55,806 行的零增长棘轮，后续新增功能仍需继续按行为域拆分 |

性能问题的实际处理是把 333 行三源向导组件从启动入口移入按需组件包，并把 AI 日报纯展示辅助函数移入延迟静态模块；没有调高 620 KB 硬目标。

## 5. 测试结果

| 层级 | 命令 / 范围 | 结果 |
| --- | --- | --- |
| PHP 全量 | PHPUnit 全套 | **4,568 tests / 42,115 assertions 通过；1 skipped；0 failed** |
| Node 全量 | `npm run test:node` | **1,661 / 1,661 通过；0 failed** |
| 浏览器重点链路 | 渐进渲染、三源向导、经营问答、历史恢复、行动卡、智能助手 | **9 / 9 通过** |
| 隔离浏览器日常回归 | 登录、6 个核心模块、API 健康、六段 OTA 经营链 | **2 / 2 通过** |
| 数据库 | `npm run db:migrate` + `npm run db:check` | **175 / 175，通过** |
| 前端入口 | `npm run verify:public-entry` | **通过，启动体积在目标内** |
| 源码热点 | `npm run verify:source-hotspot-budget` | **通过** |

隔离浏览器使用明确标记的合成 E2E 用户和酒店，只验证交互、作用域、保存/回读合同及失败状态；它不是生产经营事实或真实账号验收。

## 6. 本地页面与接口证据

- 候选本地地址：`http://127.0.0.1:18080/`。
- `/api/health`：HTTP 200；应用、数据库、数据库版本和竞品报告幂等检查为 `ok`。
- 未登录访问 `/api/hotels`：HTTP 401，未放开业务接口。
- 本地运行模式：`development_fallback`，`production_runtime_ready=false`。这只证明本地应用可运行，不能替代生产运行态验收。
- [公开登录页](evidence/production-candidate-20260821/local-public-login.png)
- [隔离 E2E 登录后的今日经营看板](evidence/production-candidate-20260821/local-authenticated-compass-isolated-e2e.png)

## 7. 精确修改文件清单

### 后端、路由与业务服务

```text
app/controller/OperatingIntelligence.php
app/controller/OperationManagement.php
app/controller/RevenueAi.php
app/exception/LlmDirectRequestException.php
app/service/AiDecisionQualityService.php
app/service/LlmClient.php
app/service/OperatingQuestionAiAnswerService.php
app/service/OperatingQuestionExecutionBridgeService.php
app/service/OperatingQuestionService.php
app/service/OperationActionLifecycleService.php
app/service/OperationManagementService.php
app/service/OtaStandardEtlService.php
app/service/RevenueAiOverviewService.php
app/service/RevenueCockpitApprovalService.php
app/service/RevenueCockpitStrictEvidenceService.php
app/service/RevenueDecisionFrameService.php
app/service/SystemUsageAssistantService.php
app/service/operation/ExecutionFlowReadService.php
app/service/operation/OperationActionLifecycleConcern.php
app/service/operation/OperationEffectReviewService.php
app/service/operation/OperationExecutionTenantConcern.php
route/app.php
```

### 数据库

```text
database/migrations/20260816_seed_hotel_self_service_sop_reference.sql
database/migrations/20260820_seed_geo_content_operations_reference.sql
database/migrations/20260821_create_operation_action_management_loop.sql
```

### 前端源文件、模板与生成物

```text
public/.htaccess
public/app-bootstrap.js
public/app-bootstrap.min.js
public/app-main.js
public/app-main.min.js
public/app-render.min.js
public/app-startup-helpers.min.js
public/app-startup-render.min.js
public/components/system/app-main-components-loader.js
public/components/system/app-main-components.js
public/components/system/business-closure-loader.js
public/components/system/business-closure-views.js
public/components/system/data-config-dialogs.js
public/components/system/operating-intelligence-components.js
public/components/system/operating-intelligence-loader.js
public/ctrip-static.js
public/index.html
public/operation-static.js
public/revenue-ai-static.js
public/router.php
public/style-startup.min.css
public/style.css
public/style.min.css
public/system-static.js
public/tailwind.min.css
resources/frontend/app-template.html
resources/frontend/templates/fragments/16-page-ai-daily-report.html
resources/frontend/templates/fragments/17-page-ops-track.html
resources/frontend/templates/fragments/20-page-knowledge-center.html
resources/frontend/templates/fragments/24-page-ctrip-ebooking.html
resources/frontend/templates/fragments/27-page-agent-center.html
resources/frontend/templates/fragments/40-dialog-hotel.html
resources/frontend/templates/manifest.json
```

### 构建、预算与现场验证工具

```text
scripts/build_frontend_authenticated_style.mjs
scripts/lib/frontend_authenticated_style_build.mjs
scripts/lib/frontend_entry_build.mjs
scripts/lib/frontend_performance_budget.mjs
scripts/lib/frontend_template_build.mjs
scripts/lib/source_hotspot_budget.mjs
scripts/verify_operating_question_deepseek_live.php
scripts/verify_public_entry_guard.mjs
scripts/verify_revenue_cockpit_live_readback.mjs
```

### PHP 与浏览器/Node 测试

```text
tests/AiDecisionQualityServiceTest.php
tests/ControllerRouteContractTest.php
tests/LlmClientTest.php
tests/OperatingIntelligenceServiceTest.php
tests/OperatingQuestionExecutionBridgeServiceTest.php
tests/OtaStandardEtlReadbackQueryTest.php
tests/RevenueAiControllerTest.php
tests/RevenueCockpitApprovalServiceTest.php
tests/RevenueCockpitStrictEvidenceServiceTest.php
tests/RevenueDecisionFrameIntegrationTest.php
tests/RevenueDecisionFrameServiceTest.php
tests/SystemUsageAssistantServiceTest.php
tests/automation/ctrip_channel_order_breakdown.test.mjs
tests/automation/ctrip_order_analysis_panel.test.mjs
tests/automation/e2e-helpers.js
tests/automation/e2e_isolated_entrypoints.test.mjs
tests/automation/frontend_authenticated_bootstrap.test.mjs
tests/automation/frontend_authenticated_style_build.test.mjs
tests/automation/frontend_full_render_transition.spec.js
tests/automation/frontend_performance_budget.test.mjs
tests/automation/frontend_template_build.test.mjs
tests/automation/hotel_three_source_onboarding_ui.test.mjs
tests/automation/lean_navigation_grouping.test.mjs
tests/automation/operating_intelligence.test.mjs
tests/automation/operating_question_action_card.spec.js
tests/automation/operating_question_floating.spec.js
tests/automation/operation_frontend_closure.test.mjs
tests/automation/option_a_frontend_regressions.test.mjs
tests/automation/revenue_ai_static.test.mjs
```

### 交付说明与证据

```text
docs/releases/production-candidate-20260821.md
docs/releases/evidence/production-candidate-20260821/local-public-login.png
docs/releases/evidence/production-candidate-20260821/local-authenticated-compass-isolated-e2e.png
```

## 8. 当前未验证项与上线阻塞

1. 受控生产环境文件尚未提供。`npm run review:release-env` 当前按设计失败：缺少 `release-evidence-temp/production.env`；正式发布必须通过 `RELEASE_ENV_FILE` 指向受控生产配置后重跑。
2. 本地健康接口仍为 `development_fallback`；生产必须返回 `production_runtime_ready=true`，并证明持久化 cache、lock、report 路径和单实例/分布式策略符合实际部署。
3. 未执行真实账号登录、真实门店选择、真实业务日期读取、真实 OTA 保存回读、真实企业微信送达或任何生产写入。
4. 现有携程现场链路仍有外部登录阻塞：Profile `6866634` 未登录，任务 `4101` 没有形成目标日保存行。不能把本地合成数据或历史记录当成该现场闭环。
5. 未提交、未推送、未部署；现有自动部署脚本要求审核后的干净提交，不能直接把当前未提交工作树当生产来源。

## 9. 上线前执行清单

### A. 发布负责人审核与冻结

- [ ] 核对候选包 SHA-256 与同目录 `.sha256` 完全一致。
- [ ] 审核本清单的 99 个变更文件，确认没有混入 `.env`、Cookie、Token、原始 OTA 响应、运行态缓存、备份或用户本地数据。
- [ ] 在明确授权后，把候选内容形成一个精确、可审计的 Git 提交；记录提交 SHA、基线和候选包 SHA。
- [ ] 准备受控生产 env 文件并运行 `npm run review:release-env`，结果必须为 0 failure。
- [ ] 生成并验证新鲜数据库备份；记录备份路径、大小、SHA-256 和恢复演练结果。

### B. 暂存与迁移

- [ ] 使用当前 `deploy/cloud/install_release.sh` 或 `scripts/deploy_tencent_cloud.ps1 -StageOnly` 暂存，不先切换 `current`。
- [ ] 在暂存目录执行 `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`。
- [ ] 若生产启用云端 Node/OTA 运行时，确认 Node.js 20+，再执行脚本约定的 `npm ci --omit=dev --ignore-scripts --no-audit --no-fund`。
- [ ] 在备份完成后，按独立审核流程执行 `php think db:migrate`；确认 `175/175`，且两张行动账本表和四个只增不改触发器存在。
- [ ] 在暂存目录执行前端重建和 `npm run verify:public-entry`；启动 gzip 必须不高于 620,000 B，入口指纹必须与包内一致。

### C. 切换后技术验收

- [ ] `/api/health` 返回 HTTP 200，且 `production_runtime_ready=true`；不得出现 `development_fallback`。
- [ ] 未登录业务接口返回 401；登录、退出和酒店切换后不得复用上一账号/上一酒店状态。
- [ ] 检查 PHP/FPM、Web、队列和已安装定时器；发布不得自动安装或启用用户未批准的新定时器。
- [ ] 检查公共入口无 CSP、资源 404、Vue 页面异常和指纹漂移。

### D. 真实业务验收（必须重新执行）

- [ ] 使用真实账号登录；人工完成密码、验证码、短信、Passkey 或 2FA，不把凭证交给 Codex。
- [ ] 选择验收门店，记录系统酒店 ID、OTA 平台门店 ID、租户和业务日期。
- [ ] 在数据页分别核对 PMS、携程、美团来源、状态、时间和缺失项；执行一次授权范围内的采集后，完成正式保存和精确回读。
- [ ] 在今日经营看板确认同一酒店、同一业务日；缺失数据保持缺失，OTA 事实不扩大为全酒店结论。
- [ ] 提交一条经营问题，刷新后从历史恢复同一问题 ID、答案、证据和作用域。
- [ ] 对可执行建议生成行动卡，确认只到待审批；审批、执行、证据和复盘均按同一酒店/日期/指标回读。
- [ ] 检查携程下载内容与页面当前卡片、列、顺序、格式和缺失状态完全一致。
- [ ] 如需企业微信，使用明确的正式群和测试内容完成一次受控发送，并取得业务成功回执；页面预览或 HTTP 200 不算送达。

## 10. 回滚方式与停止条件

### 回滚方式

1. 切换前保存 `current` 指向的旧 release 路径和新鲜数据库备份。
2. 若健康接口、登录、资源加载或核心业务验收失败，立即把 `current` 原子切回旧 release，并恢复原有已安装服务/定时器的 enabled/active 状态。
3. 重启或 reload PHP/FPM 与 Web 服务，重新验证旧版本健康接口和登录。
4. 新增数据库表默认保留，因为它们是追加、向后兼容结构；不要自动 DROP。只有确认新表没有生产数据、参考知识没有被使用且备份可恢复后，才另行执行经审核的回滚迁移。

### 必须停止上线的条件

- 生产健康接口不是 200 或仍是 `development_fallback`。
- 迁移不是 `175/175`，或任一迁移校验和/登记状态异常。
- 启动 gzip 超过 620,000 B、生成物与源文件不一致、入口资源 404 或 CSP 报错。
- 登录串账号、酒店/租户串店、业务日期错位、缺失值被显示成 0/成功。
- 问答历史无法精确恢复、行动卡绕过人工审批、存在未授权 OTA/企业微信真实写入。
- 真实账号、真实门店、真实日期的生产验收尚未完成。
