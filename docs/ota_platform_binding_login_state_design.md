# 宿析 OS OTA 平台绑定与登录态设计方案

> 更新日期：2026-06-29
> 适用范围：携程、美团 OTA 数据源绑定、浏览器 Profile 登录态、采集状态、评价订单匹配、敏感能力隔离
> 核心链路：OTA 数据 -> 收益分析 -> AI 决策 -> 运营管理 -> 投资决策

## 1. 设计结论

宿析 OS 可以学习外部代运营后台的产品结构和状态机，但不学习其账号密码托管方式作为默认主线。

推荐方向：

```text
系统账号登录
-> OTA 数据源绑定
-> 账号使用者在自己的电脑打开平台后台
-> 用户人工完成短信/验证码/人机验证
-> 系统记录 manual_login_state_verified
-> 同步目标日期 OTA 数据
-> 用真实入库行、字段证据和 verifier 证明闭环
```

不进入默认主线：

```text
系统内填写携程/美团账号密码
后端长期托管 OTA 密码或完整会话
用旧版 App 会话作为核心数据源
只凭 login_status 判断采集成功
默认批量获取完整手机号
```

## 2. 两层登录边界

| 层级 | 宿析 OS 定义 | 允许保存 | 不允许混淆 |
| --- | --- | --- | --- |
| 系统自身登录 | 用户登录宿析 OS，获得系统 token、用户、角色、酒店、租户和权限上下文 | 系统 token、用户 ID、角色、hotelId、tenantId、权限摘要 | 不代表已登录携程/美团 |
| OTA 数据源授权 | 酒店与携程/美团门店、Profile、采集范围绑定 | 平台、系统酒店 ID、平台门店 ID、Profile 标识、采集范围、状态、失败原因 | 不默认保存 OTA 账号密码 |
| OTA 登录态 | 账号使用者在自己的电脑完成人工登录和验证 | `manual_login_state_verified`、验证时间、Profile 状态摘要、本机采集证据导入状态 | 不把 Cookie/API、login_status 或服务端弹窗当作数据闭环 |
| OTA 数据闭环 | 目标日期真实数据已采集、入库、字段可信、UI 显示可追溯 | 目标日期行数、source path、metric key、storage field、verifier 输出 | 不用历史样本、空数据或 fallback 标绿 |

系统登录 token 命名要避免误导。若前端为了兼容保留 `localStorage.token`，新增别名也应表达“系统登录”，例如 `suxios_token`。不建议把宿析 OS 系统 token 命名成 OTA 登录态。

## 3. P0 状态模型

### 3.1 数据源绑定状态

```text
unbound          未绑定
binding          绑定中
bound            已绑定
expired          登录态失效
failed           绑定失败
disabled         已停用
unknown          未知，需复核
```

字段建议：

| 字段 | 含义 |
| --- | --- |
| `platform` | `ctrip` / `meituan` |
| `system_hotel_id` | 宿析 OS 酒店 ID |
| `platform_hotel_id` | 携程酒店 ID / 美团 POI ID / 门店 ID |
| `profile_id` | 浏览器 Profile 标识 |
| `binding_status` | 数据源绑定状态 |
| `binding_failure_reason` | 绑定失败原因 |
| `enabled` | 是否启用 |
| `updated_at` | 最近状态更新时间 |

### 3.2 Profile 登录态

```text
not_verified                 未验证
manual_login_state_verified   已人工验证
expired                      已过期
captcha_required             需要验证码/短信
human_verification_required  需要人机验证
profile_missing              缺少 Profile
platform_session_error       平台会话异常
```

关键规则：

- `manual_login_state_verified=true` 只表示登录态已人工确认。
- 它不是目标日期数据已采集的证明。
- 登录态过期、验证码未完成、人机验证未完成必须直接显示，不用“同步失败”泛化。

### 3.3 采集状态

```text
not_collected       未采集
collecting          采集中
success             成功
partial_success     部分成功
failed              失败
data_gap            数据缺口
field_missing       字段缺失
not_loaded          未加载
```

采集成功必须至少满足：

```text
目标日期存在真实入库行
关键 metric key 有 source path
关键 storage field 有入库值或明确缺口
字段状态没有被 fallback 或历史样本冒充
对应 verifier 通过
```

## 4. P0 登录流程条

外部系统的流程是：

```text
账号登录 -> 安全验证 -> 人机验证 -> 完成
```

宿析 OS 应改成：

```text
打开平台登录
-> 等待用户验证
-> 确认登录态
-> 同步目标日期数据
-> 验证数据完整性
```

每一步都要有状态：

| 步骤 | 成功状态 | 阻塞状态 |
| --- | --- | --- |
| 打开平台登录 | Profile 窗口已打开 | Profile 缺失、浏览器启动失败 |
| 等待用户验证 | 用户完成短信/验证码/人机验证 | `captcha_required`、`human_verification_required`、超时 |
| 确认登录态 | `manual_login_state_verified=true` | 会话过期、平台仍返回登录页 |
| 同步目标日期数据 | sync task 运行完成 | 接口异常、页面未加载、无业务行 |
| 验证数据完整性 | verifier 通过 | 目标日期无数据、字段缺失、source path 缺失 |

## 5. 失败原因规范

P0 必须显式展示这些失败原因：

```text
not_logged_in
captcha_required
human_verification_required
session_expired
profile_missing
target_date_no_data
field_missing
platform_api_error
browser_runtime_error
sync_completed_without_saved_rows
```

禁止文案：

```text
同步成功，但实际 saved_count=0
已授权，但没有 manual_login_state_verified
已完成，但目标日期无入库行
接口正常，但字段缺失被默认值补齐
```

## 6. 美团手机号能力隔离

美团手机号能力不进入订单同步主流程，单独作为敏感模块设计。

建议状态：

```text
not_enabled
permission_denied
querying
success
failed
quota_exceeded
session_expired
```

P1/P2 设计要求：

| 能力 | 规则 |
| --- | --- |
| 订单列表 | 默认只展示脱敏手机号 |
| 查看完整手机号 | 单条订单触发，不批量默认获取 |
| 权限控制 | 仅授权角色可用 |
| 审计记录 | 记录用户、时间、酒店、订单、原因、结果 |
| 状态展示 | 查询中、成功、失败、额度不足、会话过期分别显示 |
| 数据源边界 | 不把旧 APK / App 会话作为宿析 OS 主数据源 |

## 7. 评价订单匹配

这项能力适合进入宿析 OS 的 OTA 运营链路。

输入字段：

```text
评价入住时间
评价房型
评价平台用户标识
候选订单入住/离店时间
候选订单房型
订单状态
平台订单标识脱敏值
```

匹配策略：

| 置信度 | 规则 | 系统动作 |
| --- | --- | --- |
| 高 | 入住日期、房型、用户标识、订单状态高度一致 | 可自动绑定，但保留撤销 |
| 中 | 日期或房型部分一致，存在多个候选 | 列候选，人工确认 |
| 低 | 关键字段缺失或冲突 | 不绑定，只保留缺口原因 |

业务价值：

```text
OTA 数据
-> 评价管理
-> 差评定位
-> 运营动作
-> 复盘
```

## 8. 携程运营动作

携程欢迎模板、自动回复、消息触达等属于“登录态后的运营能力”，不能与数据采集闭环混同。

建议放入 P2：

| 能力 | 边界 |
| --- | --- |
| 欢迎模板检测 | 只读检测，记录模板是否存在 |
| 欢迎模板创建 | 明确人工确认，不默认自动写平台 |
| 回复/触达动作 | 必须进入运营执行审批和审计 |
| 数据闭环 | 运营动作不能替代目标日期 OTA 数据证明 |

## 9. 推荐落地路线

| 阶段 | 学什么 | 宿析 OS 落地 |
| --- | --- | --- |
| P0 | 平台绑定状态卡片 | OTA 数据源状态页：绑定、登录态、采集、失败原因分开 |
| P0 | 登录流程状态 | Profile 登录态流程条：打开、验证、确认、同步、校验 |
| P0 | 错误反馈 | 失败原因直接展示，不兜底标绿 |
| P1 | 评价订单匹配 | 候选订单、置信度、人工绑定/解绑 |
| P2 | 手机号取号 | 独立敏感能力，权限、审计、额度和状态齐全 |
| P2 | 携程运营动作 | 欢迎模板等登录态后动作，走审批和审计 |

## 10. P0 验收标准

P0 完成时，必须能回答：

| 问题 | 验收方式 |
| --- | --- |
| 当前用户是否登录宿析 OS | 系统 token 状态和用户/酒店权限上下文可见 |
| 当前酒店是否绑定携程/美团数据源 | 数据源绑定状态可见 |
| OTA Profile 是否人工验证 | `manual_login_state_verified` 明确展示 |
| 为什么不能采集 | 失败原因不是泛化错误 |
| 是否采到了目标日期数据 | 目标日期入库行和 verifier 输出证明 |
| 是否可以进入收益/AI | 只在 OTA 证据链通过后进入，不用历史样本冒充 |

最小验证命令：

```powershell
npm.cmd run verify:p0-ota-field-loop -- --date=<目标日期>
npm.cmd run verify:e2e-contracts
npm.cmd run verify:public-entry
```

若只验证携程单平台，必须显式使用平台参数，且不得把美团未完成掩盖成全平台完成。

## 11. 非目标

本方案不做：

```text
不新增 OTA 账号密码托管主线
不新增后端长期保存携程/美团密码
不默认启用 App 会话取号
不把 login_status 当作采集完成
不把 Cookie/API 临时路径包装成已验证数据源
不把 OTA 渠道数据包装成全酒店经营事实
```

## 12. 当前补强优先级

1. 先补状态模型和 UI 文案，分清系统登录、平台绑定、Profile 登录态、目标日采集证据。
2. 再补流程条，让用户知道卡在打开 Profile、验证码、人机验证、登录态确认、同步还是数据校验。
3. 再补评价订单匹配，服务差评定位和运营复盘。
4. 最后再考虑美团手机号、携程欢迎模板等敏感或写平台能力，单独权限和审计。
