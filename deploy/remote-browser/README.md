# 宿析OS 云端浏览器网关

## 当前交付状态

这是可部署但尚未部署的最小受保护网关资产。它接入现有
`CloudBrowserProfileService` 状态机，但本次没有 SSH、没有启动云端浏览器、
没有真实登录，也没有导出任何 Profile、Cookie 或会话资料。

## 安全契约

- Profile 持久层只允许 `*.tar.gz.enc`，使用 AES-256-GCM 和每个
  `profile_id` 独立 AAD；主密钥由 systemd credential 提供。
- 解密后的 Profile 只存在 `/run/suxios-cloud-browser/profiles`，浏览器退出后
  先重新加密，再将状态机推进到 `ready_to_collect`。
- 登录入口使用既有 15 分钟一次性票据。`open` 仅做不消费校验；
  `complete` 在密文写入成功后原子消费票据并推进状态。
- 网关 `8787`、noVNC `6080`、VNC `5900`、按需 CDP `9223` 全部只允许
  `127.0.0.1`。网关启动时不会自动启动 Chromium；登录与采集在任何异步校验
  之前争用同一个原子容量槽，同一时刻最多一个窗口，繁忙时显式返回 `409`。
- noVNC 的 WebSocket 必须经过网关生命周期代理；登录完成、取消或超时会主动
  销毁该登录的全部查看连接，旧页面不能看到下一门店复用的共享桌面。
- 采集回执只接受结构化身份、日期、保存数、回读数和字段事实摘要，不接受
  Cookie、Token、请求头、原始 HTML/HAR、Profile 路径等会话材料。
- 每条回执包含 `prev_hash` 和 `receipt_hash`；链校验失败时网关拒绝启动。

## 网关契约

### 1. 打开短期登录窗口

`POST http://127.0.0.1:8787/v1/login/open`

```json
{
  "profile_id": "cbp_opaque-id",
  "session_id": "cbls_opaque-id",
  "ticket": "one-time-login-ticket",
  "platform": "ctrip"
}
```

成功后只返回本机 noVNC 地址、到期时间和公开 ID。网关此时才按需解密
Profile 并启动一个 Chromium；票据错误、过期或范围不一致时不会启动。

### 2. 完成登录

用户在受保护窗口完成真实登录后，由受信任的本机操作调用：

`POST http://127.0.0.1:8787/v1/login/complete`

请求体与 `open` 相同，并且必须带受信任本机进程持有的
`Authorization: Bearer <control-token>`。普通登录窗口不能自行把 Profile
标记为已验证。网关依次停止浏览器、加密 Profile、消费一次性票据、推进状态机，
并写入 `login_profile_ready` 回执。数据库完成失败时会精确取消该登录票据并恢复
此前状态；如果响应丢失但数据库已经提交为 `ready_to_collect`，PHP 会保留已提交
事实并返回明确的结果未知状态，不会把成功 Profile 回滚或留下假活跃会话。

取消登录使用同样的精确 `profile_id`、`session_id`、`platform` 调用
`POST /v1/login/cancel`（需要 control token）；它会停止浏览器、封存 Profile、
断开查看连接并释放全局容量。正在打开或已经活跃的精确会话清理成功返回
`status=cancelled`、`cleanup_verified=true`；无匹配时幂等返回
`status=no_active_login`、`cleanup_verified=true`。PHP 入口发生响应丢失时必须先
验证网关已清理，之后才允许撤销数据库票据。

监督进程在采集子进程被强制终止后，可调用 `POST /v1/collection/abort`：

```json
{ "profile_public_id": "cbp_opaque-id" }
```

该接口仅接受这一个精确字段，需要 control token，只会终止同 Profile 正在打开
或已活跃的采集。完成清理返回 `status=aborted`、`cleanup_verified=true`；没有匹配时
幂等返回 `status=no_active_collection`、`cleanup_verified=true`，不会影响登录窗口。

### 3. 写入采集回执

`POST http://127.0.0.1:8787/v1/collection/receipt`

请求必须带 `Authorization: Bearer <control-token>`，正文只允许：

```json
{
  "task_id": "cct_opaque-id",
  "profile_id": "cbp_opaque-id",
  "platform": "ctrip",
  "tenant_id": 8,
  "hotel_id": 80,
  "target_date": "2026-07-25",
  "source_method": "cloud_browser_profile",
  "status": "saved",
  "identity_verified": true,
  "saved_count": 12,
  "readback_count": 12,
  "field_facts_sha256": "64-lowercase-hex",
  "failure_stage": null
}
```

`saved` 必须同时满足身份已验证且保存数等于回读数，否则回执门禁拒绝。
`partial`、`failed`、`blocked` 必须保持真实状态，不会被转换为成功。

## 获得云端授权后的部署步骤

只有目标服务器、SSH 账号、部署窗口和回滚责任人均已明确授权后才能执行：

```bash
cd /var/www/suxios/current
sudo bash deploy/remote-browser/install_secure_remote_browser.sh /var/www/suxios/current
sudo bash deploy/remote-browser/verify_secure_remote_browser.sh
```

安装器会启动显示层、VNC、noVNC 和网关，但不会启动 Chromium。不要为
`5900`、`6080`、`8787` 或 `9223` 添加公网防火墙规则。

安装前置条件：Node.js 必须不低于 `20.10.0`；浏览器必须是非 Snap 的系统
Chrome/Chromium。Ubuntu 的 Snap Chromium 无法可靠访问 `/run` 下的短期 Profile，
安装器不会通过 `apt` 拉取会转向 Snap 的 `chromium` 包，仅检查现成的系统 Chrome，
不满足时明确阻断。升级时只有缺失值或历史默认 `300` 秒会迁移为采集窗口
`1200` 秒；显式自定义值必须在 `900..1800` 秒内，否则安装器停止并要求修正。

操作人员从自己的电脑建立 SSH 隧道：

```bash
ssh -N \
  -L 8787:127.0.0.1:8787 \
  -L 6080:127.0.0.1:6080 \
  <ssh-user>@<server-address>
```

当前 VNC/noVNC 只允许 loopback 访问，不再使用需要传给页面的共享 VNC 密码。
日常登录应使用宿析同源受控入口；SSH 隧道只保留给已授权运维人员应急排障。

## 验收与停止条件

部署后必须先运行 `verify_secure_remote_browser.sh`。只有在用户真实登录、
密文 Profile 写入、状态机为 `ready_to_collect`、采集保存与数据库回读一致、
回执链校验通过后，才能称为云端端到端闭环。

本地代码测试、systemd 静态资产或 Profile 状态存在，均不能替代真实云端验收。

## 宿析同源可视登录（新门店默认方式）

新门店不再需要用户读取或复制共享 VNC 密码。`x11vnc` 和 `noVNC` 仍只监听
`127.0.0.1`；宿析页面通过 `/cloud-browser-viewer/` 同源入口访问，Nginx 对每个资源和
WebSocket 请求校验 15 分钟 HttpOnly Cookie。WebSocket 还必须携带 auth 子请求生成的
Profile/登录会话摘要并与当前网关槽精确一致；旧 Cookie 即使处在撤销窗口也无法接入
下一会话。未授权请求必须返回 `401`。

安装受控查看通道：

```bash
sudo bash /var/www/suxios/current/deploy/nginx/install_cloud_browser_viewer.sh /var/www/suxios/current
```

详细契约见 `deploy/nginx/README-cloud-browser-viewer.md`。旧版 SSH 隧道仅作应急运维备用，不是新门店的主流程。
