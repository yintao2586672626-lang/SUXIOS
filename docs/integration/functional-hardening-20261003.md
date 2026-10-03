# 宿析OS功能锤炼 — 2026-10-03

基于已提交并通过两轮CI的b4116a62480342ad26e97dc16f51e374b16e458a，本轮先完成九组功能路径修复，再按正式提交接入五项定向锤炼；共享源码合并后重新验收。原整合的31项工作区、投资基础、五项强化与历史修复均保留。

## 已处理问题

| 范围 | 复现的问题 | 最终行为与证据 |
|---|---|---|
|navigation|收益驾驶舱别名绕过已有导航解析|复用现有handleMenuClick；携程/美团保留精确stored/manual标签；实际Vue挂载验证16个原生目录入口|
|campaign_edit|先选活动记录的迟到响应覆盖最后选择|独立编辑序号；成功、失败、新记录、保存和卸载均校验当前操作；真实挂载连续选择与记录身份断言|
|guest_history|旧客诉历史覆盖最后选择|独立历史序号与kind/key校验，刷新/范围/卸载失效；同范围先后响应与错误回归|
|workspace_draft|配置保存回包会替换较新响应式草稿|提交版本用于receipt/applied；较新草稿保留未提交；7提交/14草稿回归；保存时表单隐藏，证据限响应式状态|
|handover_limit|合并前校验导致保存101项且旧150项无法编辑|合并去重后限制100；封存超限旧版本可编辑/关闭且不能增长；SQLite100/101、150编辑/关闭、151拒绝与零副作用|
|booking_file|旧文件读取覆盖新文件或手改导入文本，返回原范围时复活|文件序号、范围、当前导入草稿共同决定回包所有权；迟到成功/失败、ABA范围、手改文本与实际文件选择|
|booking_correction|旧更正回读覆盖新酒店、记录或同酒店较新草稿|更正序号、酒店/记录/范围与整份草稿指纹；保存使旧读取失效；迟到成功/失败、同酒店编辑、实际DOM输入与切店|
|investment_totals|累计金额误用单笔上限且大额显示丢分|累计字符串精确转整数分；保留单笔上限和溢出拒绝；页面用BigInt分组；真实保存两笔999999999999.99回读1999999999999.98；整数上限/溢出/精确分|
|actual_cost_dates|未来库存被保存和采用为已发生实际成本|上海今日校验；采用旧快照时再次校验未来核算月/启用来源日；未来422/409不写入；今天/历史/禁用参考与方案精确回读|

## 根修复 checkpoint 验收（d7d0370a）

- PHP：17个互不重复目标文件，246 tests / 1846 assertions；按本轮最后通过的运行合并，未重复累计红绿和兼容子集。
- 页面与资源：16个互不重复Node文件，198 tests，0失败/跳过；含实际生产组件挂载、文件选择、酒店草稿、配置/历史、SVG/WebM与生成投资桥。
- 完整前端构建、公共入口检查通过；启动gzip 619534/620000，未放宽预算。
- 旧PR关键修复保护、全部整合Git blob与当前验收源码身份在提交后核对；最终CI必须对应最终推送SHA，不能用基线CI替代。
- 范围复查：OTA非有限汇总/缺失状态、渠道净贡献、预订日期/来源/同提前期，投资台账/导入/情景、耗材来源/确认、人审版本和正式导出门禁均已核对。无实质新发现的模块复用对应当前源码与已有测试；不是全部真实页面现场验收。

## 8ff70d7组合 checkpoint 验收（后续主线/审计变化前）

来源提交：7c77dd64bf9cd6c56fc4672d749808c5c4ce6874，根修复提交：d7d0370a70f776028727339192f530167820f3e7。冲突按行为保留双方必要修复，生成资源依据最终源码重新构建。来源报告中的301项PHP、121项前端及10项DOM是来源版本证据，不与下列最终组合重复相加。

- 当前组合PHP：30个不重复文件，427 tests / 3180 assertions，0失败/跳过。
- 当前组合Node：16个文件，234 tests，0失败/跳过；独立生产CSS浏览器另有1 test / 14 DOM场景通过。
- 启动gzip 619532/620000，固定构建及资源身份均通过。数据库仍为隔离SQLite，浏览器响应仍为本地合成。
- 追加对应功能：渠道严格金额和酒店身份；耗材失败禁用旧采用、同草稿幂等重试、按ID独立GET核对、跨月及未来来源和可见保存按钮；预订精确回读/范围/容量及房型切换；投资非零数值下溢与来源日期；人审旧记录内容和状态复验。

## ffdc7f7主线与依赖 checkpoint（后续PR60前）

验证期间main合入PR59：5821434c4839c9b9fd70bd0106ad176ba37d4b21。普通合并保留耗材精确库存、金额和采用一致性，学习不可变版本、完整读回及当前酒店行为，同时保留本轮已发生来源/同月/未来日期拒绝、幂等失败重试和独立GET核对。新学习日期入口的NUL在投资目标及profile控制器已先复现500，再修复为422且零新增记录。

当前审计报4high，根因是braces公告GHSA-vfj7-8cjw-p6xm，经micromatch/fast-glob/PurgeCSS8链引入。限定现有构建依赖改用API兼容的PurgeCSS7.0.2，移除该链，未忽略公告或关闭审核；当前npm audit为0漏洞。原CSS/模板/入口链重新构建验证，预算未放宽。

- 最终PHP：37个不重复文件，630 tests / 4121 assertions。
- 最终Node：20个文件，334 tests；独立生产CSS浏览器另有1 test / 14 DOM场景。全部通过，0失败/跳过。
- 启动gzip：619532/620000。上方checkpoint和来源统计均为历史证据，不重复累加。
- 最终GitHub验收必须对应包含此次main和依赖更新的提交；8ff70d7当时的依赖失败不被其他来源的绿灯替代。

## 最终投资主线接入与组合验收

main在检查期间继续合入PR60：d5bc8f6d2362d8ebbae3b9213b1c57d15475b238。普通合并保留投资回本账务、首次投入/回本精度、期间绑定的导入核对、受限项目不泄露、保存版本及页面迟到响应保护；根资金桥累计整数分、精确分显示和人工来源边界保持。只有共享生成入口缓存冲突，最终按完整源码重建。依赖与已验收PurgeCSS7.0.2锁一致。

- 最终PHP：40个不重复文件，722 tests / 5162 assertions。
- 最终Node：20个文件，357 tests；独立生产CSS浏览器另有1 test / 14 DOM场景。全部0失败/跳过。
- 启动gzip 619536/620000，审计0漏洞；所有来源和先前checkpoint均未与此统计相加。
- 最终GitHub验收必须对应包含PR60的当前SHA，ffdc7f7的检查只作为先前版本记录。当前功能闭环验收后停止。

## 证据边界

数据库均为隔离临时SQLite；浏览器为合成身份和响应。没有真实酒店或OTA/PMS写入、消息、审批、生产迁移、重启或部署。采购参考仍为reference_only，手工实收仍manual_unverified。已有投资查看与台账权限门槛保持原样。工作区草稿保护是响应式状态证据，保存期间表单隐藏，不声称普通用户可见输入丢失。

机器可读记录：docs/integration/receipts/functional-hardening-20261003.json。GitHub PR58的精确提交与两轮CI验收在推送后独立回读记录。

## 31项目录覆盖表

“native”表示指向已存在的产品入口；“config”表示字段映射/员工鉴权入口配置；“manual”表示人工保存版本，不能升级为平台/全酒店核验事实。所有31项的启用、批次、顺序和配置精确回读都由 BusinessWorkspaceServiceTest 与实际隔离浏览器覆盖。下表的产品业务验收范围另列，不将目录存在等同于完整实务验收。

| ID | 目录名称 | 类型 / 实际入口 | 本次操作、保存、回显证据与边界 |
|---:|---|---|---|
|24|OTA数据|native / online-data|页面条件存在；这里只验目录/入口，不读取真实OTA或证明采集保存。|
|19|收益期预订监测|native / finance booking|父级openWorkspaceFinance处理；现有隔离挂载验证设置应用真实BookingMonitoringPanel。实际预订数据未验。|
|26|广告数据|native / ctrip-ads；美团settings对应stored ads|真实父handler有CTRIP/美团tab处理；现有浏览器验证美团stored广告导航事件。广告事实未验。|
|25|价格数据|native / ctrip-market-competition|页面及专用tab入口存在；真实账号竞对事实未验。|
|18|收益驾驶舱|native别名 / trusted-revenue-analysis|原W1已修；实际挂载正确到agent-center/revenue/analysis。真实收益事实和业务保存未验。|
|1|工作台|native / compass|真实页面条件存在；本次不扩大到工作台业务保存。|
|2|酒店管理|native / hotels|真实页面条件存在；酒店写入未验。|
|15|间夜与均价联动|native / revenue-research-center|真实页面条件存在；该模块算法/事实验证不在本子范围。|
|17|点评数据|native / ctrip-review-match；美团settings对应review-match|CTRIP/美团专用tab可分发；真实点评事实未验。|
|30|数据来源配置|native / data-config|真实页面条件存在；专有连接/凭据未读或验证。|
|6|月任务与经营预算|native / operating-targets|真实页面条件存在；本次不扩大到日报/预算真实写入。|
|7|多店经营比较|native / finance portfolio|父级finance-tab接收；未证明真实多店同口径比较。|
|31|操作日志|native / operation-logs|真实页面条件存在；真实日志/权限页未验。|
|3|用户管理|native / users|真实页面条件存在；真实员工写入未验。|
|4|角色管理|native / roles|真实页面条件存在；真实权限修改未验。|
|27|系统设置|native / system-config|真实页面条件存在；真实系统配置修改未验。|
|29|来源接口与字段映射|config/manual / source_mapping|保存字段映射、历史精确回读、同酒店日期范围预览隔离测试通过；不代表远程API已连接。|
|10|酒店周报|manual / weekly_review|同服务保存人工评述/措施/引用与历史；实际隔离浏览器验证周报保存、引用失效、导出精确版本。事实仍manual_unverified。|
|12|店总周报|manual / manager_review|进入同note保存合同；源码路径已核对，未独立跑该kind完整浏览器流程。|
|8|复购率|manual source cohort / guests repeat|隔离服务+controller/SQLite+挂载Vue覆盖导入、覆盖声明、分母、保存回读、更正/void、错误与范围。来源人工声明，非全酒店/终身复购事实。|
|9|宾客舆情与客诉|manual / guests feedback|隔离fixture覆盖责任人、登记/编辑、追加事实、关闭/重开、证据和回读；不包含线上自动舆情采集。W3已修并回归。|
|23|反馈跟进|manual / guests feedback|复用同宾客反馈事实闭环；同上述证据，不包含消息发送。W3已修并回归。|
|22|反馈二维码配置|config / guests entry|隔离保存、编辑、精确回读、酒店改号，二维码用独立ZXing解码通过；入口明确authenticated_staff_only，不是匿名宾客写入。|
|16|OTA目标与措施复盘|manual / ota_review|同note保存合同、措施完成需证据；源码路径核对，未独立跑该kind完整浏览器流程；不写OTA措施。|
|14|抖音与营销作品数据|manual / campaigns marketing|隔离服务覆盖作品幂等、版本、未知数值、实际到店结果独立日期来源、金额与范围；合成Vue保存回显通过。无平台抓取。W2已修并挂载回归。|
|20|节日营销海报|manual + native artifact / campaigns poster|隔离service保存版本/SVG/HTML、转义、长文完整、改内容撤销审核；浏览器合成保存/download SVG通过。人工来源素材，无发布。|
|21|酒店短视频|manual brief + native browser WebM / campaigns video_brief|隔离service保存制作单与HTML；浏览器合成数据生成可播放1280x720 WebM（109853bytes）。文字画面，未提供实拍/音乐或平台发布。|
|5|必要日报与补录|native转跳 + manual关联 / campaigns report_reconciliation|本区打开原每日事实录入/pms-operating-data；仅关联现有daily_report，隔离service保存核对说明且拒绝跨酒店/日期。不是新增平台订单/83项日报实现。|
|28|日报接口适配|config/manual / source_mapping|与#29同字段映射入口；隔离预览可用，不表示专有日报接口已接通。|
|11|班次交接|manual / campaigns handover|隔离service覆盖继承未结、改日期/历史源拒绝、接班确认、证据关闭和版本；合成Vue草稿范围清理通过。W5已修，100/101与旧150项编辑/关闭/151拒绝均有SQLite精确回读回归。|
|13|报表补录与记录核对|manual / campaigns report_reconciliation|隔离service关联已有日报、保存/精确回读、同酒店同日期约束。真实日报未验，不创建OTA订单。|
