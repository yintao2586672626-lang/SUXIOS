# 投资回本参考经验整合与验收

日期：2026-10-02（北京时间）。范围：投资回本模块的比较、导入检查、账目核对和累计走势。

状态：实现已修改｜验证聚焦通过｜Git未提交｜部署/现场未部署｜阻塞本机8080未运行；真实运行库检查存在6项跨模块待升级；整页模板与分片不一致

## 已落地

| 参考经验 | 本次实现 | 口径与边界 |
| --- | --- | --- |
| Stessa、Rentastic 的投资组合与单项目分层 | 保留卡片，增加项目比较；按缺口、回本进度、名称或已保存卡片顺序排序；可直接打开台账或记收回 | 比较只覆盖当前载入且匹配搜索的项目，统一截至日；比较排序不改动卡片保存顺序 |
| 将组合表现拆成能直接行动的数字 | 总览增加各项目未回本缺口合计，同时单列已覆盖项目盈余 | 盈余不抵扣其他项目缺口；缺失金额不冒充零；大金额展示约数时另列精确元金额；比较表直接展示精确元金额 |
| Actual 的导入匹配与人工核对 | 导入预览提前列出已有完全重复、批内重复、相似候选及原因；逐行显示检查状态和本次实际金额增量 | 完全重复阻止确认；同日同额但备注不同仅作为相似候选，需明确确认；修改日期、金额、项目或选中行会让旧检查失效 |
| 核对状态应对应具体账目版本 | 新增、修改、作废、删除涉及已核对范围的实际记录后撤销旧核对；导入复用同一规则 | 计划、已作废、期初排除、核对范围之后的记录不误撤销；同步更新项目摘要，重新确认原日期不会被误判为重复保存 |
| 现金变化沿时间展示 | 详情增加默认折叠的累计投入与净收回阶梯图，以及精确余额表 | 只使用已录入实际记录和期初余额；不补缺失月份；计划不计实际；冲回减少净收回；末点必须与后台汇总完全一致，否则不画图 |

公开来源及前期逐项比较见 [参考评估](investment-payback-reference-review-20261002.md)。采用的是公开产品理念，没有注册外部账号，也没有将外部产品样例写入真实酒店账目。

本轮没有实现逐笔凭证长期存储、银行账户转账配对或新的现金性质分类；这些仍是前期报告中的后续候选。

## 代码与构建

- 主页面：`public/components/system/investment-payback.js`。
- 导入：`public/components/system/investment-payback-import.js`、`app/service/InvestmentPaybackImportService.php`。
- 台账核对：`app/service/InvestmentPaybackService.php`。
- 页面验收发现旧 Tailwind 全量源缺少已使用的 `min-h-[44px]` 工具类，补入该类并从源构建；实际按钮由20px恢复为44px。
- 通过现有构建同步回本组件、加载器、样式、登录关键样式及入口版本；同步了当时落后的启动 helper 产物。整页模板构建被现有模板/分片不一致阻止，没有强行选择或覆盖其中一方。
- 回本组件构建校验哈希：`d78ba17f3b`；本次最后一次 Tailwind 校验哈希：`962af52467`。

## 聚焦验证

| 检查 | 实际结果 |
| --- | --- |
| InvestmentPaybackPersistenceTest | 34 tests / 442 assertions，通过；含新增、编辑、删除、作废、跨日期、重新核对、幂等和审计失败事务回滚 |
| InvestmentPaybackImportTest | 25 tests / 193 assertions，通过；含预览范围、重复、相似、金额影响、过期检查拒绝、导入与核对失效联动 |
| InvestmentPaybackCalculatorTest | 15 tests / 86 assertions，通过 |
| InvestmentPaybackRoutingTest | 23 tests / 64 assertions，通过 |
| 前端 workbench 与 import | 共56项通过；包含编译产物的严格布尔值确认路径 |
| 回本组件构建、Tailwind 构建校验 | 通过 |
| 全站 public-entry 检查 | 未通过：业务模板分片与 `resources/frontend/app-template.html` 不一致，`public/app-render.min.js` 不符合当前源模板；该检查不能记作全站通过 |

PHP聚焦测试合计97项、785个断言。没有运行全仓测试，也没有将聚焦结果表述为生产或真实账号验收。

## 独立浏览器保存与回读

使用 `tests/Support/payback_import_fixture_router.php`、独立临时 SQLite 和 `127.0.0.1:18188`。所有写入都是合成数据；真实数据库只做版本状态只读检查。

1. 比较表按缺口排序、按进度排序可操作，返回卡片保留卡片顺序。缺少金额的项目仍显示待录入。已回本项目盈余13,490元与未回本项目缺口分别展示。
2. 合成项目先核对至2026-09-30，在同一截至日页面显示已核对。
3. 粘贴3行：已有完全重复30,000元、同日同额但备注不同的另一笔30,000元、新增1,234.56元。完全重复提前标出，确认按钮不可用。
4. 把新增金额改为1,234.57元，旧检查失效，重查后实际净收回增量为31,234.57元。明确排除完全重复行后，仍必须确认相似候选才能保存。
5. 确认2行后页面与API精确回读：投入520,000.00元，净收回432,834.57元，缺口87,165.43元，11笔明细，`history_complete_through=null`。页面显示待核对。
6. 累计走势图末行与上述汇总完全一致；重新打开合成页面后金额和记录仍在。
7. 320px iframe内，页面可用宽度305px（扣除纵向滚动条），页面滚动宽度也是305px；比较表在239px容器内部横向滚动1060px，未撑宽整页。主要按钮实测44px。
8. 窄屏导入弹窗宽266px，内部滚动宽也是266px；金额表独立横向滚动。逐行正确显示“完全重复，请排除”和“检查通过，待确认”。

截图位于 `output/payback-verification/`：

- `payback-comparison-integrated-20261002.png`：最终比较视图，合成数据。
- `payback-cumulative-integrated-20261002.png`：累计走势及保存后的余额，合成数据。
- `payback-import-review-integrated-20261002.png`：重复原因及金额影响，合成数据。
- `payback-comparison-320-integrated-20261002.png`、`payback-import-review-320-integrated-20261002.png`：窄屏独立滚动验证。

用户原来的8080标签页保留，未导航、刷新、关闭或填写。不能把这些合成页面截图当作用户真实项目已完成新版验收。

## 当前运行阻塞与下一步

验证过程中，8080可读取新版静态资源，但 `/api/health` 返回503。当时3306与8081–8083均有服务监听，直接访问PHP健康检查显示 `database=ok`、`database_schema=upgrade_required`。该次503并非没有PHP进程。

最后复核时8080已无法连接（连接被拒绝），此前的静态资源可读取状态不能表述为当前服务可用。本轮没有停止8080或8081–8083；独立18188验收服务此时也已不在运行。原浏览器标签仍保留。

只读数据库版本检查：当前版本 `20261001_create_investment_payback_ledger`，要求版本 `20261002_z_create_guest_operations`。以下6个SQL文件尚未登记，本轮没有新增或执行它们：

1. `20261002_create_ai_report_presentation_reviews.sql`
2. `20261002_create_campaign_operation_versions.sql`
3. `20261002_create_operating_evidence_snapshots.sql`
4. `20261002_create_room_type_on_books_snapshots.sql`
5. `20261002_z_create_business_workspace.sql`
6. `20261002_z_create_guest_operations.sql`

恢复真实运行需要先确认这些跨模块待交付变更与真实数据库的升级范围，并解决整页模板/分片的来源一致性，再通过数据库检查、public-entry和8080健康检查。当前原页面不要为验收而刷新；先保存正在录入的内容。

本轮实现的模块闭环已完成；真实账号内新版本操作、全站集成、生产部署和业务效果尚未验证。没有提交、推送或部署。
