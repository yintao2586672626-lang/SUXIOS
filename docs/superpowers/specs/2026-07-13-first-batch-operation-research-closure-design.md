# 第一批运营页面与收益研究执行闭环设计

## 1. 目标

在不改 OTA 采集、数据库结构、PMS/财务事实层和既有运营执行后端的前提下，完成第一批用户可见闭环：

1. 恢复 `ops-source` 经营数据总览、`ops-analysis` 问题根因分析、`ops-insight` 风险预警三个已有入口的页面正文。
2. 将收益研究中达到 `research_ready_for_execution` 的单酒店结果接入现有执行意图接口，并能在 `ops-track` 回读。
3. 建立导航入口与页面正文的自动契约，防止再次出现“入口存在、页面空白”。
4. 将老板视角导航中的“待开发...”改为“更多功能”，不重做组内已实现页面。

本批完成后停止，不继续进入 Revenue AI 真实输入、Phase2/Phase3 持久化、ROI 语义、页面深链、取消率预测、客群细分或 LTV。

## 2. 已确认现状

- `public/system-static.js` 已定义 `ops-source`、`ops-analysis`、`ops-insight`，`public/app-main.js` 已有对应页面监听和加载函数，`route/app.php` 已有 `/operation/full-data`、`/operation/root-cause`、`/operation/alerts` 接口。
- `resources/frontend/app-template.html` 当前只有 `ops-plan`、`ai-daily-report`、`ops-track` 的运营页面正文，缺少前三个页面块；生成的 `public/app-render.min.js` 同样缺少它们。
- 经营总览已经具备 `operationSummaryCards`、`operationOtaCards`、`operationCompetitorCards`、`operationSourceBrief`、`operationDecisionCards`，不再创建第二套转换逻辑。
- 根因分析已经返回 `main_problem`、`problem_level`、`conclusion`、`root_causes`、`next_actions`；数据不足时后端明确返回 `data_insufficient`。
- 风险预警已经具备风险级别/已读筛选、未读计数和批量标记已读动作。
- 收益研究页面当前只提供“开始预测”和“进入模块”；后端 `POST /api/revenue-research/execution-intent` 已完成单酒店权限、ready-only、重复意图和执行意图写入校验。
- 当前 `review:functional-readiness` 仍从 `public/index.html` 查找 `operationExecutionFlow`，与预编译模板源位置不一致，需要改为读取规范前端源集合。

## 3. 范围与非目标

### 3.1 本批范围

- 修改规范模板源 `resources/frontend/app-template.html`。
- 在 `public/app-main.js` 中只增加收益研究前端执行状态与调用函数，并调整老板导航配置名称及运营入口。
- 复用 `public/operation-static.js` 已有的卡片构建器和筛选配置；本批不修改该文件。
- 新增独立的导航页面契约验证器及聚焦自动化测试。
- 重新生成 `public/app-render.min.js`、`public/app-main.min.js`，同步 `public/index.html` 内容哈希。

### 3.2 明确不做

- 不改携程、美团采集字段、登录方式、Profile、Cookie、保存映射或 P0 门禁。
- 不新增 Revenue AI 房型、底价、竞对价格或价格建议数据。
- 不改 `route/app.php`、`RevenueResearch` 控制器、`RevenueResearchService`、`OperationManagementService` 和运营执行表结构，除非实施时发现当前接口合同与本设计证据不一致；此时停止并重新评审，不做顺手扩展。
- 不开发 PMS、财务事实、投资级 ROI 或真实投决结论。
- 不引入 Vue Router、新组件库、新依赖或整体前端重构。
- 不把 OTA 渠道指标描述成全酒店经营事实。

## 4. 页面设计

三个页面沿用现有白底卡片、灰色边框、圆角和 Tailwind 工具类，不建立新视觉体系。页面必须同时具备加载、成功、空数据、部分数据和失败状态。

### 4.1 经营数据总览 `ops-source`

页面顺序：

1. 标题区：标题“经营数据总览”，副标题明确“授权 OTA 与本地已有经营记录，不代表全酒店财务事实”。
2. 筛选区：复用 `operationFilters.hotel_id`、`operationFilters.date` 和 `operationHotelOptions`；点击刷新调用 `loadOperationFullData()`。
3. 数据可信提示：展示 `operationSourceBrief.status/summary`；有 `abnormal_flags` 时逐条展示，不隐藏异常。
4. 经营结果卡：使用 `operationSummaryCards`，展示收入、订单、间夜、ADR、OCC、RevPAR；缺值继续显示 `-`，真实零值显示 `0`。
5. OTA 漏斗卡：使用 `operationOtaCards`；标题和说明固定标注“OTA 渠道”。
6. 竞对与服务质量：使用 `operationCompetitorCards`，并直接展示 `service_quality` 的 PSI、服务分和样本数；各模块显示自己的 `data_status`。
7. 判断入口：使用 `operationDecisionCards`；“进入根因分析”只切换到 `ops-analysis` 并调用现有分析函数。

### 4.2 问题根因分析 `ops-analysis`

页面顺序：

1. 复用同一酒店和日期筛选，避免三个页面产生互不一致的当前范围。
2. 点击“开始分析”调用 `analyzeOperationRootCause()`，不新增问题类型或模型调用。
3. 结果头展示 `main_problem`、`problem_level`、`conclusion`。
4. `root_causes` 按后端 `priority` 顺序展示标题、置信度、证据和建议；文案使用“可能根因”，不包装成已证实因果。
5. `next_actions` 作为建议清单展示，不自动创建执行单。
6. 当 `problem_level=data_insufficient` 或 `root_causes=[]` 时，显示后端缺数结论和补数动作，提供返回经营总览/数据健康的入口，不生成默认根因。

### 4.3 风险预警 `ops-insight`

页面顺序：

1. 顶部展示未读数 `operationUnreadCount`，刷新调用 `loadOperationAlerts()`。
2. 使用现有 `operationAlertFilters` 和 `filteredOperationAlerts`，保留全部、高/中/低、未读、已读筛选。
3. 每条预警展示风险级别、标题、说明、酒店、日期、状态和建议动作；不把规则生成预警写成模型结论。
4. “标记已读”复用 `markOperationAlertsRead()`；没有目标记录时保持现有明确提示。
5. 空列表展示 `operationAlerts.data_status`；加载失败展示 `operationError.alerts`，不得用“暂无风险”掩盖请求失败。

## 5. 收益研究转执行设计

### 5.1 前端状态

在每个 `revenueResearchRuns[productKey]` 中增加：

- `executionLoading`：防止重复点击。
- `executionError`：内联展示接口返回的 4xx/5xx 原因。
- `executionIntent`：保存成功返回的执行意图摘要和 ID。

重新运行同一研究产品时清空旧的执行状态，避免旧执行 ID 被误认为属于新结果。

### 5.2 准入规则

“转运营执行”按钮仅在已有研究结果后出现，并同时满足以下条件才可用：

- `result.readiness.stage === 'research_ready_for_execution'`；
- `result.readiness.execution_ready === true`；
- `result.hotel_scope.mode === 'single_hotel'`；
- `result.hotel_scope.hotel_id` 为当前账号可见的正整数酒店 ID。

如果研究是在“全部可见酒店”范围运行，按钮保持禁用，说明“请选择单店并重新运行”；不使用当前下拉框给旧的多店结果补酒店 ID。数据缺口、动作缺失或模块未接入时直接展示现有 readiness 原因，不发送接口请求。

### 5.3 请求与回读

点击后调用现有 `POST /api/revenue-research/execution-intent`，请求体固定由
`JSON.stringify({ hotel_id: result.hotel_scope.hotel_id, research: result })`
生成。前端不自行拼接 source ID、readiness 或证据，不绕过后端 ready-only 和重复检查。

成功后：

1. 保存 `execution_intent.id/status`；
2. 页面显示“已转执行 #ID”；
3. 提供“查看执行跟踪”按钮，切换到 `ops-track` 并调用 `loadOperationActions()`；
4. 不自动审批、不自动执行、不写 OTA。

后端返回 `422` 时展示 readiness/酒店范围原因；返回 `409` 时展示重复关联原因；其他错误保留真实接口阶段，不统一包装成模型失败。

## 6. 导航与防回归契约

### 6.1 导航调整

- 在老板视角“运营执行”分组中加入经营数据总览、问题根因分析、风险预警，引用已有 source path 和权限，不复制菜单定义。
- 将“待开发...”改名为“更多功能”，同步相关注释；组内 children、权限和 path 全部保持不变。

### 6.2 页面契约

新增 `scripts/verify_navigation_page_contract.mjs`：

1. 在受控 VM 中读取 `public/system-static.js` 的 `menuItemDefinitions`。
2. 递归取得所有带 `path` 的叶子菜单；相同 path 的不同 tab 合并为一个页面合同。
3. 从 `public/app-main.js` 的 `BOSS_VISIBLE_NAVIGATION_CONFIG` 定界块提取 `sourcePath` 和 `overrides.path`，把老板视角生成的页面路径并入合同集合。
4. 读取并解码 `resources/frontend/app-template.html`，取得全部 `currentPage === '<path>'` 页面块。
5. 使用显式别名表处理 `ai-workbench -> compass` 这类已验证的渲染别名；别名必须在脚本内逐项解释，不允许通用兜底。
6. 任一导航 path 既没有直接页面块、也没有显式别名时失败，并输出缺失 path。
7. 额外固定检查 `ops-source`、`ops-analysis`、`ops-insight` 的 `data-testid` 页面根节点，以及收益研究转执行按钮和现有 endpoint 调用。

`scripts/verify_release_functional_readiness.mjs` 的前端代码检查改为读取 `public/index.html + resources/frontend/app-template.html + public/app-main.js` 的规范集合；不再要求业务 UI 字符串必须留在空壳 `public/index.html`。

## 7. 文件边界

预计修改：

- `resources/frontend/app-template.html`：三个页面正文、收益研究执行按钮/状态。
- `public/app-main.js`：收益研究转执行状态与函数、运营入口、分组改名。
- `scripts/verify_release_functional_readiness.mjs`：读取规范前端源集合。
- `package.json`：注册新的页面契约验证命令，并纳入最小前端守卫。

预计新增：

- `scripts/verify_navigation_page_contract.mjs`。
- `tests/automation/first_batch_operation_research_ui.test.mjs`。

生成产物：

- `public/app-render.min.js`。
- `public/app-main.min.js`。
- `public/index.html` 中对应内容哈希。

明确不修改：

- `route/app.php`。
- `app/controller/RevenueResearch.php`。
- `app/service/RevenueResearchService.php`。
- `app/controller/OperationManagement.php`。
- `app/service/OperationManagementService.php`。
- `database/` 下所有文件。

## 8. 验证与验收

### 8.1 自动验证

实施时按先失败、后通过的顺序增加并运行：

```powershell
node --test tests\automation\first_batch_operation_research_ui.test.mjs
npm.cmd run build:frontend-template
npm.cmd run build:frontend-entry
npm.cmd run verify:frontend-template
npm.cmd run verify:public-entry
npm.cmd run verify:navigation-pages
npm.cmd run verify:e2e-contracts
npm.cmd run review:functional-readiness
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --filter RevenueResearchServiceTest
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --filter ControllerRouteContractTest
git diff --check
```

### 8.2 页面验收

在本地服务健康检查为 HTTP 200 后，用实际页面验证：

1. 从“运营执行”打开三个页面，均有正文且刷新后无 Vue 原文泄漏。
2. 选择有权限酒店和日期，经营总览、根因分析、预警分别出现加载、成功/空数据和失败状态。
3. 缺数据时显示 `data_status`/readiness，不显示假 0、假成功或全酒店结论。
4. 单酒店、ready 的收益研究结果可以创建执行意图；多酒店或非 ready 结果不能请求接口。
5. 成功创建后可进入 `ops-track` 并回读同一执行意图 ID。
6. “更多功能”组内原有页面与权限保持可用。

### 8.3 停止条件

第一批只有在上述自动验证和本地页面验收完成后才算结束。真实后端或数据库不可用时，前端合同可以标记为已验证，但“执行意图数据库回读”必须标记为未验证，不能用 mock 代替。完成后停止，不自动开始第二批。

## 9. 风险与控制

- **预编译产物漂移**：只编辑规范模板和入口源，通过两个 build 命令生成产物，并由 hash/大小守卫验证；不直接手改 min 文件。
- **重复执行意图**：前端 loading 防双击，后端继续以现有 `409` 重复检查为最终权威。
- **酒店范围错配**：执行只接受研究结果自身的 `single_hotel` 范围，不把多店结果绑定到当前下拉酒店。
- **旧结果污染**：重新运行研究时清空上次执行状态；切换产品只读取对应 product key。
- **模板体积增长**：页面复用现有 computed 和 helper，保持 `app-render.min.js` 低于现有 1.3 MB 原始、225 KB gzip 门槛。
- **既有功能回归**：不改后端路线、数据结构和已实现页面；导航只增加已有 source 引用并改分组文案。
