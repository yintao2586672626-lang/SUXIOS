# 2026-09-05 首页与数据查询修复交付记录

## 目标与边界

让经营首页正确展示数据状态、让已有查询不静默改变日期范围，并修复首次加载、页面切换和窄屏下的可读性问题。以本机西安天诚（hotel 121）为主要页面验收入口。

本轮为本地实现与验收，不代表项目整体完工。未主动执行 OTA/PMS 采集或经营写入、导入真实数据、审批、外发、提交、推送或部署；没有读取密码、Cookie、Profile、localStorage 或令牌。浏览器只复用既有本机登录态，正常页面自身的后台请求不等于本轮验证了真实 OTA 采集。

活动工作树：`.active-worktrees/HOTEL-clean-20260901`。分支 `codex/save-all-20260903`，基线 HEAD `9fa781fc92baa66b410f4e5d6e8c8c3c1ebae81b`，本轮没有产生新提交。开始时已有 36 个跟踪修改和 31 个未跟踪状态条目；原有修改全部保留。冻结 `HOTEL/` 未修改。

## 已修复

| 问题 | 原因 | 修复与证据边界 |
|---|---|---|
| 首次打开首页排版缺失 | 首页专用 CSS 被放进默认首页不立即加载的延迟资源组 | 将该 CSS 纳入认证后启动资源；不改变登录前公开资源边界 |
| 浅背景配浅色字、返回首页后样式退回 | 通用卡片规则和后加载的完整样式覆盖首页专用表面 | 提高仅首页对应选择器优先级，保持深色进度面板、浅色数据面板；首次及完整样式加载后均检查 |
| 窄屏筛选控件挤压标题 | 标题与固定宽度控件保持同一行 | 允许按内容宽度换行；768 像素视口无页面水平溢出，标题与控件矩形不重叠 |
| 0 项已验证却称部分取得/可对照 | 概要与对照徽标没有检查可用事实数 | 0 项显示未取得；加载中显示正在读取；无可用事实且请求失败显示读取失败；已有部分事实继续分来源展示 |
| 业务日期事件声明被覆盖 | 组件重复声明 emits，后一项缺少业务日期事件 | 合并为一份正确声明；实页切换到 09-01，再返回首页 09-04 已确认 |
| 周计划未生成被当成服务故障 | latest 查询将无快照 404 统一包装成读取失败 | 新增只读 availability 结果；明确 not_generated、readback_verified=false；权限、存储失败、精确 ID 缺失仍为错误；已有快照返回不变 |
| 期间问题被悄悄按一天回答 | 日期解析只取首日或回退当前日期 | 期间、多日期、无年份范围、星期范围在单日读数前明确阻断，保留请求范围并提示现有历史入口；期间数值聚合仍未实现 |
| 明确单日日期误判 | 中文日期边界、重复日期描述及酒店名包含月份词 | 保留完整年份，规范同日重复描述，仅剥离已确认酒店全名；覆盖“今年9月4日”“今天（2026年9月5日）”“七月酒店…昨天” |
| 测试样例挤掉真实历史订单窗口 | 先选最新日期，再排除测试样例 | 内存和数据库路径先排除明确样例再选最近已存 30 天；未知标记、人工导入和含“测试”的酒店名不被误删 |

## 自动化验证

测试全部使用 fixture、临时 SQLite 或内存数据，不以测试样例作为真实经营事实。

- PHP 精准查询：`tests/PreciseQueryRouterServiceTest.php`，60 tests / 468 assertions，通过。
- PHP 双平台快析：`tests/DualOtaOrderQuickAnalysisWindowTest.php` + `tests/DualOtaOrderQuickAnalysisServiceTest.php`，27 tests / 193 assertions，通过。
- PHP 周计划：`tests/WeeklyOperatingPlanAvailabilityTest.php` + `tests/WeeklyOperatingPlanSnapshotServiceTest.php`，17 tests / 106 assertions，通过。合计 104 tests / 767 assertions。
- Node 六个直接相关测试文件，69 tests，通过：`home_missing_state_regression`、`home_business_time_model`、`home_operating_orchestration`、`weekly_operating_plan`、`dashboard_failure_root_recovery`、`frontend_authenticated_bootstrap`（均在 `tests/automation/`，后缀 `.test.mjs`）。
- 已先复现零事实/周计划空态、日期范围、测试样例窗口失败，再修改；独立复核额外发现的日期误伤与漏拦范围也已补测修复。
- `npm.cmd run build:frontend-startup-helpers`、`npm.cmd run verify:frontend-startup-helpers`、`npm.cmd run verify:public-entry` 通过。
- `node scripts/verify_e2e_contracts.mjs`：2291 条静态集成合同检查通过；这不是浏览器 E2E 或真实采集证明。
- 修改的 PHP/JS 语法及目标文件 `git diff --check` 通过。

## 本机页面观察（2026-09-05 约 04:43—05:09，Asia/Shanghai）

1. 首页西安天诚、业务日 2026-09-04，仍为已验证 0/31；修复后不再声称部分取得或部分可对照。真实缺失未被补成 0。
2. 切换到 2026-09-01 后日期和文案同步更新；该日也未形成首页要求的严格事实。未用其他酒店或日期替代。
3. 周计划在该门店相应周次显示“尚未生成”，不再与“读取失败”混在一起；没有自动生成计划。
4. 首页首次刷新已加载专用样式。再进入门店管理触发完整样式后返回首页，深色进度面板与浅色数据面板均保持；768 像素下 document.body.scrollWidth=768，标题/筛选控件无重叠。临时视口已恢复。
5. 携程页切至西安天诚时，UI 显示“登录态已验证”，但本店订单快析与订单深度分析没有可用记录。该 UI 状态不能证明当前凭据仍可采集，本轮未触发抓取验证。
6. 携程“入库记录”选择西安天诚，日期 2026-08-06 至 2026-09-05，显示 5 组竞争圈记录，业务日为 08-08、08-16、08-30、08-31、09-01，均为“已入库，来源待核”。列表是合并后的批次/组，不是 5 条订单或 5 个已核验日；组内本店和竞品合计指标不能当成本店业绩。
7. 入库记录原先采用独立的“全部酒店”筛选，不能把列表总数当作顶部当前酒店的数据量。核查后恢复原筛选及携程原酒店“敦煌漠蓝新”，最终停留在首页西安天诚、09-04。
8. 页面切换时曾出现一次“执行闭环加载失败”提示；之后首页成功回读今日编排并显示该日无任务。未稳定复现或确认原因，不列为已修复。
9. 最终健康检查为 `status=ok`、`runtime_mode=development_fallback`、`production_runtime_ready=false`。最后刷新已加载首页 CSS `h1ee58fd51f` 与 helper `hdde28134c1`，页面完成加载，原视口宽 1145；不表示生产就绪。

## 仍需完成的业务环节

- 先在西安天诚选一个已有原始来源可核对的携程业务日，完成本店与竞争圈拆分、来源核验、指标保存与精确回读，再验证首页/历史/查询一致。现有待核竞争圈记录不能直接升级为订单收入。
- 没有保存的原始证据不能凭空复原；需找回原始文件或在明确授权下重新采集。不要把全局 Cookie 警告当作每个门店的数据缺口根因。
- 精准查询的期间汇总仍未提供；目前明确拒绝静默单日替代，日期范围历史列表继续可用。
- 当前原有未提交变更包含其他并行功能，本轮没有整理成独立可提交包，也未声称 GitHub、部署或真实经营效果完成。

## 本轮文件边界

业务代码：`app/controller/OperatingOpportunity.php`、`app/service/WeeklyOperatingPlanSnapshotService.php`、`app/service/PreciseQueryRouterService.php`、`app/service/DualOtaOrderQuickAnalysisService.php`。

前端：`public/home-static.js`、`public/compass-authority-polish.css`、`public/index.html`、生成物 `public/app-startup-helpers.min.js`。

测试：`tests/PreciseQueryRouterServiceTest.php`、`tests/WeeklyOperatingPlanSnapshotServiceTest.php`、新增 `tests/WeeklyOperatingPlanAvailabilityTest.php`、新增 `tests/DualOtaOrderQuickAnalysisWindowTest.php`、新增 `tests/automation/home_missing_state_regression.test.mjs`、`tests/automation/frontend_authenticated_bootstrap.test.mjs`。

以上为本轮修改范围，不代表这些文件的全部 Git diff 都属于本轮；尤其快析服务、首页入口和生成物原先已有修改。
