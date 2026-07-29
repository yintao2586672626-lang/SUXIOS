# 授权浏览器最小端点采集

## 目标

在用户已授权、已登录的平台会话内，用最少请求获取明确的经营事实，减少页面遍历和 DOM 解析，同时保持门店、日期、来源、保存回读和推送边界可验证。

这不是绕过登录或平台权限，也不是把内部业务端点冒充平台开放 API。登录、验证码、短信、账号授权和门店权限仍由平台控制。

## 路径优先级

| 优先级 | 路径 | 适用场景 | 边界 |
| --- | --- | --- | --- |
| P0 | 已知端点的隔离会话内直请求 | 端点、请求体、响应字段和身份/日期证据已经验证 | 只调用当前业务输出需要的端点 |
| P1 | BrowserContext/Page 响应监听或 CDP Network | 首次发现端点、平台改版、响应合同漂移 | 只保留清洗后的路径、字段合同和必要事实，不保存敏感 HAR |
| P2 | 页面内 fetch/XHR Hook 或受控浏览器扩展 | 外部控制器无法稳定附着，且必须长期观察用户可见浏览器 | 权限最小化；只作为发现或辅助，不取代系统绑定和保存回读 |
| P3 | DOM 可见字段提取 | 结构化响应没有该字段，或需要页面标签、排名、摘要证据 | 选择器失效必须显式失败，不能用页面文案补造数据 |
| P4 | 导出文件/人工导入 | 自动会话失效、临时补数或历史数据 | 标记来源和人工导入状态，不冒充实时采集 |

已知端点不应每次重新扫描。日常任务直接执行 P0；只有登录失效、端点未命中、响应结构变化或身份/日期证据不足时，才降级到 P1/P2 排障。DOM 永远不是默认主线。

## 直请求的推荐实现

1. 同一个浏览器进程可以承载携程、美团、订单来了等多个平台。
2. 每个平台/账号/门店使用独立 BrowserContext 或独立 Profile，并配置独立互斥锁。
3. 已知端点优先在授权 BrowserContext 中直接请求：
   - 仅 Cookie 鉴权且请求合同稳定时，可使用与 BrowserContext 共享 Cookie jar 的请求客户端。
   - 依赖 localStorage、CSRF 或页面生成请求头时，优先在同源页面上下文执行受控 `fetch`，避免把 Cookie/token 复制到配置、日志或任务参数。
4. 响应只接受可信域名、XHR/fetch、成功状态和结构化业务 JSON；按大小和 schema 设置上限。
5. 每次运行先完成平台门店身份和系统酒店绑定校验，再校验目标业务日期；任一不符禁止保存和推送。
6. 保存后按来源、酒店、日期和采集批次精确回读；只有回读一致才生成推送预览。

端口只是连接浏览器的通道，不是隔离或身份依据。不得因为三个平台共用一个调试端口而共享 Cookie、localStorage、默认上下文或门店绑定。

## 最小端点选择规则

先定义输出，再选端点：

| 经营输出 | 端点选择 |
| --- | --- |
| 今日核心经营卡片 | 身份端点 + 一个汇总端点 |
| 房费明细 | 只取房费类型的汇总明细；只有输出需要逐日/逐项明细时再取 DailyDetail |
| 区域指标 | 只在区域推送或对比任务中取区域汇总；区域趋势按所选指标单独请求 |
| 指标趋势 | 用户选择哪个指标就请求对应 type，不把全部趋势类型作为每次必抓 |
| 远期房态 | 一次获取满足展示窗口的日期范围，前端按 3/7/14/21 天视图切换；这些数字是观察窗口，不是固定日期段 |

端点注册表至少记录：

- `platform`
- `business_module`
- `path` 和方法
- 请求体字段名、允许值和目标日期规则
- 所需鉴权来源类别，但不记录 Cookie/token 值
- 响应 JSON path、类型、单位和缺失状态
- 酒店身份与日期证据
- `last_verified_at`、schema 指纹和失败原因

## 订单来了当前最小映射

| 输出 | 端点/类型 |
| --- | --- |
| 门店身份 | `/v2/ntw/web/ntw/get` |
| 今日核心指标 | `/v2/um-b/web/pro/data/businessIndicatorsTotal` |
| 房费明细 | `/v2/um-b/web/pro/data/businessIndicatorsSumDetail`，`type=0` |
| 必要的逐日房费明细 | `/v2/um-b/web/pro/data/businessIndicatorsDailyDetail`，`type=0` |
| 区域核心指标 | `/v2/um-b/web/pro/data/businessIndicatorsTotal/county` |
| 单项经营趋势 | `/v2/um-b/web/pro/data/businessIndicatorsTrend`，只传所选指标的 `type` |
| 单项区域趋势 | `/v2/um-b/web/pro/data/businessIndicatorsTrend/county`，只传所选指标的 `type` |
| 远期房态 | `/v2/hm-b/pro/web/accom/roomStat/forward/v2` |

普通实时推送不应调用完整诊断集合。区域、全部趋势和远期房态应按各自的推送计划或用户选择独立执行。

## 黑洞系统（hotel-auto-x）中可复用的精髓

这里的参考对象是用户此前提供并已沉淀为 `hotel-auto-x-*` Skills 的黑洞系统，不是朋友项目的收益判定代码，也不只是某个 fetch/XHR Hook。

黑洞系统采用的是完整采集流水线：

```text
平台/门店 Profile
-> 独立采集器
-> 调度、互斥锁、会话健康、重试/熔断
-> API 响应优先、DOM 兜底
-> CollectResult
-> 统一 ETL process_result()
-> daily_report / realtime_snapshot
-> 黑洞推送
```

| 层 | 黑洞系统做法 | 宿析OS应吸收的规则 |
| --- | --- | --- |
| 会话隔离 | PMS 组账号使用 `profiles/pms_default`；美团、携程使用 `profiles/<platform>_<store_id>` | 平台/账号/门店显式绑定；不得共享默认上下文或串用 Profile |
| 采集器 | PMS、携程、美团各有独立 collector；日报、实时、点评、广告按任务拆分 | 按来源和经营输出选择最小采集器，不执行无关全量任务 |
| 调度 | 运行前检查任务锁、Profile 锁、会话状态；失败可重试一次，连续失败进入 cooldown/circuit-open | 单店单日先行；资源忙或风控时停止强行重试 |
| 取数 | 结构化 API/response 为主，重要字段才用 DOM fallback | 已知端点直请求；网络捕获用于发现和漂移；DOM 只兜底 |
| 数据分层 | 昨日日报进入 `daily_report`，今日实时进入 `realtime_snapshot` | 实时事实不能覆盖已结算日报；来源、日期和口径保持独立 |
| 写入 | 采集器返回 `CollectResult`，统一经 `pipeline.etl.process_result()` 写入 | 不从临时脚本直写业务表；必须保存后精确回读 |
| 运维状态 | `task_runs`、collection logs、alerts、session active/expired | 登录、采集、写入、回读、推送分层报告，不把登录成功等同采集成功 |
| 推送 | 从已归一、已落库的来源事实构造 blackhole payload | 不直接把浏览器临时响应当成已交付数据 |

对订单来了最值得复用的是：独立 PMS collector、日报/实时分层、组账号下的门店切换、API 优先、统一 ETL、任务锁与会话健康。订单来了当前已知端点可以比黑洞系统的页面遍历更进一步，直接按“身份、今日核心、房费明细、区域、单项趋势、远期”六类意图执行最少请求。

黑洞系统只是流程参考。它的 `/home/qing/hotel-auto-x/hotel_auto_x` 路径、函数名、表名和 scheduler 名称不是宿析OS现成实现，不能直接复制为当前项目事实。

项目内对应的只读参考：

- `plugins/suxi-os-toolkit/skills/hotel-auto-x-login/SKILL.md`
- `plugins/suxi-os-toolkit/skills/hotel-auto-x-pms-collector/SKILL.md`
- `plugins/suxi-os-toolkit/skills/hotel-auto-x-ctrip-collector/SKILL.md`
- `plugins/suxi-os-toolkit/skills/hotel-auto-x-meituan-collector/SKILL.md`
- `plugins/suxi-os-toolkit/skills/suxi-ota-pms-collector-operating-loop/SKILL.md`

## 方案取舍

| 方案 | 效率 | 稳定性 | 结论 |
| --- | --- | --- | --- |
| BrowserContext 内已知端点直请求 | 最高 | 高，需维护字段合同 | 日常主线 |
| Playwright/Page 响应事件或 CDP `Network.getResponseBody` | 高 | 高，适合发现和漂移诊断 | 发现/排障 |
| 页面 fetch/XHR Hook | 高 | 中，受页面脚本、iframe 和加载顺序影响 | 兼容辅助 |
| Chrome 扩展 `debugger`/DevTools Network | 中 | 中，权限和维护成本更高 | 外部浏览器无法被控制时再用 |
| HAR | 低 | 低，容易陈旧且可能含敏感材料 | 仅脱敏 fixture/回归 |
| DOM 遍历 | 低 | 低，易受页面改版影响 | 最后兜底 |

## 必须显式的失败状态

- `login_expired`
- `sandbox_not_bound`
- `hotel_identity_mismatch`
- `target_date_unverified`
- `endpoint_not_hit`
- `schema_changed`
- `response_partial`
- `save_failed`
- `readback_mismatch`

这些状态不能转成 `0`、空数组、旧数据或“推送成功”。

## 技术依据

- [Playwright `BrowserContext`](https://playwright.dev/docs/api/class-browsercontext)：同一浏览器内可创建不共享 Cookie/cache 的隔离上下文。
- [Playwright `APIRequestContext`](https://playwright.dev/docs/api/class-apirequestcontext)：`browserContext.request` 使用对应 BrowserContext 的 Cookie jar。
- [Chrome DevTools Protocol `Target.createBrowserContext`](https://chromedevtools.github.io/devtools-protocol/tot/Target/#method-createBrowserContext)：可创建多个类似无痕 Profile 的上下文。
- [Chrome DevTools Protocol `Network.getResponseBody`](https://chromedevtools.github.io/devtools-protocol/1-3/Network/#method-getResponseBody)：可在网络事件命中后读取响应体。
- [Chrome Extension `chrome.debugger`](https://developer.chrome.com/docs/extensions/reference/api/debugger)：可通过扩展附着标签页并使用受限 CDP 域；需要显式 `debugger` 权限，因此不是默认主线。
